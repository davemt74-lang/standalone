<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$health=release_environment_checks($pdo,$config);$json=in_array('--json',$argv,true);
if($json){echo json_encode($health,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit($health['ready']?0:1);}
echo ANNOTATED_RELEASE." · ".ANNOTATED_RELEASE_VERSION."\n";
echo str_repeat('=',58)."\n";
foreach($health['checks'] as $c){$icon=$c['status']==='pass'?'PASS':($c['status']==='warn'?'WARN':'FAIL');printf("%-5s  %-28s %s\n",$icon,$c['label'],$c['detail']);}
echo "\nWorkers\n";foreach($health['workers'] as $w)printf("%-16s %-8s last seen %s\n",$w['name'],$w['status'],$w['last_seen_at']?:'never');
echo "\nQueues\n";foreach($health['queues'] as $name=>$q){if(isset($q['error'])){echo "$name: unavailable\n";continue;}printf("%-16s queued=%d processing=%d failed=%d blocked=%d done=%d\n",$name,$q['queued'],$q['processing'],$q['failed'],$q['blocked'],$q['done']);}
echo "\n".($health['ready']?'Critical release checks pass.':'RELEASE BLOCKED: fix critical failures before deploying.')."\n";
exit($health['ready']?0:1);
