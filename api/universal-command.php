<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$viewer=current_user($pdo);if(!$viewer)json_response(['ok'=>false,'error'=>['code'=>'AUTH_REQUIRED','message'=>'Sign in required.']],401);
rate_limit_api_or_429($pdo,'universal-command','user:'.$viewer['id'],240,3600);
try{
  $q=(string)($_GET['q']??'');$limit=max(3,min(15,(int)($_GET['limit']??8)));
  json_response(['ok'=>true,'data'=>universal_command_search($pdo,$viewer,$q,$limit)]);
}catch(Throwable $e){
  error_log('[Annotated universal command] '.$e->getMessage());
  json_response(['ok'=>false,'error'=>['code'=>'UNIVERSAL_COMMAND_ERROR','message'=>'Search failed.']],500);
}
