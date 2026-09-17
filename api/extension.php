<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/extension-auth.php';
extension_api_headers($config);
enforce_extension_bearer_session($pdo);
$action=(string)($_GET['action']??'');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
require __DIR__.'/extension-base.php';
require __DIR__.'/extension-live.php';
require __DIR__.'/extension-publish.php';
json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
