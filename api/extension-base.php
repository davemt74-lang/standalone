<?php
function ext_source_id(PDO $pdo,string $public): int { $q=$pdo->prepare('SELECT id FROM sources WHERE public_id=?');$q->execute([$public]);return (int)($q->fetchColumn()?:0); }
function ext_team_id(PDO $pdo,string $public,int $userId): int { $q=$pdo->prepare('SELECT t.id FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE t.public_id=? AND tm.user_id=? LIMIT 1');$q->execute([$public,$userId]);return (int)($q->fetchColumn()?:0); }
function ext_annotation(PDO $pdo,string $public): ?array { $q=$pdo->prepare("SELECT id,user_id,source_id,public_id FROM annotations WHERE public_id=? AND status='published'");$q->execute([$public]);return $q->fetch()?:null; }

if($action==='me'){
    $u=require_api_user($pdo);
    json_response(['ok'=>true,'data'=>['user'=>['public_id'=>$u['public_id'],'username'=>$u['username'],'display_name'=>$u['display_name'],'role'=>$u['role'],'live_presence_mode'=>$u['live_presence_mode']]]]);
}

if($action==='page_context'){
    $url=trim((string)($input['url']??''));
    if(!filter_var($url,FILTER_VALIDATE_URL))json_response(['ok'=>false,'error'=>['code'=>'INVALID_URL']],422);
    $canonical=canonicalize_url($url);$hash=hash('sha256',$canonical);
    $q=$pdo->prepare('SELECT id,public_id,title,canonical_url,status,current_version_id FROM sources WHERE canonical_url_hash=?');$q->execute([$hash]);$source=$q->fetch();
    $u=current_user($pdo);
    if(!$source&&$u)$source=ensure_source($pdo,$canonical,$input['title']??null,$input['media_type']??null);
    if(!$source)json_response(['ok'=>true,'data'=>['source'=>null,'canonical_url'=>$canonical,'annotation_count'=>0,'following_annotation_count'=>0,'presence_count'=>0,'authenticated'=>false,'presence_mode'=>'off']]);
    $q=$pdo->prepare("SELECT COUNT(*) FROM annotations WHERE source_id=? AND visibility='public' AND status='published'");$q->execute([$source['id']]);$count=(int)$q->fetchColumn();
    $followingCount=0;if($u){$q=$pdo->prepare("SELECT COUNT(*) FROM annotations a JOIN follows f ON f.followed_user_id=a.user_id AND f.follower_user_id=? WHERE a.source_id=? AND a.visibility='public' AND a.status='published'");$q->execute([$u['id'],$source['id']]);$followingCount=(int)$q->fetchColumn();}
    $presence=0;try{$q=$pdo->prepare('SELECT COUNT(*) FROM page_presence_sessions WHERE source_id=? AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 90 SECOND) AND presence_mode<>"off"');$q->execute([$source['id']]);$presence=(int)$q->fetchColumn();}catch(PDOException $e){}
    $watched=false;if($u){try{$q=$pdo->prepare('SELECT 1 FROM source_watches WHERE source_id=? AND user_id=?');$q->execute([$source['id'],$u['id']]);$watched=(bool)$q->fetchColumn();}catch(PDOException $e){}}
    json_response(['ok'=>true,'data'=>['source'=>['public_id'=>$source['public_id'],'title'=>$source['title'],'canonical_url'=>$source['canonical_url'],'status'=>$source['status']],'annotation_count'=>$count,'following_annotation_count'=>$followingCount,'presence_count'=>$presence,'authenticated'=>(bool)$u,'presence_mode'=>$u['live_presence_mode']??'off','watched'=>$watched]]);
}

if($action==='feed_page'||$action==='feed_following'){
    $u=current_user($pdo);$uid=(int)($u['id']??0);
    $common="SELECT a.public_id,a.text_commentary,a.audio_commentary_path,a.published_at,a.user_id,u2.public_id author_public_id,u2.username,u2.display_name,s.public_id source_public_id,s.title source_title,s.canonical_url,CASE WHEN a.source_version_id<>s.current_version_id THEN s.status ELSE 'current' END source_status,c.capture_type,c.selected_text,c.start_seconds,c.end_seconds,c.screenshot_target_path,at.status transcript_status,COALESCE(at.edited_text,at.raw_text) transcript_text,(SELECT COUNT(*) FROM comments cm WHERE cm.annotation_id=a.id) comment_count,".($uid?"EXISTS(SELECT 1 FROM follows f2 WHERE f2.follower_user_id=$uid AND f2.followed_user_id=a.user_id)":"0")." is_following,".($uid?"EXISTS(SELECT 1 FROM saved_annotations sa WHERE sa.user_id=$uid AND sa.annotation_id=a.id)":"0")." is_saved FROM annotations a JOIN users u2 ON u2.id=a.user_id JOIN sources s ON s.id=a.source_id JOIN captures c ON c.id=a.capture_id LEFT JOIN annotation_transcripts at ON at.annotation_id=a.id ";
    if($action==='feed_page'){
        $source=(string)($input['source']??'');
        $sql=$common."WHERE s.public_id=? AND a.visibility='public' AND a.status='published'".($uid?" AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=$uid AND b.blocked_user_id=a.user_id) OR (b.blocker_user_id=a.user_id AND b.blocked_user_id=$uid))":"")." ORDER BY a.published_at DESC LIMIT 50";
        $q=$pdo->prepare($sql);$q->execute([$source]);
    }else{
        $u=require_api_user($pdo);
        $sql=$common."JOIN follows f ON f.followed_user_id=a.user_id WHERE f.follower_user_id=? AND a.visibility='public' AND a.status='published' AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=? AND b.blocked_user_id=a.user_id) OR (b.blocker_user_id=a.user_id AND b.blocked_user_id=?)) ORDER BY a.published_at DESC LIMIT 50";
        $q=$pdo->prepare($sql);$q->execute([$u['id'],$u['id'],$u['id']]);
    }
    json_response(['ok'=>true,'data'=>['annotations'=>$q->fetchAll()]]);
}

