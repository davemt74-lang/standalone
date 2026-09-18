<?php
declare(strict_types=1);

function live_client_session_id(string $value): string {
    $value=trim($value);
    if(!preg_match('/^[A-Za-z0-9_-]{12,64}$/',$value))throw new InvalidArgumentException('A valid Live client session is required.');
    return $value;
}
function live_client_message_id(string $value): string {
    $value=trim($value);
    if(!preg_match('/^[A-Za-z0-9_-]{12,64}$/',$value))throw new InvalidArgumentException('A valid Live client message id is required.');
    return $value;
}
function live_cloak_alias(): string { return 'Cloaked '.strtoupper(substr(bin2hex(random_bytes(3)),0,6)); }

function live_source_row(PDO $pdo,string $publicId): ?array {
    $q=$pdo->prepare('SELECT id,public_id,title,domain,canonical_url,status,current_version_id FROM sources WHERE public_id=? LIMIT 1');
    $q->execute([$publicId]);return $q->fetch()?:null;
}
function live_room_scope(PDO $pdo,array $viewer,string $sourcePublicId,string $roomType='public',?string $roomPublicId=null,bool $forWrite=false): ?array {
    $source=live_source_row($pdo,$sourcePublicId);if(!$source)return null;
    $uid=(int)$viewer['id'];$roomType=in_array($roomType,['public','team','project'],true)?$roomType:'public';
    $scope=['source'=>$source,'source_id'=>(int)$source['id'],'room_type'=>$roomType,'team_id'=>null,'project_id'=>null,'room_public_id'=>null,'room_name'=>'Public','can_post'=>true,'can_moderate'=>(($viewer['role']??'')==='admin')];
    if($roomType==='team'){
        $q=$pdo->prepare('SELECT t.id,t.public_id,t.name,tm.role FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE t.public_id=? AND tm.user_id=? LIMIT 1');$q->execute([(string)$roomPublicId,$uid]);$team=$q->fetch();if(!$team)return null;
        $scope['team_id']=(int)$team['id'];$scope['room_public_id']=$team['public_id'];$scope['room_name']='Team · '.$team['name'];$scope['can_moderate']=$scope['can_moderate']||in_array((string)$team['role'],['owner','admin'],true);
    }elseif($roomType==='project'){
        $project=project_access($pdo,$uid,(string)$roomPublicId);if(!$project)return null;
        $scope['project_id']=(int)$project['id'];$scope['room_public_id']=$project['public_id'];$scope['room_name']='Research · '.$project['title'];$scope['can_post']=project_can_write($project);$scope['can_moderate']=$scope['can_moderate']||in_array((string)($project['access_role']??''),['owner','admin'],true);
        if($forWrite&&!$scope['can_post'])return null;
    }
    return $scope;
}
function live_room_sql(array $room,string $alias=''): array {
    $p=$alias!==''?$alias.'.':'';
    if($room['room_type']==='team')return ["{$p}room_type='team' AND {$p}team_id=? AND {$p}project_id IS NULL",[(int)$room['team_id']]];
    if($room['room_type']==='project')return ["{$p}room_type='project' AND {$p}project_id=? AND {$p}team_id IS NULL",[(int)$room['project_id']]];
    return ["{$p}room_type='public' AND {$p}team_id IS NULL AND {$p}project_id IS NULL",[]];
}
function live_rooms_for_user(PDO $pdo,array $viewer): array {
    $uid=(int)$viewer['id'];
    $q=$pdo->prepare('SELECT t.public_id,t.name,tm.role FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE tm.user_id=? ORDER BY t.name');$q->execute([$uid]);$teams=$q->fetchAll();
    $q=$pdo->prepare("SELECT DISTINCT rp.public_id,rp.title,CASE WHEN rp.owner_user_id=? THEN 'owner' ELSE COALESCE(tm.role,'viewer') END access_role FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE rp.owner_user_id=? OR tm.user_id=? ORDER BY rp.title");$q->execute([$uid,$uid,$uid,$uid]);$projects=$q->fetchAll();
    foreach($projects as &$p)$p['can_post']=project_can_write($p);unset($p);
    return ['teams'=>$teams,'projects'=>$projects];
}
function live_presence_cleanup(PDO $pdo): void {
    $pdo->exec("DELETE FROM live_presence_sessions WHERE last_seen_at<DATE_SUB(NOW(),INTERVAL 90 SECOND)");
    $pdo->exec("DELETE p FROM live_presence_sessions p LEFT JOIN team_members tm ON tm.team_id=p.team_id AND tm.user_id=p.user_id WHERE p.room_type='team' AND (p.team_id IS NULL OR tm.user_id IS NULL)");
    $pdo->exec("DELETE p FROM live_presence_sessions p LEFT JOIN research_projects rp ON rp.id=p.project_id LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=p.user_id WHERE p.room_type='project' AND (rp.id IS NULL OR (rp.owner_user_id<>p.user_id AND tm.user_id IS NULL))");
}
function live_presence_touch(PDO $pdo,array $viewer,array $room,string $clientSessionId,string $mode): array {
    $clientSessionId=live_client_session_id($clientSessionId);if(!in_array($mode,['visible','team_only','cloaked','off'],true))$mode='cloaked';$uid=(int)$viewer['id'];
    live_presence_cleanup($pdo);
    $pdo->prepare('UPDATE users SET live_presence_mode=? WHERE id=?')->execute([$mode,$uid]);$pdo->prepare('UPDATE live_presence_sessions SET presence_mode=? WHERE user_id=?')->execute([$mode,$uid]);
    if($mode==='off'){$pdo->prepare('DELETE FROM live_presence_sessions WHERE user_id=? AND client_session_id=?')->execute([$uid,$clientSessionId]);return ['total'=>0,'visible'=>[],'cloaked'=>[],'following_visible'=>0,'mode'=>'off'];}
    $q=$pdo->prepare('SELECT cloak_alias FROM live_presence_sessions WHERE user_id=? AND client_session_id=? LIMIT 1');$q->execute([$uid,$clientSessionId]);$alias=(string)($q->fetchColumn()?:live_cloak_alias());
    $pdo->prepare("INSERT INTO live_presence_sessions(public_id,user_id,source_id,room_type,team_id,project_id,client_session_id,presence_mode,cloak_alias,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE source_id=VALUES(source_id),room_type=VALUES(room_type),team_id=VALUES(team_id),project_id=VALUES(project_id),presence_mode=VALUES(presence_mode),cloak_alias=VALUES(cloak_alias),last_seen_at=NOW()")
        ->execute([ulid_like(),$uid,$room['source_id'],$room['room_type'],$room['team_id'],$room['project_id'],$clientSessionId,$mode,$alias]);
    return live_presence_rows($pdo,$viewer,$room,$mode);
}
function live_presence_rows(PDO $pdo,array $viewer,array $room,string $viewerMode='cloaked'): array {
    live_presence_cleanup($pdo);[$where,$params]=live_room_sql($room,'p');$sql="SELECT p.user_id,u.live_presence_mode presence_mode,p.cloak_alias,u.public_id,u.username,u.display_name,EXISTS(SELECT 1 FROM follows f WHERE f.follower_user_id=? AND f.followed_user_id=p.user_id) is_following FROM live_presence_sessions p JOIN users u ON u.id=p.user_id WHERE p.source_id=? AND $where AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 90 SECOND)";
    $q=$pdo->prepare($sql);$q->execute(array_merge([(int)$viewer['id'],(int)$room['source_id']],$params));$visible=[];$cloaked=[];$total=0;$following=0;$seenUsers=[];
    foreach($q->fetchAll() as $r){
        $subject=(int)$r['user_id'];if(isset($seenUsers[$subject])||is_blocked($pdo,(int)$viewer['id'],$subject))continue;$seenUsers[$subject]=1;$total++;
        $canSee=presence_identity_visible($pdo,(int)$viewer['id'],$subject,(string)$r['presence_mode']);
        if($canSee){$visible[]=['public_id'=>$r['public_id'],'username'=>$r['username'],'display_name'=>$r['display_name'],'following'=>(bool)$r['is_following'],'is_self'=>$subject===(int)$viewer['id']];if($r['is_following'])$following++;}
        else $cloaked[]=['alias'=>(string)$r['cloak_alias'],'is_self'=>$subject===(int)$viewer['id']];
    }
    return ['total'=>$total,'visible'=>$visible,'cloaked'=>$cloaked,'following_visible'=>$following,'mode'=>$viewerMode];
}
function live_presence_leave(PDO $pdo,array $viewer,string $clientSessionId): void {
    $pdo->prepare('DELETE FROM live_presence_sessions WHERE user_id=? AND client_session_id=?')->execute([(int)$viewer['id'],live_client_session_id($clientSessionId)]);
}

