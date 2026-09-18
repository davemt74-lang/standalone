<?php
if($action==='search'){
    $u=require_api_user($pdo);$term=(string)($input['q']??'');$filters=is_array($input['filters']??null)?$input['filters']:$input;
    $results=search_unified($pdo,$term,$u,$filters);json_response(['ok'=>true,'data'=>$results]);
}
if($action==='search_recent'){
    $u=require_api_user($pdo);json_response(['ok'=>true,'data'=>['recent'=>search_recent($pdo,$u,12)]]);
}
if($action==='search_saved'){
    $u=require_api_user($pdo);json_response(['ok'=>true,'data'=>['saved'=>search_saved_list($pdo,$u)]]);
}
if($action==='search_save'){
    $u=require_api_mutation_auth($pdo);try{$scope=(string)($input['scope']??'private');$visibility='private';$scopeId=null;if(str_starts_with($scope,'team:')){$visibility='team';$scopeId=substr($scope,5);}elseif(str_starts_with($scope,'project:')){$visibility='project';$scopeId=substr($scope,8);}$saved=search_saved_create($pdo,$u,(string)($input['title']??''),(string)($input['q']??''),is_array($input['filters']??null)?$input['filters']:[],$visibility,$scopeId,!empty($input['alerts']));json_response(['ok'=>true,'data'=>$saved],201);}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_SAVED_SEARCH','message'=>$e->getMessage()]],422);}catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'SEARCH_FORBIDDEN','message'=>$e->getMessage()]],403);}
}
if($action==='search_delete'){
    $u=require_api_mutation_auth($pdo);if(!search_saved_delete($pdo,$u,(string)($input['saved_id']??'')))json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);json_response(['ok'=>true,'data'=>['deleted'=>true]]);
}
if($action==='search_alert'){
    $u=require_api_mutation_auth($pdo);if(!search_saved_alert_toggle($pdo,$u,(string)($input['saved_id']??''),!empty($input['enabled'])))json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);json_response(['ok'=>true,'data'=>['enabled'=>!empty($input['enabled'])]]);
}
if($action==='search_add_research'){
    $u=require_api_mutation_auth($pdo);$project=project_access($pdo,(int)$u['id'],(string)($input['project_id']??''));if(!$project||!project_can_write($project))json_response(['ok'=>false,'error'=>['code'=>'PROJECT_FORBIDDEN']],403);
    $type=(string)($input['result_type']??'');$public=(string)($input['result_id']??'');
    if($type==='annotation'){$a=annotation_access($pdo,$public,$u);if(!$a)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);$pdo->prepare('INSERT IGNORE INTO project_annotations(project_id,annotation_id,added_by_user_id) VALUES(?,?,?)')->execute([$project['id'],$a['id'],$u['id']]);live_event_emit_research_add($pdo,(int)$project['id'],(int)$a['id'],(int)$u['id']);}
    elseif($type==='source'){$s=source_access($pdo,$public,$u);if(!$s)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);$pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([$project['id'],$s['id'],$u['id']]);}
    else json_response(['ok'=>false,'error'=>['code'=>'INVALID_RESULT_TYPE']],422);
    json_response(['ok'=>true,'data'=>['added'=>true]]);
}
