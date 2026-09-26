<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$action=(string)($_GET['action']??'list');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $isMutation=in_array($action,['generate','archive'],true);
    if($isMutation&&$method!=='POST')json_response(['ok'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED','message'=>'This System Report action requires POST.']],405);
    $viewer=$isMutation?require_api_mutation_auth($pdo):require_api_user($pdo);
    if(!research_system_reports_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Research System Reports require the latest database upgrade.']],503);
    if($action==='types')json_response(['ok'=>true,'data'=>['types'=>research_system_report_types()]]);
    $agent=trim((string)($input['agent_id']??''));if($agent==='')throw new InvalidArgumentException('Research Agent is required.');
    if($action==='list'){
        rate_limit_api_or_429($pdo,'research-system-reports-read','user:'.$viewer['id'],600,3600);
        json_response(['ok'=>true,'data'=>['reports'=>research_system_report_list($pdo,$viewer,$agent,(int)($input['limit']??100))]]);
    }
    if($action==='knowledge'){
        rate_limit_api_or_429($pdo,'research-system-reports-read','user:'.$viewer['id'],300,3600);
        json_response(['ok'=>true,'data'=>research_system_report_knowledge($pdo,$config,$viewer,$agent)]);
    }
    if($action==='generate'){
        rate_limit_api_or_429($pdo,'research-system-reports-write','user:'.$viewer['id'],60,3600);
        $report=research_system_report_generate($pdo,$config,$viewer,$agent,(string)($input['report_type']??''),(string)($input['title']??''),false);
        json_response(['ok'=>true,'data'=>['report'=>$report]],201);
    }
    if($action==='archive'){
        rate_limit_api_or_429($pdo,'research-system-reports-write','user:'.$viewer['id'],120,3600);
        $report=research_system_report_archive($pdo,$viewer,(string)($input['report_id']??''));
        json_response(['ok'=>true,'data'=>['report'=>$report]]);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){
    json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);
}catch(RuntimeException $e){
    json_response(['ok'=>false,'error'=>['code'=>'REPORT_ERROR','message'=>$e->getMessage()]],403);
}catch(Throwable $e){
    $reference=substr(hash('sha256','research-system-reports|'.$e->getMessage().'|'.microtime(true)),0,12);
    error_log('[Annotated Research System Reports '.$reference.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    json_response(['ok'=>false,'error'=>['code'=>'REPORT_INTERNAL','message'=>'Research System Reports could not complete this request. Reference: '.$reference]],500);
}
