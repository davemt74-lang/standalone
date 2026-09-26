<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$action=(string)($_GET['action']??'list');
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$input=$method==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $mutations=['generate','refresh','create_document','archive','save_preset','archive_preset','run_preset'];
    $isMutation=in_array($action,$mutations,true);
    if($isMutation&&$method!=='POST')json_response(['ok'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED','message'=>'This Report Studio action requires POST.']],405);
    $viewer=$isMutation?require_api_mutation_auth($pdo):require_api_user($pdo);
    if(!research_report_studio_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Research Agent Report Studio requires the latest database upgrade.']],503);
    if($action==='types')json_response(['ok'=>true,'data'=>['types'=>research_system_report_types()]]);
    $agent=trim((string)($input['agent_id']??''));if($agent==='')throw new InvalidArgumentException('Research Agent is required.');

    if($action==='list'){
        rate_limit_api_or_429($pdo,'research-report-studio-read','user:'.$viewer['id'],600,3600);
        json_response(['ok'=>true,'data'=>['reports'=>research_system_report_list($pdo,$viewer,$agent,(int)($input['limit']??100))]]);
    }
    if($action==='get'){
        rate_limit_api_or_429($pdo,'research-report-studio-read','user:'.$viewer['id'],600,3600);
        $report=research_system_report_access($pdo,$viewer,(string)($input['report_id']??''));if(!$report||!hash_equals((string)$report['agent_public_id'],$agent))throw new RuntimeException('Report Run not found.');
        json_response(['ok'=>true,'data'=>['report'=>$report]]);
    }
    if($action==='knowledge'){
        rate_limit_api_or_429($pdo,'research-report-studio-read','user:'.$viewer['id'],300,3600);
        json_response(['ok'=>true,'data'=>research_system_report_knowledge($pdo,$config,$viewer,$agent)]);
    }
    if($action==='presets'){
        rate_limit_api_or_429($pdo,'research-report-studio-read','user:'.$viewer['id'],600,3600);
        json_response(['ok'=>true,'data'=>['presets'=>research_report_studio_preset_list($pdo,$viewer,$agent)]]);
    }
    if($action==='compare'){
        rate_limit_api_or_429($pdo,'research-report-studio-read','user:'.$viewer['id'],300,3600);
        $comparison=research_report_studio_compare($pdo,$config,$viewer,(string)($input['older_report_id']??''),(string)($input['newer_report_id']??''));
        if(!hash_equals((string)$comparison['newer']['agent_public_id'],$agent))throw new RuntimeException('Report Run not found.');
        json_response(['ok'=>true,'data'=>['comparison'=>$comparison]]);
    }
    if($action==='generate'){
        rate_limit_api_or_429($pdo,'research-report-studio-write','user:'.$viewer['id'],60,3600);
        $report=research_system_report_generate($pdo,$config,$viewer,$agent,(string)($input['report_type']??''),(string)($input['title']??''),false,null,$input,null,null,'user');
        json_response(['ok'=>true,'data'=>['report'=>$report]],201);
    }
    if($action==='refresh'){
        rate_limit_api_or_429($pdo,'research-report-studio-write','user:'.$viewer['id'],60,3600);
        $report=research_report_studio_refresh($pdo,$config,$viewer,$agent,(string)($input['report_id']??''));
        json_response(['ok'=>true,'data'=>['report'=>$report]],201);
    }
    if($action==='create_document'){
        rate_limit_api_or_429($pdo,'research-report-studio-write','user:'.$viewer['id'],60,3600);
        $doc=research_report_studio_create_document($pdo,$viewer,$agent,(string)($input['report_id']??''),(array)($input['section_keys']??[]));
        json_response(['ok'=>true,'data'=>['document'=>$doc]],201);
    }
    if($action==='archive'){
        rate_limit_api_or_429($pdo,'research-report-studio-write','user:'.$viewer['id'],120,3600);
        $report=research_system_report_archive($pdo,$viewer,(string)($input['report_id']??''),$agent);
        json_response(['ok'=>true,'data'=>['report'=>$report]]);
    }
    if($action==='save_preset'){
        rate_limit_api_or_429($pdo,'research-report-studio-write','user:'.$viewer['id'],120,3600);
        $preset=research_report_studio_preset_save($pdo,$viewer,$agent,$input);
        json_response(['ok'=>true,'data'=>['preset'=>$preset]],201);
    }
    if($action==='archive_preset'){
        rate_limit_api_or_429($pdo,'research-report-studio-write','user:'.$viewer['id'],120,3600);
        $preset=research_report_studio_preset_archive($pdo,$viewer,$agent,(string)($input['preset_id']??''));
        json_response(['ok'=>true,'data'=>['preset'=>$preset]]);
    }
    if($action==='run_preset'){
        rate_limit_api_or_429($pdo,'research-report-studio-write','user:'.$viewer['id'],60,3600);
        $report=research_report_studio_run_preset($pdo,$config,$viewer,$agent,(string)($input['preset_id']??''),false);
        json_response(['ok'=>true,'data'=>['report'=>$report]],201);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){
    json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);
}catch(RuntimeException $e){
    json_response(['ok'=>false,'error'=>['code'=>'REPORT_STUDIO_ERROR','message'=>$e->getMessage()]],403);
}catch(Throwable $e){
    $reference=substr(hash('sha256','research-report-studio|'.$e->getMessage().'|'.microtime(true)),0,12);
    error_log('[Annotated Research Report Studio '.$reference.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    json_response(['ok'=>false,'error'=>['code'=>'REPORT_STUDIO_INTERNAL','message'=>'Research Agent Report Studio could not complete this request. Reference: '.$reference]],500);
}
