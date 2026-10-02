<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/v1-backup-certification.php';
$backup='';
$json=in_array('--json',$argv,true);
foreach(array_slice($argv,1) as $arg)if(str_starts_with($arg,'--backup='))$backup=substr($arg,9);
if($backup===''){fwrite(STDERR,"Usage: php bin/v1-restore-certify.php --backup=/outside-webroot/annotated-backup [--json]\n");exit(2);}
$storage=basename(rtrim((string)($config['storage']['private_root']??''),'/'));
$report=v1_backup_archive_certify($backup,$storage);
if($report['ready']){
    try{$plan=release_restore_plan($backup,$config);$report['restore_plan']=[
      'backup_id'=>$plan['backup_id'],'database_target'=>$plan['database_target'],
      'storage_target'=>$plan['storage_target'],'execution'=>$plan['execution'],
      'steps'=>$plan['steps'],
    ];}
    catch(Throwable $e){$report['ready']=false;$report['errors'][]='Restore planning blocked: '.$e->getMessage();}
}
$report['evidence']=v1_backup_recovery_evidence($report);
if($json)echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
else{
    echo "V1 backup archive certification: ".($report['ready']?'PASS':'BLOCKED')."\n";
    foreach($report['errors'] as $error)echo " - ".$error."\n";
    echo "No restore was executed. Confirm the matching release and rehearse in isolation.\n";
}
exit($report['ready']?0:1);
