<?php
declare(strict_types=1);
require_once __DIR__.'/source-integrity.php';

function feed_cursor_encode(int $id): string {
    return rtrim(strtr(base64_encode((string)$id),'+/','-_'),'=');
}
function feed_cursor_decode(?string $cursor): ?int {
    $cursor=trim((string)$cursor);if($cursor==='')return null;
    $raw=strtr($cursor,'-_','+/');$pad=strlen($raw)%4;if($pad)$raw.=str_repeat('=',4-$pad);
    $decoded=base64_decode($raw,true);if($decoded===false||!ctype_digit($decoded))return null;
    $id=(int)$decoded;return $id>0?$id:null;
}
function feed_access_sql(?array $viewer,string $alias='a'): array {
    $uid=(int)($viewer['id']??0);$role=(string)($viewer['role']??'');
    if(!$uid)return ["$alias.status='published' AND $alias.visibility='public'",[]];
    if($role==='admin')return ["$alias.status='published'",[]];
    $sql="$alias.status='published' AND (
      $alias.visibility='public'
      OR $alias.user_id=?
      OR ($alias.visibility='team' AND $alias.team_id IS NOT NULL AND EXISTS(
        SELECT 1 FROM team_members fam WHERE fam.team_id=$alias.team_id AND fam.user_id=?
      ))
      OR EXISTS(
        SELECT 1
        FROM project_annotations fpa
        JOIN research_projects frp ON frp.id=fpa.project_id
        LEFT JOIN team_members fpm ON fpm.team_id=frp.team_id AND fpm.user_id=?
        WHERE fpa.annotation_id=$alias.id AND (frp.owner_user_id=? OR fpm.user_id IS NOT NULL)
      )
    )";
    return [$sql,[$uid,$uid,$uid,$uid]];
}
function feed_block_sql(?array $viewer,string $authorExpr='a.user_id'): string {
    $uid=(int)($viewer['id']??0);if(!$uid)return '1=1';
    return "NOT EXISTS(SELECT 1 FROM blocks fb WHERE (fb.blocker_user_id=$uid AND fb.blocked_user_id=$authorExpr) OR (fb.blocker_user_id=$authorExpr AND fb.blocked_user_id=$uid))";
}
function feed_source_by_public(PDO $pdo,string $publicId): ?array {
    $q=$pdo->prepare('SELECT s.*,cv.version_number current_version_number,cv.captured_at current_version_captured_at FROM sources s LEFT JOIN source_versions cv ON cv.id=s.current_version_id WHERE s.public_id=? LIMIT 1');
    $q->execute([$publicId]);return $q->fetch()?:null;
}
function feed_annotation_post_type(array $row): string {
    $type=(string)($row['capture_type']??'');$selected=trim((string)($row['selected_text']??''));$commentary=trim((string)($row['text_commentary']??''));
    $sourceType=strtolower((string)($row['source_type']??''));$provider=strtolower((string)($row['media_provider']??''));$url=strtolower((string)($row['canonical_url']??''));
    if($type==='video_clip')return 'video';
    if($type==='audio_clip'){
        if($sourceType==='podcast'||str_contains($provider,'podcast'))return 'podcast';
        $musicProviders=['spotify','soundcloud','bandcamp','tidal','deezer','apple_music','music'];
        if(in_array($provider,$musicProviders,true)||str_contains($url,'spotify.com')||str_contains($url,'soundcloud.com')||str_contains($url,'bandcamp.com')||str_contains($url,'music.apple.com')||str_contains($url,'tidal.com'))return 'music';
        return 'audio';
    }
    if(in_array($type,['image_region','page_region'],true))return $selected!==''?'image_quote':'image';
    if($type==='text')return $selected!==''?'quote':($commentary!==''?'note':'annotation');
    return $commentary!==''?'note':'annotation';
}
function feed_context_url(array $row): string {
    $url=(string)($row['canonical_url']??'');if($url==='')return '';
    $start=$row['start_seconds']!==null?(float)$row['start_seconds']:null;$end=$row['end_seconds']!==null?(float)$row['end_seconds']:null;
    $provider=(string)($row['media_provider']??'');$selected=trim((string)($row['selected_text']??''));
    if($start!==null&&$provider==='youtube'){
        $parts=parse_url($url);if(!$parts)return $url;$query=[];if(!empty($parts['query']))parse_str($parts['query'],$query);$query['t']=(string)max(0,(int)floor($start)).'s';
        $port=isset($parts['port'])?':'.$parts['port']:'';$path=$parts['path']??'/';
        return ($parts['scheme']??'https').'://'.($parts['host']??'').$port.$path.($query?'?'.http_build_query($query):'');
    }
    if($start!==null)return $url.'#t='.max(0,(int)floor($start)).($end!==null?','.max(0,(int)ceil($end)):'');
    if($selected!==''){
        $snippet=mb_substr((string)preg_replace('/\s+/u',' ',$selected),0,220);
        return $url.'#:~:text='.rawurlencode($snippet);
    }
    return $url;
}
function feed_annotation_rows(PDO $pdo,?array $viewer,string $mode,?int $sourceId=null,?int $cursorId=null,int $limit=15): array {
    $limit=max(1,min(30,$limit));$uid=(int)($viewer['id']??0);
    [$access,$params]=feed_access_sql($viewer,'a');$where=["($access)",'('.feed_block_sql($viewer,'a.user_id').')'];
    if($mode==='page'){
        if(!$sourceId)return ['annotations'=>[],'next_cursor'=>null,'unread_count'=>0];
        $where[]='a.source_id=?';$params[]=$sourceId;
    }elseif($mode==='following'){
        if(!$uid)return ['annotations'=>[],'next_cursor'=>null,'unread_count'=>0];
        $where[]="(a.user_id=$uid OR EXISTS(SELECT 1 FROM follows ff WHERE ff.follower_user_id=$uid AND ff.followed_user_id=a.user_id) OR EXISTS(SELECT 1 FROM source_watches fsw WHERE fsw.user_id=$uid AND fsw.source_id=a.source_id))";
    }else throw new InvalidArgumentException('Invalid feed mode.');
    if($cursorId){$where[]='a.id<?';$params[]=$cursorId;}
    $flags=$uid ? "
      EXISTS(SELECT 1 FROM follows f2 WHERE f2.follower_user_id=$uid AND f2.followed_user_id=a.user_id) is_following,
      EXISTS(SELECT 1 FROM saved_annotations sa WHERE sa.user_id=$uid AND sa.annotation_id=a.id) is_saved,
      EXISTS(SELECT 1 FROM feed_reads fr WHERE fr.user_id=$uid AND fr.annotation_id=a.id) is_read,
      EXISTS(SELECT 1 FROM source_watches sw WHERE sw.user_id=$uid AND sw.source_id=a.source_id) source_following,
      EXISTS(SELECT 1 FROM follows ff2 WHERE ff2.follower_user_id=$uid AND ff2.followed_user_id=a.user_id) from_followed_user,
      EXISTS(SELECT 1 FROM source_watches sw2 WHERE sw2.user_id=$uid AND sw2.source_id=a.source_id) from_followed_source,
      EXISTS(SELECT 1 FROM annotation_reactions arl WHERE arl.user_id=$uid AND arl.annotation_id=a.id AND arl.reaction='like') viewer_liked,
      (a.user_id=$uid) is_self" : "0 is_following,0 is_saved,1 is_read,0 source_following,0 from_followed_user,0 from_followed_source,0 viewer_liked,0 is_self";
    $sql="SELECT
      a.id internal_id,a.public_id,a.source_version_id,a.text_commentary,a.published_at,a.visibility,a.team_id,
      u.public_id author_public_id,u.username,u.display_name,u.profile_image_url,
      s.public_id source_public_id,s.title source_title,s.domain source_domain,s.source_type,s.canonical_url,s.status source_record_status,s.current_version_id,
      sv.version_number capture_version_number,sv.captured_at capture_version_captured_at,
      cv.version_number current_version_number,cv.captured_at current_version_captured_at,
      CASE WHEN a.source_version_id<>s.current_version_id THEN s.status ELSE 'current' END source_status,
      (a.source_version_id<>s.current_version_id) source_changed,
      t.public_id team_public_id,t.name team_name,
      c.capture_type,c.selected_text,c.start_seconds,c.end_seconds,c.media_provider,c.provider_media_id,c.media_title,c.media_author,
      c.screenshot_target_path,
      md.storage_path media_path,md.processing_status media_status,
      a.audio_commentary_path,at.status transcript_status,COALESCE(at.edited_text,at.raw_text) transcript_text,
      (SELECT COUNT(*) FROM comments cm WHERE cm.annotation_id=a.id AND COALESCE(cm.moderation_status,'visible')='visible') comment_count,
      (SELECT COUNT(*) FROM annotation_reactions ar WHERE ar.annotation_id=a.id AND ar.reaction='like') like_count,
      $flags
      FROM annotations a
      JOIN users u ON u.id=a.user_id
      JOIN sources s ON s.id=a.source_id
      JOIN source_versions sv ON sv.id=a.source_version_id
      LEFT JOIN source_versions cv ON cv.id=s.current_version_id
      JOIN captures c ON c.id=a.capture_id
      LEFT JOIN teams t ON t.id=a.team_id
      LEFT JOIN media_derivatives md ON md.capture_id=c.id
      LEFT JOIN annotation_transcripts at ON at.annotation_id=a.id
      WHERE COALESCE(s.moderation_status,'visible')='visible' AND ".implode(' AND ',$where)."
      ORDER BY a.id DESC LIMIT ".($limit+1);
    $q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll();$hasMore=count($rows)>$limit;if($hasMore)array_pop($rows);
    $next=null;if($hasMore&&$rows)$next=feed_cursor_encode((int)$rows[count($rows)-1]['internal_id']);
    foreach($rows as &$row){
        $row['screenshot_url']=!empty($row['screenshot_target_path'])?evidence_url((string)$row['public_id'],'target'):null;
        $row['audio_url']=!empty($row['audio_commentary_path'])?evidence_url((string)$row['public_id'],'audio'):null;
        $row['media_url']=!empty($row['media_path'])?evidence_url((string)$row['public_id'],'media'):null;
        $row['context_url']=feed_context_url($row);
        $row['post_type']=feed_annotation_post_type($row);
        $row['integrity']=source_integrity_annotation_state($pdo,['id'=>(int)$row['internal_id'],'source_version_id'=>(int)$row['source_version_id'],'current_source_version_id'=>(int)$row['current_version_id']]);
        foreach(['source_changed','is_following','is_saved','is_read','source_following','from_followed_user','from_followed_source','viewer_liked','is_self'] as $k)$row[$k]=(bool)$row[$k];
        $row['like_count']=(int)($row['like_count']??0);$row['comment_count']=(int)($row['comment_count']??0);$row['post_type']=feed_annotation_post_type($row);
        unset($row['internal_id'],$row['screenshot_target_path'],$row['audio_commentary_path'],$row['media_path']);
    }unset($row);
    $unread=$mode==='following'&&$uid?feed_following_unread_count($pdo,$viewer):0;
    return ['annotations'=>$rows,'next_cursor'=>$next,'unread_count'=>$unread];
}
function feed_following_unread_count(PDO $pdo,array $viewer): int {
    $uid=(int)($viewer['id']??0);if(!$uid)return 0;[$access,$params]=feed_access_sql($viewer,'a');
    $sql="SELECT COUNT(*) FROM annotations a WHERE ($access) AND a.user_id<>$uid AND ".feed_block_sql($viewer,'a.user_id')."
      AND (EXISTS(SELECT 1 FROM follows ff WHERE ff.follower_user_id=$uid AND ff.followed_user_id=a.user_id)
        OR EXISTS(SELECT 1 FROM source_watches sw WHERE sw.user_id=$uid AND sw.source_id=a.source_id))
      AND NOT EXISTS(SELECT 1 FROM feed_reads fr WHERE fr.user_id=$uid AND fr.annotation_id=a.id)";
    $q=$pdo->prepare($sql);$q->execute($params);return (int)$q->fetchColumn();
}
function feed_source_visible_count(PDO $pdo,int $sourceId,?array $viewer): int {
    [$access,$params]=feed_access_sql($viewer,'a');$sql="SELECT COUNT(*) FROM annotations a WHERE a.source_id=? AND ($access) AND ".feed_block_sql($viewer,'a.user_id');
    array_unshift($params,$sourceId);$q=$pdo->prepare($sql);$q->execute($params);return (int)$q->fetchColumn();
}
function feed_source_followed_author_count(PDO $pdo,int $sourceId,array $viewer): int {
    $uid=(int)$viewer['id'];[$access,$params]=feed_access_sql($viewer,'a');
    $sql="SELECT COUNT(*) FROM annotations a WHERE a.source_id=? AND ($access) AND ".feed_block_sql($viewer,'a.user_id')." AND EXISTS(SELECT 1 FROM follows f WHERE f.follower_user_id=$uid AND f.followed_user_id=a.user_id)";
    array_unshift($params,$sourceId);$q=$pdo->prepare($sql);$q->execute($params);return (int)$q->fetchColumn();
}
function feed_source_followed(PDO $pdo,int $sourceId,int $userId): bool {
    $q=$pdo->prepare('SELECT 1 FROM source_watches WHERE source_id=? AND user_id=?');$q->execute([$sourceId,$userId]);return (bool)$q->fetchColumn();
}
function feed_toggle_source_follow(PDO $pdo,int $sourceId,int $userId): bool {
    $q=$pdo->prepare('SELECT 1 FROM sources WHERE id=?');$q->execute([$sourceId]);if(!$q->fetchColumn())throw new RuntimeException('Source not found.');
    if(feed_source_followed($pdo,$sourceId,$userId)){$pdo->prepare('DELETE FROM source_watches WHERE source_id=? AND user_id=?')->execute([$sourceId,$userId]);return false;}
    $pdo->prepare('INSERT IGNORE INTO source_watches(source_id,user_id) VALUES(?,?)')->execute([$sourceId,$userId]);return true;
}
function feed_toggle_annotation_like(PDO $pdo,array $viewer,string $annotationPublicId): array {
    $a=annotation_access($pdo,$annotationPublicId,$viewer);if(!$a||$a['status']!=='published')throw new RuntimeException('Annotation not found.');
    $uid=(int)$viewer['id'];$aid=(int)$a['id'];
    $q=$pdo->prepare("SELECT 1 FROM annotation_reactions WHERE annotation_id=? AND user_id=? AND reaction='like'");$q->execute([$aid,$uid]);
    if($q->fetchColumn()){$pdo->prepare("DELETE FROM annotation_reactions WHERE annotation_id=? AND user_id=? AND reaction='like'")->execute([$aid,$uid]);$liked=false;}
    else{$pdo->prepare("INSERT INTO annotation_reactions(annotation_id,user_id,reaction) VALUES(?,?,'like')")->execute([$aid,$uid]);$liked=true;}
    $q=$pdo->prepare("SELECT COUNT(*) FROM annotation_reactions WHERE annotation_id=? AND reaction='like'");$q->execute([$aid]);
    return ['liked'=>$liked,'like_count'=>(int)$q->fetchColumn()];
}
function feed_comments(PDO $pdo,string $annotationPublicId,?array $viewer): ?array {
    $a=annotation_access($pdo,$annotationPublicId,$viewer);if(!$a||$a['status']!=='published')return null;$uid=(int)($viewer['id']??0);$admin=(($viewer['role']??'')==='admin');
    $block=$uid?" AND NOT EXISTS(SELECT 1 FROM blocks cb WHERE (cb.blocker_user_id=$uid AND cb.blocked_user_id=c.user_id) OR (cb.blocker_user_id=c.user_id AND cb.blocked_user_id=$uid))":'';
    $moderation=$admin?'':($uid?" AND (COALESCE(c.moderation_status,'visible')='visible' OR c.user_id=$uid)":" AND COALESCE(c.moderation_status,'visible')='visible'");
    $q=$pdo->prepare("SELECT c.id,c.public_id,c.user_id,c.parent_comment_id,c.body,c.moderation_status,c.created_at,u.public_id author_public_id,u.username,u.display_name FROM comments c JOIN users u ON u.id=c.user_id WHERE c.annotation_id=?$block$moderation ORDER BY c.id ASC LIMIT 150");$q->execute([$a['id']]);
    $rows=$q->fetchAll();foreach($rows as &$row){$row['id']=(string)$row['id'];$row['parent_comment_id']=$row['parent_comment_id']!==null?(string)$row['parent_comment_id']:null;$row['restricted']=($row['moderation_status']??'visible')!=='visible';if($row['restricted']&&!$admin&&(int)$uid!==(int)($row['user_id']??0))$row['body']='';unset($row['user_id']);}unset($row);
    return ['annotation'=>$a,'comments'=>$rows];
}
function feed_create_comment(PDO $pdo,array $user,string $annotationPublicId,string $body,?int $parentId=null): array {
    $body=trim($body);if($body===''||mb_strlen($body)>5000)throw new InvalidArgumentException('Comment must be between 1 and 5000 characters.');
    $a=annotation_access($pdo,$annotationPublicId,$user);if(!$a||$a['status']!=='published')throw new RuntimeException('Annotation not found.');
    if(is_blocked($pdo,(int)$user['id'],(int)$a['user_id']))throw new RuntimeException('Commenting is unavailable for this annotation.');
    $parentUserId=null;if($parentId){
        $q=$pdo->prepare("SELECT user_id FROM comments WHERE id=? AND annotation_id=? AND COALESCE(moderation_status,'visible')<>'removed'");$q->execute([$parentId,$a['id']]);$parentUserId=$q->fetchColumn();
        if($parentUserId===false)throw new InvalidArgumentException('Reply target is not part of this discussion.');
    }
    $commentPublic=ulid_like();$pdo->prepare('INSERT INTO comments(public_id,annotation_id,user_id,parent_comment_id,body) VALUES(?,?,?,?,?)')->execute([$commentPublic,$a['id'],$user['id'],$parentId,$body]);$id=(int)$pdo->lastInsertId();if(function_exists('live_event_emit_for_annotation'))live_event_emit_for_annotation($pdo,(int)$a['id'],'comment',$id);
    $sq=$pdo->prepare('SELECT public_id FROM sources WHERE id=?');$sq->execute([$a['source_id']]);$sourcePublic=(string)($sq->fetchColumn()?:'');
    $context=['annotation_public_id'=>$a['public_id'],'conversation_public_id'=>$a['public_id'],'comment_public_id'=>$commentPublic,'source_public_id'=>$sourcePublic];
    if((int)$a['user_id']!==(int)$user['id'])notify_user($pdo,(int)$a['user_id'],(int)$user['id'],'comment','annotation',$a['public_id'],$user['display_name'].' commented on your annotation.',['dedupe_key'=>'comment:'.$commentPublic.':author','group_key'=>'conversation:'.$a['public_id'],'context'=>$context]);
    if($parentUserId!==null&&(int)$parentUserId!==(int)$user['id']&&(int)$parentUserId!==(int)$a['user_id'])notify_user($pdo,(int)$parentUserId,(int)$user['id'],'comment_reply','annotation',$a['public_id'],$user['display_name'].' replied to your comment.',['dedupe_key'=>'comment:'.$commentPublic.':reply','group_key'=>'conversation:'.$a['public_id'],'context'=>$context]);
    $q=$pdo->prepare("SELECT COUNT(*) FROM comments WHERE annotation_id=? AND COALESCE(moderation_status,'visible')='visible'");$q->execute([$a['id']]);
    return ['id'=>(string)$id,'public_id'=>$commentPublic,'comment_count'=>(int)$q->fetchColumn()];
}
function feed_mark_read(PDO $pdo,array $viewer,array $publicIds): int {
    $ids=array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$publicIds))));$ids=array_slice($ids,0,50);$marked=0;
    foreach($ids as $publicId){$a=annotation_access($pdo,$publicId,$viewer);if(!$a||$a['status']!=='published')continue;$pdo->prepare('INSERT INTO feed_reads(user_id,annotation_id,first_read_at,last_read_at) VALUES(?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE last_read_at=NOW()')->execute([$viewer['id'],$a['id']]);$marked++;}
    return $marked;
}
