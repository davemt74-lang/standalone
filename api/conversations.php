<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$action=(string)($_GET['action']??'list');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
if(!conversation_runtime_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Team Chat.']],503);
try{
    if($action==='list'){
        $viewer=require_api_user($pdo);
        json_response(['ok'=>true,'data'=>['conversations'=>conversation_team_list($pdo,$viewer)]]);
    }
    if($action==='messages'){
        $viewer=require_api_user($pdo);$conversation=trim((string)($input['conversation']??''));$before=(int)($input['before']??0);
        $data=conversation_message_rows($pdo,$viewer,$conversation,$before>0?$before:null,(int)($input['limit']??50));
        if(!$data)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        json_response(['ok'=>true,'data'=>$data]);
    }
    if($action==='send'){
        $viewer=require_api_mutation_auth($pdo);rate_limit_api_or_429($pdo,'conversation-send','user:'.$viewer['id'],240,3600);
        $data=conversation_message_create($pdo,$viewer,trim((string)($input['conversation']??'')),(string)($input['body']??''),isset($input['parent_message'])?trim((string)$input['parent_message']):null,isset($input['client_message_id'])?trim((string)$input['client_message_id']):null);
        json_response(['ok'=>true,'data'=>$data],$data['created']?201:200);
    }
    if($action==='read'){
        $viewer=require_api_mutation_auth($pdo);rate_limit_api_or_429($pdo,'conversation-read','user:'.$viewer['id'],900,3600);
        $ok=conversation_mark_read($pdo,$viewer,trim((string)($input['conversation']??'')),isset($input['message'])?trim((string)$input['message']):null);
        if(!$ok)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        json_response(['ok'=>true,'data'=>['read'=>true]]);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'FORBIDDEN','message'=>$e->getMessage()]],403);}
