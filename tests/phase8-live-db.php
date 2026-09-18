<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/storage.php';require_once $root.'/app/functions.php';require_once $root.'/app/access.php';require_once $root.'/app/live.php';
function p8(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p8throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}
$run='p8'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Owner');$researcher=$makeUser('Researcher');$viewer=$makeUser('Viewer');$outsider=$makeUser('Outsider');$revoked=$makeUser('Revoked');$admin=$makeUser('Admin','admin');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 8 Team']);$teamId=(int)$pdo->lastInsertId();
foreach([[$owner,'owner'],[$researcher,'researcher'],[$viewer,'viewer'],[$revoked,'viewer']] as [$u,$role])$pdo->prepare('INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,?)')->execute([$teamId,$u['id'],$role]);
$projectPublic=$pub('project');$pdo->prepare('INSERT INTO research_projects(public_id,owner_user_id,team_id,title,description) VALUES(?,?,?,?,?)')->execute([$projectPublic,$owner['id'],$teamId,'Phase 8 Research','Live room project']);$projectId=(int)$pdo->lastInsertId();

$url='https://'.$run.'.example.test/live';$source=ensure_source($pdo,$url,'Phase 8 Source');$sourceId=(int)$source['id'];$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)')->execute([$sourceId,$source['canonical_url'],'Phase 8 Source','live body',hash('sha256','live body')]);$versionId=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE sources SET current_version_id=?,status='current' WHERE id=?")->execute([$versionId,$sourceId]);

$publicRoom=live_room_scope($pdo,$owner,$source['public_id'],'public',null);$teamRoom=live_room_scope($pdo,$owner,$source['public_id'],'team',$teamPublic);$projectRoom=live_room_scope($pdo,$owner,$source['public_id'],'project',$projectPublic);$viewerProject=live_room_scope($pdo,$viewer,$source['public_id'],'project',$projectPublic);
p8($publicRoom!==null&&$teamRoom!==null&&$projectRoom!==null,'owner resolves Public, Team, and Research rooms');
p8(live_room_scope($pdo,$outsider,$source['public_id'],'team',$teamPublic)===null,'outsider cannot guess Team room');
p8(live_room_scope($pdo,$outsider,$source['public_id'],'project',$projectPublic)===null,'outsider cannot guess Research room');
p8($viewerProject!==null&&!$viewerProject['can_post'],'Research viewer receives read-only room');
p8(live_room_scope($pdo,$viewer,$source['public_id'],'project',$projectPublic,true)===null,'Research viewer cannot resolve writable room');
p8($projectRoom['can_post']&&$projectRoom['can_moderate'],'Research owner can post and moderate');

$ownerSession='owner-session-'.$run;$researcherSession='research-session-'.$run;$viewerSession='viewer-session-'.$run;$outsiderSession='outside-session-'.$run;
live_presence_touch($pdo,$owner,$publicRoom,$ownerSession,'visible');
live_presence_touch($pdo,$researcher,live_room_scope($pdo,$researcher,$source['public_id'],'public'),$researcherSession,'cloaked');
live_presence_touch($pdo,$viewer,live_room_scope($pdo,$viewer,$source['public_id'],'public'),$viewerSession,'team_only');
$rows=live_presence_rows($pdo,$owner,$publicRoom,'visible');p8(count($rows['visible'])===2&&count($rows['cloaked'])===1,'team member sees visible and team-only identities while cloaked presence stays pseudonymous');
$cloak=$rows['cloaked'][0]??[];p8(isset($cloak['alias'])&&!isset($cloak['public_id'])&&!isset($cloak['username'])&&!isset($cloak['display_name']),'cloaked presence payload contains alias only');
$outsiderRoom=live_room_scope($pdo,$outsider,$source['public_id'],'public');live_presence_touch($pdo,$outsider,$outsiderRoom,$outsiderSession,'visible');$outsidePresence=live_presence_rows($pdo,$outsider,$outsiderRoom,'visible');
$viewerNamed=array_filter($outsidePresence['visible'],fn($r)=>($r['public_id']??'')===$viewer['public_id']);p8(count($viewerNamed)===0,'Team-only presence identity is hidden from outsiders');

