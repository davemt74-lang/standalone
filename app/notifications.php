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
    if($type==='research_report'&&function_exists('research_report_access'))return research_report_access($pdo,$public,$viewer)!==null;
    return true;
}
function notification_url(PDO $pdo,array $viewer,array $n): ?string {
    if(!notification_object_access($pdo,$viewer,$n))return null;$type=(string)($n['object_type']??'');$public=(string)($n['object_public_id']??'');$context=json_decode((string)($n['context_json']??''),true)?:[];
    if($type==='annotation')return '/annotation.php?id='.rawurlencode($public).(!empty($context['comment_id'])?'#discussion':'');
    if($type==='source')return '/source.php?id='.rawurlencode($public).(!empty($context['source_change_event_id'])?'#change-'.rawurlencode((string)$context['source_change_event_id']):'');
    if($type==='user'){
        $q=$pdo->prepare('SELECT username FROM users WHERE public_id=?');$q->execute([$public]);$u=(string)($q->fetchColumn()?:'');return $u!==''?'/profile.php?u='.rawurlencode($u):null;
    }
    if($type==='rights_claim')return '/claim-status.php?id='.rawurlencode($public);
    if($type==='moderation_report')return '/report-status.php?id='.rawurlencode($public);
    if($type==='research_report')return '/research-report.php?id='.rawurlencode($public);
    return null;
}
function notification_rows(PDO $pdo,array $viewer,int $limit=200,bool $unreadOnly=false): array {
    $limit=max(1,min(300,$limit));$sql='SELECT * FROM notifications WHERE user_id=? AND archived_at IS NULL'.($unreadOnly?' AND read_at IS NULL':'').' ORDER BY created_at DESC,id DESC LIMIT '.$limit;
    $q=$pdo->prepare($sql);$q->execute([$viewer['id']]);$rows=[];$groups=[];
    foreach($q->fetchAll() as $n){
        if(!notification_object_access($pdo,$viewer,$n))continue;$n['url']=notification_url($pdo,$viewer,$n);$n['context']=json_decode((string)($n['context_json']??''),true)?:[];
        $key=(string)($n['group_key']?:$n['public_id']);
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
    $q=$pdo->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND public_id=?');$q->execute([$viewer['id'],$publicId]);return $q->rowCount();
}
function notification_archive(PDO $pdo,array $viewer,string $publicId): bool {
    $q=$pdo->prepare('UPDATE notifications SET archived_at=COALESCE(archived_at,NOW()) WHERE user_id=? AND public_id=?');$q->execute([$viewer['id'],$publicId]);return $q->rowCount()>0;
}
function notification_mute_set(PDO $pdo,array $viewer,string $scopeType,string $scopePublicId,string $category='all',bool $muted=true): bool {
    if(!in_array($scopeType,['source','annotation','conversation','live_room','user'],true)||trim($scopePublicId)==='')throw new InvalidArgumentException('Invalid notification mute scope.');
    $category=trim($category)?:'all';
    if($muted)$pdo->prepare('INSERT IGNORE INTO notification_mutes(user_id,scope_type,scope_public_id,category) VALUES(?,?,?,?)')->execute([$viewer['id'],$scopeType,$scopePublicId,$category]);
    else $pdo->prepare('DELETE FROM notification_mutes WHERE user_id=? AND scope_type=? AND scope_public_id=? AND category=?')->execute([$viewer['id'],$scopeType,$scopePublicId,$category]);
    return $muted;
}
