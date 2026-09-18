<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/storage.php';require_once $root.'/app/functions.php';require_once $root.'/app/access.php';require_once $root.'/app/notifications.php';require_once $root.'/app/source-integrity.php';require_once $root.'/app/live.php';require_once $root.'/app/moderation.php';
function p9(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p9throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}
$run='p9'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'visible')")->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Owner');$researcher=$makeUser('Researcher');$viewer=$makeUser('Viewer');$outsider=$makeUser('Outsider');$admin=$makeUser('Admin','admin');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 9 Team']);$teamId=(int)$pdo->lastInsertId();
foreach([[$owner,'owner'],[$viewer,'viewer']] as [$member,$role])$pdo->prepare('INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,?)')->execute([$teamId,$member['id'],$role]);

$source=ensure_source($pdo,'https://'.$run.'.example.test/story','Phase 9 Source');$sourceId=(int)$source['id'];
$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)')->execute([$sourceId,$source['canonical_url'],'Phase 9 Source','alpha bravo charlie delta echo foxtrot and zulu yankee whiskey victor uniform tango',hash('sha256','v1')]);$v1=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE sources SET current_version_id=?,status='current' WHERE id=?")->execute([$v1,$sourceId]);
$makeAnnotation=function(array $u,string $visibility,string $selected,?int $team=null)use($pdo,$pub,$sourceId,$v1): array{$cap=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?,'text',?)")->execute([$cap,$sourceId,$v1,$u['id'],$selected]);$capId=(int)$pdo->lastInsertId();$ann=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,team_id,status) VALUES(?,?,?,?,?,?,?,?,'published')")->execute([$ann,$u['id'],$sourceId,$v1,$capId,'Phase 9 annotation',$visibility,$team]);return ['id'=>(int)$pdo->lastInsertId(),'public_id'=>$ann,'user_id'=>(int)$u['id'],'source_id'=>$sourceId,'source_version_id'=>$v1];};
$annChanged=$makeAnnotation($owner,'public','alpha bravo charlie delta echo foxtrot');
$annMissing=$makeAnnotation($researcher,'public','zulu yankee whiskey victor uniform tango');
$annPrivate=$makeAnnotation($owner,'private','private secret passage');
$annTeam=$makeAnnotation($owner,'team','team secret passage',$teamId);

p9(notification_create($pdo,(int)$outsider['id'],(int)$owner['id'],'comment','annotation',$annChanged['public_id'],'First comment',['dedupe_key'=>'d1-'.$run,'group_key'=>'conversation:'.$annChanged['public_id'],'context'=>['annotation_public_id'=>$annChanged['public_id'],'conversation_public_id'=>$annChanged['public_id'],'source_public_id'=>$source['public_id']]]),'notification creation succeeds');
p9(!notification_create($pdo,(int)$outsider['id'],(int)$owner['id'],'comment','annotation',$annChanged['public_id'],'Duplicate',['dedupe_key'=>'d1-'.$run,'group_key'=>'conversation:'.$annChanged['public_id']]),'notification dedupe key suppresses retry');
p9(notification_create($pdo,(int)$outsider['id'],(int)$researcher['id'],'comment_reply','annotation',$annChanged['public_id'],'Second comment',['dedupe_key'=>'d2-'.$run,'group_key'=>'conversation:'.$annChanged['public_id'],'context'=>['annotation_public_id'=>$annChanged['public_id'],'conversation_public_id'=>$annChanged['public_id'],'source_public_id'=>$source['public_id']]]),'second grouped notification created');
$rows=notification_rows($pdo,$outsider,100,false);$group=array_values(array_filter($rows,fn($n)=>($n['object_public_id']??'')===$annChanged['public_id']))[0]??[];
p9((int)($group['group_count']??0)===2&&(!isset($group['id'])&&!isset($group['actor_user_id'])),'notification rows group updates and omit internal identity fields');
notification_mark_read($pdo,$outsider,(string)$group['public_id']);$q=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND group_key=? AND read_at IS NULL');$q->execute([$outsider['id'],'conversation:'.$annChanged['public_id']]);p9((int)$q->fetchColumn()===0,'marking grouped notification read marks the whole group');
notification_archive($pdo,$outsider,(string)$group['public_id']);$q=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND group_key=? AND archived_at IS NULL');$q->execute([$outsider['id'],'conversation:'.$annChanged['public_id']]);p9((int)$q->fetchColumn()===0,'archiving grouped notification archives the whole group');

