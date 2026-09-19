<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/installer.php';require_once $root.'/app/storage.php';require_once $root.'/app/functions.php';require_once $root.'/app/access.php';require_once $root.'/app/notifications.php';require_once $root.'/app/conversations.php';
function p12(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p12throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}
$run='p12'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,?string $photo=null)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode,profile_image_url) VALUES(?,?,?,?,NOW(),'active','user','cloaked',?)")->execute([$pub('u'),$username,$name,$username.'@example.test',$photo]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Owner','/uploads/profiles/owner-test.jpg');$member=$makeUser('Member');$viewer=$makeUser('Viewer');$outsider=$makeUser('Outsider');$revoked=$makeUser('Revoked');

p12(conversation_runtime_ready($pdo),'conversation runtime reports ready after migration');
p12(conversation_presence_ready($pdo),'chat presence runtime reports ready after migration');
$presenceClient='presence-'.$run;
$ownerPresence=conversation_presence_touch($pdo,$owner,$presenceClient);
p12(($ownerPresence['effective_status']??'')==='online','automatic Chat Status is online while heartbeat is active');
$busy=conversation_status_update($pdo,$owner,'busy','Deep research');
p12(($busy['status_mode']??'')==='busy'&&($busy['effective_status']??'')==='busy'&&($busy['custom_status']??'')==='Deep research','busy Chat Status and custom status persist');
p12throws(fn()=>conversation_presence_touch($pdo,$owner,'bad'),'presence heartbeat rejects invalid client session ids');


$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 12A Team']);$teamId=(int)$pdo->lastInsertId();
foreach([[$owner,'owner'],[$member,'researcher'],[$viewer,'viewer'],[$revoked,'viewer']] as [$u,$role])$pdo->prepare('INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,?)')->execute([$teamId,$u['id'],$role]);
$team=['id'=>$teamId,'public_id'=>$teamPublic,'name'=>'Phase 12A Team','owner_user_id'=>$owner['id'],'access_role'=>'owner','member_count'=>4];

$c1=conversation_team_ensure($pdo,$team,$owner);$c2=conversation_team_ensure($pdo,$team,$owner);
$teamPresence=conversation_presence_rows($pdo,$member,array_merge($c1,['conversation_type'=>'team','team_id'=>$teamId]));
$ownerStatus=array_values(array_filter($teamPresence,fn($p)=>($p['username']??'')===$owner['username']))[0]??[];
p12(($ownerStatus['effective_status']??'')==='busy'&&($ownerStatus['custom_status']??'')==='Deep research','teammates receive busy/custom Chat Status from presence rows');
$invisible=conversation_status_update($pdo,$owner,'invisible','Heads down');
p12(($invisible['effective_status']??'')==='offline','Invisible status suppresses the online indicator while heartbeat remains active');
$teamPresence=conversation_presence_rows($pdo,$member,array_merge($c1,['conversation_type'=>'team','team_id'=>$teamId]));$ownerStatus=array_values(array_filter($teamPresence,fn($p)=>($p['username']??'')===$owner['username']))[0]??[];
p12(($ownerStatus['effective_status']??'')==='offline','teammates see Invisible users as offline');
$away=conversation_status_update($pdo,$owner,'away','Back soon');p12(($away['effective_status']??'')==='away','Away status is exposed while heartbeat is active');
$pdo->prepare('UPDATE chat_presence_sessions SET last_seen_at=DATE_SUB(NOW(),INTERVAL 2 MINUTE) WHERE user_id=?')->execute([$owner['id']]);conversation_presence_cleanup($pdo);
p12((conversation_status_get($pdo,(int)$owner['id'])['effective_status']??'')==='offline','stale heartbeat expires online presence after the 90-second window');
conversation_presence_touch($pdo,$owner,$presenceClient);conversation_presence_leave($pdo,$owner,$presenceClient);
p12((conversation_status_get($pdo,(int)$owner['id'])['effective_status']??'')==='offline','explicit chat leave clears online presence immediately');
conversation_status_update($pdo,$owner,'auto','');

p12((int)$c1['id']===(int)$c2['id'],'repeated Team Chat initialization resolves one canonical conversation');
$q=$pdo->prepare("SELECT COUNT(*) FROM conversations WHERE conversation_type='team' AND team_id=?");$q->execute([$teamId]);p12((int)$q->fetchColumn()===1,'one Team owns exactly one persistent conversation');
p12throws(fn()=>conversation_team_ensure($pdo,$team,$outsider),'conversation initialization independently requires Team membership');

$memberList=conversation_team_list($pdo,$member);p12(count($memberList)===1&&$memberList[0]['team_public_id']===$teamPublic,'team member sees their Team Chat in conversation list');
p12(count(conversation_team_list($pdo,$outsider))===0,'outsider does not discover Team Chat');

