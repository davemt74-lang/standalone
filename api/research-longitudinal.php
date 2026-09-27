<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$action=(string)($_GET['action']??'summary');$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$input=$method==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $isMutation=$action==='capture';
    if($isMutation&&$method!=='POST')json_response(['ok'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED','message'=>'Longitudinal capture requires POST.']],405);
    $viewer=$isMutation?require_api_mutation_auth($pdo):require_api_user($pdo);
    if(!research_longitudinal_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Longitudinal Research Intelligence requires the latest database upgrade.']],503);
    $agent=trim((string)($input['agent_id']??''));if($agent==='')throw new InvalidArgumentException('Research Agent is required.');

    if($action==='summary'){
        rate_limit_api_or_429($pdo,'research-longitudinal-read','user:'.$viewer['id'],600,3600);
        $days=max(1,min(3650,(int)($input['days']??30)));$since=date('Y-m-d H:i:s',strtotime('-'.$days.' days'));
        json_response(['ok'=>true,'data'=>['latest'=>research_longitudinal_latest_snapshot($pdo,$viewer,$agent),'summary'=>research_longitudinal_summary($pdo,$viewer,$agent,$since),'days'=>$days]]);
    }
    if($action==='snapshots'){
        rate_limit_api_or_429($pdo,'research-longitudinal-read','user:'.$viewer['id'],600,3600);
        json_response(['ok'=>true,'data'=>['snapshots'=>research_longitudinal_snapshot_list($pdo,$viewer,$agent,(int)($input['limit']??60))]]);
    }
    if($action==='changes'){
        rate_limit_api_or_429($pdo,'research-longitudinal-read','user:'.$viewer['id'],600,3600);
        $since=trim((string)($input['since']??''));$resolved=$since!==''?research_longitudinal_since_token($pdo,$viewer,$agent,$since):null;
        json_response(['ok'=>true,'data'=>['changes'=>research_longitudinal_change_list($pdo,$viewer,$agent,$resolved,(int)($input['limit']??200)),'since'=>$resolved]]);
    }
    if($action==='compare'){
        rate_limit_api_or_429($pdo,'research-longitudinal-read','user:'.$viewer['id'],300,3600);
        $comparison=research_longitudinal_compare_snapshots($pdo,$viewer,(string)($input['older_snapshot_id']??''),(string)($input['newer_snapshot_id']??''));
        if(!hash_equals((string)$comparison['newer']['agent_public_id'],$agent))throw new RuntimeException('Snapshot is unavailable.');
        json_response(['ok'=>true,'data'=>['comparison'=>$comparison]]);
    }
    if($action==='capture'){
        rate_limit_api_or_429($pdo,'research-longitudinal-write','user:'.$viewer['id'],120,3600);
        $result=research_longitudinal_capture($pdo,$viewer,$agent,'manual',null);
        json_response(['ok'=>true,'data'=>$result],!empty($result['created'])?201:200);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){
    json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);
}catch(RuntimeException $e){
    json_response(['ok'=>false,'error'=>['code'=>'LONGITUDINAL_RESEARCH_ERROR','message'=>$e->getMessage()]],403);
}catch(Throwable $e){
    $reference=substr(hash('sha256','research-longitudinal|'.$e->getMessage().'|'.microtime(true)),0,12);
    error_log('[Annotated Longitudinal Research '.$reference.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    json_response(['ok'=>false,'error'=>['code'=>'LONGITUDINAL_RESEARCH_INTERNAL','message'=>'Longitudinal Research Intelligence could not complete this request. Reference: '.$reference]],500);
}