function live_identity_mode(array $viewer,array $room,bool $reveal=false): string {
    if($reveal)return 'visible';$mode=(string)($viewer['live_presence_mode']??'cloaked');
    if($mode==='visible')return 'visible';if($mode==='team_only')return 'team_only';return 'cloaked';
}
function live_session_alias(PDO $pdo,int $userId,string $clientSessionId): string {
    $q=$pdo->prepare('SELECT cloak_alias FROM live_presence_sessions WHERE user_id=? AND client_session_id=? LIMIT 1');$q->execute([$userId,$clientSessionId]);return (string)($q->fetchColumn()?:live_cloak_alias());
}
function live_message_create(PDO $pdo,array $viewer,array $room,string $body,?float $timestamp,string $clientMessageId,string $clientSessionId,bool $reveal=false,?string $parentPublicId=null): array {
    if(!$room['can_post'])throw new RuntimeException('This Research room is read-only for your role.');$body=trim($body);if($body===''||mb_strlen($body)>3000)throw new InvalidArgumentException('Live messages must be between 1 and 3000 characters.');
    $clientMessageId=live_client_message_id($clientMessageId);$clientSessionId=live_client_session_id($clientSessionId);$uid=(int)$viewer['id'];$parentId=null;
    if($parentPublicId){$parent=live_message_access($pdo,$viewer,$room,$parentPublicId);if(!$parent)throw new RuntimeException('Reply target is not available in this room.');$parentId=(int)$parent['id'];}
    $q=$pdo->prepare('SELECT public_id FROM live_messages WHERE user_id=? AND client_message_id=? LIMIT 1');$q->execute([$uid,$clientMessageId]);$existing=$q->fetchColumn();if($existing)return ['created'=>false,'deduplicated'=>true,'public_id'=>(string)$existing];
    $identity=live_identity_mode($viewer,$room,$reveal);$alias=$identity==='cloaked'?live_session_alias($pdo,$uid,$clientSessionId):null;$publicId=ulid_like();
    $pdo->prepare('INSERT INTO live_messages(public_id,source_id,user_id,room_type,team_id,project_id,parent_message_id,identity_mode,cloak_alias,client_message_id,body,source_timestamp_seconds) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$publicId,$room['source_id'],$uid,$room['room_type'],$room['team_id'],$room['project_id'],$parentId,$identity,$alias,$clientMessageId,$body,$timestamp]);
    $roomKey=$room['room_type'].':'.($room['room_public_id']?:$room['source']['public_id']);$context=['source_public_id'=>$room['source']['public_id'],'live_room_key'=>$roomKey,'room_type'=>$room['room_type'],'room_id'=>$room['room_public_id']];
    $notified=[];
    if($parentId&&!empty($parent['user_id'])&&(int)$parent['user_id']!==$uid){$target=(int)$parent['user_id'];if(notification_create($pdo,$target,$uid,'live_reply','live_message',$publicId,'Someone replied to your Live message.',['category'=>'live','dedupe_key'=>'live-reply:'.$publicId.':'.$target,'group_key'=>'live-room:'.$roomKey,'context'=>$context]))$notified[$target]=true;}
    if(preg_match_all('/@([A-Za-z0-9_]{2,50})/u',$body,$matches)){
        foreach(array_unique($matches[1]) as $username){$uq=$pdo->prepare("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1");$uq->execute([$username]);$targetUser=$uq->fetch();if(!$targetUser||(int)$targetUser['id']===$uid||isset($notified[(int)$targetUser['id']]))continue;$targetRoom=live_room_scope($pdo,$targetUser,(string)$room['source']['public_id'],(string)$room['room_type'],$room['room_public_id'],false);if(!$targetRoom)continue;$target=(int)$targetUser['id'];if(notification_create($pdo,$target,$uid,'live_mention','live_message',$publicId,'You were mentioned in a Live room.',['category'=>'live','dedupe_key'=>'live-mention:'.$publicId.':'.$target,'group_key'=>'live-room:'.$roomKey,'context'=>$context]))$notified[$target]=true;}
    }
    return ['created'=>true,'deduplicated'=>false,'public_id'=>$publicId];
}
function live_message_access(PDO $pdo,array $viewer,array $room,string $publicId): ?array {
    [$where,$params]=live_room_sql($room,'lm');$q=$pdo->prepare("SELECT lm.* FROM live_messages lm WHERE lm.public_id=? AND lm.source_id=? AND $where LIMIT 1");$q->execute(array_merge([$publicId,$room['source_id']],$params));$m=$q->fetch();if(!$m||is_blocked($pdo,(int)$viewer['id'],(int)$m['user_id']))return null;return $m;
}
function live_message_rows(PDO $pdo,array $viewer,array $room,int $afterId=0,int $limit=100): array {
    $limit=max(1,min(100,$limit));[$where,$params]=live_room_sql($room,'lm');
    $sql="SELECT lm.*,u.public_id user_public_id,u.username,u.display_name,(SELECT COUNT(*) FROM live_message_reactions r WHERE r.message_id=lm.id AND r.reaction='like') like_count,EXISTS(SELECT 1 FROM live_message_reactions r WHERE r.message_id=lm.id AND r.user_id=? AND r.reaction='like') viewer_liked FROM live_messages lm JOIN users u ON u.id=lm.user_id WHERE lm.source_id=? AND $where AND lm.id>? AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=? AND b.blocked_user_id=lm.user_id) OR (b.blocker_user_id=lm.user_id AND b.blocked_user_id=?)) ORDER BY lm.pinned_at IS NULL,lm.pinned_at DESC,lm.id ASC LIMIT $limit";
    $q=$pdo->prepare($sql);$q->execute(array_merge([(int)$viewer['id'],(int)$room['source_id']],$params,[$afterId,(int)$viewer['id'],(int)$viewer['id']]));
    $out=[];$cursor=$afterId;
    foreach($q->fetchAll() as $m){$cursor=max($cursor,(int)$m['id']);$isSelf=(int)$m['user_id']===(int)$viewer['id'];$identityVisible=$m['identity_mode']==='visible'||($m['identity_mode']==='team_only'&&presence_identity_visible($pdo,(int)$viewer['id'],(int)$m['user_id'],'team_only'));$deleted=!empty($m['deleted_at']);
        $row=['public_id'=>$m['public_id'],'body'=>$deleted?null:$m['body'],'deleted'=>$deleted,'source_timestamp_seconds'=>$m['source_timestamp_seconds'],'created_at'=>$m['created_at'],'pinned'=>!empty($m['pinned_at']),'parent_public_id'=>null,'is_self'=>$isSelf,'can_delete'=>$isSelf||$room['can_moderate'],'can_pin'=>$room['can_moderate'],'like_count'=>(int)$m['like_count'],'viewer_liked'=>(bool)$m['viewer_liked'],'identity_mode'=>$m['identity_mode']];
        if($m['parent_message_id']){$pq=$pdo->prepare('SELECT public_id FROM live_messages WHERE id=?');$pq->execute([$m['parent_message_id']]);$row['parent_public_id']=$pq->fetchColumn()?:null;}
        if($identityVisible)$row['author']=['public_id'=>$m['user_public_id'],'username'=>$m['username'],'display_name'=>$m['display_name']];else $row['cloak_alias']=$m['cloak_alias']?:'Cloaked participant';
        $out[]=$row;
    }
    return ['messages'=>$out,'cursor'=>$cursor];
}
function live_message_delete(PDO $pdo,array $viewer,array $room,string $publicId): bool {
    $m=live_message_access($pdo,$viewer,$room,$publicId);if(!$m)return false;$self=(int)$m['user_id']===(int)$viewer['id'];if(!$self&&!$room['can_moderate'])throw new RuntimeException('You cannot remove this message.');
    $pdo->prepare('UPDATE live_messages SET deleted_at=COALESCE(deleted_at,NOW()),deleted_by_user_id=COALESCE(deleted_by_user_id,?) WHERE id=?')->execute([(int)$viewer['id'],$m['id']]);return true;
}
function live_message_pin(PDO $pdo,array $viewer,array $room,string $publicId): bool {
    if(!$room['can_moderate'])throw new RuntimeException('Room moderator access is required.');$m=live_message_access($pdo,$viewer,$room,$publicId);if(!$m)return false;$pin=empty($m['pinned_at']);$pdo->prepare('UPDATE live_messages SET pinned_at=?,pinned_by_user_id=? WHERE id=?')->execute([$pin?date('Y-m-d H:i:s'):null,$pin?(int)$viewer['id']:null,$m['id']]);return $pin;
}
function live_message_react(PDO $pdo,array $viewer,array $room,string $publicId,string $reaction='like'): bool {
    if($reaction!=='like')throw new InvalidArgumentException('Unsupported reaction.');$m=live_message_access($pdo,$viewer,$room,$publicId);if(!$m||$m['deleted_at'])throw new RuntimeException('Message is not available.');$q=$pdo->prepare('SELECT 1 FROM live_message_reactions WHERE message_id=? AND user_id=? AND reaction=?');$q->execute([$m['id'],$viewer['id'],$reaction]);$exists=(bool)$q->fetchColumn();if($exists)$pdo->prepare('DELETE FROM live_message_reactions WHERE message_id=? AND user_id=? AND reaction=?')->execute([$m['id'],$viewer['id'],$reaction]);else $pdo->prepare('INSERT INTO live_message_reactions(message_id,user_id,reaction) VALUES(?,?,?)')->execute([$m['id'],$viewer['id'],$reaction]);return !$exists;
}

function live_event_emit(PDO $pdo,int $sourceId,string $roomType,?int $teamId,?int $projectId,?int $actorUserId,string $eventType,string $objectType,?string $objectPublicId,array $payload=[],?string $eventKey=null): void {
    $eventKey=$eventKey?:$eventType.':'.$objectType.':'.($objectPublicId?:ulid_like()).':'.$roomType.':'.($teamId?:$projectId?:0);
    try{$pdo->prepare('INSERT IGNORE INTO live_events(public_id,event_key,source_id,room_type,team_id,project_id,actor_user_id,event_type,object_type,object_public_id,payload_json) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([ulid_like(),$eventKey,$sourceId,$roomType,$teamId,$projectId,$actorUserId,$eventType,$objectType,$objectPublicId,$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);}catch(PDOException $e){}
}
function live_event_emit_for_annotation(PDO $pdo,int $annotationId,string $eventType='annotation',?int $commentId=null): void {
    $q=$pdo->prepare('SELECT a.id,a.public_id,a.user_id,a.source_id,a.visibility,a.team_id FROM annotations a WHERE a.id=? LIMIT 1');$q->execute([$annotationId]);$a=$q->fetch();if(!$a)return;$objectId=(string)$a['public_id'];$suffix=$commentId?':'.$commentId:'';$actorUserId=(int)$a['user_id'];if($eventType==='comment'&&$commentId){$cq=$pdo->prepare('SELECT user_id FROM comments WHERE id=? AND annotation_id=?');$cq->execute([$commentId,$annotationId]);$actorUserId=(int)($cq->fetchColumn()?:$actorUserId);}
    if($a['visibility']==='public')live_event_emit($pdo,(int)$a['source_id'],'public',null,null,$actorUserId,$eventType,'annotation',$objectId,[],$eventType.':'.$a['id'].$suffix.':public');
    elseif($a['visibility']==='team'&&!empty($a['team_id']))live_event_emit($pdo,(int)$a['source_id'],'team',(int)$a['team_id'],null,$actorUserId,$eventType,'annotation',$objectId,[],$eventType.':'.$a['id'].$suffix.':team:'.$a['team_id']);
    $q=$pdo->prepare('SELECT project_id FROM project_annotations WHERE annotation_id=?');$q->execute([$annotationId]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $projectId)live_event_emit($pdo,(int)$a['source_id'],'project',null,(int)$projectId,$actorUserId,$eventType,'annotation',$objectId,[],$eventType.':'.$a['id'].$suffix.':project:'.$projectId);
}
function live_event_emit_research_add(PDO $pdo,int $projectId,int $annotationId,int $actorUserId): void {
    $q=$pdo->prepare('SELECT a.public_id,a.source_id FROM annotations a WHERE a.id=?');$q->execute([$annotationId]);$a=$q->fetch();if(!$a)return;live_event_emit($pdo,(int)$a['source_id'],'project',null,$projectId,$actorUserId,'research_add','annotation',(string)$a['public_id'],[],'research_add:'.$projectId.':'.$annotationId);
}
function live_event_emit_source_change(PDO $pdo,int $sourceId,int $changeEventId): void {
    $q=$pdo->prepare('SELECT public_id FROM sources WHERE id=?');$q->execute([$sourceId]);$public=(string)$q->fetchColumn();if($public==='')return;live_event_emit($pdo,$sourceId,'public',null,null,null,'source_change','source',$public,['change_event_id'=>$changeEventId],'source_change:'.$changeEventId.':public');
    $q=$pdo->prepare('SELECT project_id FROM project_sources WHERE source_id=? UNION SELECT DISTINCT pa.project_id FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id WHERE a.source_id=?');$q->execute([$sourceId,$sourceId]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $projectId)live_event_emit($pdo,$sourceId,'project',null,(int)$projectId,null,'source_change','source',$public,['change_event_id'=>$changeEventId],'source_change:'.$changeEventId.':project:'.$projectId);
}
function live_event_rows(PDO $pdo,array $viewer,array $room,int $afterId=0,int $limit=60): array {
    $limit=max(1,min(100,$limit));[$where,$params]=live_room_sql($room,'e');$extra=$room['room_type']==='public'?$where:"(($where) OR (e.room_type='public' AND e.event_type='source_change' AND e.team_id IS NULL AND e.project_id IS NULL))";
    $q=$pdo->prepare("SELECT e.* FROM live_events e WHERE e.source_id=? AND $extra AND e.id>? ORDER BY e.id ASC LIMIT $limit");$q->execute(array_merge([$room['source_id']],$params,[$afterId]));$events=[];$cursor=$afterId;
    foreach($q->fetchAll() as $e){$cursor=max($cursor,(int)$e['id']);if($e['actor_user_id']&&is_blocked($pdo,(int)$viewer['id'],(int)$e['actor_user_id']))continue;
        if(in_array($e['event_type'],['annotation','comment','research_add'],true)){$a=annotation_access($pdo,(string)$e['object_public_id'],$viewer);if(!$a||$a['status']!=='published')continue;}
        $label=match($e['event_type']){'annotation'=>'New annotation published','comment'=>'New annotation comment','source_change'=>'Source changed','research_add'=>'Annotation added to Research',default=>'Live activity'};
        $events[]=['public_id'=>$e['public_id'],'event_type'=>$e['event_type'],'object_type'=>$e['object_type'],'object_public_id'=>$e['object_public_id'],'label'=>$label,'created_at'=>$e['created_at']];
    }
    return ['events'=>$events,'cursor'=>$cursor];
}