$pdo->prepare('UPDATE user_preferences SET notify_comments=0 WHERE user_id=?')->execute([$outsider['id']]);p9(!notification_create($pdo,$outsider['id'],$owner['id'],'comment','annotation',$annChanged['public_id'],'Muted by preference',['dedupe_key'=>'pref-'.$run]),'comment preference suppresses notification creation');$pdo->prepare('UPDATE user_preferences SET notify_comments=1 WHERE user_id=?')->execute([$outsider['id']]);
notification_mute_set($pdo,$outsider,'source',$source['public_id'],'sources',true);p9(!notification_create($pdo,$outsider['id'],null,'source_integrity','source',$source['public_id'],'Muted source',['category'=>'sources','dedupe_key'=>'mute-'.$run,'context'=>['source_public_id'=>$source['public_id']]]),'source mute suppresses source notification');notification_mute_set($pdo,$outsider,'source',$source['public_id'],'sources',false);
$pdo->prepare('INSERT INTO blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([$outsider['id'],$owner['id']]);p9(!notification_create($pdo,$outsider['id'],$owner['id'],'new_follower','user',$owner['public_id'],'Blocked actor',['dedupe_key'=>'block-'.$run]),'blocked actor cannot create recipient notification');$pdo->prepare('DELETE FROM blocks WHERE blocker_user_id=? AND blocked_user_id=?')->execute([$outsider['id'],$owner['id']]);

notification_create($pdo,$outsider['id'],$owner['id'],'comment','annotation',$annPrivate['public_id'],'Private leak attempt',['dedupe_key'=>'private-'.$run]);
$privateRows=notification_rows($pdo,$outsider,100,false);p9(!in_array($annPrivate['public_id'],array_column($privateRows,'object_public_id'),true),'notification delivery revalidates private annotation access');
notification_create($pdo,$outsider['id'],$owner['id'],'team_annotation','annotation',$annTeam['public_id'],'Team leak attempt',['category'=>'team','dedupe_key'=>'team-out-'.$run]);
notification_create($pdo,$viewer['id'],$owner['id'],'team_annotation','annotation',$annTeam['public_id'],'Team member update',['category'=>'team','dedupe_key'=>'team-in-'.$run]);
p9(!in_array($annTeam['public_id'],array_column(notification_rows($pdo,$outsider,100,false),'object_public_id'),true),'Team notification is hidden from non-member');
p9(in_array($annTeam['public_id'],array_column(notification_rows($pdo,$viewer,100,false),'object_public_id'),true),'Team notification is visible to current Team member');

$newText='alpha bravo charlie revised material plus unrelated current source text';
$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,2,?,?,?,?)')->execute([$sourceId,$source['canonical_url'],'Phase 9 Source',$newText,hash('sha256','v2')]);$v2=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE sources SET current_version_id=?,status='edited' WHERE id=?")->execute([$v2,$sourceId]);
$pdo->prepare("INSERT INTO source_change_events(source_id,previous_version_id,new_version_id,change_type,target_changed,diff_summary) VALUES(?,?,?,'edited',1,'Phase 9 change')")->execute([$sourceId,$v1,$v2]);$eventId=(int)$pdo->lastInsertId();
$analysis=source_integrity_analyze_event($pdo,$eventId);p9($analysis['impact_type']==='passage_missing'&&$analysis['affected_annotation_count']===2,'source event records strongest impact and affected count');
$q=$pdo->prepare('SELECT a.public_id,sai.impact_type FROM source_annotation_impacts sai JOIN annotations a ON a.id=sai.annotation_id WHERE sai.source_change_event_id=?');$q->execute([$eventId]);$impactMap=[];foreach($q->fetchAll() as $x)$impactMap[$x['public_id']]=$x['impact_type'];
p9(($impactMap[$annChanged['public_id']]??'')==='passage_changed','partially matching annotation is classified passage_changed');
p9(($impactMap[$annMissing['public_id']]??'')==='passage_missing','missing annotation passage is classified passage_missing');
$pdo->prepare('INSERT IGNORE INTO source_watches(source_id,user_id) VALUES(?,?)')->execute([$sourceId,$outsider['id']]);source_integrity_notify_event($pdo,$eventId);source_integrity_notify_event($pdo,$eventId);
$q=$pdo->prepare("SELECT context_json FROM notifications WHERE user_id=? AND dedupe_key=?");$q->execute([$owner['id'],'source-change:'.$eventId]);$ownerCtx=json_decode((string)$q->fetchColumn(),true);$q->execute([$researcher['id'],'source-change:'.$eventId]);$researchCtx=json_decode((string)$q->fetchColumn(),true);$q->execute([$outsider['id'],'source-change:'.$eventId]);$watchCtx=json_decode((string)$q->fetchColumn(),true);
p9(($ownerCtx['integrity_state']??'')==='passage_changed','annotation author receives their own changed-passage state');
p9(($researchCtx['integrity_state']??'')==='passage_missing','annotation author receives their own missing-passage state');
p9(($watchCtx['integrity_state']??'')==='source_updated','watcher is not falsely told their passage disappeared');
$q=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND dedupe_key=?');$q->execute([$owner['id'],'source-change:'.$eventId]);p9((int)$q->fetchColumn()===1,'source integrity notifications dedupe repeated delivery');
p9(source_integrity_classify('alpha bravo',$newText,'unavailable')['impact_type']==='source_unavailable','unavailable source classification is explicit');
p9(source_integrity_classify('alpha bravo',$newText,'restored')['impact_type']==='source_restored','restored source classification is explicit');

