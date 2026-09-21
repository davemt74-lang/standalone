<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();$action=(string)($_GET['action']??'status');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $viewer=in_array($action,['subscribe','unsubscribe'],true)?require_api_mutation_auth($pdo):require_api_user($pdo);
    if(!living_research_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Living Research.']],503);
    if($action==='status'){$report=research_report_access($pdo,(string)($input['report_id']??''),$viewer);if(!$report)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);json_response(['ok'=>true,'data'=>['subscription'=>living_research_subscription($pdo,$viewer,$report),'read_status'=>living_research_read_status($pdo,$viewer,$report),'subscriber_count'=>living_research_subscriber_count($pdo,(int)$report['id']),'topics'=>living_research_topics($pdo,(int)$report['id'])]]);}
    if($action==='subscribe'||$action==='unsubscribe'){rate_limit_api_or_429($pdo,'living-research-subscription','user:'.$viewer['id'],120,3600);$report=research_report_access($pdo,(string)($input['report_id']??''),$viewer);if(!$report)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);$on=$action==='subscribe';living_research_set_subscription($pdo,$viewer,$report,$on,true);json_response(['ok'=>true,'data'=>['subscribed'=>$on]]);}
    if($action==='diff'){$report=research_report_access($pdo,(string)($input['report_id']??''),$viewer);if(!$report)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);$diff=living_research_version_diff($pdo,$report,$viewer,(int)($input['from']??0),(int)($input['to']??0));unset($diff['from_snapshot'],$diff['to_snapshot']);json_response(['ok'=>true,'data'=>$diff]);}
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'LIVING_RESEARCH_ERROR','message'=>$e->getMessage()]],400);}
