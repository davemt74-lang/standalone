<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
if(!data_training_ready($pdo)){fwrite(STDERR,"Training Registry requires the Phase 41 database upgrade.\n");exit(2);}
$max=max(1,min(50,(int)($argv[1]??5)));$done=0;$failed=0;
for($i=0;$i<$max;$i++){
    try{$job=data_training_process_next($pdo,$config);if(!$job)break;$done++;echo $job['public_id'].' · '.$job['status'].($job['provider_status']?' · '.$job['provider_status']:'')."\n";}
    catch(Throwable $e){$failed++;fwrite(STDERR,'training worker failure: '.$e->getMessage()."\n");}
}
echo "Training worker complete · processed=$done · failures=$failed\n";
exit($failed>0?1:0);
