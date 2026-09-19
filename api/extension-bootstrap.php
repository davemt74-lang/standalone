<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/extension-auth.php';
extension_api_headers($config);

if($_SERVER['REQUEST_METHOD']!=='GET')json_response(['ok'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED']],405);
json_response(['ok'=>true,'data'=>[
    'product'=>'Annotated',
    'base_url'=>rtrim((string)($config['app']['base_url']??''),'/'),
    'extension_auth'=>'sidebar',
]]);
