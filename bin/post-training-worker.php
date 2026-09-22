<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
if(!data_post_training_ready($pdo)){fwrite(STDERR,"Post-Training Evaluation requires the Phase 42 database upgrade.\n");exit(2);}
$max=max(1,min(50,(int)($argv[1]??10)));$done=0;$failed=0;release_worker_heartbeat($pdo,'post_training','starting','Post-training readiness worker started.');
for($i=0;$i<$max;$i++){
    try{$plan=data_post_training_process_next($pdo);if(!$plan)break;$done++;echo $plan['public_id'].' · '.$plan['status']."\n";}
    catch(Throwable $e){$failed++;fwrite(STDERR,'post-training worker failure: '.$e->getMessage()."\n");}
}
$status=$failed>0?'failure':'success';release_worker_heartbeat($pdo,'post_training',$status,"Post-training worker complete · processed=$done · failures=$failed",$done);
echo "Post-training worker complete · processed=$done · failures=$failed\n";
exit($failed>0?1:0);