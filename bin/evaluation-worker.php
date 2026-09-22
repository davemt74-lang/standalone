<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
if(!data_evaluation_ready($pdo)){release_worker_heartbeat($pdo,'evaluation','failure','Evaluation Harness requires the Phase 39 database upgrade.');fwrite(STDERR,"Evaluation Harness requires the Phase 39 database upgrade.\n");exit(2);}
$max=max(1,min(50,(int)($argv[1]??5)));$done=0;$failed=0;release_worker_heartbeat($pdo,'evaluation','starting','Evaluation worker started.');
for($i=0;$i<$max;$i++){
    try{$run=data_evaluation_process_next($pdo,$config);if(!$run)break;$done++;echo 'completed '.$run['public_id'].' · '.$run['benchmark_type'].' · cases '.(int)($run['summary']['case_count']??0)."\n";}
    catch(Throwable $e){$failed++;fwrite(STDERR,'evaluation failed: '.$e->getMessage()."\n");}
}
$status=$failed>0?'failure':'success';release_worker_heartbeat($pdo,'evaluation',$status,"Evaluation worker complete · completed=$done · failed=$failed",$done);
echo "Evaluation worker complete · completed=$done · failed=$failed\n";
exit($failed>0?1:0);
