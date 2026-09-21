<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$action=(string)($_GET['action']??'summary');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
if(!data_attribution_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Data & Attribution.']],503);
try{
    if($action==='summary'){
        $u=require_api_user($pdo);json_response(['ok'=>true,'data'=>data_contributor_summary($pdo,$u)]);
    }
    if($action==='lineage'){
        $u=require_api_user($pdo);$row=data_response_lineage_access($pdo,$u,(string)($input['run_id']??''));if(!$row)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);json_response(['ok'=>true,'data'=>$row]);
    }
    if($action==='source_rights'){
        $u=require_api_user($pdo);$row=data_source_rights($pdo,(string)($input['source_id']??''));if(!$row)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);if(($u['role']??'')!=='admin')unset($row['reviewed_by_user_id']);json_response(['ok'=>true,'data'=>$row]);
    }
    if($action==='preferences'){
        $u=require_api_mutation_auth($pdo);json_response(['ok'=>true,'data'=>data_contributor_preferences_update($pdo,$u,$input)]);
    }
    if($action==='grant'){
        $u=require_api_mutation_auth($pdo);$type=(string)($input['object_type']??'');$id=(string)($input['object_id']??'');json_response(['ok'=>true,'data'=>data_usage_grant_set($pdo,$u,$type,$id,$input)]);
    }
    if($action==='revoke_grant'){
        $u=require_api_mutation_auth($pdo);$ok=data_usage_grant_revoke($pdo,$u,(string)($input['object_type']??''),(string)($input['object_id']??''));json_response(['ok'=>true,'data'=>['revoked'=>$ok]]);
    }
    if($action==='sync'){
        $u=require_api_mutation_auth($pdo);json_response(['ok'=>true,'data'=>data_attribution_sync_user($pdo,$u,300)]);
    }
    if($action==='source_rights_set'){
        $u=require_api_mutation_auth($pdo);json_response(['ok'=>true,'data'=>data_source_rights_set($pdo,$u,(string)($input['source_id']??''),$input)]);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'DATA_ATTRIBUTION_ERROR','message'=>$e->getMessage()]],403);}
