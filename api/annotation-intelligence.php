<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$action=(string)($_GET['action']??'get');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
if(!annotation_intelligence_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Annotation Intelligence.']],503);
try{
    if($action==='get'){
        $viewer=require_api_user($pdo);$id=trim((string)($input['annotation_id']??$input['id']??''));$a=annotation_access($pdo,$id,$viewer);if(!$a)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        $record=annotation_intelligence_record($pdo,(int)$a['id']);if($record)$record['relationships']=annotation_intelligence_visible_relationships($pdo,(int)$a['id'],$viewer,12);
        json_response(['ok'=>true,'data'=>['annotation_id'=>$id,'intelligence'=>$record]]);
    }
    if($action==='refresh'){
        $viewer=require_api_mutation_auth($pdo);rate_limit_api_or_429($pdo,'annotation-intelligence-refresh','user:'.$viewer['id'],60,3600);
        $id=trim((string)($input['annotation_id']??$input['id']??''));$a=annotation_access($pdo,$id,$viewer);if(!$a)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        if((int)$a['user_id']!==(int)$viewer['id']&&($viewer['role']??'')!=='admin')json_response(['ok'=>false,'error'=>['code'=>'FORBIDDEN']],403);
        $queued=annotation_intelligence_queue($pdo,(int)$a['id'],null,2);json_response(['ok'=>true,'data'=>['annotation_id'=>$id,'queued'=>$queued]]);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'ANNOTATION_INTELLIGENCE_ERROR','message'=>$e->getMessage()]],400);}