$m1=conversation_message_create($pdo,$owner,$c1['public_id'],'Team hello',null,'client-'.$run);
$m1Retry=conversation_message_create($pdo,$owner,$c1['public_id'],'Team hello',null,'client-'.$run);
p12($m1['created']&&!$m1Retry['created']&&$m1Retry['deduplicated'],'client message id makes Team Chat retries idempotent');
$q=$pdo->prepare('SELECT COUNT(*) FROM conversation_messages WHERE conversation_id=? AND client_message_id=?');$q->execute([$c1['id'],'client-'.$run]);p12((int)$q->fetchColumn()===1,'idempotent send produces one stored message');
$q=$pdo->prepare("SELECT category,object_type,object_public_id FROM notifications WHERE user_id=? AND notification_type='team_message' ORDER BY id DESC LIMIT 1");$q->execute([$member['id']]);$notification=$q->fetch();
p12(($notification['category']??'')==='team'&&($notification['object_type']??'')==='conversation'&&($notification['object_public_id']??'')===$c1['public_id'],'team message creates permission-aware team notification for other members');
$nq=$pdo->prepare("SELECT * FROM notifications WHERE user_id=? AND notification_type='team_message' ORDER BY id DESC LIMIT 1");$nq->execute([$member['id']]);$nrow=$nq->fetch();
p12(notification_object_access($pdo,$member,$nrow)===true&&notification_object_access($pdo,$outsider,$nrow)===false,'Team Chat notification access rechecks current Team membership');
p12(notification_url($pdo,$member,$nrow)==='/home.php?team='.rawurlencode($teamPublic).'#team-chat','Team Chat notification deep-links to the correct Home rail');

$memberRows=conversation_message_rows($pdo,$member,$c1['public_id']);$first=$memberRows['messages'][0]??[];
p12(($first['body']??'')==='Team hello','authorized member reads team message');
p12(($first['profile_image_url']??'')==='/uploads/profiles/owner-test.jpg','message payload carries sender profile photo');
p12(conversation_message_rows($pdo,$outsider,$c1['public_id'])===null,'outsider cannot read a guessed Team conversation id');

$memberList=conversation_team_list($pdo,$member);p12((int)$memberList[0]['unread_count']===1,'message from another member increments unread count');
p12(conversation_mark_read($pdo,$member,$c1['public_id'],'missing-'.$run)===false,'invalid read target is rejected instead of marking the whole conversation read');
$memberList=conversation_team_list($pdo,$member);p12((int)$memberList[0]['unread_count']===1,'invalid read target leaves unread state unchanged');
$ownerList=conversation_team_list($pdo,$owner);p12((int)$ownerList[0]['unread_count']===0,'own message does not count as unread');
p12(conversation_mark_read($pdo,$member,$c1['public_id'],$m1['public_id']),'member can mark Team Chat read');
$memberList=conversation_team_list($pdo,$member);p12((int)$memberList[0]['unread_count']===0,'mark read clears Team Chat unread count');

$reply=conversation_message_create($pdo,$member,$c1['public_id'],'Reply to owner',$m1['public_id'],'reply-'.$run);p12($reply['created'],'authorized member can reply inside Team Chat');
$rows=conversation_message_rows($pdo,$owner,$c1['public_id']);$replyRow=array_values(array_filter($rows['messages'],fn($r)=>$r['public_id']===$reply['public_id']))[0]??[];
p12(($replyRow['parent_public_id']??'')===$m1['public_id'],'reply payload preserves parent message context');

$team2Public=$pub('team2');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$team2Public,$owner['id'],'Second Team']);$team2Id=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner')")->execute([$team2Id,$owner['id']]);
$team2=['id'=>$team2Id,'public_id'=>$team2Public,'name'=>'Second Team','owner_user_id'=>$owner['id'],'access_role'=>'owner','member_count'=>1];$cOther=conversation_team_ensure($pdo,$team2,$owner);$other=conversation_message_create($pdo,$owner,$cOther['public_id'],'Other team message',null,'other-'.$run);
p12throws(fn()=>conversation_message_create($pdo,$owner,$c1['public_id'],'Bad cross-team reply',$other['public_id'],'cross-'.$run),'cross-Team reply target is rejected');

$viewerMessage=conversation_message_create($pdo,$viewer,$c1['public_id'],'Viewer can collaborate',null,'viewer-'.$run);p12($viewerMessage['created'],'viewer Team role can participate in Team Chat');

$q=$pdo->prepare("SELECT COUNT(*) FROM conversation_events WHERE conversation_id=? AND event_type='message_created'");$q->execute([$c1['id']]);p12((int)$q->fetchColumn()>=3,'message creation emits immutable conversation events');

p12(conversation_access($pdo,$revoked,$c1['public_id'])!==null,'current Team member initially resolves conversation');
$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$revoked['id']]);
p12(conversation_access($pdo,$revoked,$c1['public_id'])===null,'revoked Team member immediately loses conversation access');
$q=$pdo->prepare('SELECT COUNT(*) FROM conversation_members WHERE conversation_id=? AND user_id=?');$q->execute([$c1['id'],$revoked['id']]);conversation_access($pdo,$owner,$c1['public_id']);$q->execute([$c1['id'],$revoked['id']]);p12((int)$q->fetchColumn()===0,'membership sync removes stale revoked conversation member row');

$agentPublic=$pub('agent');$pdo->prepare("INSERT INTO conversations(public_id,conversation_type,created_by_user_id,title) VALUES(?,'agent',?,'Future Agent Chat')")->execute([$agentPublic,$owner['id']]);$agentId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'owner')")->execute([$agentId,$owner['id']]);
p12(conversation_access($pdo,$owner,$agentPublic)!==null,'explicit member can resolve future non-Team conversation');
p12(conversation_access($pdo,$outsider,$agentPublic)===null,'non-Team conversations require explicit membership and cannot be guessed');

echo "Phase 12A Unified Conversations + Team Chat MariaDB suite passed.\n";