$claim=rights_claim_create($pdo,$outsider,$annChanged['public_id'],'Outsider',$outsider['email'],'inaccurate_context','The context is inaccurate.');
p9(rights_claim_access($pdo,$claim['public_id'],null,$claim['tracking_token'])!==null,'claim tracking token grants claimant status access');
p9(rights_claim_access($pdo,$claim['public_id'],null,'wrong-token')===null,'wrong claim token is rejected');
p9(rights_claim_access($pdo,$claim['public_id'],$outsider,null)!==null,'signed-in claimant can access own claim');
rights_claim_update($pdo,$admin,$claim['id'],'rejected','Reviewed','Evidence does not support the requested action.');$claimRow=rights_claim_access($pdo,$claim['public_id'],$outsider,null);p9(($claimRow['status']??'')==='rejected','admin claim decision updates claimant-visible status');
rights_claim_appeal($pdo,$claimRow,$outsider,'Please review the preserved source version again.');$claimRow=rights_claim_access($pdo,$claim['public_id'],$outsider,null);p9(($claimRow['status']??'')==='appealed','claimant can appeal a final claim decision');
rights_claim_update($pdo,$admin,$claim['id'],'restricted','Reopened review','Temporarily restricted during review.');$q=$pdo->prepare('SELECT status FROM annotations WHERE id=?');$q->execute([$annChanged['id']]);p9($q->fetchColumn()==='restricted','rights claim restriction soft-restricts annotation without deleting evidence');
$q=$pdo->prepare('SELECT COUNT(*) FROM captures WHERE id=(SELECT capture_id FROM annotations WHERE id=?)');$q->execute([$annChanged['id']]);p9((int)$q->fetchColumn()===1,'rights restriction preserves captured evidence');
rights_claim_update($pdo,$admin,$claim['id'],'resolved','Resolved','Annotation restored after review.');$q=$pdo->prepare('SELECT status FROM annotations WHERE id=?');$q->execute([$annChanged['id']]);p9($q->fetchColumn()==='published','resolved restricted claim can restore annotation');

