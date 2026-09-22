<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/installer.php';require_once $root.'/app/storage.php';require_once $root.'/app/functions.php';require_once $root.'/app/access.php';require_once $root.'/app/notifications.php';require_once $root.'/app/rate-limit.php';require_once $root.'/app/conversations.php';require_once $root.'/app/agent-chat.php';
function p12b(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p12bthrows(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}
$run='p12b'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('AgentOwner','admin');$other=$makeUser('AgentOther');$member=$makeUser('AgentMember');

$c=agent_chat_create($pdo,$owner,'First Agent Thread');
p12b(($c['conversation_type']??'')==='agent','Agent Chat creates an agent conversation');
p12b(agent_chat_access($pdo,$owner,$c['public_id'])!==null,'Agent conversation owner can reopen their chat');
p12b(agent_chat_access($pdo,$other,$c['public_id'])===null,'another user cannot guess or open someone else\'s Agent conversation');
$list=agent_chat_list($pdo,$owner);p12b(count(array_filter($list,fn($r)=>$r['public_id']===$c['public_id']))===1,'Agent conversation appears in owner history');
p12b(count(array_filter(agent_chat_list($pdo,$other),fn($r)=>$r['public_id']===$c['public_id']))===0,'Agent history is isolated per user');

$userMessage=conversation_message_create($pdo,$owner,$c['public_id'],'What changed?',null,'agent-client-'.$run);
$agentMessage=agent_chat_insert_agent_message($pdo,$c,'Here is the current summary.',(int)$userMessage['id']);
$pdo->prepare("INSERT INTO conversation_message_attachments(message_id,attachment_type,object_public_id,metadata_json) VALUES(?,?,?,?)")->execute([$userMessage['id'],'research',$pub('ctx'),json_encode(['label'=>'Research context'])]);
$rows=agent_chat_message_rows($pdo,$owner,$c['public_id']);
p12b(($rows['messages'][0]['role']??'')==='user'&&($rows['messages'][1]['role']??'')==='assistant','Agent message history preserves user and assistant roles');
p12b(($rows['messages'][1]['public_id']??'')===$agentMessage['public_id'],'Agent response remains linked in the persistent conversation');
p12b(count($rows['messages'][0]['attachments']??[])===1,'structured context attachments are returned with Agent messages');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Agent Context Team']);$teamId=(int)$pdo->lastInsertId();
foreach([[$owner,'owner'],[$member,'researcher']] as [$u,$role])$pdo->prepare('INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,?)')->execute([$teamId,$u['id'],$role]);
$teamCtx=agent_chat_context_item($pdo,$owner,'team',$teamPublic);p12b(($teamCtx['public_id']??'')===$teamPublic,'Agent Chat can attach an authorized Team as context');
p12b(agent_chat_context_item($pdo,$other,'team',$teamPublic)===null,'Agent Chat refuses Team context for non-members');

$projectPublic=$pub('project');$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title) VALUES(?,?,?)")->execute([$projectPublic,$owner['id'],'Agent Context Research']);
$researchCtx=agent_chat_context_item($pdo,$owner,'research',$projectPublic);p12b(($researchCtx['public_id']??'')===$projectPublic,'Agent Chat can attach an authorized Research project');
p12b(agent_chat_context_item($pdo,$other,'research',$projectPublic)===null,'Agent Chat refuses private Research context for unauthorized users');
$contextOptions=agent_chat_context_options($pdo,$owner);
p12b(count(array_filter($contextOptions['research']??[],fn($row)=>($row['public_id']??'')===$projectPublic))===1,'Agent context picker lists owned Research on strict MySQL-compatible ordering');
p12b(count(array_filter($contextOptions['teams']??[],fn($row)=>($row['public_id']??'')===$teamPublic))===1,'Agent context picker lists authorized Teams');

$source=ensure_source($pdo,'https://agent-context-'.$run.'.example.test/article','Agent public Annotation source');
$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,content_hash) VALUES(?,1,?,?,?)')->execute([$source['id'],$source['canonical_url'],'Agent public Annotation source',hash('sha256',$run)]);$versionId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$versionId,$source['id']]);
$capturePublic=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?,'text','Agent public evidence')")->execute([$capturePublic,$source['id'],$versionId,$owner['id']]);$captureId=(int)$pdo->lastInsertId();
$annotationPublic=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status) VALUES(?,?,?,?,?,'Agent public Annotation','public','published')")->execute([$annotationPublic,$owner['id'],$source['id'],$versionId,$captureId]);
p12b(agent_chat_context_item($pdo,$other,'annotation',$annotationPublic)!==null,'Agent Chat can attach an otherwise accessible public Annotation');
$pdo->prepare('INSERT INTO blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([$owner['id'],$other['id']]);
p12b(agent_chat_context_item($pdo,$other,'annotation',$annotationPublic)===null,'Agent Chat cannot use Annotation context to bypass a user block');
$pdo->prepare('DELETE FROM blocks WHERE blocker_user_id=? AND blocked_user_id=?')->execute([$owner['id'],$other['id']]);

$normalized=agent_chat_context_normalize($pdo,$owner,[['type'=>'team','public_id'=>$teamPublic],['type'=>'team','public_id'=>$teamPublic],['type'=>'research','public_id'=>$projectPublic]]);
p12b(count($normalized)===2,'Agent Chat deduplicates structured context before prompting');
p12bthrows(fn()=>agent_chat_context_normalize($pdo,$other,[['type'=>'team','public_id'=>$teamPublic]]),'Agent Chat rejects unavailable structured context rather than trusting the client');

$second=agent_chat_create($pdo,$owner,'Second Agent Thread');p12b($second['public_id']!==$c['public_id'],'New Chat creates a distinct persistent Agent conversation');
p12b(agent_chat_available($pdo,$owner),'administrator account is eligible for Agent Chat');
p12b(!agent_chat_available($pdo,$other),'free account does not silently consume Pro Agent Chat');

echo "Phase 12B Agent Chat Canvas MariaDB suite passed.\n";
