<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();

$action=(string)($_GET['action']??'list');
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$input=$method==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;

try{
    $readActions=['list','summary','detail','graph','outcome_detail','outcome_summary'];
    $viewer=in_array($action,$readActions,true)?require_api_user($pdo):require_api_mutation_auth($pdo);
    if(!research_decisions_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Research Decisions require the latest database upgrade.']],503);

    if($action==='list'){
        $agent=trim((string)($input['agent_id']??''));if($agent==='')throw new InvalidArgumentException('Research Agent is required.');
        $status=trim((string)($input['status']??''));json_response(['ok'=>true,'data'=>['decisions'=>research_decision_list($pdo,$viewer,$agent,(int)($input['limit']??100),$status!==''?$status:null)]]);
    }
    if($action==='summary'){
        $agent=trim((string)($input['agent_id']??''));if($agent==='')throw new InvalidArgumentException('Research Agent is required.');
        json_response(['ok'=>true,'data'=>research_decision_summary($pdo,$viewer,$agent)]);
    }
    if($action==='detail'){
        $id=trim((string)($input['decision_id']??''));if($id==='')throw new InvalidArgumentException('Decision is required.');
        $row=research_decision_detail($pdo,$viewer,$id);if(!$row)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        json_response(['ok'=>true,'data'=>['decision'=>$row]]);
    }
    if($action==='graph'){
        $id=trim((string)($input['decision_id']??''));if($id==='')throw new InvalidArgumentException('Decision is required.');
        json_response(['ok'=>true,'data'=>['graph'=>research_decision_evidence_graph($pdo,$viewer,$id)]]);
    }
    if($action==='outcome_detail'){
        $id=trim((string)($input['outcome_id']??''));if($id==='')throw new InvalidArgumentException('Decision outcome is required.');
        $row=research_decision_outcome_access($pdo,$viewer,$id);if(!$row)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        json_response(['ok'=>true,'data'=>['outcome'=>$row]]);
    }
    if($action==='outcome_summary'){
        $id=trim((string)($input['decision_id']??''));if($id==='')throw new InvalidArgumentException('Decision is required.');
        json_response(['ok'=>true,'data'=>research_decision_outcome_summary($pdo,$viewer,$id)]);
    }

    if($method!=='POST')json_response(['ok'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED','message'=>'Decision changes require POST.']],405);
    rate_limit_api_or_429($pdo,'research-decisions-write','user:'.$viewer['id'],180,3600);

    if($action==='create'){
        $input['agent_id']=trim((string)($input['agent_id']??''));if($input['agent_id']==='')throw new InvalidArgumentException('Research Agent is required.');
        json_response(['ok'=>true,'data'=>['decision'=>research_decision_create($pdo,$viewer,$input,false)]],201);
    }
    if($action==='from_mission'){
        $mission=trim((string)($input['mission_id']??''));if($mission==='')throw new InvalidArgumentException('Research Mission is required.');
        json_response(['ok'=>true,'data'=>['decision'=>research_decision_from_mission($pdo,$viewer,$mission,$input,false)]],201);
    }
    if($action==='update'){
        $id=trim((string)($input['decision_id']??''));if($id==='')throw new InvalidArgumentException('Decision is required.');
        json_response(['ok'=>true,'data'=>['decision'=>research_decision_update($pdo,$viewer,$id,$input,false)]]);
    }
    if($action==='set_status'){
        $id=trim((string)($input['decision_id']??''));$status=trim((string)($input['status']??''));if($id===''||$status==='')throw new InvalidArgumentException('Decision and status are required.');
        json_response(['ok'=>true,'data'=>['decision'=>research_decision_set_status($pdo,$viewer,$id,$status,false)]]);
    }
    if($action==='add_ref'){
        $id=trim((string)($input['decision_id']??''));if($id==='')throw new InvalidArgumentException('Decision is required.');
        json_response(['ok'=>true,'data'=>['reference'=>research_decision_add_ref($pdo,$viewer,$id,$input,false)]],201);
    }
    if($action==='remove_ref'){
        $id=trim((string)($input['decision_id']??''));$ref=trim((string)($input['reference_id']??''));if($id===''||$ref==='')throw new InvalidArgumentException('Decision and reference are required.');
        json_response(['ok'=>true,'data'=>['decision'=>research_decision_remove_ref($pdo,$viewer,$id,$ref,false)]]);
    }
    if($action==='add_challenge'){
        $id=trim((string)($input['decision_id']??''));if($id==='')throw new InvalidArgumentException('Decision is required.');
        json_response(['ok'=>true,'data'=>['challenge'=>research_decision_add_challenge($pdo,$viewer,$id,$input,false)]],201);
    }
    if($action==='update_challenge'){
        $challenge=trim((string)($input['challenge_id']??''));if($challenge==='')throw new InvalidArgumentException('Decision challenge is required.');
        json_response(['ok'=>true,'data'=>['challenge'=>research_decision_update_challenge($pdo,$viewer,$challenge,$input,false)]]);
    }
    if($action==='set_challenge_status'){
        $challenge=trim((string)($input['challenge_id']??''));$status=trim((string)($input['status']??''));if($challenge===''||$status==='')throw new InvalidArgumentException('Decision challenge and status are required.');
        json_response(['ok'=>true,'data'=>['challenge'=>research_decision_set_challenge_status($pdo,$viewer,$challenge,$status,(string)($input['resolution']??''),false)]]);
    }
    if($action==='add_challenge_ref'){
        $challenge=trim((string)($input['challenge_id']??''));if($challenge==='')throw new InvalidArgumentException('Decision challenge is required.');
        json_response(['ok'=>true,'data'=>['reference'=>research_decision_add_challenge_ref($pdo,$viewer,$challenge,$input,false)]],201);
    }
    if($action==='remove_challenge_ref'){
        $challenge=trim((string)($input['challenge_id']??''));$ref=trim((string)($input['reference_id']??''));if($challenge===''||$ref==='')throw new InvalidArgumentException('Decision challenge and reference are required.');
        json_response(['ok'=>true,'data'=>['challenge'=>research_decision_remove_challenge_ref($pdo,$viewer,$challenge,$ref,false)]]);
    }
    if($action==='record_outcome'){
        $id=trim((string)($input['decision_id']??''));if($id==='')throw new InvalidArgumentException('Decision is required.');
        json_response(['ok'=>true,'data'=>['outcome'=>research_decision_record_outcome($pdo,$viewer,$id,$input,false)]],201);
    }
    if($action==='update_outcome'){
        $id=trim((string)($input['outcome_id']??''));if($id==='')throw new InvalidArgumentException('Decision outcome is required.');
        json_response(['ok'=>true,'data'=>['outcome'=>research_decision_update_outcome($pdo,$viewer,$id,$input,false)]]);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_DECISION_ERROR','message'=>$e->getMessage()]],403);}
catch(Throwable $e){$reference=substr(hash('sha256','research-decisions|'.$e->getMessage().'|'.microtime(true)),0,12);error_log('[Annotated research decisions '.$reference.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_DECISION_INTERNAL','message'=>'Research Decisions could not complete this request. Reference: '.$reference]],500);}
