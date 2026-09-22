<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$ops=release_operational_audit($pdo,$config,dirname(__DIR__));$health=$ops['environment'];$json=in_array('--json',$argv,true);
if($json){echo json_encode($ops,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit($ops['ready']?0:1);}
echo ANNOTATED_RELEASE." · ".ANNOTATED_RELEASE_VERSION." · Phase ".ANNOTATED_RELEASE_PHASE."\n";
echo str_repeat('=',58)."\n";
foreach($health['checks'] as $c){$icon=$c['status']==='pass'?'PASS':($c['status']==='warn'?'WARN':'FAIL');printf("%-5s  %-28s %s\n",$icon,$c['label'],$c['detail']);}
echo "\nRelease operations\n";foreach($ops['checks'] as $name=>$check)printf("%-5s  %-28s %s\n",$check['pass']?'PASS':'FAIL',ucwords(str_replace('_',' ',$name)),$check['detail']);echo "Fingerprint: ".$ops['release']['package_fingerprint']."\n";
echo "\nWorkers\n";foreach($health['workers'] as $w)printf("%-20s %-8s last seen %-19s · %s\n",$w['name'],$w['status'],$w['last_seen_at']?:'never',$w['command']?:'');
echo "\nQueues\n";foreach($health['queues'] as $name=>$q){if(isset($q['error'])){echo "$name: unavailable\n";continue;}printf("%-16s queued=%d processing=%d failed=%d blocked=%d done=%d\n",$name,$q['queued'],$q['processing'],$q['failed'],$q['blocked'],$q['done']);}
echo "\nBackup tooling: ".($ops['backup']['pass']?'READY':'BLOCKED')."\n";echo ($ops['ready']?'Operational release checks pass.':'RELEASE BLOCKED: fix operational failures before deploying.')."\n";
exit($ops['ready']?0:1);
