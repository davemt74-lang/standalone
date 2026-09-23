<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();

$action=(string)($_GET['action']??'list');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;

try{
    $readActions=['list','summary','detail','task'];
    $viewer=in_array($action,$readActions,true)?require_api_user($pdo):require_api_mutation_auth($pdo);
    if(!research_tasks_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Research Tasks, Plans & Deliverables require the latest database upgrade.']],503);

    if($action==='list'){
        $agentId=trim((string)($input['agent_id']??''));if($agentId==='')throw new InvalidArgumentException('Research Agent is required.');
        json_response(['ok'=>true,'data'=>['plans'=>research_task_plan_list($pdo,$viewer,$agentId,100)]]);
    }
    if($action==='summary'){
        $agentId=trim((string)($input['agent_id']??''));if($agentId==='')throw new InvalidArgumentException('Research Agent is required.');
        json_response(['ok'=>true,'data'=>research_task_summary($pdo,$viewer,$agentId)]);
    }
    if($action==='detail'){
        $planId=trim((string)($input['plan_id']??''));if($planId==='')throw new InvalidArgumentException('Research plan is required.');
        $plan=research_task_plan_detail($pdo,$viewer,$planId);if(!$plan)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        json_response(['ok'=>true,'data'=>['plan'=>$plan]]);
    }
    if($action==='task'){
        $taskId=trim((string)($input['task_id']??''));if($taskId==='')throw new InvalidArgumentException('Research task is required.');
        $task=research_task_access($pdo,$viewer,$taskId);if(!$task)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        $q=$pdo->prepare("SELECT gate_type,status,detail,required_json FROM research_task_completion_gates WHERE task_id=? ORDER BY id");$q->execute([(int)$task['id']]);$task['gates']=$q->fetchAll()?:[];
        $q=$pdo->prepare("SELECT ref_type,ref_public_id,locator,relationship,created_at FROM research_task_evidence_refs WHERE task_id=? ORDER BY id");$q->execute([(int)$task['id']]);$task['evidence_refs']=$q->fetchAll()?:[];
        json_response(['ok'=>true,'data'=>['task'=>$task]]);
    }

    rate_limit_api_or_429($pdo,'research-tasks-write','user:'.$viewer['id'],180,3600);

    if($action==='create_plan'){
        $input['agent_id']=trim((string)($input['agent_id']??''));if($input['agent_id']==='')throw new InvalidArgumentException('Research Agent is required.');
        $plan=research_task_plan_create($pdo,$viewer,$input,false);
        json_response(['ok'=>true,'data'=>['plan'=>$plan]],201);
    }
    if($action==='update_plan'){
        $planId=trim((string)($input['plan_id']??''));if($planId==='')throw new InvalidArgumentException('Research plan is required.');
        json_response(['ok'=>true,'data'=>['plan'=>research_task_plan_update($pdo,$viewer,$planId,$input,false)]]);
    }
    if($action==='add_task'){
        $planId=trim((string)($input['plan_id']??''));if($planId==='')throw new InvalidArgumentException('Research plan is required.');
        json_response(['ok'=>true,'data'=>['task'=>research_task_plan_add_task($pdo,$viewer,$planId,$input,false)]],201);
    }
    if($action==='update_task'){
        $taskId=trim((string)($input['task_id']??''));if($taskId==='')throw new InvalidArgumentException('Research task is required.');
        json_response(['ok'=>true,'data'=>['task'=>research_task_update($pdo,$viewer,$taskId,$input)]]);
    }
    if($action==='run_task'){
        $taskId=trim((string)($input['task_id']??''));if($taskId==='')throw new InvalidArgumentException('Research task is required.');
        $task=research_task_access($pdo,$viewer,$taskId);if(!$task)throw new RuntimeException('Research task not found.');
        if(empty($task['agent_public_id']))throw new RuntimeException('This legacy task is not attached to a Research Agent.');
        $agent=research_task_agent($pdo,$viewer,(string)$task['agent_public_id']);research_task_project($pdo,$viewer,$agent);
        if(in_array((string)$task['status'],['complete','done','archived'],true))throw new RuntimeException('Completed or archived tasks cannot be queued again without editing them first.');
        research_task_queue($pdo,(int)$task['id'],(int)$viewer['id'],'manual');
        json_response(['ok'=>true,'data'=>['queued'=>true,'task_id'=>$taskId]]);
    }
    if($action==='review_task'){
        $taskId=trim((string)($input['task_id']??''));if($taskId==='')throw new InvalidArgumentException('Research task is required.');
        $approve=!array_key_exists('approve',$input)||(bool)$input['approve'];
        json_response(['ok'=>true,'data'=>['task'=>research_task_review($pdo,$viewer,$taskId,$approve)]]);
    }
    if($action==='waive_gate'){
        $taskId=trim((string)($input['task_id']??''));$gate=trim((string)($input['gate_type']??''));if($taskId===''||$gate==='')throw new InvalidArgumentException('Research task and gate are required.');
        json_response(['ok'=>true,'data'=>['task'=>research_task_gate_waive($pdo,$viewer,$taskId,$gate)]]);
    }
    if(in_array($action,['pause_plan','resume_plan','archive_plan'],true)){
        $planId=trim((string)($input['plan_id']??''));if($planId==='')throw new InvalidArgumentException('Research plan is required.');
        $status=$action==='pause_plan'?'paused':($action==='resume_plan'?'active':'archived');
        json_response(['ok'=>true,'data'=>['plan'=>research_task_plan_set_status($pdo,$viewer,$planId,$status)]]);
    }
    if($action==='resume_deliverable'){
        $planId=trim((string)($input['plan_id']??''));if($planId==='')throw new InvalidArgumentException('Research plan is required.');
        json_response(['ok'=>true,'data'=>['plan'=>research_task_deliverable_resume($pdo,$viewer,$planId)]]);
    }
    if($action==='finalize_deliverable'){
        $planId=trim((string)($input['plan_id']??''));if($planId==='')throw new InvalidArgumentException('Research plan is required.');
        json_response(['ok'=>true,'data'=>['plan'=>research_task_deliverable_finalize($pdo,$viewer,$planId)]]);
    }

    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_TASK_ERROR','message'=>$e->getMessage()]],403);}
catch(Throwable $e){$reference=substr(hash('sha256','research-tasks|'.$e->getMessage().'|'.microtime(true)),0,12);error_log('[Annotated research tasks '.$reference.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_TASK_INTERNAL','message'=>'Research Tasks could not complete this request. Reference: '.$reference]],500);}
