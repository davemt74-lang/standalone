<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$action=(string)($_GET['action']??'search');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $viewer=$_SERVER['REQUEST_METHOD']==='POST'?require_api_mutation_auth($pdo):require_api_user($pdo);
    if(!research_retrieval_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Unified Research retrieval requires the latest database upgrade.']],503);
    $projectId=trim((string)($input['project_id']??''));if($projectId==='')throw new InvalidArgumentException('Research project is required.');
    if($action==='search'){
        rate_limit_api_or_429($pdo,'research-retrieval-search','user:'.$viewer['id'],600,3600);
        $filters=[
          'type'=>(string)($input['type']??'all'),'folder_id'=>(string)($input['folder_id']??''),
          'status'=>(string)($input['status']??''),'date_from'=>(string)($input['date_from']??''),
          'date_to'=>(string)($input['date_to']??''),'creator'=>(string)($input['creator']??'')
        ];
        $data=research_retrieval_search($pdo,$config,$viewer,$projectId,(string)($input['q']??''),$filters,(int)($input['limit']??30),true);
        json_response(['ok'=>true,'data'=>$data]);
    }
    if($action==='related'){
        rate_limit_api_or_429($pdo,'research-retrieval-related','user:'.$viewer['id'],300,3600);
        $data=research_retrieval_related($pdo,$config,$viewer,$projectId,(string)($input['object_type']??''),(string)($input['object_id']??''),(int)($input['limit']??8));
        json_response(['ok'=>true,'data'=>['results'=>$data]]);
    }
    if($action==='rebuild'){
        $project=project_access($pdo,(int)$viewer['id'],$projectId);if(!$project)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        if(!project_can_write($project))json_response(['ok'=>false,'error'=>['code'=>'FORBIDDEN']],403);
        rate_limit_api_or_429($pdo,'research-retrieval-rebuild','user:'.$viewer['id'],20,3600);
        research_retrieval_queue_project($pdo,(int)$project['id']);
        json_response(['ok'=>true,'data'=>['queued'=>true,'project_id'=>$projectId]]);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){
    json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);
}catch(RuntimeException $e){
    json_response(['ok'=>false,'error'=>['code'=>'RETRIEVAL_ERROR','message'=>$e->getMessage()]],403);
}catch(Throwable $e){
    $reference=substr(hash('sha256','research-retrieval|'.$e->getMessage().'|'.microtime(true)),0,12);
    error_log('[Annotated research retrieval '.$reference.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    json_response(['ok'=>false,'error'=>['code'=>'RETRIEVAL_INTERNAL','message'=>'Research retrieval could not complete this request. Reference: '.$reference]],500);
}
