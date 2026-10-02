<?php
declare(strict_types=1);
/**
 * Targeted real-database acceptance: use current schema to bind backup target
 * and ensure a signed manifest alone cannot conceal damaged archive streams.
 * Never restores or drops the user's database.
 */
$root=dirname(__DIR__);
$dsn=(string)getenv('DB_DSN');
if($dsn==='')throw new RuntimeException('DB_DSN required.');
require_once $root.'/app/installer.php';
require_once $root.'/app/functions.php';
require_once $root.'/app/release.php';
require_once $root.'/app/v1-backup-certification.php';
$pdo=new PDO($dsn,(string)getenv('DB_USER'),(string)getenv('DB_PASS'),[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pass=static function(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);echo "PASS: $label\n";};
$pass(installer_pending_migrations($pdo,$root.'/database/migrations')===[],
 'Current MariaDB/MySQL 8 schema is migration-complete.');
$tar=release_command_path('tar');$gzip=release_command_path('gzip');
$pass($tar!==null&&$gzip!==null&&function_exists('gzencode'),'Archive verification tools are available.');
$tmp=sys_get_temp_dir().'/annotated-v1-restore-'.bin2hex(random_bytes(7));
$private=$tmp.'/private-store';$backup=$tmp.'/backup';
if(!mkdir($private,0700,true)||!mkdir($backup,0700,true))throw new RuntimeException('Cannot create isolated fixture.');
$config=['app'=>['base_url'=>'https://annotated.example.test'],
 'db'=>['dsn'=>$dsn,'user'=>(string)getenv('DB_USER'),'pass'=>(string)getenv('DB_PASS')],
 'storage'=>['private_root'=>$private]];
$writeTar=static function()use($tar,$tmp,$backup):void{
 $r=release_process_run([$tar,'-C',$tmp,'-czf',$backup.'/private-storage.tar.gz','private-store']);
 if($r['code']!==0)throw new RuntimeException('Tar fixture failed: '.$r['stderr']);
};
$writeManifest=static function()use($backup,$config,$root):void{
 release_backup_manifest_write($backup,$config,$root,['build_sha'=>'v1-archive-acceptance']);
};
try{
 file_put_contents($private.'/evidence.txt',"private evidence survives a matched archive\n");
 file_put_contents($backup.'/database.sql.gz',gzencode("-- isolated database backup fixture\nSELECT 1;\n",9));
 $writeTar();$writeManifest();
 $report=v1_backup_archive_certify($backup,'private-store',$tar,$gzip);
 $pass($report['ready'],'Untampered matched database and private-storage archive passes certification.');
 $pass($report['production_restored']===false&&!$report['integrity_is_authentication'],
 'Certified archives do not pretend actual production restore or origin authentication.');
 $pass(v1_backup_archive_certify($backup,'some-other-store',$tar,$gzip)['ready']===false,
 'Different configured storage target is rejected.');
 file_put_contents($backup.'/.INCOMPLETE',"interrupted\n");
 $pass(v1_backup_archive_certify($backup,'private-store',$tar,$gzip)['ready']===false,
 'Interrupted backup fails closed even with valid component hashes.');
 unlink($backup.'/.INCOMPLETE');
 file_put_contents($backup.'/database.sql.gz',gzencode('broken',9));
 file_put_contents($backup.'/database.sql.gz','corrupted-stream',FILE_APPEND);
 $writeManifest();
 $pass(v1_backup_archive_certify($backup,'private-store',$tar,$gzip)['ready']===false,
 'Re-signed but malformed database gzip is rejected.');
 file_put_contents($backup.'/database.sql.gz',gzencode('SELECT 1;',9));
 @symlink($tmp.'/outside-secret',$private.'/escape-link');
 if(is_link($private.'/escape-link')){
   $writeTar();$writeManifest();
   $pass(v1_backup_archive_certify($backup,'private-store',$tar,$gzip)['ready']===false,
    'Re-signed archive containing a symlink is rejected.');
   unlink($private.'/escape-link');
 }
 $writeTar();$writeManifest();
 file_put_contents($backup.'/private-storage.tar.gz','tampered',FILE_APPEND);
 $pass(v1_backup_archive_certify($backup,'private-store',$tar,$gzip)['ready']===false,
  'Tampered storage content is rejected by the original SHA-256 verifier.');
}finally{
 foreach(glob($backup.'/*')?:[] as $f)@unlink($f);
 foreach(glob($private.'/*')?:[] as $f)@unlink($f);
 @rmdir($backup);@rmdir($private);@rmdir($tmp);
}
echo "V1 targeted backup archive certification DB acceptance passed.\n";