$commentPublic=$pub('comment');$pdo->prepare('INSERT INTO comments(public_id,annotation_id,user_id,body) VALUES(?,?,?,?)')->execute([$commentPublic,$annChanged['id'],$owner['id'],'Reportable comment']);$commentId=(int)$pdo->lastInsertId();
$report=moderation_report_create($pdo,$outsider,'comment',$commentPublic,'harassment','Review this comment.');$reportAgain=moderation_report_create($pdo,$outsider,'comment',$commentPublic,'harassment','Duplicate report.');
p9($report['created']&&!$reportAgain['created']&&$reportAgain['public_id']===$report['public_id'],'duplicate open report from same reporter is reused');
$q=$pdo->prepare('SELECT id FROM moderation_reports WHERE public_id=?');$q->execute([$report['public_id']]);$reportId=(int)$q->fetchColumn();moderation_report_update($pdo,$admin,$reportId,'actioned','remove','Removed after review.');
$q=$pdo->prepare('SELECT moderation_status FROM comments WHERE id=?');$q->execute([$commentId]);p9($q->fetchColumn()==='removed','moderator remove action soft-removes comment');
$q=$pdo->prepare('SELECT COUNT(*) FROM moderation_actions WHERE report_id=?');$q->execute([$reportId]);p9((int)$q->fetchColumn()===1,'moderation decision creates immutable audit action');
moderation_report_update($pdo,$admin,$reportId,'actioned','restore','Restored after secondary review.');$q=$pdo->prepare('SELECT moderation_status FROM comments WHERE id=?');$q->execute([$commentId]);p9($q->fetchColumn()==='visible','moderator restore action restores comment');

$sourceReport=moderation_report_create($pdo,$outsider,'source',$source['public_id'],'other','Review source listing.');$q=$pdo->prepare('SELECT id FROM moderation_reports WHERE public_id=?');$q->execute([$sourceReport['public_id']]);$sourceReportId=(int)$q->fetchColumn();moderation_report_update($pdo,$admin,$sourceReportId,'actioned','restrict','Restrict source during review.');
p9(source_access($pdo,$source['public_id'],null)===null,'restricted source is unavailable to anonymous viewers');
p9(source_access($pdo,$source['public_id'],$admin)!==null,'admin retains restricted source evidence access');
p9(annotation_access($pdo,$annChanged['public_id'],null)===null,'annotations on restricted source are not publicly accessible');
p9(annotation_access($pdo,$annChanged['public_id'],$admin)!==null,'admin retains annotation evidence access under restricted source');
moderation_report_update($pdo,$admin,$sourceReportId,'actioned','restore','Source restored.');p9(source_access($pdo,$source['public_id'],null)!==null,'restored source becomes publicly accessible again');

$publicRoom=live_room_scope($pdo,$owner,$source['public_id'],'public',null,false);$mentionId='mention-'.$run.'-123456';live_message_create($pdo,$owner,$publicRoom,'hello @'.$viewer['username'],null,$mentionId,'owner-live-'.$run,false,null);
$q=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND notification_type='live_mention'");$q->execute([$viewer['id']]);$mentionsBefore=(int)$q->fetchColumn();p9($mentionsBefore>=1,'authorized Live mention creates notification');
$pdo->prepare('INSERT INTO blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([$viewer['id'],$owner['id']]);live_message_create($pdo,$owner,$publicRoom,'again @'.$viewer['username'],null,'mention2-'.$run.'-12345','owner-live-'.$run,false,null);$q->execute([$viewer['id']]);p9((int)$q->fetchColumn()===$mentionsBefore,'blocked Live actor cannot deliver mention notification');$pdo->prepare('DELETE FROM blocks WHERE blocker_user_id=? AND blocked_user_id=?')->execute([$viewer['id'],$owner['id']]);

$teamRoom=live_room_scope($pdo,$owner,$source['public_id'],'team',$teamPublic,false);live_message_create($pdo,$owner,$teamRoom,'private team ping @'.$outsider['username'],null,'teammention-'.$run,'owner-live-'.$run,false,null);$q=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND notification_type='live_mention' AND body='You were mentioned in a Live room.'");$q->execute([$outsider['id']]);p9((int)$q->fetchColumn()===0,'non-member never receives Team Live mention notification');

echo "Phase 9 Notifications, Source Intelligence, Claims and Moderation MariaDB suite passed.\n";