live_presence_touch($pdo,$researcher,live_room_scope($pdo,$researcher,$source['public_id'],'public'),$researcherSession,'cloaked');live_presence_touch($pdo,$researcher,live_room_scope($pdo,$researcher,$source['public_id'],'public'),$researcherSession,'cloaked');
$q=$pdo->prepare('SELECT COUNT(*) FROM live_presence_sessions WHERE user_id=? AND client_session_id=?');$q->execute([$researcher['id'],$researcherSession]);p8((int)$q->fetchColumn()===1,'repeated heartbeat does not duplicate one client session');
$pdo->prepare("UPDATE live_presence_sessions SET last_seen_at=DATE_SUB(NOW(),INTERVAL 5 MINUTE) WHERE user_id=? AND client_session_id=?")->execute([$outsider['id'],$outsiderSession]);live_presence_cleanup($pdo);$q=$pdo->prepare('SELECT COUNT(*) FROM live_presence_sessions WHERE user_id=? AND client_session_id=?');$q->execute([$outsider['id'],$outsiderSession]);p8((int)$q->fetchColumn()===0,'stale Live presence is cleaned up');
$revokedProject=live_room_scope($pdo,$revoked,$source['public_id'],'project',$projectPublic);live_presence_touch($pdo,$revoked,$revokedProject,'revoked-session-'.$run,'cloaked');$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$revoked['id']]);live_presence_cleanup($pdo);$q=$pdo->prepare('SELECT COUNT(*) FROM live_presence_sessions WHERE user_id=?');$q->execute([$revoked['id']]);p8((int)$q->fetchColumn()===0,'revoked Research access removes Live presence immediately');


$owner=$pdo->query("SELECT * FROM users WHERE id=".(int)$owner['id'])->fetch();$researcher=$pdo->query("SELECT * FROM users WHERE id=".(int)$researcher['id'])->fetch();$viewer=$pdo->query("SELECT * FROM users WHERE id=".(int)$viewer['id'])->fetch();
$pdo->prepare("UPDATE users SET live_presence_mode='cloaked' WHERE id=?")->execute([$owner['id']]);$owner['live_presence_mode']='cloaked';live_presence_touch($pdo,$owner,$publicRoom,$ownerSession,'cloaked');
$m1=live_message_create($pdo,$owner,$publicRoom,'Cloaked hello',12.5,'msg-cloak-'.$run,$ownerSession,false,null);$m1dup=live_message_create($pdo,$owner,$publicRoom,'Cloaked hello',12.5,'msg-cloak-'.$run,$ownerSession,false,null);
p8($m1['created']&&!$m1dup['created']&&$m1dup['deduplicated'],'client message id makes retries idempotent');
$q=$pdo->prepare('SELECT COUNT(*) FROM live_messages WHERE user_id=? AND client_message_id=?');$q->execute([$owner['id'],'msg-cloak-'.$run]);p8((int)$q->fetchColumn()===1,'idempotent retry creates one database row');
$ownerRows=live_message_rows($pdo,$owner,$publicRoom,0,100)['messages'];$ownCloak=array_values(array_filter($ownerRows,fn($r)=>$r['public_id']===$m1['public_id']))[0]??[];
p8(($ownCloak['is_self']??false)===true&&!isset($ownCloak['author'])&&isset($ownCloak['cloak_alias']),'cloaked sender payload omits their own profile identity');

$mReveal=live_message_create($pdo,$owner,$publicRoom,'Visible hello',null,'msg-reveal-'.$run,$ownerSession,true,null);$outsiderRows=live_message_rows($pdo,$outsider,$outsiderRoom,0,100)['messages'];$visible=array_values(array_filter($outsiderRows,fn($r)=>$r['public_id']===$mReveal['public_id']))[0]??[];
p8(($visible['author']['public_id']??'')===$owner['public_id'],'explicit reveal includes visible message author');

