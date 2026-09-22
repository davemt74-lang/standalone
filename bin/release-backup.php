<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$root=dirname(__DIR__);
$args=[];foreach(array_slice($argv,1) as $arg)if(str_starts_with($arg,'--')&&str_contains($arg,'=')){[$k,$v]=explode('=',substr($arg,2),2);$args[$k]=$v;}elseif($arg==='--dry-run')$args['dry-run']='1';elseif($arg==='--json')$args['json']='1';
$base=(string)($args['output']??($root.'/backups'));$dry=isset($args['dry-run']);$json=isset($args['json']);$req=release_backup_requirements($config);
$stamp=gmdate('Ymd\THis\Z');$suffix=substr(bin2hex(random_bytes(4)),0,8);$dest=rtrim($base,'/').'/annotated-'.$stamp.'-'.$suffix;
$preview=['ready'=>$req['pass'],'destination'=>$dest,'requirements'=>$req['checks'],'release'=>release_manifest_data($root,(string)(getenv('ANNOTATED_BUILD_SHA')?:''))];
if($dry){echo $json?json_encode($preview,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL:("Backup dry run: ".($req['pass']?'READY':'BLOCKED')."\nDestination: $dest\n");exit($req['pass']?0:1);}
if(!$req['pass']){fwrite(STDERR,"Backup blocked: prerequisites are incomplete. Run with --dry-run --json for details.\n");exit(2);}
if(!is_dir($base)&&!mkdir($base,0700,true)&&!is_dir($base))throw new RuntimeException('Unable to create backup output directory.');if(!mkdir($dest,0700,true))throw new RuntimeException('Unable to create backup directory.');
$target=$req['database'];$clientFile=tempnam(sys_get_temp_dir(),'annotated-db-');if($clientFile===false)throw new RuntimeException('Unable to create temporary database client file.');
try{
    chmod($clientFile,0600);$cnf="[client]\nuser=".$target['user']."\npassword=".$target['password']."\n";if($target['unix_socket'])$cnf.="socket=".$target['unix_socket']."\n";else $cnf.="host=".$target['host']."\nport=".$target['port']."\n";file_put_contents($clientFile,$cnf,LOCK_EX);
    $sql=$dest.'/database.sql';$cmd=[$req['commands']['dump'],'--defaults-extra-file='.$clientFile,'--single-transaction','--quick','--skip-lock-tables','--routines','--events','--triggers','--hex-blob',$target['database']];$run=release_process_run($cmd,$sql);if($run['code']!==0)throw new RuntimeException('Database backup failed: '.mb_substr(trim($run['stderr']),0,500));release_gzip_file($sql,$dest.'/database.sql.gz');@unlink($sql);
    $storage=rtrim((string)$config['storage']['private_root'],'/');$tar=release_process_run([$req['commands']['tar'],'-C',dirname($storage),'-czf',$dest.'/private-storage.tar.gz',basename($storage)]);if($tar['code']!==0)throw new RuntimeException('Private storage backup failed: '.mb_substr(trim($tar['stderr']),0,500));
    $manifest=release_backup_manifest_write($dest,$config,$root,['build_sha'=>(string)(getenv('ANNOTATED_BUILD_SHA')?:getenv('GITHUB_SHA')?:'')]);$verify=release_backup_manifest_verify($dest);if(!$verify['ok'])throw new RuntimeException('Backup verification failed immediately after creation: '.implode(' ',$verify['errors']));
    file_put_contents($dest.'/RESTORE.txt',"Annotated backup ".$manifest['backup_id']."\nVerify: php bin/release-backup-verify.php --backup=".escapeshellarg($dest)."\nRestore plan: php bin/release-restore-plan.php --backup=".escapeshellarg($dest)."\n",LOCK_EX);
    $result=['ok'=>true,'backup_id'=>$manifest['backup_id'],'directory'=>$dest,'files'=>$manifest['files']];echo $json?json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL:("Backup complete.\nID: ".$manifest['backup_id']."\nDirectory: $dest\n");
}finally{@unlink($clientFile);}
