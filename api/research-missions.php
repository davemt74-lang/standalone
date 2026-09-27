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
    if(!research_missions_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Research Missions require the latest database upgrade.']],503);

    if($action==='list'){
        $agent=trim((string)($input['agent_id']??''));if($agent==='')throw new InvalidArgumentException('Research Agent is required.');
        json_response(['ok'=>true,'data'=>['missions'=>research_mission_list($pdo,$viewer,$agent,(int)($input['limit']??100))]]);
    }
    if($action==='summary'){
        $agent=trim((string)($input['agent_id']??''));if($agent==='')throw new InvalidArgumentException('Research Agent is required.');
        json_response(['ok'=>true,'data'=>research_mission_summary($pdo,$viewer,$agent)]);
    }
    if($action==='detail'){
        $mission=trim((string)($input['mission_id']??''));if($mission==='')throw new InvalidArgumentException('Research Mission is required.');
        $row=research_mission_detail($pdo,$viewer,$mission);if(!$row)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        json_response(['ok'=>true,'data'=>['mission'=>$row]]);
    }

    if($method!=='POST')json_response(['ok'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED','message'=>'Mission changes require POST.']],405);
    rate_limit_api_or_429($pdo,'research-missions-write','user:'.$viewer['id'],180,3600);

    if($action==='create'){
        $input['agent_id']=trim((string)($input['agent_id']??''));if($input['agent_id']==='')throw new InvalidArgumentException('Research Agent is required.');
        json_response(['ok'=>true,'data'=>['mission'=>research_mission_create($pdo,$viewer,$input,false)]],201);
    }
    if($action==='update'){
        $mission=trim((string)($input['mission_id']??''));if($mission==='')throw new InvalidArgumentException('Research Mission is required.');
        json_response(['ok'=>true,'data'=>['mission'=>research_mission_update($pdo,$viewer,$mission,$input,false)]]);
    }
    if($action==='set_status'){
        $mission=trim((string)($input['mission_id']??''));$status=trim((string)($input['status']??''));if($mission===''||$status==='')throw new InvalidArgumentException('Research Mission and status are required.');
        json_response(['ok'=>true,'data'=>['mission'=>research_mission_set_status($pdo,$viewer,$mission,$status,false)]]);
    }
    if($action==='add_criterion'){
        $mission=trim((string)($input['mission_id']??''));if($mission==='')throw new InvalidArgumentException('Research Mission is required.');
        json_response(['ok'=>true,'data'=>['criterion'=>research_mission_add_criterion($pdo,$viewer,$mission,$input,false)]],201);
    }
    if($action==='update_criterion'){
        $criterion=trim((string)($input['criterion_id']??''));if($criterion==='')throw new InvalidArgumentException('Mission criterion is required.');
        json_response(['ok'=>true,'data'=>['criterion'=>research_mission_update_criterion($pdo,$viewer,$criterion,$input,false)]]);
    }
    if($action==='add_subquestion'){
        $mission=trim((string)($input['mission_id']??''));if($mission==='')throw new InvalidArgumentException('Research Mission is required.');
        json_response(['ok'=>true,'data'=>['subquestion'=>research_mission_add_subquestion($pdo,$viewer,$mission,$input,false)]],201);
    }
    if($action==='update_subquestion'){
        $sub=trim((string)($input['subquestion_id']??''));if($sub==='')throw new InvalidArgumentException('Mission sub-question is required.');
        json_response(['ok'=>true,'data'=>['subquestion'=>research_mission_update_subquestion($pdo,$viewer,$sub,$input,false)]]);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_MISSION_ERROR','message'=>$e->getMessage()]],403);}
catch(Throwable $e){$reference=substr(hash('sha256','research-missions|'.$e->getMessage().'|'.microtime(true)),0,12);error_log('[Annotated research missions '.$reference.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_MISSION_INTERNAL','message'=>'Research Missions could not complete this request. Reference: '.$reference]],500);}
