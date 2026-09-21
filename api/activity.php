<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/unified-activity.php';
api_headers();
$viewer=require_api_user($pdo);$limit=(int)($_GET['limit']??40);
json_response(['ok'=>true,'data'=>['items'=>unified_activity_collect($pdo,$viewer,$limit)]]);
