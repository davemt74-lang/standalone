<?php
declare(strict_types=1);

$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','functions','access','object-handoff','notifications','conversations'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p31(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p31throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

$run='p31'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array {
    $username=substr(strtolower($name).'_'.$run,0,48);$public=$pub('u');
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$public,$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('HandoffOwner');$member=$makeUser('HandoffMember');$outsider=$makeUser('HandoffOutsider');

$team1Public=$pub('team1');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$team1Public,$owner['id'],'Continuity Team']);$team1Id=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$team1Id,$owner['id'],$team1Id,$member['id']]);
$team1=['id'=>$team1Id,'public_id'=>$team1Public,'name'=>'Continuity Team','owner_user_id'=>$owner['id'],'access_role'=>'owner','member_count'=>2];
$conversation1=conversation_team_ensure($pdo,$team1,$owner);

$team2Public=$pub('team2');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$team2Public,$owner['id'],'Other Team']);$team2Id=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner')")->execute([$team2Id,$owner['id']]);
$team2=['id'=>$team2Id,'public_id'=>$team2Public,'name'=>'Other Team','owner_user_id'=>$owner['id'],'access_role'=>'owner','member_count'=>1];
$conversation2=conversation_team_ensure($pdo,$team2,$owner);

$source=ensure_source($pdo,'https://phase31-'.$run.'.example.test/article','Phase 31 source');
$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,content_hash) VALUES(?,1,?,?,?)')->execute([$source['id'],$source['canonical_url'],'Phase 31 source',hash('sha256',$run)]);
$versionId=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE sources SET current_version_id=?,status='current' WHERE id=?")->execute([$versionId,$source['id']]);

$makeAnnotation=function(string $visibility,?int $teamId,string $text)use($pdo,$owner,$source,$versionId,$pub): string {
    $capture=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?,'text',?)")
      ->execute([$capture,$source['id'],$versionId,$owner['id'],'Captured '.$text]);$captureId=(int)$pdo->lastInsertId();
    $annotation=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,team_id,status) VALUES(?,?,?,?,?,?,?,?,'published')")
      ->execute([$annotation,$owner['id'],$source['id'],$versionId,$captureId,$text,$visibility,$teamId]);return $annotation;
};
$publicAnn=$makeAnnotation('public',null,'Public continuity annotation');
$teamAnn=$makeAnnotation('team',$team1Id,'Team continuity annotation');
$privateAnn=$makeAnnotation('private',null,'Private continuity annotation');
$otherTeamAnn=$makeAnnotation('team',$team2Id,'Other Team annotation');

p31(object_handoff_resolve($pdo,$member,'annotation',$publicAnn)!==null,'Team member can resolve public Annotation handoff');
p31(object_handoff_resolve($pdo,$member,'annotation',$teamAnn)!==null,'Team member can resolve same-Team Annotation handoff');
p31(object_handoff_resolve($pdo,$member,'annotation',$privateAnn)===null,'Team member cannot resolve owner private Annotation handoff');
p31(object_handoff_resolve($pdo,$member,'annotation',$otherTeamAnn)===null,'Team member cannot resolve another Team Annotation handoff');

$publicMessage=conversation_message_create($pdo,$owner,$conversation1['public_id'],'Shared public Annotation.',null,'public-'.$run,[['type'=>'annotation','public_id'=>$publicAnn]]);
p31($publicMessage['created']&&count($publicMessage['attachments']??[])===1,'public Annotation can be attached to Team Chat as an object reference');
$teamMessage=conversation_message_create($pdo,$owner,$conversation1['public_id'],'Shared Team Annotation.',null,'team-'.$run,[['type'=>'annotation','public_id'=>$teamAnn]]);
p31($teamMessage['created']&&count($teamMessage['attachments']??[])===1,'same-Team Annotation can be attached to Team Chat');

p31throws(fn()=>conversation_message_create($pdo,$owner,$conversation1['public_id'],'Should fail',null,'private-'.$run,[['type'=>'annotation','public_id'=>$privateAnn]]),'private Annotation cannot be converted into a Team-visible attachment');
p31throws(fn()=>conversation_message_create($pdo,$owner,$conversation2['public_id'],'Should fail',null,'wrong-team-'.$run,[['type'=>'annotation','public_id'=>$teamAnn]]),'Team-only Annotation cannot be handed to a different Team conversation');

$otherPublic=conversation_message_create($pdo,$owner,$conversation2['public_id'],'Public cross-Team share.',null,'public-other-'.$run,[['type'=>'annotation','public_id'=>$publicAnn]]);
p31($otherPublic['created'],'public Annotation may be shared into another Team without changing Annotation visibility');

