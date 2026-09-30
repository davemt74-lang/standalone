<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$viewer=require_api_mutation_auth($pdo);
$input=json_decode(file_get_contents('php://input'),true)?:[];
rate_limit_api_or_429($pdo,'object-navigation','user:'.$viewer['id'],180,3600);
try{
    $action=trim((string)($_GET['action']??''));
    if($action==='pin'){
        $pinned=research_object_pin_set($pdo,$viewer,(string)($input['type']??''),(string)($input['public_id']??''),(bool)($input['pinned']??false));
        json_response(['ok'=>true,'data'=>['pinned'=>$pinned]]);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION','message'=>'Unknown object navigation action.']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'OBJECT_NAVIGATION_ERROR','message'=>$e->getMessage()]],403);}
catch(Throwable $e){error_log('[Annotated object navigation] '.$e->getMessage());json_response(['ok'=>false,'error'=>['code'=>'OBJECT_NAVIGATION_INTERNAL','message'=>'Object navigation request failed.']],500);}
