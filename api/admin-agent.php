<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/admin-agent.php';
api_headers();$action=(string)($_GET['action']??'state');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    if($action==='state'){$admin=require_api_user($pdo);admin_agent_assert_admin($pdo,$admin);json_response(['ok'=>true,'data'=>['ready'=>admin_agent_ready($pdo),'threads'=>admin_agent_threads($pdo,$admin,50)]]);}
    if($action==='messages'){$admin=require_api_user($pdo);admin_agent_assert_admin($pdo,$admin);$data=admin_agent_messages($pdo,$admin,trim((string)($input['thread']??'')),!empty($input['before'])?(int)$input['before']:null,(int)($input['limit']??80));if(!$data)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND','message'=>'Admin Agent thread not found.']],404);json_response(['ok'=>true,'data'=>$data]);}
    if($action==='new'){$admin=require_api_mutation_auth($pdo);admin_agent_assert_admin($pdo,$admin);rate_limit_api_or_429($pdo,'admin-agent-new','user:'.$admin['id'],60,3600);$thread=admin_agent_thread_create($pdo,$admin);json_response(['ok'=>true,'data'=>['thread'=>$thread]],201);}
    if($action==='send'){$admin=require_api_mutation_auth($pdo);admin_agent_assert_admin($pdo,$admin);$data=admin_agent_send($pdo,$config,$admin,trim((string)($input['thread']??'')),(string)($input['prompt']??''),isset($input['client_message_id'])?trim((string)$input['client_message_id']):null);json_response(['ok'=>true,'data'=>$data],201);}
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'ADMIN_AGENT_ERROR','message'=>$e->getMessage()]],403);}
catch(Throwable $e){$reference=substr(hash('sha256','admin-agent|'.$e->getMessage().'|'.microtime(true).'|'.random_bytes(8)),0,12);error_log('[Annotated admin-agent '.$reference.'] '.$e->getMessage());json_response(['ok'=>false,'error'=>['code'=>'ADMIN_AGENT_INTERNAL','message'=>'Admin Agent could not complete this request. Reference: '.$reference]],500);}
