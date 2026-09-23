<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$action=(string)($_GET['action']??'list');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    if($action==='list'){
        $viewer=require_api_user($pdo);
        json_response(['ok'=>true,'data'=>['ready'=>research_agent_ready($pdo),'agents'=>research_agent_list($pdo,$viewer,50)]]);
    }
    if($action==='create'){
        $viewer=require_api_mutation_auth($pdo);
        rate_limit_api_or_429($pdo,'research-agent-create','user:'.$viewer['id'],30,3600);
        $agent=research_agent_create($pdo,$viewer,is_array($input)?$input:[]);
        json_response(['ok'=>true,'data'=>['agent'=>$agent]],201);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){
    json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);
}catch(RuntimeException $e){
    json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_AGENT_ERROR','message'=>$e->getMessage()]],403);
}catch(Throwable $e){
    $reference=substr(hash('sha256','research-agent|'.$e->getMessage().'|'.microtime(true).'|'.random_bytes(8)),0,12);
    error_log('[Annotated research-agent '.$reference.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_AGENT_INTERNAL','message'=>'Research Agent could not complete this request. Reference: '.$reference]],500);
}
