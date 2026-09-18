<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$limit=max(1,min(200,(int)($argv[1]??50)));
$count=search_saved_alert_worker($pdo,$limit);
fwrite(STDOUT,"Processed saved-search alerts; $count new matches.\n");
