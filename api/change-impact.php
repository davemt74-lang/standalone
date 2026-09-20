<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();$action=(string)($_GET['action']??'view');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $viewer=$action==='decide'?require_api_mutation_auth($pdo):require_api_user($pdo);
    if(!change_impact_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Change Impact Intelligence.']],503);
    if($action==='view'){$eventId=(int)($input['event_id']??$input['event']??0);$view=change_impact_event_view($pdo,$viewer,$eventId);if(!$view)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);json_response(['ok'=>true,'data'=>$view]);}
    if($action==='project'){$project=(string)($input['project_id']??'');if($project==='')json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>'Project ID is required.']],422);json_response(['ok'=>true,'data'=>['summary'=>change_impact_project_summary($pdo,$viewer,$project,20)]]);}
    if($action==='decide'){rate_limit_api_or_429($pdo,'change-impact-review','user:'.$viewer['id'],180,3600);$row=change_impact_set_decision($pdo,$viewer,(int)($input['event_id']??0),(string)($input['object_type']??''),(string)($input['object_public_id']??''),(string)($input['decision']??''),(string)($input['note']??''),isset($input['snoozed_until'])?(string)$input['snoozed_until']:null);json_response(['ok'=>true,'data'=>['review'=>$row]]);}
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'IMPACT_ERROR','message'=>$e->getMessage()]],400);}