$pdo->prepare("UPDATE users SET live_presence_mode='team_only' WHERE id=?")->execute([$viewer['id']]);$viewer['live_presence_mode']='team_only';live_presence_touch($pdo,$viewer,live_room_scope($pdo,$viewer,$source['public_id'],'public'),$viewerSession,'team_only');
$mTeamOnly=live_message_create($pdo,$viewer,live_room_scope($pdo,$viewer,$source['public_id'],'public'),'Team-only identity message',null,'msg-teamonly-'.$run,$viewerSession,false,null);
$ownerRows=live_message_rows($pdo,$owner,$publicRoom,0,100)['messages'];$ownerTeamOnly=array_values(array_filter($ownerRows,fn($r)=>$r['public_id']===$mTeamOnly['public_id']))[0]??[];$outsideRows=live_message_rows($pdo,$outsider,$outsiderRoom,0,100)['messages'];$outsideTeamOnly=array_values(array_filter($outsideRows,fn($r)=>$r['public_id']===$mTeamOnly['public_id']))[0]??[];
p8(($ownerTeamOnly['author']['public_id']??'')===$viewer['public_id']&&!isset($outsideTeamOnly['author']),'Team-only message identity is disclosed only to shared-team viewers');

$reply=live_message_create($pdo,$owner,$publicRoom,'Reply',null,'msg-reply-'.$run,$ownerSession,false,$mTeamOnly['public_id']);$replyRows=live_message_rows($pdo,$owner,$publicRoom,0,100)['messages'];$replyRow=array_values(array_filter($replyRows,fn($r)=>$r['public_id']===$reply['public_id']))[0]??[];
p8(($replyRow['parent_public_id']??'')===$mTeamOnly['public_id'],'Live replies remain attached to a message in the same room');
p8throws(fn()=>live_message_create($pdo,$owner,$teamRoom,'Bad cross-room reply',null,'msg-cross-'.$run,$ownerSession,false,$mTeamOnly['public_id']),'cross-room reply target is rejected');

p8(live_message_react($pdo,$owner,$publicRoom,$mTeamOnly['public_id'])===true,'Live reaction can be added');p8(live_message_react($pdo,$owner,$publicRoom,$mTeamOnly['public_id'])===false,'Live reaction toggles off');

p8throws(fn()=>live_message_pin($pdo,$owner,$publicRoom,$mTeamOnly['public_id']),'non-admin cannot moderate Public room');
p8(live_message_pin($pdo,$admin,live_room_scope($pdo,$admin,$source['public_id'],'public'),$mTeamOnly['public_id'])===true,'global admin can pin Public room message');
$researcherTeamRoom=live_room_scope($pdo,$researcher,$source['public_id'],'team',$teamPublic);$teamMessage=live_message_create($pdo,$researcher,$researcherTeamRoom,'Team message',null,'msg-team-'.$run,$researcherSession,false,null);
p8(live_message_pin($pdo,$owner,$teamRoom,$teamMessage['public_id'])===true,'Team owner can pin Team room message');
p8(live_message_delete($pdo,$researcher,$researcherTeamRoom,$teamMessage['public_id'])===true,'message author can delete own Live message');

$projectResearcher=live_room_scope($pdo,$researcher,$source['public_id'],'project',$projectPublic);p8($projectResearcher!==null&&$projectResearcher['can_post'],'Researcher can post in Research room');
live_presence_touch($pdo,$researcher,$projectResearcher,$researcherSession,'cloaked');$projectMessage=live_message_create($pdo,$researcher,$projectResearcher,'Project room message',null,'msg-project-'.$run,$researcherSession,false,null);p8(!empty($projectMessage['public_id']),'Research room accepts authorized researcher message');
p8throws(fn()=>live_message_create($pdo,$viewer,$viewerProject,'Read only attempt',null,'msg-viewer-'.$run,$viewerSession,false,null),'read-only Research role cannot post');

