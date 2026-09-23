<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
$action=(string)($input['action']??$_GET['action']??'status');
try{
    $viewer=$_SERVER['REQUEST_METHOD']==='POST'?require_api_mutation_auth($pdo):require_api_user($pdo);
    if(!research_autonomy_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Autonomous Research requires the latest database upgrade.']],503);
    $agentPublic=trim((string)($input['agent_id']??''));if($agentPublic==='')throw new InvalidArgumentException('Research Agent is required.');
    $agent=research_agent_access($pdo,$viewer,$agentPublic);if(!$agent)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
    if($action==='status'){json_response(['ok'=>true,'data'=>research_autonomy_status($pdo,$viewer,$agentPublic)]);}
    if($action==='run'){
        $project=project_access($pdo,(int)$viewer['id'],(string)$agent['project_public_id']);if(!$project||!project_can_write($project))json_response(['ok'=>false,'error'=>['code'=>'FORBIDDEN']],403);
        rate_limit_api_or_429($pdo,'research-autonomy-run','user:'.$viewer['id'],30,3600);
        research_autonomy_queue($pdo,(int)$agent['id'],(int)$viewer['id'],'manual','Manual Research Agent refresh.');
        json_response(['ok'=>true,'data'=>['queued'=>true,'agent_id'=>$agentPublic]]);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'AUTONOMY_ERROR','message'=>$e->getMessage()]],403);}
catch(Throwable $e){$reference=substr(hash('sha256','research-autonomy|'.$e->getMessage().'|'.microtime(true)),0,12);error_log('[Annotated research autonomy '.$reference.'] '.$e->getMessage());json_response(['ok'=>false,'error'=>['code'=>'AUTONOMY_INTERNAL','message'=>'Autonomous Research could not complete this request. Reference: '.$reference]],500);}
