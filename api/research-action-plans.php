<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();

$action=(string)($_GET['action']??'list');
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$input=$method==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;

try{
    $readActions=['list','summary','detail'];
    $viewer=in_array($action,$readActions,true)?require_api_user($pdo):require_api_mutation_auth($pdo);
    if(!research_action_plans_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Action Plan Ledger requires the latest database upgrade.']],503);

    if($action==='list'){
        $agent=trim((string)($input['agent_id']??''));$decision=trim((string)($input['decision_id']??''));
        json_response(['ok'=>true,'data'=>['action_plans'=>research_action_plan_list($pdo,$viewer,$agent!==''?$agent:null,$decision!==''?$decision:null,(int)($input['limit']??100))]]);
    }
    if($action==='summary')json_response(['ok'=>true,'data'=>research_action_plan_summary($pdo,$viewer)]);
    if($action==='detail'){
        $id=trim((string)($input['action_plan_id']??''));if($id==='')throw new InvalidArgumentException('Action Plan is required.');
        $row=research_action_plan_detail($pdo,$viewer,$id);if(!$row)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        json_response(['ok'=>true,'data'=>['action_plan'=>$row]]);
    }

    if($method!=='POST')json_response(['ok'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED','message'=>'Action Plan changes require POST.']],405);
    rate_limit_api_or_429($pdo,'research-action-plans-write','user:'.$viewer['id'],180,3600);

    if($action==='from_decision'){
        $decision=trim((string)($input['decision_id']??''));if($decision==='')throw new InvalidArgumentException('Decision is required.');
        json_response(['ok'=>true,'data'=>['action_plan'=>research_action_plan_from_decision($pdo,$viewer,$decision,$input,false)]],201);
    }
    if($action==='update'){
        $id=trim((string)($input['action_plan_id']??''));if($id==='')throw new InvalidArgumentException('Action Plan is required.');
        json_response(['ok'=>true,'data'=>['action_plan'=>research_action_plan_update($pdo,$viewer,$id,$input,false)]]);
    }
    if($action==='set_status'){
        $id=trim((string)($input['action_plan_id']??''));$status=trim((string)($input['status']??''));if($id===''||$status==='')throw new InvalidArgumentException('Action Plan and status are required.');
        json_response(['ok'=>true,'data'=>['action_plan'=>research_action_plan_set_status($pdo,$viewer,$id,$status,false)]]);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_ACTION_PLAN_ERROR','message'=>$e->getMessage()]],403);}
catch(Throwable $e){$reference=substr(hash('sha256','research-action-plans|'.$e->getMessage().'|'.microtime(true)),0,12);error_log('[Annotated research action plans '.$reference.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_ACTION_PLAN_INTERNAL','message'=>'Research Action Plans could not complete this request. Reference: '.$reference]],500);}
