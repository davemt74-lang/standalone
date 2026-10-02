<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/v1-worker-certification.php';
$report=v1_worker_certification_snapshot($pdo,dirname(__DIR__));
$json=in_array('--json',$argv,true);
if($json){echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;exit($report['code_and_heartbeat_ready']?0:1);}
echo "Annotated V1 Worker Certification — READ ONLY\n";
echo "Heartbeat and queue readiness: ".($report['code_and_heartbeat_ready']?'PASS':'BLOCKED')."\n";
echo "Production scheduler and notification delivery: NOT VERIFIED\n";
foreach($report['workers'] as $w){
    echo sprintf("%-24s %-19s %s\n",$w['name'],$w['state'],$w['issues']?implode(', ',$w['issues']):$w['heartbeat']);
}
echo "\nOperator verification required:\n";
foreach($report['operator_checks'] as $i=>$step)echo ($i+1).". ".$step."\n";
exit($report['code_and_heartbeat_ready']?0:1);