if($action==='follow'){
    $u=require_api_mutation_auth($pdo);$public=(string)($input['user_id']??'');
    $q=$pdo->prepare('SELECT id FROM users WHERE public_id=? AND status="active"');$q->execute([$public]);$target=(int)($q->fetchColumn()?:0);
    if(!$target||$target===(int)$u['id'])json_response(['ok'=>false,'error'=>['code'=>'INVALID_USER']],422);
    if(is_blocked($pdo,(int)$u['id'],$target))json_response(['ok'=>false,'error'=>['code'=>'BLOCKED']],403);
    $q=$pdo->prepare('SELECT 1 FROM follows WHERE follower_user_id=? AND followed_user_id=?');$q->execute([$u['id'],$target]);
    if($q->fetchColumn()){$pdo->prepare('DELETE FROM follows WHERE follower_user_id=? AND followed_user_id=?')->execute([$u['id'],$target]);$following=false;}
    else{$pdo->prepare('INSERT INTO follows(follower_user_id,followed_user_id) VALUES(?,?)')->execute([$u['id'],$target]);$following=true;notify_user($pdo,$target,(int)$u['id'],'new_follower','user',$u['public_id'],$u['display_name'].' followed you.');}
    json_response(['ok'=>true,'data'=>['following'=>$following]]);
}

if($action==='save'){
    $u=require_api_mutation_auth($pdo);$a=ext_annotation($pdo,(string)($input['annotation_id']??''));if(!$a)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
    $q=$pdo->prepare('SELECT 1 FROM saved_annotations WHERE user_id=? AND annotation_id=?');$q->execute([$u['id'],$a['id']]);
    if($q->fetchColumn()){$pdo->prepare('DELETE FROM saved_annotations WHERE user_id=? AND annotation_id=?')->execute([$u['id'],$a['id']]);$saved=false;}
    else{$pdo->prepare('INSERT INTO saved_annotations(user_id,annotation_id) VALUES(?,?)')->execute([$u['id'],$a['id']]);$saved=true;}
    json_response(['ok'=>true,'data'=>['saved'=>$saved]]);
}

if($action==='comment'){
    $u=require_api_mutation_auth($pdo);$body=trim((string)($input['body']??''));if($body===''||mb_strlen($body)>5000)json_response(['ok'=>false,'error'=>['code'=>'INVALID_COMMENT']],422);
    $a=ext_annotation($pdo,(string)($input['annotation_id']??''));if(!$a)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);if(is_blocked($pdo,(int)$u['id'],(int)$a['user_id']))json_response(['ok'=>false,'error'=>['code'=>'BLOCKED']],403);
    $pdo->prepare('INSERT INTO comments(annotation_id,user_id,body) VALUES(?,?,?)')->execute([$a['id'],$u['id'],$body]);
    if((int)$a['user_id']!==(int)$u['id'])notify_user($pdo,(int)$a['user_id'],(int)$u['id'],'comment','annotation',$a['public_id'],$u['display_name'].' commented on your annotation.');
    json_response(['ok'=>true,'data'=>['created'=>true]],201);
}

if($action==='watch_source'){
    $u=require_api_mutation_auth($pdo);$sourceId=ext_source_id($pdo,(string)($input['source']??''));if(!$sourceId)json_response(['ok'=>false,'error'=>['code'=>'SOURCE_NOT_FOUND']],404);
    $q=$pdo->prepare('SELECT 1 FROM source_watches WHERE source_id=? AND user_id=?');$q->execute([$sourceId,$u['id']]);
    if($q->fetchColumn()){$pdo->prepare('DELETE FROM source_watches WHERE source_id=? AND user_id=?')->execute([$sourceId,$u['id']]);$watched=false;}else{$pdo->prepare('INSERT INTO source_watches(source_id,user_id) VALUES(?,?)')->execute([$sourceId,$u['id']]);$watched=true;}
    json_response(['ok'=>true,'data'=>['watched'=>$watched]]);
}
