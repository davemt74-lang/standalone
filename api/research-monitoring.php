<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$action=(string)($_GET['action']??'list');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $readActions=['list','events','candidates','summary'];
    $viewer=in_array($action,$readActions,true)?require_api_user($pdo):require_api_mutation_auth($pdo);
    if(!research_monitor_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Continuous Research Monitoring requires the latest database upgrade.']],503);

    if($action==='list'){
        $agentId=trim((string)($input['agent_id']??''));if($agentId==='')throw new InvalidArgumentException('Research Agent is required.');
        json_response(['ok'=>true,'data'=>['watches'=>research_monitor_list($pdo,$viewer,$agentId,150)]]);
    }
    if($action==='summary'){
        $agentId=trim((string)($input['agent_id']??''));if($agentId==='')throw new InvalidArgumentException('Research Agent is required.');
        json_response(['ok'=>true,'data'=>research_monitor_summary($pdo,$viewer,$agentId)]);
    }
    if($action==='events'){
        $agentId=trim((string)($input['agent_id']??''));if($agentId==='')throw new InvalidArgumentException('Research Agent is required.');
        json_response(['ok'=>true,'data'=>['events'=>research_monitor_events($pdo,$viewer,$agentId,(int)($input['limit']??100))]]);
    }
    if($action==='candidates'){
        $watchId=trim((string)($input['watch_id']??''));if($watchId==='')throw new InvalidArgumentException('Monitoring watch is required.');
        json_response(['ok'=>true,'data'=>['candidates'=>research_monitor_candidates($pdo,$viewer,$watchId,(int)($input['limit']??100))]]);
    }
    if($action==='create'){
        rate_limit_api_or_429($pdo,'research-monitor-create','user:'.$viewer['id'],60,3600);
        json_response(['ok'=>true,'data'=>['watch'=>research_monitor_create($pdo,$viewer,is_array($input)?$input:[])]],201);
    }
    if($action==='update'){
        rate_limit_api_or_429($pdo,'research-monitor-update','user:'.$viewer['id'],120,3600);
        $watchId=trim((string)($input['watch_id']??''));if($watchId==='')throw new InvalidArgumentException('Monitoring watch is required.');
        json_response(['ok'=>true,'data'=>['watch'=>research_monitor_update($pdo,$viewer,$watchId,is_array($input)?$input:[])]]);
    }
    if($action==='pause'||$action==='resume'||$action==='archive'){
        rate_limit_api_or_429($pdo,'research-monitor-state','user:'.$viewer['id'],120,3600);
        $watchId=trim((string)($input['watch_id']??''));if($watchId==='')throw new InvalidArgumentException('Monitoring watch is required.');
        $status=$action==='pause'?'paused':($action==='resume'?'active':'archived');
        json_response(['ok'=>true,'data'=>['updated'=>research_monitor_set_status($pdo,$viewer,$watchId,$status)]]);
    }
    if($action==='run'){
        rate_limit_api_or_429($pdo,'research-monitor-run','user:'.$viewer['id'],60,3600);
        $watchId=trim((string)($input['watch_id']??''));$watch=research_monitor_watch_access($pdo,$viewer,$watchId);if(!$watch)throw new RuntimeException('Monitoring watch not found.');
        $agent=research_monitor_agent($pdo,$viewer,(string)$watch['agent_public_id']);research_monitor_project_can_write($pdo,$viewer,$agent);research_monitor_queue($pdo,(int)$watch['id'],(int)$viewer['id'],'manual');
        json_response(['ok'=>true,'data'=>['queued'=>true,'watch_id'=>$watchId]]);
    }
    if($action==='promote'){
        rate_limit_api_or_429($pdo,'research-monitor-promote','user:'.$viewer['id'],120,3600);
        $candidateId=trim((string)($input['candidate_id']??''));if($candidateId==='')throw new InvalidArgumentException('Discovery candidate is required.');
        json_response(['ok'=>true,'data'=>['candidate'=>research_monitor_candidate_promote_for_user($pdo,$viewer,$candidateId)]]);
    }
    if($action==='ignore'){
        rate_limit_api_or_429($pdo,'research-monitor-ignore','user:'.$viewer['id'],120,3600);
        $candidateId=trim((string)($input['candidate_id']??''));if($candidateId==='')throw new InvalidArgumentException('Discovery candidate is required.');
        json_response(['ok'=>true,'data'=>['updated'=>research_monitor_candidate_ignore($pdo,$viewer,$candidateId)]]);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_MONITORING_ERROR','message'=>$e->getMessage()]],403);}
catch(Throwable $e){$reference=substr(hash('sha256','research-monitoring|'.$e->getMessage().'|'.microtime(true)),0,12);error_log('[Annotated research monitoring '.$reference.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_MONITORING_INTERNAL','message'=>'Research Monitoring could not complete this request. Reference: '.$reference]],500);}
