<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$viewer=require_api_mutation_auth($pdo);
rate_limit_api_or_429($pdo,'profile-follow','user:'.$viewer['id'],120,3600);
$input=json_decode(file_get_contents('php://input'),true)?:[];
try{
  json_response(['ok'=>true,'data'=>profile_network_toggle_follow($pdo,$viewer,(string)($input['user_id']??''))]);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_USER','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'FOLLOW_FORBIDDEN','message'=>$e->getMessage()]],403);}
catch(Throwable $e){error_log('[Annotated profile follow] '.$e->getMessage());json_response(['ok'=>false,'error'=>['code'=>'FOLLOW_ERROR','message'=>'Unable to update follow state.']],500);}
