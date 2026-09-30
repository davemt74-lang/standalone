<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$action=(string)($_GET['action']??'list');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $viewer=$_SERVER['REQUEST_METHOD']==='POST'?require_api_mutation_auth($pdo):require_api_user($pdo);
    if(!research_memory_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Agent Memory requires the latest database upgrade.']],503);

    $agentPublic=trim((string)($input['agent_id']??''));
    if($agentPublic==='')throw new InvalidArgumentException('Research Agent is required.');
    $agent=research_agent_access($pdo,$viewer,$agentPublic);
    if(!$agent)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
    $projectPublic=(string)$agent['project_public_id'];

    if($action==='list'){
        $items=research_memory_catalog($pdo,$viewer,$projectPublic,(int)($input['limit']??120));
        json_response(['ok'=>true,'data'=>['items'=>$items,'summary'=>research_memory_summary($pdo,$viewer,$projectPublic)]]);
    }

    $type=trim((string)($input['object_type']??''));
    $objectId=trim((string)($input['object_id']??''));
    if($type===''||$objectId==='')throw new InvalidArgumentException('Knowledge object is required.');

    if($action==='history'){
        json_response(['ok'=>true,'data'=>['events'=>research_memory_history($pdo,$viewer,$projectPublic,$type,$objectId,50)]]);
    }

    if($action==='update'){
        rate_limit_api_or_429($pdo,'research-memory-update','user:'.$viewer['id'],180,3600);
        $control=research_memory_upsert(
            $pdo,$viewer,$projectPublic,$type,$objectId,
            (string)($input['retrieval_state']??'inherit'),
            (string)($input['correction_text']??'')
        );
        json_response(['ok'=>true,'data'=>['control'=>[
          'public_id'=>$control['public_id']??null,
          'retrieval_state'=>$control['retrieval_state']??'inherit',
          'correction_text'=>$control['correction_text']??'',
          'corrected_at'=>$control['corrected_at']??null,
          'updated_at'=>$control['updated_at']??null,
        ]]],200);
    }

    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){
    json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);
}catch(RuntimeException $e){
    json_response(['ok'=>false,'error'=>['code'=>'MEMORY_ERROR','message'=>$e->getMessage()]],403);
}catch(Throwable $e){
    $reference=substr(hash('sha256','research-memory|'.$e->getMessage().'|'.microtime(true)),0,12);
    error_log('[Annotated research memory '.$reference.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    json_response(['ok'=>false,'error'=>['code'=>'MEMORY_INTERNAL','message'=>'Agent Memory could not complete this request. Reference: '.$reference]],500);
}
