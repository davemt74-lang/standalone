<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$dsn=(string)getenv('DB_DSN');
if($dsn==='')throw new RuntimeException('DB_DSN required for backup certification fixture.');
$pdo=new PDO($dsn,(string)getenv('DB_USER'),(string)getenv('DB_PASS'),[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false,
]);
require_once $root.'/app/installer.php';
require_once $root.'/app/release.php';
require_once $root.'/app/v1-backup-certification.php';
$check=static function(bool $ok,string $m):void{if(!$ok)throw new RuntimeException('FAIL: '.$m);echo 'PASS: '.$m.PHP_EOL;};
$tmp=sys_get_temp_dir().'/annotated-v1-backup-'.bin2hex(random_bytes(5));
$storage=$tmp.'/private';$backups=$tmp.'/backups';$dir=$backups.'/sample';
foreach([$storage,$dir] as $p)if(!mkdir($p,0700,true))throw new RuntimeException('Cannot build private fixture.');
$cfg=['db'=>['dsn'=>$dsn,'user'=>(string)getenv('DB_USER'),'pass'=>(string)getenv('DB_PASS')],
      'storage'=>['private_root'=>$storage]];
$archive=$dir.'/private-storage.tar.gz';
$sqlFile=$dir.'/database.sql.gz';
$table='v1_restore_probe_'.bin2hex(random_bytes(4));
try{
    file_put_contents($storage.'/identity.txt','private restore identity '.bin2hex(random_bytes(8)));
    $sql="CREATE TABLE ".$table." (id INT NOT NULL PRIMARY KEY, value VARCHAR(50) NOT NULL) ENGINE=InnoDB; INSERT INTO ".$table." VALUES (1,'isolated restore replay');";
    $gz=gzopen($sqlFile,'wb9');if(!$gz)throw new RuntimeException('Cannot create SQL gzip fixture.');
    gzwrite($gz,$sql);gzclose($gz);
    $tar=release_command_path(['tar']);$gzip=release_command_path(['gzip']);
    $check($tar!==null&&$gzip!==null,'Tar and gzip certification dependencies are installed.');
    $run=release_process_run([$tar,'-C',$tmp,'-czf',$archive,'private']);
    $check($run['code']===0,'Private storage fixture is a valid tar.gz archive.');
    $manifest=release_backup_manifest_write($dir,$cfg,$root,['build_sha'=>'v1-backup-cert-test']);
    $cert=v1_backup_archive_certify($dir,'private',$tar,$gzip);
    $check($cert['ready']&&hash_equals($manifest['backup_id'],$cert['backup_id']),'Original paired archives and manifest pass deep verification.');
    $plan=release_restore_plan($dir,$cfg);
    $check($plan['execution']==='manual_confirmation_required'&&$plan['destructive'],'Original restore planner remains human-controlled.');
    $check(!v1_backup_recovery_evidence($cert)['production_restored'],'Local certification cannot claim a production restore.');
    $restored=$tmp.'/isolated-staging';mkdir($restored,0700);
    $extract=release_process_run([$tar,'-xzf',$archive,'-C',$restored]);
    $check($extract['code']===0
        &&file_get_contents($restored.'/private/identity.txt')===file_get_contents($storage.'/identity.txt'),
        'Certified storage archive restores byte-for-byte into an isolated staging directory.');
    $sqlRead=gzopen($sqlFile,'rb');$replay='';while(!gzeof($sqlRead)){$block=gzread($sqlRead,4096);if($block===false)throw new RuntimeException('Invalid SQL stream.');$replay.=$block;}gzclose($sqlRead);
    $check(hash_equals($sql,$replay),'Database SQL backup restores its exact compressed statement stream.');
    foreach(explode(';',$replay) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
    $check((string)$pdo->query('SELECT value FROM '.$table.' WHERE id=1')->fetchColumn()==='isolated restore replay',
      'Restored SQL executes on the isolated section database without touching production.');
    $pdo->exec('DROP TABLE '.$table);
    file_put_contents($sqlFile,'not a gzip stream');
    release_backup_manifest_write($dir,$cfg,$root,['build_sha'=>'v1-backup-cert-test']);
    $invalid=v1_backup_archive_certify($dir,'private',$tar,$gzip);
    $check(!$invalid['ready']&&str_contains(implode(' ',$invalid['errors']),'gzip'),'Re-signed corrupt SQL gzip is still rejected.');
    $gz=gzopen($sqlFile,'wb9');gzwrite($gz,$sql);gzclose($gz);
    symlink('/etc',$storage.'/external-link');
    $run=release_process_run([$tar,'-C',$tmp,'-czf',$archive,'private']);
    $check($run['code']===0,'Unsafe symlink fixture was constructed.');
    release_backup_manifest_write($dir,$cfg,$root,['build_sha'=>'v1-backup-cert-test']);
    $unsafe=v1_backup_archive_certify($dir,'private',$tar,$gzip);
    $check(!$unsafe['ready']&&str_contains(implode(' ',$unsafe['errors']),'link'),
       'Even re-signed private tar files containing symlinks are refused.');
    $check(!v1_backup_archive_certify($dir,'other-root',$tar,$gzip)['ready'],
       'Wrong private storage target is rejected even with a valid manifest.');
}finally{
    try{$pdo->exec('DROP TABLE IF EXISTS '.$table);}catch(Throwable $e){}
    if(is_dir($tmp)){
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $entry){$name=$entry->getPathname();if($entry->isDir()&&!$entry->isLink())@rmdir($name);else @unlink($name);}
        @rmdir($tmp);
    }
}
echo "V1 isolated archive + SQL replay certification passed.\n";
