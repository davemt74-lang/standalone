<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$viewer=require_api_mutation_auth($pdo);
$action=trim((string)($_GET['action']??''));
$input=json_decode(file_get_contents('php://input'),true)?:[];
rate_limit_api_or_429($pdo,'global-create','user:'.$viewer['id'],180,3600);

function global_create_agent_project(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_agent_access($pdo,$viewer,trim($agentPublic));if(!$agent)throw new RuntimeException('Research Agent not found.');
    $project=research_agent_workspace_project($pdo,$viewer,(string)$agent['public_id']);if(!$project)throw new RuntimeException('Research Agent workspace not found.');
    return [$agent,$project];
}
function global_create_response(array $object,string $url): never {
    json_response(['ok'=>true,'data'=>['object'=>$object,'redirect'=>$url]],201);
}

try{
    if($action==='research_agent'){
        $agent=research_agent_create($pdo,$viewer,$input,false);
        global_create_response($agent,'/home.php?agent='.rawurlencode((string)$agent['conversation_public_id']));
    }
    if($action==='portfolio'){
        $p=research_intelligence_portfolio_create($pdo,$viewer,$input,false);
        global_create_response($p,'/research-intelligence-portfolios.php?portfolio='.rawurlencode((string)$p['public_id']));
    }
    if($action==='mission'){
        $input['agent_id']=trim((string)($input['agent_id']??''));if($input['agent_id']==='')throw new InvalidArgumentException('Research Agent is required.');
        $m=research_mission_create($pdo,$viewer,$input,false);
        global_create_response($m,'/research-missions.php?agent='.rawurlencode((string)$input['agent_id']).'&mission='.rawurlencode((string)$m['public_id']));
    }
    if($action==='task'){
        [$agent,$project]=global_create_agent_project($pdo,$viewer,(string)($input['agent_id']??''));
        $task=research_task_create_for_project($pdo,$viewer,$project,$input,false);
        global_create_response($task,'/research-tasks.php?agent='.rawurlencode((string)$agent['public_id']).'&task='.rawurlencode((string)$task['public_id']));
    }
    if($action==='program'){
        $input['agent_id']=trim((string)($input['agent_id']??''));if($input['agent_id']==='')throw new InvalidArgumentException('Research Agent is required.');
        $program=research_program_create($pdo,$viewer,$input,false);
        global_create_response($program,'/research-programs.php?agent='.rawurlencode((string)$input['agent_id']).'&program='.rawurlencode((string)$program['public_id']));
    }
    if($action==='decision'){
        $input['agent_id']=trim((string)($input['agent_id']??''));if($input['agent_id']==='')throw new InvalidArgumentException('Research Agent is required.');
        $decision=research_decision_create($pdo,$viewer,$input,false);
        global_create_response($decision,'/research-decisions.php?decision='.rawurlencode((string)$decision['public_id']));
    }
    if($action==='action_plan'){
        $decision=trim((string)($input['decision_id']??''));if($decision==='')throw new InvalidArgumentException('Accepted or reopened Decision is required.');
        $plan=research_action_plan_from_decision($pdo,$viewer,$decision,$input,false);
        global_create_response($plan,'/research-action-plans.php?action_plan='.rawurlencode((string)$plan['public_id']));
    }
    if($action==='document'){
        [$agent,$project]=global_create_agent_project($pdo,$viewer,(string)($input['agent_id']??''));
        $doc=research_agent_workspace_create_document($pdo,$viewer,$project,$input,false);
        global_create_response($doc,'/home.php?agent='.rawurlencode((string)$agent['conversation_public_id']).'&doc='.rawurlencode((string)$doc['public_id']));
    }
    if($action==='report'){
        $agent=trim((string)($input['agent_id']??''));if($agent==='')throw new InvalidArgumentException('Research Agent is required.');
        $report=research_system_report_generate($pdo,$config,$viewer,$agent,(string)($input['report_type']??'research_brief'),(string)($input['title']??''),false,null,[],null,null,'user');
        global_create_response($report,'/research-reports.php?agent='.rawurlencode($agent).'&report='.rawurlencode((string)$report['public_id']));
    }
    if($action==='sticky'){
        [$agent,$project]=global_create_agent_project($pdo,$viewer,(string)($input['agent_id']??''));
        $sticky=research_agent_workspace_create_sticky($pdo,$viewer,$project,$input);
        global_create_response($sticky,'/home.php?agent='.rawurlencode((string)$agent['conversation_public_id']).'&desktop=1');
    }
    if($action==='team'){
        $name=mb_substr(trim((string)($input['name']??'')),0,190);if($name==='')throw new InvalidArgumentException('Team name is required.');
        $public=ulid_like();$pdo->beginTransaction();
        try{$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$public,(int)$viewer['id'],$name]);$teamId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner')")->execute([$teamId,(int)$viewer['id']]);$pdo->commit();}
        catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        global_create_response(['public_id'=>$public,'name'=>$name],'/team.php?id='.rawurlencode($public));
    }
    if($action==='source'){
        $url=trim((string)($input['url']??''));if($url==='')throw new InvalidArgumentException('Source URL is required.');
        $source=ensure_source($pdo,$url,trim((string)($input['title']??''))?:null);
        global_create_response($source,'/source.php?id='.rawurlencode((string)$source['public_id']));
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION','message'=>'Unknown create action.']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'CREATE_ERROR','message'=>$e->getMessage()]],403);}
catch(Throwable $e){$ref=substr(hash('sha256','global-create|'.$action.'|'.$e->getMessage().'|'.microtime(true)),0,12);error_log('[Annotated global create '.$ref.'] '.$e->getMessage());json_response(['ok'=>false,'error'=>['code'=>'CREATE_INTERNAL','message'=>'Create action failed. Reference: '.$ref]],500);}
