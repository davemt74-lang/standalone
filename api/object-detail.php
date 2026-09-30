<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$viewer=require_api_user($pdo);
rate_limit_api_or_429($pdo,'universal-object-detail','user:'.$viewer['id'],240,3600);
$type=(string)($_GET['type']??'');$public=(string)($_GET['id']??'');
try{
  $detail=universal_object_detail($pdo,$viewer,$type,$public);
  if(!$detail)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Object not found or unavailable.']],404);
  json_response(['ok'=>true,'data'=>$detail]);
}catch(Throwable $e){error_log('[Annotated universal object detail] '.$e->getMessage());json_response(['ok'=>false,'error'=>['code'=>'OBJECT_DETAIL_ERROR','message'=>'Object detail failed.']],500);}
