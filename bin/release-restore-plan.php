<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$args=[];foreach(array_slice($argv,1) as $arg)if(str_starts_with($arg,'--backup='))$args['backup']=substr($arg,9);elseif($arg==='--json')$args['json']='1';
$dir=(string)($args['backup']??'');if($dir===''){fwrite(STDERR,"Usage: php bin/release-restore-plan.php --backup=/path/to/backup [--json]\n");exit(2);}
try{$plan=release_restore_plan($dir,$config);}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
if(isset($args['json'])){echo json_encode($plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit(0);}
echo "VERIFIED BACKUP: ".$plan['backup_id']."\n";echo "Restore execution is intentionally manual. Stop traffic/workers first and restore DB + private storage as a matched pair.\n\n";foreach($plan['steps'] as $i=>$step)echo ($i+1).". ".$step."\n";echo "\nCommand templates (no password included):\nDB: ".$plan['commands']['database']."\nStorage: ".$plan['commands']['storage']."\n";
