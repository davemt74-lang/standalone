<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();$action=(string)($_GET['action']??'project');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $viewer=$action==='record'?require_api_mutation_auth($pdo):require_api_user($pdo);
    if(!research_verification_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Research Verification.']],503);
    if($action==='project'){
        $project=(string)($input['project_id']??'');$summary=research_verification_project_summary($pdo,$viewer,$project,200);if(empty($summary['available']))json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        json_response(['ok'=>true,'data'=>['summary'=>$summary]]);
    }
    if($action==='claim'){
        $claim=research_claim_access($pdo,$viewer,(string)($input['claim_id']??''));if(!$claim)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        json_response(['ok'=>true,'data'=>['claim'=>research_verification_claim_state($pdo,$viewer,$claim)]]);
    }
    if($action==='events'){
        json_response(['ok'=>true,'data'=>['events'=>research_verification_project_events($pdo,$viewer,(string)($input['project_id']??''),100)]]);
    }
    if($action==='record'){
        rate_limit_api_or_429($pdo,'research-verification','user:'.$viewer['id'],80,3600);
        $event=research_verification_record($pdo,$viewer,(string)($input['subject_type']??''),(string)($input['subject_id']??''),(string)($input['decision']??''),(string)($input['note']??''));
        json_response(['ok'=>true,'data'=>['event'=>['public_id'=>$event['public_id'],'decision'=>$event['decision'],'created_at'=>$event['created_at']]]],201);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'VERIFICATION_ERROR','message'=>$e->getMessage()]],400);}
