<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();$action=(string)($_GET['action']??'list');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $viewer=in_array($action,['list','summary'],true)?require_api_user($pdo):require_api_mutation_auth($pdo);
    if(!research_outcomes_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Decision Memory.']],503);
    if($action==='list'){research_outcome_sync($pdo,$viewer);$project=isset($input['project'])&&trim((string)$input['project'])!==''?(string)$input['project']:null;$decision=isset($input['decision'])&&trim((string)$input['decision'])!==''?(string)$input['decision']:null;json_response(['ok'=>true,'data'=>['outcomes'=>research_outcome_list($pdo,$viewer,$project,$decision,150)]]);}
    if($action==='summary'){research_outcome_sync($pdo,$viewer);$project=isset($input['project'])&&trim((string)$input['project'])!==''?(string)$input['project']:null;json_response(['ok'=>true,'data'=>research_outcome_summary($pdo,$viewer,$project)]);}
    if($action==='record'){rate_limit_api_or_429($pdo,'research-outcome-record','user:'.$viewer['id'],60,3600);$row=research_outcome_manual($pdo,$viewer,$input);json_response(['ok'=>true,'data'=>['outcome'=>$row]],201);}
    if($action==='feedback'){rate_limit_api_or_429($pdo,'research-outcome-feedback','user:'.$viewer['id'],180,3600);$row=research_outcome_feedback_set($pdo,$viewer,(string)($input['outcome_id']??''),array_key_exists('usefulness',$input)&&$input['usefulness']!==''?(string)$input['usefulness']:null,(string)($input['follow_up_state']??'none'),(string)($input['comment']??''));json_response(['ok'=>true,'data'=>['outcome'=>$row]]);}
    if($action==='sync'){rate_limit_api_or_429($pdo,'research-outcome-sync','user:'.$viewer['id'],30,3600);json_response(['ok'=>true,'data'=>research_outcome_sync($pdo,$viewer)]);}
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'OUTCOME_ERROR','message'=>$e->getMessage()]],400);}
