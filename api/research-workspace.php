<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/research-workspace.php';
api_headers();
$action=(string)($_GET['action']??'get');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
if(!research_workspace_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Research Workspace Intelligence.']],503);
try{
    $viewer=$_SERVER['REQUEST_METHOD']==='POST'?require_api_mutation_auth($pdo):require_api_user($pdo);
    $projectPublic=trim((string)($input['project_id']??$input['id']??''));
    $project=project_access($pdo,(int)$viewer['id'],$projectPublic);
    if(!$project)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
    if($action==='get'){
        $snapshot=research_workspace_deterministic_snapshot($pdo,(int)$project['id']);
        $record=research_workspace_record($pdo,(int)$project['id']);
        json_response(['ok'=>true,'data'=>['project_id'=>$projectPublic,'snapshot'=>$snapshot,'intelligence'=>$record]]);
    }
    if($action==='refresh'){
        if(!project_can_write($project))json_response(['ok'=>false,'error'=>['code'=>'FORBIDDEN']],403);
        rate_limit_api_or_429($pdo,'research-workspace-refresh','user:'.$viewer['id'],60,3600);
        $queued=research_workspace_queue($pdo,(int)$project['id'],2);
        $record=research_workspace_record($pdo,(int)$project['id']);
        json_response(['ok'=>true,'data'=>['project_id'=>$projectPublic,'queued'=>$queued,'intelligence'=>$record]]);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_WORKSPACE_ERROR','message'=>$e->getMessage()]],400);}