$pdo->prepare('INSERT INTO blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([$outsider['id'],$owner['id']]);$blockedRows=live_message_rows($pdo,$outsider,$outsiderRoom,0,100)['messages'];p8(!in_array($m1['public_id'],array_column($blockedRows,'public_id'),true)&&!in_array($mReveal['public_id'],array_column($blockedRows,'public_id'),true),'blocked relationships remove Live messages server-side');$pdo->prepare('DELETE FROM blocks WHERE blocker_user_id=? AND blocked_user_id=?')->execute([$outsider['id'],$owner['id']]);

$makeAnnotation=function(array $u,string $visibility,?int $team=null,string $text='note')use($pdo,$pub,$sourceId,$versionId): array{$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?,'text',?)")->execute([$pub('cap'),$sourceId,$versionId,$u['id'],$text]);$cap=(int)$pdo->lastInsertId();$ap=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,team_id,status) VALUES(?,?,?,?,?,?,?,?, 'published')")->execute([$ap,$u['id'],$sourceId,$versionId,$cap,$text,$visibility,$team]);$id=(int)$pdo->lastInsertId();return ['id'=>$id,'public_id'=>$ap];};
$publicAnn=$makeAnnotation($owner,'public',null,'public live event');$teamAnn=$makeAnnotation($owner,'team',$teamId,'team live event');$privateAnn=$makeAnnotation($owner,'private',null,'private live event');
$pdo->prepare('INSERT INTO project_annotations(project_id,annotation_id,added_by_user_id) VALUES(?,?,?)')->execute([$projectId,$privateAnn['id'],$owner['id']]);
live_event_emit_for_annotation($pdo,$publicAnn['id']);live_event_emit_for_annotation($pdo,$publicAnn['id']);live_event_emit_for_annotation($pdo,$teamAnn['id']);live_event_emit_for_annotation($pdo,$privateAnn['id']);
$publicEvents=live_event_rows($pdo,$outsider,$outsiderRoom,0,100)['events'];$teamEvents=live_event_rows($pdo,$owner,$teamRoom,0,100)['events'];$projectEvents=live_event_rows($pdo,$owner,$projectRoom,0,100)['events'];
p8(in_array($publicAnn['public_id'],array_column($publicEvents,'object_public_id'),true),'Public annotation event reaches Public room');
p8(!in_array($teamAnn['public_id'],array_column($publicEvents,'object_public_id'),true)&&!in_array($privateAnn['public_id'],array_column($publicEvents,'object_public_id'),true),'Team and Private annotation events never reach Public room');
p8(in_array($teamAnn['public_id'],array_column($teamEvents,'object_public_id'),true),'Team annotation event reaches its Team room');
p8(in_array($privateAnn['public_id'],array_column($projectEvents,'object_public_id'),true),'Private annotation event reaches authorized Research room only');
$q=$pdo->prepare("SELECT COUNT(*) FROM live_events WHERE event_key=?");$q->execute(['annotation:'.$publicAnn['id'].':public']);p8((int)$q->fetchColumn()===1,'Live event keys suppress duplicate annotation broadcasts');

$pdo->prepare("INSERT INTO source_change_events(source_id,previous_version_id,new_version_id,change_type,target_changed,diff_summary) VALUES(?,?,?,'updated',0,'Phase 8 source event')")->execute([$sourceId,$versionId,$versionId]);$changeId=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([$projectId,$sourceId,$owner['id']]);live_event_emit_source_change($pdo,$sourceId,$changeId);$publicEvents=live_event_rows($pdo,$outsider,$outsiderRoom,0,100)['events'];$projectEvents=live_event_rows($pdo,$owner,$projectRoom,0,100)['events'];
p8(count(array_filter($publicEvents,fn($e)=>$e['event_type']==='source_change'))>=1&&count(array_filter($projectEvents,fn($e)=>$e['event_type']==='source_change'))>=1,'source-change activity reaches Public and relevant Research rooms');

live_presence_leave($pdo,$owner,$ownerSession);$q=$pdo->prepare('SELECT COUNT(*) FROM live_presence_sessions WHERE user_id=? AND client_session_id=?');$q->execute([$owner['id'],$ownerSession]);p8((int)$q->fetchColumn()===0,'explicit Live leave removes client presence immediately');

echo "Phase 8 Live Rooms and Cloak Mode MariaDB suite passed.\n";
