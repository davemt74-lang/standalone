<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$viewer=$_SERVER['REQUEST_METHOD']==='POST'?require_api_mutation_auth($pdo):require_api_user($pdo);
$action=(string)($_GET['action']??'list');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
  if($action==='list'){
    if(research_agent_stories_ready($pdo))research_agent_story_sync($pdo,$config,$viewer,8);
    json_response(['ok'=>true,'data'=>[
      'ready'=>research_agent_stories_ready($pdo),
      'stories'=>research_agent_story_list($pdo,$viewer,max(1,min(30,(int)($input['limit']??12)))),
      'llm'=>research_agent_story_llm_available($pdo,$config)
    ]]);
  }
  if(in_array($action,['view','dismiss'],true)){
    $public=trim((string)($input['public_id']??''));if($public==='')throw new InvalidArgumentException('Story is required.');
    rate_limit_api_or_429($pdo,'research-agent-story-state','user:'.$viewer['id'],240,3600);
    if(!research_agent_story_state($pdo,$viewer,$public,$action))json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
    json_response(['ok'=>true]);
  }
  json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(Throwable $e){$ref=substr(hash('sha256','story|'.$e->getMessage().'|'.microtime(true)),0,12);error_log('[Annotated Research Agent Story '.$ref.'] '.$e->getMessage());json_response(['ok'=>false,'error'=>['code'=>'STORY_ERROR','message'=>'Story request failed. Reference: '.$ref]],500);}
