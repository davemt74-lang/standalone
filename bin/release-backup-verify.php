<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/release.php';require_once dirname(__DIR__).'/app/release-operations.php';
$args=[];foreach(array_slice($argv,1) as $arg)if(str_starts_with($arg,'--backup='))$args['backup']=substr($arg,9);elseif($arg==='--json')$args['json']='1';
$dir=(string)($args['backup']??'');if($dir===''){fwrite(STDERR,"Usage: php bin/release-backup-verify.php --backup=/path/to/backup [--json]\n");exit(2);}
$r=release_backup_manifest_verify($dir);if(isset($args['json']))echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;else{echo ($r['ok']?'PASS':'FAIL')." backup verification\n";foreach($r['errors'] as $e)echo "- $e\n";if($r['manifest'])echo "Backup ID: ".($r['manifest']['backup_id']??'unknown')."\n";}exit($r['ok']?0:1);
