<?php
if($action==='presence'){
    $u=require_api_mutation_auth($pdo);$sourceId=ext_source_id($pdo,(string)($input['source']??''));if(!$sourceId)json_response(['ok'=>false,'error'=>['code'=>'SOURCE_NOT_FOUND']],404);
    $mode=(string)($input['mode']??$u['live_presence_mode']);if(!in_array($mode,['visible','team_only','cloaked','off'],true))$mode='cloaked';
    $pdo->prepare('UPDATE users SET live_presence_mode=? WHERE id=?')->execute([$mode,$u['id']]);
    if($mode==='off')$pdo->prepare('DELETE FROM page_presence_sessions WHERE source_id=? AND user_id=?')->execute([$sourceId,$u['id']]);else $pdo->prepare('INSERT INTO page_presence_sessions(source_id,user_id,presence_mode,last_seen_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE presence_mode=VALUES(presence_mode),last_seen_at=NOW()')->execute([$sourceId,$u['id'],$mode]);
    $q=$pdo->prepare("SELECT p.user_id,p.presence_mode,u.public_id,u.username,u.display_name,EXISTS(SELECT 1 FROM follows f WHERE f.follower_user_id=? AND f.followed_user_id=p.user_id) is_following FROM page_presence_sessions p JOIN users u ON u.id=p.user_id WHERE p.source_id=? AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 90 SECOND) AND p.presence_mode<>'off'");$q->execute([$u['id'],$sourceId]);
    $visible=[];$total=0;$following=0;foreach($q->fetchAll() as $r){if(is_blocked($pdo,(int)$u['id'],(int)$r['user_id']))continue;$total++;if($r['presence_mode']==='visible'||($r['presence_mode']==='team_only'&&users_share_team($pdo,(int)$u['id'],(int)$r['user_id']))){$visible[]=['public_id'=>$r['public_id'],'username'=>$r['username'],'display_name'=>$r['display_name'],'following'=>(bool)$r['is_following']];if($r['is_following'])$following++;}}
    json_response(['ok'=>true,'data'=>['total'=>$total,'visible'=>$visible,'following_visible'=>$following,'mode'=>$mode]]);
}

if($action==='live_teams'){
    $u=require_api_user($pdo);$q=$pdo->prepare('SELECT t.public_id,t.name FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE tm.user_id=? ORDER BY t.name');$q->execute([$u['id']]);json_response(['ok'=>true,'data'=>['teams'=>$q->fetchAll()]]);
}

if($action==='live'){
    $u=require_api_user($pdo);$sourceId=ext_source_id($pdo,(string)($input['source']??''));if(!$sourceId)json_response(['ok'=>false,'error'=>['code'=>'SOURCE_NOT_FOUND']],404);
    $room=(string)($input['room_type']??'public');$teamId=null;$params=[$sourceId];
    if($room==='team'){$teamId=ext_team_id($pdo,(string)($input['team_id']??''),(int)$u['id']);if(!$teamId)json_response(['ok'=>false,'error'=>['code'=>'TEAM_FORBIDDEN']],403);$where="lm.room_type='team' AND lm.team_id=?";$params[]=$teamId;}else{$where="lm.room_type='public'";$room='public';}
    $sql="SELECT lm.id,lm.body,lm.source_timestamp_seconds,lm.created_at,u.public_id user_public_id,u.username,u.display_name FROM live_messages lm JOIN users u ON u.id=lm.user_id WHERE lm.source_id=? AND $where AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=? AND b.blocked_user_id=lm.user_id) OR (b.blocker_user_id=lm.user_id AND b.blocked_user_id=?)) ORDER BY lm.id DESC LIMIT 100";
    $params[]=$u['id'];$params[]=$u['id'];$q=$pdo->prepare($sql);$q->execute($params);json_response(['ok'=>true,'data'=>['messages'=>array_reverse($q->fetchAll()),'room_type'=>$room]]);
}

if($action==='live_message'){
    $u=require_api_mutation_auth($pdo);rate_limit_api($pdo,$config,'live_message_user',rate_limit_subject_user($u),120,300);$body=trim((string)($input['body']??''));if($body===''||mb_strlen($body)>3000)json_response(['ok'=>false,'error'=>['code'=>'INVALID_MESSAGE']],422);
    $sourceId=ext_source_id($pdo,(string)($input['source']??''));if(!$sourceId)json_response(['ok'=>false,'error'=>['code'=>'SOURCE_NOT_FOUND']],404);
    $room=(string)($input['room_type']??'public');$teamId=null;
    if($room==='team'){$teamId=ext_team_id($pdo,(string)($input['team_id']??''),(int)$u['id']);if(!$teamId)json_response(['ok'=>false,'error'=>['code'=>'TEAM_FORBIDDEN']],403);}else{$room='public';if($u['live_presence_mode']!=='visible'&&empty($input['reveal_identity']))json_response(['ok'=>false,'error'=>['code'=>'IDENTITY_DISCLOSURE_REQUIRED','message'=>'Posting publicly reveals your identity for this message.']],409);}
    $timestamp=isset($input['timestamp'])?(float)$input['timestamp']:null;$pdo->prepare('INSERT INTO live_messages(source_id,user_id,room_type,team_id,body,source_timestamp_seconds) VALUES(?,?,?,?,?,?)')->execute([$sourceId,$u['id'],$room,$teamId,$body,$timestamp]);
    json_response(['ok'=>true,'data'=>['created'=>true]],201);
}

if($action==='research_projects'){
    $u=require_api_user($pdo);$q=$pdo->prepare('SELECT DISTINCT rp.public_id,rp.title FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id WHERE rp.owner_user_id=? OR tm.user_id=? ORDER BY rp.title');$q->execute([$u['id'],$u['id']]);json_response(['ok'=>true,'data'=>['projects'=>$q->fetchAll()]]);
}
if($action==='research_add'){
    $u=require_api_mutation_auth($pdo);rate_limit_api($pdo,$config,'research_add_user',rate_limit_subject_user($u),60,3600);$projectRow=project_access($pdo,(int)$u['id'],(string)($input['project_id']??''));$project=$projectRow&&project_can_write($projectRow)?(int)$projectRow['id']:0;$a=ext_annotation($pdo,(string)($input['annotation_id']??''),$u);if(!$project||!$a||$a['status']!=='published')json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);$pdo->prepare('INSERT IGNORE INTO project_annotations(project_id,annotation_id,added_by_user_id) VALUES(?,?,?)')->execute([$project,$a['id'],$u['id']]);if((int)$a['user_id']!==(int)$u['id'])notify_user($pdo,(int)$a['user_id'],(int)$u['id'],'research_usage','annotation',$a['public_id'],$u['display_name'].' added your annotation to research.');json_response(['ok'=>true,'data'=>['added'=>true]]);
}

if($action==='notifications'){
    $u=require_api_user($pdo);$q=$pdo->prepare('SELECT id,notification_type,object_type,object_public_id,body,read_at,created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100');$q->execute([$u['id']]);json_response(['ok'=>true,'data'=>['notifications'=>$q->fetchAll()]]);
}
if($action==='notification_read'){
    $u=require_api_mutation_auth($pdo);$pdo->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND id=?')->execute([$u['id'],(int)($input['id']??0)]);json_response(['ok'=>true,'data'=>['read'=>true]]);
}
