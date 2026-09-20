<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/agent-chat.php';
api_headers();
$action=(string)($_GET['action']??'list');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
if(!conversation_runtime_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Agent Chat.']],503);
try{
  if($action==='list'){$viewer=require_api_user($pdo);json_response(['ok'=>true,'data'=>['available'=>agent_chat_available($pdo,$viewer),'conversations'=>agent_chat_list($pdo,$viewer)]]);}
  if($action==='context_options'){$viewer=require_api_user($pdo);json_response(['ok'=>true,'data'=>agent_chat_context_options($pdo,$viewer)]);}
  if($action==='capabilities'){$viewer=require_api_user($pdo);json_response(['ok'=>true,'data'=>['available'=>agent_actions_ready($pdo),'capabilities'=>agent_action_capabilities()]]);}
  if($action==='action_confirm'){$viewer=require_api_mutation_auth($pdo);if(!agent_actions_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Agent Research Actions.']],503);rate_limit_api_or_429($pdo,'agent-action-confirm','user:'.$viewer['id'],120,3600);$data=agent_action_confirm_execute($pdo,$viewer,trim((string)($input['proposal_id']??'')));json_response(['ok'=>true,'data'=>$data]);}
  if($action==='action_reject'){$viewer=require_api_mutation_auth($pdo);if(!agent_actions_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Agent Research Actions.']],503);rate_limit_api_or_429($pdo,'agent-action-reject','user:'.$viewer['id'],240,3600);$data=agent_action_reject($pdo,$viewer,trim((string)($input['proposal_id']??'')));json_response(['ok'=>true,'data'=>$data]);}
  if($action==='messages'){$viewer=require_api_user($pdo);$data=agent_chat_message_rows($pdo,$viewer,trim((string)($input['conversation']??'')),!empty($input['before'])?(int)$input['before']:null,(int)($input['limit']??60));if(!$data)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);json_response(['ok'=>true,'data'=>$data]);}
  if($action==='new'){$viewer=require_api_mutation_auth($pdo);if(!agent_chat_available($pdo,$viewer))json_response(['ok'=>false,'error'=>['code'=>'PRO_REQUIRED','message'=>'Agent Chat is available to Pro and administrator accounts.']],403);$c=agent_chat_create($pdo,$viewer);json_response(['ok'=>true,'data'=>['conversation'=>['public_id'=>$c['public_id'],'title'=>$c['title']]]],201);}
  if($action==='send'){$viewer=require_api_mutation_auth($pdo);rate_limit_api_or_429($pdo,'agent-chat-send','user:'.$viewer['id'],120,3600);$data=agent_chat_send($pdo,$config,$viewer,isset($input['conversation'])?trim((string)$input['conversation']):null,(string)($input['prompt']??''),is_array($input['context']??null)?$input['context']:[],isset($input['client_message_id'])?trim((string)$input['client_message_id']):null);json_response(['ok'=>true,'data'=>$data],201);}
  json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(AgentActionStale $e){json_response(['ok'=>false,'error'=>['code'=>'STALE_ACTION','message'=>$e->getMessage()]],409);}
catch(AgentActionForbidden $e){json_response(['ok'=>false,'error'=>['code'=>'FORBIDDEN','message'=>$e->getMessage()]],403);}
catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'AGENT_CHAT_ERROR','message'=>$e->getMessage()]],403);}
