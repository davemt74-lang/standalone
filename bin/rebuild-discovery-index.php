<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$count=search_rebuild_public_entities($pdo,(int)($argv[1]??1000));
fwrite(STDOUT,"Indexed $count public Research entity mentions.\n");
