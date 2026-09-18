<?php
if($action==='presence'){
    $u=require_api_mutation_auth($pdo);try{$room=live_room_scope($pdo,$u,(string)($input['source']??''),(string)($input['room_type']??'public'),isset($input['room_id'])?(string)$input['room_id']:null,false);if(!$room)json_response(['ok'=>false,'error'=>['code'=>'ROOM_FORBIDDEN']],403);$data=live_presence_touch($pdo,$u,$room,(string)($input['client_session_id']??''),(string)($input['mode']??$u['live_presence_mode']));$data['room']=['type'=>$room['room_type'],'id'=>$room['room_public_id'],'name'=>$room['room_name']];json_response(['ok'=>true,'data'=>$data]);}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_PRESENCE','message'=>$e->getMessage()]],422);}
}
if($action==='live_leave'){
    $u=require_api_mutation_auth($pdo);try{live_presence_leave($pdo,$u,(string)($input['client_session_id']??''));json_response(['ok'=>true,'data'=>['left'=>true]]);}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_SESSION','message'=>$e->getMessage()]],422);}
}
if($action==='live_rooms'||$action==='live_teams'){
    $u=require_api_user($pdo);$rooms=live_rooms_for_user($pdo,$u);if($action==='live_teams')json_response(['ok'=>true,'data'=>['teams'=>$rooms['teams']]]);json_response(['ok'=>true,'data'=>$rooms]);
}
if($action==='live'){
    $u=require_api_user($pdo);$room=live_room_scope($pdo,$u,(string)($input['source']??''),(string)($input['room_type']??'public'),isset($input['room_id'])?(string)$input['room_id']:null,false);if(!$room)json_response(['ok'=>false,'error'=>['code'=>'ROOM_FORBIDDEN']],403);
    $messages=live_message_rows($pdo,$u,$room,max(0,(int)($input['after_message']??0)),100);$events=live_event_rows($pdo,$u,$room,max(0,(int)($input['after_event']??0)),60);$presence=live_presence_rows($pdo,$u,$room,(string)$u['live_presence_mode']);
    json_response(['ok'=>true,'data'=>['messages'=>$messages['messages'],'message_cursor'=>$messages['cursor'],'events'=>$events['events'],'event_cursor'=>$events['cursor'],'presence'=>$presence,'room'=>['type'=>$room['room_type'],'id'=>$room['room_public_id'],'name'=>$room['room_name'],'can_post'=>$room['can_post'],'can_moderate'=>$room['can_moderate']],'source'=>['public_id'=>$room['source']['public_id'],'status'=>$room['source']['status'],'current_version_id'=>$room['source']['current_version_id']]]]);
}
if($action==='live_message'){
    $u=require_api_mutation_auth($pdo);$room=live_room_scope($pdo,$u,(string)($input['source']??''),(string)($input['room_type']??'public'),isset($input['room_id'])?(string)$input['room_id']:null,true);if(!$room)json_response(['ok'=>false,'error'=>['code'=>'ROOM_FORBIDDEN']],403);$timestamp=isset($input['timestamp'])?(float)$input['timestamp']:null;if($timestamp!==null&&(!is_finite($timestamp)||$timestamp<0))$timestamp=null;
    try{$created=live_message_create($pdo,$u,$room,(string)($input['body']??''),$timestamp,(string)($input['client_message_id']??''),(string)($input['client_session_id']??''),!empty($input['reveal_identity']),isset($input['parent_message_id'])?(string)$input['parent_message_id']:null);json_response(['ok'=>true,'data'=>$created],$created['created']?201:200);}
    catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_MESSAGE','message'=>$e->getMessage()]],422);}catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'LIVE_FORBIDDEN','message'=>$e->getMessage()]],403);}
}
if($action==='live_message_delete'){
    $u=require_api_mutation_auth($pdo);$room=live_room_scope($pdo,$u,(string)($input['source']??''),(string)($input['room_type']??'public'),isset($input['room_id'])?(string)$input['room_id']:null,false);if(!$room)json_response(['ok'=>false,'error'=>['code'=>'ROOM_FORBIDDEN']],403);try{$ok=live_message_delete($pdo,$u,$room,(string)($input['message_id']??''));if(!$ok)json_response(['ok'=>false,'error'=>['code'=>'MESSAGE_NOT_FOUND']],404);json_response(['ok'=>true,'data'=>['deleted'=>true]]);}catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'LIVE_FORBIDDEN','message'=>$e->getMessage()]],403);}
}
if($action==='live_message_pin'){
    $u=require_api_mutation_auth($pdo);$room=live_room_scope($pdo,$u,(string)($input['source']??''),(string)($input['room_type']??'public'),isset($input['room_id'])?(string)$input['room_id']:null,false);if(!$room)json_response(['ok'=>false,'error'=>['code'=>'ROOM_FORBIDDEN']],403);try{$pinned=live_message_pin($pdo,$u,$room,(string)($input['message_id']??''));json_response(['ok'=>true,'data'=>['pinned'=>$pinned]]);}catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'LIVE_FORBIDDEN','message'=>$e->getMessage()]],403);}
}
if($action==='live_react'){
    $u=require_api_mutation_auth($pdo);$room=live_room_scope($pdo,$u,(string)($input['source']??''),(string)($input['room_type']??'public'),isset($input['room_id'])?(string)$input['room_id']:null,false);if(!$room)json_response(['ok'=>false,'error'=>['code'=>'ROOM_FORBIDDEN']],403);try{$active=live_message_react($pdo,$u,$room,(string)($input['message_id']??''),(string)($input['reaction']??'like'));json_response(['ok'=>true,'data'=>['active'=>$active]]);}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_REACTION','message'=>$e->getMessage()]],422);}catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'MESSAGE_NOT_FOUND','message'=>$e->getMessage()]],404);}
}
if($action==='research_projects'){
    $u=require_api_user($pdo);$q=$pdo->prepare('SELECT DISTINCT rp.public_id,rp.title FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id WHERE rp.owner_user_id=? OR tm.user_id=? ORDER BY rp.title');$q->execute([$u['id'],$u['id']]);json_response(['ok'=>true,'data'=>['projects'=>$q->fetchAll()]]);
}
if($action==='research_add'){
    $u=require_api_mutation_auth($pdo);$projectRow=project_access($pdo,(int)$u['id'],(string)($input['project_id']??''));$project=$projectRow&&project_can_write($projectRow)?(int)$projectRow['id']:0;$a=ext_annotation($pdo,(string)($input['annotation_id']??''),$u);if(!$project||!$a||$a['status']!=='published')json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);$pdo->prepare('INSERT IGNORE INTO project_annotations(project_id,annotation_id,added_by_user_id) VALUES(?,?,?)')->execute([$project,$a['id'],$u['id']]);live_event_emit_research_add($pdo,$project,(int)$a['id'],(int)$u['id']);if((int)$a['user_id']!==(int)$u['id'])notify_user($pdo,(int)$a['user_id'],(int)$u['id'],'research_usage','annotation',$a['public_id'],$u['display_name'].' added your annotation to research.');json_response(['ok'=>true,'data'=>['added'=>true]]);
}