$rows=conversation_message_rows($pdo,$member,$conversation1['public_id']);$shared=array_values(array_filter($rows['messages'],fn($r)=>$r['public_id']===$publicMessage['public_id']))[0]??[];
$attachment=$shared['attachments'][0]??[];
p31(($attachment['available']??false)===true&&($attachment['public_id']??'')===$publicAnn&&str_contains((string)($attachment['url']??''),'annotation.php?id='),'authorized recipient receives a structured Annotation attachment');
p31(($attachment['preview']??'')==='Public continuity annotation','attachment display metadata is re-derived from the authoritative Annotation');

$q=$pdo->prepare('SELECT attachment_type,object_public_id,metadata_json FROM conversation_message_attachments WHERE message_id=?');$q->execute([$publicMessage['id']]);$stored=$q->fetch();
p31(($stored['attachment_type']??'')==='annotation'&&($stored['object_public_id']??'')===$publicAnn&&$stored['metadata_json']===null,'Team Chat persists only object type + public ID, not copied Annotation content');

$retry=conversation_message_create($pdo,$owner,$conversation1['public_id'],'Shared public Annotation.',null,'public-'.$run,[['type'=>'annotation','public_id'=>$publicAnn]]);
$q=$pdo->prepare('SELECT COUNT(*) FROM conversation_message_attachments WHERE message_id=?');$q->execute([$publicMessage['id']]);
p31(!$retry['created']&&(int)$q->fetchColumn()===1,'idempotent Team send does not duplicate object attachments');

$pdo->prepare("UPDATE annotations SET visibility='private',team_id=NULL WHERE public_id=?")->execute([$publicAnn]);
$rowsAfter=conversation_message_rows($pdo,$member,$conversation1['public_id']);$sharedAfter=array_values(array_filter($rowsAfter['messages'],fn($r)=>$r['public_id']===$publicMessage['public_id']))[0]??[];$afterAttachment=$sharedAfter['attachments'][0]??[];
p31(($afterAttachment['available']??true)===false&&!isset($afterAttachment['preview']),'later visibility restriction turns old Team attachment into an unavailable tombstone without stale content leakage');
$restrictedRetry=conversation_message_create($pdo,$owner,$conversation1['public_id'],'Shared public Annotation.',null,'public-'.$run,[['type'=>'annotation','public_id'=>$publicAnn]]);
p31(!$restrictedRetry['created']&&$restrictedRetry['deduplicated'],'retry of an already-sent attachment remains idempotent after later visibility restriction');
$ownerRows=conversation_message_rows($pdo,$owner,$conversation1['public_id']);$ownerShared=array_values(array_filter($ownerRows['messages'],fn($r)=>$r['public_id']===$publicMessage['public_id']))[0]??[];
p31(($ownerShared['attachments'][0]['available']??false)===true,'Annotation owner still resolves their private object without Team Chat granting anyone else access');

$pdo->prepare("UPDATE annotations SET visibility='public' WHERE public_id=?")->execute([$publicAnn]);
$restored=conversation_message_rows($pdo,$member,$conversation1['public_id']);$restoredRow=array_values(array_filter($restored['messages'],fn($r)=>$r['public_id']===$publicMessage['public_id']))[0]??[];
p31(($restoredRow['attachments'][0]['available']??false)===true,'restoring Annotation access restores the reference without rewriting the Team message');

$pdo->prepare('INSERT INTO blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([$owner['id'],$member['id']]);
p31(object_handoff_resolve($pdo,$member,'annotation',$publicAnn)===null,'user block prevents Annotation handoff resolution even when the Annotation is public');
$blockedRows=conversation_message_rows($pdo,$member,$conversation1['public_id']);$blockedShared=array_values(array_filter($blockedRows['messages'],fn($r)=>$r['public_id']===$publicMessage['public_id']))[0]??[];
p31(($blockedShared['attachments'][0]['available']??true)===false,'existing Team attachment becomes unavailable when author and recipient are blocked');
$pdo->prepare('DELETE FROM blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?)')->execute([$owner['id'],$member['id'],$member['id'],$owner['id']]);

$normalized=object_handoff_normalize_attachments($pdo,$owner,conversation_access($pdo,$owner,$conversation1['public_id']),[['type'=>'annotation','public_id'=>$publicAnn],['type'=>'annotation','public_id'=>$publicAnn],['type'=>'bogus','public_id'=>'x']]);
p31(count($normalized)===1,'handoff normalization deduplicates references and ignores unsupported object types');

p31(conversation_message_rows($pdo,$outsider,$conversation1['public_id'])===null,'outsider cannot use a shared object reference to discover Team Chat');
$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$team1Id,$member['id']]);
p31(conversation_message_rows($pdo,$member,$conversation1['public_id'])===null,'Team revocation removes conversation and handoff access immediately');

$runtime=file_get_contents($root.'/app/object-handoff.php');
p31(!str_contains($runtime,'INSERT INTO annotations')&&!str_contains($runtime,'UPDATE annotations')&&!str_contains($runtime,'DELETE FROM annotations'),'handoff runtime never mutates the authoritative Annotation');

echo "Phase 31 Unified Object Handoff & Continuity MariaDB suite passed.\n";
