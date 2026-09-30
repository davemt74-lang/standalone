<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/teams-core.php';
api_headers();
$viewer=require_api_mutation_auth($pdo);
$action=trim((string)($_GET['action']??''));
$input=json_decode(file_get_contents('php://input'),true)?:[];
$createContext=function_exists('research_object_context_from_input')?research_object_context_from_input($input):[];
if($createContext&&!trim((string)($input['agent_id']??''))&&function_exists('research_object_context_agent_public')){
    $inheritedAgent=research_object_context_agent_public($pdo,$viewer,$createContext);if($inheritedAgent!=='')$input['agent_id']=$inheritedAgent;
}
rate_limit_api_or_429($pdo,'global-create','user:'.$viewer['id'],180,3600);

function global_create_agent_project(PDO $pdo,array $viewer,string $agentPublic): array {
    $agent=research_agent_access($pdo,$viewer,trim($agentPublic));if(!$agent)throw new RuntimeException('Research Agent not found.');
    $project=research_agent_workspace_project($pdo,$viewer,(string)$agent['public_id']);if(!$project)throw new RuntimeException('Research Agent workspace not found.');
    return [$agent,$project];
}
function global_create_response(PDO $pdo,array $viewer,string $type,array $object,string $url,array $context=[]): never {
    $public=trim((string)($object['public_id']??''));
    if($public!==''&&function_exists('research_object_descriptor')){
        $descriptor=research_object_descriptor($pdo,$viewer,['type'=>$type,'public_id'=>$public]);
        if($descriptor){
            research_object_recent_touch($pdo,$viewer,$type,$public,(string)$descriptor['title'],(string)$descriptor['url']);
            if($context)research_object_link_create($pdo,$viewer,$context,$type,$public,'created_from');
        }
    }
    json_response(['ok'=>true,'data'=>['object'=>$object,'redirect'=>$url]],201);
}

try{
    if($action==='research_agent'){
        $agent=research_agent_create($pdo,$viewer,$input,false);
        global_create_response($pdo,$viewer,'research_agent',$agent,'/home.php?agent='.rawurlencode((string)$agent['conversation_public_id']),$createContext);
    }
    if($action==='portfolio'){
        $p=research_intelligence_portfolio_create($pdo,$viewer,$input,false);
        global_create_response($pdo,$viewer,'portfolio',$p,'/research-intelligence-portfolios.php?portfolio='.rawurlencode((string)$p['public_id']),$createContext);
    }
    if($action==='mission'){
        $input['agent_id']=trim((string)($input['agent_id']??''));if($input['agent_id']==='')throw new InvalidArgumentException('Research Agent is required.');
        $m=research_mission_create($pdo,$viewer,$input,false);
        global_create_response($pdo,$viewer,'mission',$m,'/research-missions.php?agent='.rawurlencode((string)$input['agent_id']).'&mission='.rawurlencode((string)$m['public_id']),$createContext);
    }
    if($action==='task'){
        [$agent,$project]=global_create_agent_project($pdo,$viewer,(string)($input['agent_id']??''));
        $task=research_task_create_for_project($pdo,$viewer,$project,$input,false);
        global_create_response($pdo,$viewer,'task',$task,'/research-tasks.php?agent='.rawurlencode((string)$agent['public_id']).'&task='.rawurlencode((string)$task['public_id']),$createContext);
    }
    if($action==='program'){
        $input['agent_id']=trim((string)($input['agent_id']??''));if($input['agent_id']==='')throw new InvalidArgumentException('Research Agent is required.');
        $program=research_program_create($pdo,$viewer,$input,false);
        global_create_response($pdo,$viewer,'program',$program,'/research-programs.php?agent='.rawurlencode((string)$input['agent_id']).'&program='.rawurlencode((string)$program['public_id']),$createContext);
    }
    if($action==='decision'){
        $input['agent_id']=trim((string)($input['agent_id']??''));if($input['agent_id']==='')throw new InvalidArgumentException('Research Agent is required.');
        $decision=research_decision_create($pdo,$viewer,$input,false);
        global_create_response($pdo,$viewer,'decision',$decision,'/research-decisions.php?decision='.rawurlencode((string)$decision['public_id']),$createContext);
    }
    if($action==='action_plan'){
        $decision=trim((string)($input['decision_id']??''));if($decision==='')throw new InvalidArgumentException('Accepted or reopened Decision is required.');
        $plan=research_action_plan_from_decision($pdo,$viewer,$decision,$input,false);
        global_create_response($pdo,$viewer,'action_plan',$plan,'/research-action-plans.php?action_plan='.rawurlencode((string)$plan['public_id']),$createContext);
    }
    if($action==='document'){
        [$agent,$project]=global_create_agent_project($pdo,$viewer,(string)($input['agent_id']??''));
        $doc=research_agent_workspace_create_document($pdo,$viewer,$project,$input,false);
        global_create_response($doc,'/home.php?agent='.rawurlencode((string)$agent['conversation_public_id']).'&doc='.rawurlencode((string)$doc['public_id']));
    }
    if($action==='report'){
        $agent=trim((string)($input['agent_id']??''));if($agent==='')throw new InvalidArgumentException('Research Agent is required.');
        $report=research_system_report_generate($pdo,$config,$viewer,$agent,(string)($input['report_type']??'research_brief'),(string)($input['title']??''),false,null,[],null,null,'user');
        global_create_response($pdo,$viewer,'report',$report,'/research-reports.php?agent='.rawurlencode($agent).'&report='.rawurlencode((string)$report['public_id']),$createContext);
    }
    if($action==='sticky'){
        [$agent,$project]=global_create_agent_project($pdo,$viewer,(string)($input['agent_id']??''));
        $sticky=research_agent_workspace_create_sticky($pdo,$viewer,$project,$input);
        global_create_response($sticky,'/home.php?agent='.rawurlencode((string)$agent['conversation_public_id']).'&desktop=1');
    }
    if($action==='team'){
        $team=team_create($pdo,$viewer,(string)($input['name']??''));
        global_create_response($pdo,$viewer,'team',$team,'/team.php?id='.rawurlencode((string)$team['public_id']),$createContext);
    }
    if($action==='source'){
        $url=trim((string)($input['url']??''));if($url==='')throw new InvalidArgumentException('Source URL is required.');
        $source=ensure_source($pdo,$url,trim((string)($input['title']??''))?:null);
        global_create_response($pdo,$viewer,'source',$source,'/source.php?id='.rawurlencode((string)$source['public_id']),$createContext);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION','message'=>'Unknown create action.']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'CREATE_ERROR','message'=>$e->getMessage()]],403);}
catch(Throwable $e){$ref=substr(hash('sha256','global-create|'.$action.'|'.$e->getMessage().'|'.microtime(true)),0,12);error_log('[Annotated global create '.$ref.'] '.$e->getMessage());json_response(['ok'=>false,'error'=>['code'=>'CREATE_INTERNAL','message'=>'Create action failed. Reference: '.$ref]],500);}
