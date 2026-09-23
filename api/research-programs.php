<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();

$action=(string)($_GET['action']??'list');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;

try{
    $readActions=['list','summary','detail','runs','run_detail'];
    $viewer=in_array($action,$readActions,true)?require_api_user($pdo):require_api_mutation_auth($pdo);
    if(!research_programs_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Research Programs require the latest database upgrade.']],503);

    if($action==='list'){
        $agentId=trim((string)($input['agent_id']??''));if($agentId==='')throw new InvalidArgumentException('Research Agent is required.');
        json_response(['ok'=>true,'data'=>['programs'=>research_program_list($pdo,$viewer,$agentId,100)]]);
    }
    if($action==='summary'){
        $agentId=trim((string)($input['agent_id']??''));if($agentId==='')throw new InvalidArgumentException('Research Agent is required.');
        json_response(['ok'=>true,'data'=>research_program_summary($pdo,$viewer,$agentId)]);
    }
    if($action==='detail'){
        $programId=trim((string)($input['program_id']??''));if($programId==='')throw new InvalidArgumentException('Research Program is required.');
        $program=research_program_detail($pdo,$viewer,$programId);if(!$program)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        json_response(['ok'=>true,'data'=>['program'=>$program]]);
    }
    if($action==='runs'){
        $programId=trim((string)($input['program_id']??''));if($programId==='')throw new InvalidArgumentException('Research Program is required.');
        json_response(['ok'=>true,'data'=>['runs'=>research_program_run_list($pdo,$viewer,$programId,(int)($input['limit']??50))]]);
    }
    if($action==='run_detail'){
        $runId=trim((string)($input['run_id']??''));if($runId==='')throw new InvalidArgumentException('Research Program run is required.');
        $run=research_program_run_row($pdo,$viewer,$runId);if(!$run)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        json_response(['ok'=>true,'data'=>['run'=>$run,'deltas'=>research_program_run_deltas($pdo,$viewer,$runId,150)]]);
    }

    rate_limit_api_or_429($pdo,'research-programs-write','user:'.$viewer['id'],120,3600);

    if($action==='create'){
        $input['agent_id']=trim((string)($input['agent_id']??''));if($input['agent_id']==='')throw new InvalidArgumentException('Research Agent is required.');
        json_response(['ok'=>true,'data'=>['program'=>research_program_create($pdo,$viewer,$input,false)]],201);
    }
    if($action==='update'){
        $programId=trim((string)($input['program_id']??''));if($programId==='')throw new InvalidArgumentException('Research Program is required.');
        json_response(['ok'=>true,'data'=>['program'=>research_program_update($pdo,$viewer,$programId,$input,false)]]);
    }
    if(in_array($action,['pause','resume','archive'],true)){
        $programId=trim((string)($input['program_id']??''));if($programId==='')throw new InvalidArgumentException('Research Program is required.');
        $status=$action==='pause'?'paused':($action==='resume'?'active':'archived');
        json_response(['ok'=>true,'data'=>['program'=>research_program_set_status($pdo,$viewer,$programId,$status)]]);
    }
    if($action==='run_now'){
        $programId=trim((string)($input['program_id']??''));if($programId==='')throw new InvalidArgumentException('Research Program is required.');
        $program=research_program_access($pdo,$viewer,$programId);if(!$program)throw new RuntimeException('Research Program not found.');
        $agent=research_program_agent($pdo,$viewer,(string)$program['agent_public_id']);research_program_project($pdo,$viewer,$agent);
        $run=research_program_enqueue($pdo,$program,(int)$viewer['id'],'manual',null);if($run===null)throw new RuntimeException('Program run could not be queued because its concurrency or monthly run limit is currently reached.');
        json_response(['ok'=>true,'data'=>['queued'=>true,'run_id'=>$run]]);
    }

    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_PROGRAM_ERROR','message'=>$e->getMessage()]],403);}
catch(Throwable $e){$reference=substr(hash('sha256','research-programs|'.$e->getMessage().'|'.microtime(true)),0,12);error_log('[Annotated research programs '.$reference.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_PROGRAM_INTERNAL','message'=>'Research Programs could not complete this request. Reference: '.$reference]],500);}
