<?php
declare(strict_types=1);

require_once __DIR__.'/feed.php';
require_once __DIR__.'/research-reports.php';

function public_discovery_annotation(PDO $pdo,string $publicId,?array $viewer): ?array {
    $access=annotation_access($pdo,$publicId,$viewer);if(!$access||$access['status']!=='published')return null;$uid=(int)($viewer['id']??0);if($uid&&(int)$access['user_id']!==$uid&&is_blocked($pdo,$uid,(int)$access['user_id']))return null;
    $flags=$uid?",EXISTS(SELECT 1 FROM follows f WHERE f.follower_user_id=$uid AND f.followed_user_id=a.user_id) is_following,EXISTS(SELECT 1 FROM saved_annotations sa WHERE sa.user_id=$uid AND sa.annotation_id=a.id) is_saved,EXISTS(SELECT 1 FROM source_watches sw WHERE sw.user_id=$uid AND sw.source_id=a.source_id) source_following":" ,0 is_following,0 is_saved,0 source_following";
    $sql="SELECT a.*,u.public_id author_public_id,u.username,u.display_name,u.bio author_bio,u.profile_image_url,
      s.public_id source_public_id,s.title source_title,s.domain,s.canonical_url,s.status source_record_status,s.current_version_id current_source_version_id,
      sv.version_number capture_version_number,sv.captured_at capture_version_captured_at,
      cv.version_number current_version_number,cv.captured_at current_version_captured_at,
      CASE WHEN a.source_version_id<>s.current_version_id THEN s.status ELSE 'current' END source_status,
      c.capture_type,c.selected_text,c.start_seconds,c.end_seconds,c.media_provider,c.provider_media_id,c.media_title,c.media_author,c.source_media_duration_seconds,c.screenshot_target_path,c.screenshot_context_path,
      md.storage_path media_path,md.processing_status media_status,md.resolution_label,
      at.status transcript_status,at.raw_text transcript_raw,at.edited_text transcript_edited,at.provider transcript_provider,at.model transcript_model,
      t.public_id team_public_id,t.name team_name
      $flags
      FROM annotations a JOIN users u ON u.id=a.user_id JOIN sources s ON s.id=a.source_id
      JOIN source_versions sv ON sv.id=a.source_version_id LEFT JOIN source_versions cv ON cv.id=s.current_version_id
      JOIN captures c ON c.id=a.capture_id LEFT JOIN media_derivatives md ON md.capture_id=c.id
      LEFT JOIN annotation_transcripts at ON at.annotation_id=a.id LEFT JOIN teams t ON t.id=a.team_id WHERE a.id=? LIMIT 1";
    $q=$pdo->prepare($sql);$q->execute([$access['id']]);$a=$q->fetch();if(!$a)return null;
    $a['screenshot_url']=!empty($a['screenshot_target_path'])?evidence_url($a['public_id'],'target'):null;
    $a['context_screenshot_url']=!empty($a['screenshot_context_path'])?evidence_url($a['public_id'],'context'):null;
    $a['audio_url']=!empty($a['audio_commentary_path'])?evidence_url($a['public_id'],'audio'):null;
    $a['media_url']=!empty($a['media_path'])?evidence_url($a['public_id'],'media'):null;
    $a['context_url']=feed_context_url($a);$a['source_changed']=(int)$a['source_version_id']!==(int)$a['current_version_id'];
    foreach(['is_following','is_saved','source_following','source_changed'] as $k)$a[$k]=(bool)$a[$k];
    unset($a['screenshot_target_path'],$a['screenshot_context_path'],$a['audio_commentary_path'],$a['media_path']);
    return $a;
}
function public_discovery_comments(PDO $pdo,array $annotation,?array $viewer): array {
    $thread=feed_comments($pdo,(string)$annotation['public_id'],$viewer);return $thread['comments']??[];
}
function public_discovery_source(PDO $pdo,string $publicId,?array $viewer): ?array {
    $source=source_access($pdo,$publicId,$viewer);if(!$source)return null;$uid=(int)($viewer['id']??0);
    $q=$pdo->prepare('SELECT s.*,cv.version_number current_version_number,cv.captured_at current_version_captured_at FROM sources s LEFT JOIN source_versions cv ON cv.id=s.current_version_id WHERE s.id=?');$q->execute([$source['id']]);$source=$q->fetch()?:$source;
    $source['followed']=$uid?feed_source_followed($pdo,(int)$source['id'],$uid):false;
    $source['annotations']=feed_annotation_rows($pdo,$viewer,'page',(int)$source['id'],null,30)['annotations'];
    $q=$pdo->prepare('SELECT id,version_number,captured_at,title,content_hash,screenshot_path FROM source_versions WHERE source_id=? ORDER BY version_number DESC LIMIT 50');$q->execute([$source['id']]);$source['versions']=$q->fetchAll();
    $source['events']=[];try{$q=$pdo->prepare('SELECT sce.*,pv.version_number previous_number,nv.version_number new_number FROM source_change_events sce LEFT JOIN source_versions pv ON pv.id=sce.previous_version_id JOIN source_versions nv ON nv.id=sce.new_version_id WHERE sce.source_id=? ORDER BY sce.created_at DESC LIMIT 30');$q->execute([$source['id']]);$source['events']=$q->fetchAll();}catch(PDOException $e){}
    $q=$pdo->prepare("SELECT COUNT(*) FROM annotations WHERE source_id=? AND visibility='public' AND status='published'");$q->execute([$source['id']]);$source['public_annotation_count']=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM annotations WHERE source_id=? AND visibility='public' AND status='published'");$q->execute([$source['id']]);$source['public_contributor_count']=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT COUNT(*) FROM source_watches WHERE source_id=?');$q->execute([$source['id']]);$source['follower_count']=(int)$q->fetchColumn();
    return $source;
}
function public_discovery_profile(PDO $pdo,string $username,?array $viewer): ?array {
    $q=$pdo->prepare("SELECT u.id,u.public_id,u.username,u.display_name,u.bio,u.website_url,u.profile_image_url,u.created_at,COALESCE(p.profile_visibility,'public') profile_visibility,COALESCE(p.search_visibility,1) search_visibility FROM users u LEFT JOIN user_preferences p ON p.user_id=u.id WHERE u.username=? AND u.status='active' LIMIT 1");$q->execute([$username]);$p=$q->fetch();if(!$p)return null;
    $owner=$viewer&&(int)$viewer['id']===(int)$p['id'];if($p['profile_visibility']!=='public'&&!$owner)return null;if($viewer&&!$owner&&is_blocked($pdo,(int)$viewer['id'],(int)$p['id']))return null;
    $uid=(int)($viewer['id']??0);$p['following']=false;if($uid&&$uid!==(int)$p['id']){$q=$pdo->prepare('SELECT 1 FROM follows WHERE follower_user_id=? AND followed_user_id=?');$q->execute([$uid,$p['id']]);$p['following']=(bool)$q->fetchColumn();}
    foreach(['followers'=>'SELECT COUNT(*) FROM follows WHERE followed_user_id=?','following_count'=>'SELECT COUNT(*) FROM follows WHERE follower_user_id=?','annotation_count'=>"SELECT COUNT(*) FROM annotations WHERE user_id=? AND visibility='public' AND status='published'",'source_count'=>"SELECT COUNT(DISTINCT source_id) FROM annotations WHERE user_id=? AND visibility='public' AND status='published'"] as $key=>$sql){$q=$pdo->prepare($sql);$q->execute([$p['id']]);$p[$key]=(int)$q->fetchColumn();}
    $q=$pdo->prepare("SELECT a.public_id,a.text_commentary,a.published_at,s.public_id source_public_id,s.title source_title,s.domain,c.selected_text,c.capture_type,c.media_provider,c.media_title,(SELECT COUNT(*) FROM comments cm WHERE cm.annotation_id=a.id) comment_count FROM annotations a JOIN sources s ON s.id=a.source_id JOIN captures c ON c.id=a.capture_id WHERE a.user_id=? AND a.visibility='public' AND a.status='published' ORDER BY a.id DESC LIMIT 40");$q->execute([$p['id']]);$p['annotations']=$q->fetchAll();
    $q=$pdo->prepare("SELECT rr.public_id,rr.title,rr.summary,rr.published_at,rv.version_number FROM research_reports rr JOIN research_report_versions rv ON rv.id=rr.current_version_id WHERE rr.created_by_user_id=? AND rr.visibility='public' AND rr.status='published' ORDER BY rr.published_at DESC LIMIT 20");$q->execute([$p['id']]);$p['reports']=$q->fetchAll();
    return $p;
}
function public_discovery_explore(PDO $pdo,?array $viewer=null): array {
    $uid=(int)($viewer['id']??0);$annotationBlock=$uid?" AND NOT EXISTS(SELECT 1 FROM blocks db WHERE (db.blocker_user_id=$uid AND db.blocked_user_id=a.user_id) OR (db.blocker_user_id=a.user_id AND db.blocked_user_id=$uid))":'';$reportBlock=$uid?" AND NOT EXISTS(SELECT 1 FROM blocks rb WHERE (rb.blocker_user_id=$uid AND rb.blocked_user_id=rr.created_by_user_id) OR (rb.blocker_user_id=rr.created_by_user_id AND rb.blocked_user_id=$uid))":'';
    $sources=$pdo->query("SELECT s.public_id,s.title,s.domain,s.canonical_url,s.status,COUNT(DISTINCT a.id) annotation_count,COUNT(DISTINCT a.user_id) contributor_count,COUNT(DISTINCT c.id) comment_count,MAX(a.published_at) last_activity FROM sources s JOIN annotations a ON a.source_id=s.id AND a.visibility='public' AND a.status='published' LEFT JOIN comments c ON c.annotation_id=a.id GROUP BY s.id ORDER BY last_activity DESC LIMIT 24")->fetchAll();
    $discussed=$pdo->query("SELECT s.public_id,s.title,s.domain,COUNT(c.id) comment_count,COUNT(DISTINCT a.id) annotation_count,MAX(c.created_at) last_comment FROM sources s JOIN annotations a ON a.source_id=s.id AND a.visibility='public' AND a.status='published' JOIN comments c ON c.annotation_id=a.id GROUP BY s.id HAVING COUNT(c.id)>0 ORDER BY comment_count DESC,last_comment DESC LIMIT 12")->fetchAll();
    $annotations=$pdo->query("SELECT a.public_id,a.text_commentary,a.published_at,u.username,u.display_name,s.public_id source_public_id,s.title source_title,s.domain,c.selected_text,c.capture_type,c.media_provider,c.media_title,(SELECT COUNT(*) FROM comments cm WHERE cm.annotation_id=a.id) comment_count FROM annotations a JOIN users u ON u.id=a.user_id JOIN sources s ON s.id=a.source_id JOIN captures c ON c.id=a.capture_id WHERE a.visibility='public' AND a.status='published'".$annotationBlock." ORDER BY a.id DESC LIMIT 24")->fetchAll();
    $media=$pdo->query("SELECT a.public_id,a.text_commentary,a.published_at,u.username,u.display_name,s.public_id source_public_id,s.title source_title,c.capture_type,c.media_provider,c.media_title,c.media_author,md.processing_status FROM annotations a JOIN users u ON u.id=a.user_id JOIN sources s ON s.id=a.source_id JOIN captures c ON c.id=a.capture_id LEFT JOIN media_derivatives md ON md.capture_id=c.id WHERE a.visibility='public' AND a.status='published' AND c.capture_type IN ('video_clip','audio_clip','image_region','page_region')".$annotationBlock." ORDER BY a.id DESC LIMIT 12")->fetchAll();
    $reports=$pdo->query("SELECT rr.public_id,rr.title,rr.summary,rr.published_at,rv.version_number,u.username,u.display_name FROM research_reports rr JOIN research_report_versions rv ON rv.id=rr.current_version_id JOIN users u ON u.id=rr.created_by_user_id WHERE rr.visibility='public' AND rr.status='published'".$reportBlock." ORDER BY rr.published_at DESC LIMIT 12")->fetchAll();
    return compact('sources','discussed','annotations','media','reports');
}
function public_discovery_search(PDO $pdo,string $term,?array $viewer=null): array {
    $out=['sources'=>[],'annotations'=>[],'people'=>[],'reports'=>[]];if(mb_strlen($term)<2)return $out;$like='%'.$term.'%';$uid=(int)($viewer['id']??0);$annotationBlock=$uid?" AND NOT EXISTS(SELECT 1 FROM blocks db WHERE (db.blocker_user_id=$uid AND db.blocked_user_id=a.user_id) OR (db.blocker_user_id=a.user_id AND db.blocked_user_id=$uid))":'';$userBlock=$uid?" AND NOT EXISTS(SELECT 1 FROM blocks ub WHERE (ub.blocker_user_id=$uid AND ub.blocked_user_id=u.id) OR (ub.blocker_user_id=u.id AND ub.blocked_user_id=$uid))":'';$reportBlock=$uid?" AND NOT EXISTS(SELECT 1 FROM blocks rb WHERE (rb.blocker_user_id=$uid AND rb.blocked_user_id=rr.created_by_user_id) OR (rb.blocker_user_id=rr.created_by_user_id AND rb.blocked_user_id=$uid))":'';
    $q=$pdo->prepare("SELECT s.public_id,s.title,s.domain,s.canonical_url,s.status,COUNT(a.id) annotation_count FROM sources s JOIN annotations a ON a.source_id=s.id AND a.visibility='public' AND a.status='published' WHERE s.title LIKE ? OR s.domain LIKE ? OR s.canonical_url LIKE ? GROUP BY s.id ORDER BY MAX(a.published_at) DESC LIMIT 20");$q->execute([$like,$like,$like]);$out['sources']=$q->fetchAll();
    $q=$pdo->prepare("SELECT DISTINCT a.public_id,a.text_commentary,a.published_at,u.username,u.display_name,s.public_id source_public_id,s.title source_title,c.selected_text,c.capture_type,COALESCE(t.edited_text,t.raw_text) transcript_text FROM annotations a JOIN users u ON u.id=a.user_id JOIN sources s ON s.id=a.source_id JOIN captures c ON c.id=a.capture_id LEFT JOIN annotation_transcripts t ON t.annotation_id=a.id WHERE a.visibility='public' AND a.status='published' AND (a.text_commentary LIKE ? OR c.selected_text LIKE ? OR t.raw_text LIKE ? OR t.edited_text LIKE ? OR s.title LIKE ?)".$annotationBlock." ORDER BY a.id DESC LIMIT 30");$q->execute([$like,$like,$like,$like,$like]);$out['annotations']=$q->fetchAll();
    $q=$pdo->prepare("SELECT u.username,u.display_name,u.bio FROM users u LEFT JOIN user_preferences p ON p.user_id=u.id WHERE u.status='active' AND COALESCE(p.profile_visibility,'public')='public' AND COALESCE(p.search_visibility,1)=1 AND (u.username LIKE ? OR u.display_name LIKE ? OR u.bio LIKE ?)".$userBlock." ORDER BY u.display_name LIMIT 20");$q->execute([$like,$like,$like]);$out['people']=$q->fetchAll();
    $q=$pdo->prepare("SELECT rr.public_id,rr.title,rr.summary,rr.published_at,rv.version_number,rv.snapshot_json,u.username,u.display_name FROM research_reports rr JOIN research_report_versions rv ON rv.id=rr.current_version_id JOIN users u ON u.id=rr.created_by_user_id WHERE rr.visibility='public' AND rr.status='published' AND (rr.title LIKE ? OR rr.summary LIKE ? OR rv.snapshot_json LIKE ?)".$reportBlock." ORDER BY rr.published_at DESC LIMIT 20");$q->execute([$like,$like,$like]);$reports=$q->fetchAll();foreach($reports as &$r){$snapshot=json_decode((string)$r['snapshot_json'],true);$matches=[];if(is_array($snapshot)){foreach(($snapshot['findings']??[]) as $f)if(stripos((string)($f['title'].' '.$f['summary']),$term)!==false)$matches[]=['label'=>'Finding: '.($f['title']??''),'anchor'=>'finding-'.($f['id']??'')];foreach(($snapshot['claims']??[]) as $c)if(stripos((string)($c['statement']??''),$term)!==false)$matches[]=['label'=>'Claim: '.($c['statement']??''),'anchor'=>'claim-'.($c['id']??'')];foreach(($snapshot['entities']??[]) as $e)if(stripos((string)(($e['name']??'').' '.($e['description']??'')),$term)!==false)$matches[]=['label'=>'Entity: '.($e['name']??''),'anchor'=>'entity-'.($e['id']??'')];}$r['matches']=array_slice($matches,0,3);unset($r['snapshot_json']);}unset($r);$out['reports']=$reports;
    return $out;
}
function public_discovery_meta_description(string $text,int $max=180): string {
    $text=trim((string)preg_replace('/\s+/u',' ',$text));return mb_strlen($text)>$max?mb_substr($text,0,$max-1).'…':$text;
}
function public_discovery_absolute_url(array $config,string $path): string {
    $base=rtrim((string)($config['app']['base_url']??''),'/');return $base!==''?$base.$path:$path;
}
