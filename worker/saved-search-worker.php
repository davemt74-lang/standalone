<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$limit=max(1,min(200,(int)($argv[1]??50)));release_worker_heartbeat($pdo,'saved_search','starting','Worker invocation started.');
try{$count=search_saved_alert_worker($pdo,$limit);release_worker_heartbeat($pdo,'saved_search','success',"Processed saved-search alerts; $count new matches.",$count);fwrite(STDOUT,"Processed saved-search alerts; $count new matches.\n");}
catch(Throwable $e){$msg=mb_substr($e->getMessage(),0,1000);release_worker_heartbeat($pdo,'saved_search','failure',$msg);fwrite(STDERR,$msg."\n");exit(1);}
