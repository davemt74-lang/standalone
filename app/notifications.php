<?php
declare(strict_types=1);

function notification_category_for_type(string $type): string {
    return match(true){
        str_starts_with($type,'source_') => 'sources',
        str_starts_with($type,'research_') || str_starts_with($type,'project_') => 'research',
        str_starts_with($type,'team_') => 'team',
        str_starts_with($type,'live_') => 'live',
        str_starts_with($type,'claim_') || str_starts_with($type,'rights_') => 'claims',
        str_starts_with($type,'moderation_') || str_starts_with($type,'report_') => 'moderation',
        in_array($type,['comment','comment_reply'],true) => 'comments',
        $type==='new_follower' => 'follows',
        default => 'social',
    };
}
function notification_preferences(PDO $pdo,int $userId): array {
    try{
        $pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$userId]);
        $q=$pdo->prepare('SELECT * FROM user_preferences WHERE user_id=?');$q->execute([$userId]);return $q->fetch()?:[];
    }catch(PDOException $e){return [];}
}
function notification_pref_enabled(PDO $pdo,int $userId,string $type): bool {
    $p=notification_preferences($pdo,$userId);$category=notification_category_for_type($type);
    $specific=match($category){
        'sources'=>'notify_sources','research'=>'notify_research','team'=>'notify_team','live'=>'notify_live',
        'claims'=>'notify_claims','moderation'=>'notify_moderation','comments'=>'notify_comments','follows'=>'notify_follows',
        default=>'notify_social',
    };
    if(isset($p[$specific])&&(int)$p[$specific]===0)return false;
    if(in_array($category,['comments','follows','social'],true)&&isset($p['notify_social'])&&(int)$p['notify_social']===0)return false;
    return true;
}
function notification_is_muted(PDO $pdo,int $userId,string $category,array $context=[]): bool {
    $scopes=[];
    foreach([
        'source_public_id'=>'source','annotation_public_id'=>'annotation','conversation_public_id'=>'conversation',
        'live_room_key'=>'live_room','actor_public_id'=>'user'
    ] as $key=>$type)if(!empty($context[$key]))$scopes[]=[$type,(string)$context[$key]];
    foreach($scopes as [$type,$public]){
        $q=$pdo->prepare("SELECT 1 FROM notification_mutes WHERE user_id=? AND scope_type=? AND scope_public_id=? AND category IN ('all',?) LIMIT 1");
        $q->execute([$userId,$type,$public,$category]);if($q->fetchColumn())return true;
    }
    return false;
}
function notification_create(PDO $pdo,int $userId,?int $actorUserId,string $type,?string $objectType,?string $objectPublicId,?string $body,array $options=[]): bool {
    if($userId<=0||($actorUserId!==null&&$actorUserId===$userId&&!($options['allow_self']??false)))return false;
    if($actorUserId!==null&&function_exists('is_blocked')&&is_blocked($pdo,$userId,$actorUserId))return false;
    if(!notification_pref_enabled($pdo,$userId,$type))return false;
    $category=(string)($options['category']??notification_category_for_type($type));$context=(array)($options['context']??[]);
    if($actorUserId!==null&&!isset($context['actor_public_id'])){try{$q=$pdo->prepare('SELECT public_id FROM users WHERE id=?');$q->execute([$actorUserId]);$context['actor_public_id']=$q->fetchColumn()?:null;}catch(PDOException $e){}}
    if(notification_is_muted($pdo,$userId,$category,$context))return false;
    $dedupe=isset($options['dedupe_key'])&&$options['dedupe_key']!==''?(string)$options['dedupe_key']:null;
    $group=isset($options['group_key'])&&$options['group_key']!==''?(string)$options['group_key']:($objectType&&$objectPublicId?$type.':'.$objectType.':'.$objectPublicId:$type);
    try{
        $q=$pdo->prepare('INSERT IGNORE INTO notifications(public_id,user_id,actor_user_id,notification_type,category,dedupe_key,group_key,object_type,object_public_id,body,context_json) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([ulid_like(),$userId,$actorUserId,$type,$category,$dedupe,$group,$objectType,$objectPublicId,$body,$context?json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
        return $q->rowCount()===1;
    }catch(PDOException $e){return false;}
}
function notification_object_access(PDO $pdo,array $viewer,array $n): bool {
    $type=(string)($n['object_type']??'');$public=(string)($n['object_public_id']??'');if($public==='')return true;
    if($type==='annotation')return annotation_access($pdo,$public,$viewer)!==null;
    if($type==='source')return source_access($pdo,$public,$viewer)!==null;
    if($type==='user'){
        $q=$pdo->prepare("SELECT id FROM users WHERE public_id=? AND status='active'");$q->execute([$public]);$id=(int)($q->fetchColumn()?:0);
        return $id>0&&!is_blocked($pdo,(int)$viewer['id'],$id);
    }
    if($type==='rights_claim'){
        $q=$pdo->prepare('SELECT claimant_user_id FROM rights_claims WHERE public_id=?');$q->execute([$public]);$owner=(int)($q->fetchColumn()?:0);
        return ($viewer['role']??'')==='admin'||$owner===(int)$viewer['id'];
    }
    if($type==='moderation_report'){
        $q=$pdo->prepare('SELECT reported_by_user_id FROM moderation_reports WHERE public_id=?');$q->execute([$public]);$owner=(int)($q->fetchColumn()?:0);
        return ($viewer['role']??'')==='admin'||$owner===(int)$viewer['id'];
    }
    if($type==='research_report'){
        $q=$pdo->prepare("SELECT rr.visibility,rp.owner_user_id,rp.team_id FROM research_reports rr JOIN research_projects rp ON rp.id=rr.project_id WHERE rr.public_id=? AND rr.status='published' LIMIT 1");$q->execute([$public]);$r=$q->fetch();if(!$r)return false;if($r['visibility']==='public')return true;
        if(($viewer['role']??'')==='admin'||(int)$r['owner_user_id']===(int)$viewer['id'])return true;if(!$r['team_id'])return false;
        $sql=$r['visibility']==='team'?'SELECT 1 FROM team_members WHERE team_id=? AND user_id=? LIMIT 1':"SELECT 1 FROM team_members WHERE team_id=? AND user_id=? AND role IN ('owner','admin') LIMIT 1";$q=$pdo->prepare($sql);$q->execute([$r['team_id'],$viewer['id']]);return (bool)$q->fetchColumn();
    }
    if($type==='conversation'){
        if(!function_exists('conversation_access'))return false;return conversation_access($pdo,$viewer,$public)!==null;
    }
    if($type==='saved_search')return $viewer&&function_exists('search_saved_notification_access')&&search_saved_notification_access($pdo,$viewer,$public);
    if($type==='live_message'){
        $q=$pdo->prepare('SELECT lm.source_id,lm.room_type,t.public_id team_public_id,rp.public_id project_public_id,s.public_id source_public_id FROM live_messages lm JOIN sources s ON s.id=lm.source_id LEFT JOIN teams t ON t.id=lm.team_id LEFT JOIN research_projects rp ON rp.id=lm.project_id WHERE lm.public_id=? LIMIT 1');$q->execute([$public]);$m=$q->fetch();if(!$m)return false;
        $roomId=$m['room_type']==='team'?$m['team_public_id']:($m['room_type']==='project'?$m['project_public_id']:null);return function_exists('live_room_scope')&&live_room_scope($pdo,$viewer,(string)$m['source_public_id'],(string)$m['room_type'],$roomId,false)!==null;
    }
    return true;
}
function notification_url(PDO $pdo,array $viewer,array $n): ?string {
    if(!notification_object_access($pdo,$viewer,$n))return null;$type=(string)($n['object_type']??'');$public=(string)($n['object_public_id']??'');$context=json_decode((string)($n['context_json']??''),true)?:[];
    if($type==='annotation')return '/annotation.php?id='.rawurlencode($public).(!empty($context['comment_id'])?'#discussion':'');
    if($type==='source')return '/source.php?id='.rawurlencode($public).(!empty($context['source_change_event_id'])?'#change-'.rawurlencode((string)$context['source_change_event_id']):'');
    if($type==='user'){
        $q=$pdo->prepare('SELECT username FROM users WHERE public_id=?');$q->execute([$public]);$u=(string)($q->fetchColumn()?:'');return $u!==''?profile_path($u):null;
    }
    if($type==='rights_claim')return '/claim-status.php?id='.rawurlencode($public);
    if($type==='moderation_report')return '/report-status.php?id='.rawurlencode($public);
    if($type==='research_report')return '/research-report.php?id='.rawurlencode($public);
    if($type==='conversation'){
        if(!function_exists('conversation_access'))return null;$conversation=conversation_access($pdo,$viewer,$public);if(!$conversation)return null;
        if(($conversation['conversation_type']??'')==='team'&&!empty($conversation['team_public_id']))return '/home.php?team='.rawurlencode((string)$conversation['team_public_id']).'#team-chat';
        return '/home.php?conversation='.rawurlencode($public).'#agent-chat';
    }
    if($type==='saved_search')return '/search.php?saved='.rawurlencode($public);
    if($type==='live_message'){
        $q=$pdo->prepare('SELECT s.public_id source_public_id,lm.room_type,t.public_id team_public_id,rp.public_id project_public_id FROM live_messages lm JOIN sources s ON s.id=lm.source_id LEFT JOIN teams t ON t.id=lm.team_id LEFT JOIN research_projects rp ON rp.id=lm.project_id WHERE lm.public_id=?');$q->execute([$public]);$m=$q->fetch();if(!$m)return null;$url='/live.php?id='.rawurlencode((string)$m['source_public_id']).'&room_type='.rawurlencode((string)$m['room_type']);$roomId=$m['room_type']==='team'?$m['team_public_id']:($m['room_type']==='project'?$m['project_public_id']:null);if($roomId)$url.='&room_id='.rawurlencode((string)$roomId);return $url.'#message-'.rawurlencode($public);
    }
    return null;
}
function notification_rows(PDO $pdo,array $viewer,int $limit=200,bool $unreadOnly=false): array {
    $limit=max(1,min(300,$limit));$sql='SELECT * FROM notifications WHERE user_id=? AND archived_at IS NULL'.($unreadOnly?' AND read_at IS NULL':'').' ORDER BY created_at DESC,id DESC LIMIT '.$limit;
    $q=$pdo->prepare($sql);$q->execute([$viewer['id']]);$rows=[];$groups=[];
    foreach($q->fetchAll() as $n){
        if(!notification_object_access($pdo,$viewer,$n))continue;$n['url']=notification_url($pdo,$viewer,$n);$n['context']=json_decode((string)($n['context_json']??''),true)?:[];$key=(string)($n['group_key']?:$n['public_id']);unset($n['id'],$n['user_id'],$n['actor_user_id'],$n['dedupe_key'],$n['context_json'],$n['group_key']);
        if(isset($groups[$key])){$groups[$key]['group_count']++;if(!$n['read_at'])$groups[$key]['unread_count']++;continue;}
        $n['group_count']=1;$n['unread_count']=$n['read_at']?0:1;$groups[$key]=$n;
    }
    return array_values($groups);
}
function notification_unread_count(PDO $pdo,array $viewer): int {
    $count=0;foreach(notification_rows($pdo,$viewer,300,true) as $n)$count+=(int)$n['unread_count'];return $count;
}
function notification_mark_read(PDO $pdo,array $viewer,?string $publicId=null): int {
    if($publicId===null){$q=$pdo->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND archived_at IS NULL');$q->execute([$viewer['id']]);return $q->rowCount();}
    $q=$pdo->prepare('SELECT group_key FROM notifications WHERE user_id=? AND public_id=? LIMIT 1');$q->execute([$viewer['id'],$publicId]);$group=$q->fetchColumn();if($group===false)return 0;if($group===null){$q=$pdo->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND public_id=? AND archived_at IS NULL');$q->execute([$viewer['id'],$publicId]);return $q->rowCount();}
    $q=$pdo->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND group_key=? AND archived_at IS NULL');$q->execute([$viewer['id'],$group]);return $q->rowCount();
}
function notification_archive(PDO $pdo,array $viewer,string $publicId): bool {
    $q=$pdo->prepare('SELECT group_key FROM notifications WHERE user_id=? AND public_id=? LIMIT 1');$q->execute([$viewer['id'],$publicId]);$group=$q->fetchColumn();if($group===false)return false;if($group===null){$q=$pdo->prepare('UPDATE notifications SET archived_at=COALESCE(archived_at,NOW()) WHERE user_id=? AND public_id=?');$q->execute([$viewer['id'],$publicId]);return $q->rowCount()>0;}
    $q=$pdo->prepare('UPDATE notifications SET archived_at=COALESCE(archived_at,NOW()) WHERE user_id=? AND group_key=?');$q->execute([$viewer['id'],$group]);return $q->rowCount()>0;
}
function notification_mute_set(PDO $pdo,array $viewer,string $scopeType,string $scopePublicId,string $category='all',bool $muted=true): bool {
    if(!in_array($scopeType,['source','annotation','conversation','live_room','user'],true)||trim($scopePublicId)==='')throw new InvalidArgumentException('Invalid notification mute scope.');
    $category=trim($category)?:'all';
    if($muted)$pdo->prepare('INSERT IGNORE INTO notification_mutes(user_id,scope_type,scope_public_id,category) VALUES(?,?,?,?)')->execute([$viewer['id'],$scopeType,$scopePublicId,$category]);
    else $pdo->prepare('DELETE FROM notification_mutes WHERE user_id=? AND scope_type=? AND scope_public_id=? AND category=?')->execute([$viewer['id'],$scopeType,$scopePublicId,$category]);
    return $muted;
}
