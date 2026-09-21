<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/action-center.php';
api_headers();$viewer=require_api_user($pdo);$limit=(int)($_GET['limit']??60);
json_response(['ok'=>true,'data'=>action_center_compose($pdo,$viewer,null,$limit)]);
