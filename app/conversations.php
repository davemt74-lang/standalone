<?php
declare(strict_types=1);
require_once __DIR__.'/installer.php';

function conversation_runtime_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'conversations')&&installer_table_exists($pdo,'conversation_messages')&&installer_table_exists($pdo,'conversation_members');}
    catch(Throwable $e){return false;}
}
function conversation_presence_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'chat_status_preferences')&&installer_table_exists($pdo,'chat_presence_sessions');}
    catch(Throwable $e){return false;}
}
function conversation_status_get(PDO $pdo,int $userId): array {
    if(!conversation_presence_ready($pdo))return ['status_mode'=>'auto','custom_status'=>'','effective_status'=>'offline'];
    $pdo->prepare('INSERT IGNORE INTO chat_status_preferences(user_id) VALUES(?)')->execute([$userId]);
    $q=$pdo->prepare('SELECT status_mode,custom_status FROM chat_status_preferences WHERE user_id=?');$q->execute([$userId]);$row=$q->fetch()?:['status_mode'=>'auto','custom_status'=>null];
    conversation_presence_cleanup($pdo);
    $q=$pdo->prepare('SELECT 1 FROM chat_presence_sessions WHERE user_id=? AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 90 SECOND) LIMIT 1');$q->execute([$userId]);$active=(bool)$q->fetchColumn();
    $mode=(string)($row['status_mode']??'auto');$effective=!$active||$mode==='invisible'?'offline':($mode==='busy'?'busy':($mode==='away'?'away':'online'));
    return ['status_mode'=>$mode,'custom_status'=>(string)($row['custom_status']??''),'effective_status'=>$effective];
}
function conversation_status_update(PDO $pdo,array $viewer,string $mode,string $customStatus=''): array {
    if(!conversation_presence_ready($pdo))throw new RuntimeException('Chat status requires the Phase 12A database upgrade.');
    $mode=strtolower(trim($mode));if(!in_array($mode,['auto','available','away','busy','invisible'],true))throw new InvalidArgumentException('Invalid chat status.');
    $customStatus=trim($customStatus);if(mb_strlen($customStatus)>120)throw new InvalidArgumentException('Custom status must be 120 characters or fewer.');
    $pdo->prepare('INSERT INTO chat_status_preferences(user_id,status_mode,custom_status) VALUES(?,?,?) ON DUPLICATE KEY UPDATE status_mode=VALUES(status_mode),custom_status=VALUES(custom_status)')->execute([$viewer['id'],$mode,$customStatus!==''?$customStatus:null]);
    return conversation_status_get($pdo,(int)$viewer['id']);
}
function conversation_presence_cleanup(PDO $pdo): void {
    if(!conversation_presence_ready($pdo))return;
    $pdo->exec('DELETE FROM chat_presence_sessions WHERE last_seen_at<DATE_SUB(NOW(),INTERVAL 90 SECOND)');
}
function conversation_presence_touch(PDO $pdo,array $viewer,string $clientSessionId): array {
    if(!conversation_presence_ready($pdo))throw new RuntimeException('Chat presence requires the Phase 12A database upgrade.');
    $clientSessionId=trim($clientSessionId);if(!preg_match('/^[A-Za-z0-9._:-]{8,80}$/',$clientSessionId))throw new InvalidArgumentException('Invalid chat client session.');
    conversation_presence_cleanup($pdo);
    $pdo->prepare('INSERT INTO chat_presence_sessions(user_id,client_session_id,last_seen_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE last_seen_at=NOW()')->execute([$viewer['id'],$clientSessionId]);
    return conversation_status_get($pdo,(int)$viewer['id']);
}
function conversation_presence_leave(PDO $pdo,array $viewer,string $clientSessionId): void {
    if(!conversation_presence_ready($pdo))return;$clientSessionId=trim($clientSessionId);
    if($clientSessionId==='')return;$pdo->prepare('DELETE FROM chat_presence_sessions WHERE user_id=? AND client_session_id=?')->execute([$viewer['id'],$clientSessionId]);
}
function conversation_presence_rows(PDO $pdo,array $viewer,array $conversation): array {
    if(!conversation_presence_ready($pdo)||($conversation['conversation_type']??'')!=='team'||empty($conversation['team_id']))return [];
    conversation_presence_cleanup($pdo);
    $q=$pdo->prepare("SELECT u.public_id,u.username,u.display_name,u.profile_image_url,COALESCE(sp.status_mode,'auto') status_mode,COALESCE(sp.custom_status,'') custom_status,
      EXISTS(SELECT 1 FROM chat_presence_sessions ps WHERE ps.user_id=u.id AND ps.last_seen_at>=DATE_SUB(NOW(),INTERVAL 90 SECOND)) active_now
      FROM team_members tm JOIN users u ON u.id=tm.user_id AND u.status='active'
      LEFT JOIN chat_status_preferences sp ON sp.user_id=u.id
      WHERE tm.team_id=? ORDER BY u.display_name,u.username");
    $q->execute([$conversation['team_id']]);$out=[];
    foreach($q->fetchAll() as $row){$mode=(string)$row['status_mode'];$active=(bool)$row['active_now'];$row['effective_status']=!$active||$mode==='invisible'?'offline':($mode==='busy'?'busy':($mode==='away'?'away':'online'));if($mode==='invisible')$row['custom_status']='';unset($row['active_now']);$out[]=$row;}
    return $out;
}

function conversation_team_ensure(PDO $pdo,array $team,array $actor): array {
    $teamId=(int)$team['id'];$actorId=(int)$actor['id'];$title=trim((string)($team['name']??'Team Chat'));
    $aq=$pdo->prepare('SELECT role FROM team_members WHERE team_id=? AND user_id=? LIMIT 1');$aq->execute([$teamId,$actorId]);if(!$aq->fetchColumn())throw new RuntimeException('Team membership is required.');
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("SELECT * FROM conversations WHERE conversation_type='team' AND team_id=? FOR UPDATE");$q->execute([$teamId]);$conversation=$q->fetch();
        if(!$conversation){
            try{$pdo->prepare("INSERT INTO conversations(public_id,conversation_type,team_id,created_by_user_id,title) VALUES(?,'team',?,?,?)")->execute([ulid_like(),$teamId,$actorId,$title]);}
            catch(PDOException $e){if((string)$e->getCode()!=='23000')throw $e;}
            $q=$pdo->prepare("SELECT * FROM conversations WHERE conversation_type='team' AND team_id=? FOR UPDATE");$q->execute([$teamId]);$conversation=$q->fetch();
        }
        if(!$conversation)throw new RuntimeException('Unable to initialize team conversation.');
        conversation_sync_team_members($pdo,$conversation,$teamId);
        $pdo->commit();return $conversation;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function conversation_sync_team_members(PDO $pdo,array $conversation,int $teamId): void {
    $conversationId=(int)$conversation['id'];
    $pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role)
      SELECT ?,tm.user_id,CASE WHEN tm.role IN ('owner','admin') THEN tm.role ELSE 'member' END
      FROM team_members tm WHERE tm.team_id=?
      ON DUPLICATE KEY UPDATE member_role=VALUES(member_role)")->execute([$conversationId,$teamId]);
    $pdo->prepare("DELETE cm FROM conversation_members cm
      LEFT JOIN team_members tm ON tm.team_id=? AND tm.user_id=cm.user_id
      WHERE cm.conversation_id=? AND tm.user_id IS NULL")->execute([$teamId,$conversationId]);
}
function conversation_team_by_public(PDO $pdo,array $viewer,string $teamPublicId): ?array {
    $q=$pdo->prepare("SELECT t.id,t.public_id,t.name,t.owner_user_id,tm.role access_role,
      (SELECT COUNT(*) FROM team_members x WHERE x.team_id=t.id) member_count
      FROM teams t JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=?
      WHERE t.public_id=? LIMIT 1");
    $q->execute([$viewer['id'],$teamPublicId]);return $q->fetch()?:null;
}
function conversation_access(PDO $pdo,array $viewer,string $conversationPublicId): ?array {
    $q=$pdo->prepare("SELECT c.*,t.public_id team_public_id,t.name team_name,tm.role team_role,cm.user_id conversation_member_user_id,
      (SELECT COUNT(*) FROM team_members tx WHERE tx.team_id=c.team_id) member_count
      FROM conversations c
      LEFT JOIN teams t ON t.id=c.team_id
      LEFT JOIN team_members tm ON tm.team_id=c.team_id AND tm.user_id=?
      LEFT JOIN conversation_members cm ON cm.conversation_id=c.id AND cm.user_id=?
      WHERE c.public_id=? LIMIT 1");
    $q->execute([$viewer['id'],$viewer['id'],$conversationPublicId]);$row=$q->fetch();if(!$row)return null;
    if($row['conversation_type']==='team'){
        if(empty($row['team_id'])||empty($row['team_role']))return null;
        conversation_sync_team_members($pdo,$row,(int)$row['team_id']);
    }elseif(empty($row['conversation_member_user_id'])){return null;}
    return $row;
}
function conversation_team_list(PDO $pdo,array $viewer): array {
    if(!conversation_runtime_ready($pdo))return [];
    $q=$pdo->prepare("SELECT t.id,t.public_id,t.name,t.owner_user_id,tm.role access_role,
      (SELECT COUNT(*) FROM team_members x WHERE x.team_id=t.id) member_count
      FROM teams t JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=?
      ORDER BY t.name");
    $q->execute([$viewer['id']]);$teams=$q->fetchAll();$out=[];
    foreach($teams as $team){
        $c=conversation_team_ensure($pdo,$team,$viewer);$cid=(int)$c['id'];
        $uq=$pdo->prepare("SELECT COUNT(*) FROM conversation_messages m
          LEFT JOIN conversation_members cm ON cm.conversation_id=m.conversation_id AND cm.user_id=?
          WHERE m.conversation_id=? AND m.deleted_at IS NULL AND COALESCE(m.user_id,0)<>?
          AND m.id>COALESCE(cm.last_read_message_id,0)");
        $uq->execute([$viewer['id'],$cid,$viewer['id']]);$unread=(int)$uq->fetchColumn();
        $lq=$pdo->prepare("SELECT body,created_at FROM conversation_messages WHERE conversation_id=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");$lq->execute([$cid]);$last=$lq->fetch();
        $out[]=['public_id'=>$c['public_id'],'team_public_id'=>$team['public_id'],'team_name'=>$team['name'],'access_role'=>$team['access_role'],'member_count'=>(int)$team['member_count'],'unread_count'=>$unread,'last_message'=>$last?mb_substr((string)$last['body'],0,100):'','last_message_at'=>$last['created_at']??null];
    }
    return $out;
}
function conversation_message_rows(PDO $pdo,array $viewer,string $conversationPublicId,?int $beforeId=null,int $limit=50): ?array {
    $conversation=conversation_access($pdo,$viewer,$conversationPublicId);if(!$conversation)return null;$limit=max(1,min(100,$limit));$params=[(int)$conversation['id']];
    $where='m.conversation_id=?';if($beforeId){$where.=' AND m.id<?';$params[]=$beforeId;}
    $sql="SELECT m.id,m.public_id,m.user_id,m.sender_type,m.parent_message_id,m.body,m.created_at,m.edited_at,m.deleted_at,
      u.username,u.display_name,u.profile_image_url,p.public_id parent_public_id,p.body parent_body,pu.display_name parent_display_name
      FROM conversation_messages m
      LEFT JOIN users u ON u.id=m.user_id
      LEFT JOIN conversation_messages p ON p.id=m.parent_message_id
      LEFT JOIN users pu ON pu.id=p.user_id
      WHERE $where ORDER BY m.id DESC LIMIT ".($limit+1);
    $q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll();$more=count($rows)>$limit;if($more)array_pop($rows);$rows=array_reverse($rows);
    $attachmentMap=function_exists('object_handoff_message_attachments')?object_handoff_message_attachments($pdo,$viewer,array_column($rows,'id')):[];
    foreach($rows as &$row){$row['id']=(int)$row['id'];$row['is_self']=(int)($row['user_id']??0)===(int)$viewer['id'];$row['body']=$row['deleted_at']!==null?'Message removed.':(string)$row['body'];$row['attachments']=$row['deleted_at']!==null?[]:($attachmentMap[$row['id']]??[]);unset($row['user_id']);}unset($row);
    $next=$more&&$rows?(int)$rows[0]['id']:null;$presence=conversation_presence_rows($pdo,$viewer,$conversation);$onlineCount=count(array_filter($presence,fn($p)=>($p['effective_status']??'offline')!=='offline'));
    return ['conversation'=>['public_id'=>$conversation['public_id'],'type'=>$conversation['conversation_type'],'team_public_id'=>$conversation['team_public_id']??null,'team_name'=>$conversation['team_name']??$conversation['title'],'member_count'=>(int)($conversation['member_count']??0),'online_count'=>$onlineCount],'messages'=>$rows,'presence'=>$presence,'next_before'=>$next];
}
function conversation_message_create(PDO $pdo,array $viewer,string $conversationPublicId,string $body,?string $parentPublicId=null,?string $clientMessageId=null,array $attachments=[]): array {
    $body=trim($body);if($body===''||mb_strlen($body)>5000)throw new InvalidArgumentException('Message must be between 1 and 5000 characters.');
    $conversation=conversation_access($pdo,$viewer,$conversationPublicId);if(!$conversation)throw new RuntimeException('Conversation not found.');
    $attachments=function_exists('object_handoff_normalize_attachments')?object_handoff_normalize_attachments($pdo,$viewer,$conversation,$attachments):[];
    $parentId=null;if($parentPublicId!==null&&$parentPublicId!==''){
        $q=$pdo->prepare('SELECT id FROM conversation_messages WHERE public_id=? AND conversation_id=? AND deleted_at IS NULL');$q->execute([$parentPublicId,$conversation['id']]);$parentId=(int)($q->fetchColumn()?:0);if(!$parentId)throw new InvalidArgumentException('Reply target is not in this conversation.');
    }
    $client=trim((string)$clientMessageId);if($client==='')$client=null;if($client!==null&&strlen($client)>80)throw new InvalidArgumentException('Client message id is too long.');
    if($client!==null){$q=$pdo->prepare('SELECT public_id,id FROM conversation_messages WHERE conversation_id=? AND user_id=? AND client_message_id=? LIMIT 1');$q->execute([$conversation['id'],$viewer['id'],$client]);if($existing=$q->fetch())return ['public_id'=>$existing['public_id'],'id'=>(int)$existing['id'],'created'=>false,'deduplicated'=>true];}
    $public=ulid_like();
    try{$pdo->prepare("INSERT INTO conversation_messages(public_id,conversation_id,user_id,sender_type,parent_message_id,client_message_id,body) VALUES(?,?,?,'user',?,?,?)")->execute([$public,$conversation['id'],$viewer['id'],$parentId,$client,$body]);}
    catch(PDOException $e){
        if((string)$e->getCode()==='23000'&&$client!==null){$q=$pdo->prepare('SELECT public_id,id FROM conversation_messages WHERE conversation_id=? AND user_id=? AND client_message_id=? LIMIT 1');$q->execute([$conversation['id'],$viewer['id'],$client]);if($existing=$q->fetch())return ['public_id'=>$existing['public_id'],'id'=>(int)$existing['id'],'created'=>false,'deduplicated'=>true];}
        throw $e;
    }
    $id=(int)$pdo->lastInsertId();if($attachments&&function_exists('object_handoff_store_message_attachments'))object_handoff_store_message_attachments($pdo,$id,$attachments);$pdo->prepare('UPDATE conversations SET last_message_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$conversation['id']]);
    $pdo->prepare("INSERT INTO conversation_events(conversation_id,event_type,actor_user_id,message_id,payload_json) VALUES(?,'message_created',?,?,NULL)")->execute([$conversation['id'],$viewer['id'],$id]);
    if($conversation['conversation_type']==='team'&&!empty($conversation['team_id'])){
        $q=$pdo->prepare("SELECT tm.user_id FROM team_members tm JOIN users u ON u.id=tm.user_id AND u.status='active' WHERE tm.team_id=? AND tm.user_id<>?");$q->execute([$conversation['team_id'],$viewer['id']]);
        $teamPublic=(string)($conversation['team_public_id']??'');$teamName=(string)($conversation['team_name']??$conversation['title']??'your team');
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $recipient)notify_user($pdo,(int)$recipient,(int)$viewer['id'],'team_message','conversation',(string)$conversation['public_id'],$viewer['display_name'].' sent a message in '.$teamName.'.',['dedupe_key'=>'team-message:'.$public.':'.$recipient,'group_key'=>'team-chat:'.$conversation['public_id'],'context'=>['conversation_public_id'=>$conversation['public_id'],'team_public_id'=>$teamPublic]]);
    }
    return ['public_id'=>$public,'id'=>$id,'created'=>true,'deduplicated'=>false,'attachments'=>function_exists('object_handoff_message_attachments')?(object_handoff_message_attachments($pdo,$viewer,[$id])[$id]??[]):[]];
}
function conversation_mark_read(PDO $pdo,array $viewer,string $conversationPublicId,?string $messagePublicId=null): bool {
    $conversation=conversation_access($pdo,$viewer,$conversationPublicId);if(!$conversation)return false;$messageId=0;
    if($messagePublicId){$q=$pdo->prepare('SELECT id FROM conversation_messages WHERE public_id=? AND conversation_id=?');$q->execute([$messagePublicId,$conversation['id']]);$messageId=(int)($q->fetchColumn()?:0);if(!$messageId)return false;}
    if(!$messageId){$q=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM conversation_messages WHERE conversation_id=?');$q->execute([$conversation['id']]);$messageId=(int)$q->fetchColumn();}
    $pdo->prepare('INSERT INTO conversation_members(conversation_id,user_id,member_role,last_read_message_id,last_read_at) VALUES(?,?,?,NULLIF(?,0),NOW()) ON DUPLICATE KEY UPDATE last_read_message_id=CASE WHEN VALUES(last_read_message_id) IS NULL THEN last_read_message_id ELSE GREATEST(COALESCE(last_read_message_id,0),VALUES(last_read_message_id)) END,last_read_at=NOW()')
      ->execute([$conversation['id'],$viewer['id'],in_array((string)($conversation['team_role']??''),['owner','admin'],true)?(string)$conversation['team_role']:'member',$messageId]);
    return true;
}
