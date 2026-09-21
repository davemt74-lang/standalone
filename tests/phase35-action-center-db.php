<?php
declare(strict_types=1);

$root=dirname(__DIR__);$dsn=getenv('DB_DSN')?:'';$dbUser=getenv('DB_USER')?:'root';$dbPass=getenv('DB_PASS')?:'';
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','unified-activity','research-workflow','action-center'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p35(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p35type(array $center,string $type): ?array {foreach((array)($center['items']??[]) as $item)if(($item['source_type']??'')===$type)return $item;return null;}
function p35kind(array $center,string $kind): int {return count(array_filter((array)($center['items']??[]),fn($x)=>(string)($x['kind']??'')===$kind));}

$run='p35'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array {
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('ActionOwner');$viewer=$makeUser('ActionViewer');$outsider=$makeUser('ActionOutsider');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Action Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$viewer['id']]);
$team=['id'=>$teamId,'public_id'=>$teamPublic,'name'=>'Action Team','owner_user_id'=>$owner['id'],'access_role'=>'researcher','member_count'=>2];
$conversation=conversation_team_ensure($pdo,$team,$viewer);
$message=conversation_message_create($pdo,$owner,(string)$conversation['public_id'],'Phase 35 needs a response.',null,'phase35-'.$run);
p35($message['created'],'unread Team message fixture created');

$projectPublic=$pub('project');$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,team_id,title,description,status) VALUES(?,?,?,?,?,'active')")
  ->execute([$projectPublic,$owner['id'],$teamId,'Action Research','Phase 35 action fixture']);$projectId=(int)$pdo->lastInsertId();

$agentPublic=$pub('agent');$pdo->prepare("INSERT INTO conversations(public_id,conversation_type,created_by_user_id,title) VALUES(?,'agent',?,'Action Agent')")
  ->execute([$agentPublic,$viewer['id']]);$agentId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'owner')")->execute([$agentId,$viewer['id']]);
$assistantPublic=$pub('assistant');$pdo->prepare("INSERT INTO conversation_messages(public_id,conversation_id,user_id,sender_type,body) VALUES(?,?,NULL,'agent','Proposed action')")
  ->execute([$assistantPublic,$agentId]);$assistantId=(int)$pdo->lastInsertId();
$proposalPublic=$pub('proposal');
$pdo->prepare("INSERT INTO agent_action_proposals(public_id,conversation_id,assistant_message_id,proposed_by_user_id,project_id,capability_key,status,arguments_json,provenance_json,project_state_hash,dedupe_key,expires_at) VALUES(?,?,?,?,?,'research.create_note','pending','{"body":"Review this"}','{}',?,?,DATE_ADD(NOW(),INTERVAL 1 DAY))")
  ->execute([$proposalPublic,$agentId,$assistantId,$viewer['id'],$projectId,hash('sha256','state-'.$run),hash('sha256','dedupe-'.$run)]);

$center=action_center_compose($pdo,$viewer,null,100);
p35($center['ready']&&$center['total']>=3,'Action Center composes current actionable state');
$confirm=p35type($center,'pending_agent_action');$respond=p35type($center,'team_activity');$workflow=p35type($center,'research_workflow');
p35((bool)$confirm&&($confirm['kind']??'')==='confirm'&&($confirm['urgency']??'')==='high','pending Agent write routes to Confirm as high priority');
p35((bool)$respond&&($respond['kind']??'')==='respond','unread Team activity routes to Respond');
p35((bool)$workflow&&($workflow['kind']??'')==='continue'&&($workflow['workspace']['research_public_id']??'')===$projectPublic,'Research lifecycle next step routes to Continue with project context');
p35(($confirm['workspace']['agent_conversation_public_id']??'')===$agentPublic&&($confirm['workspace']['research_public_id']??'')===$projectPublic,'Agent confirmation carries Agent and Research workspace refs');
p35(($respond['workspace']['team_public_id']??'')===$teamPublic,'Team response carries Team workspace ref');

cognitive_feed_dismiss($pdo,$viewer,(string)$respond['key'],(string)$respond['source_type']);
$dismissed=action_center_compose($pdo,$viewer,null,100);
p35(p35type($dismissed,'team_activity')===null,'Action Center respects existing Cognitive Feed dismissal state');
cognitive_feed_restore($pdo,$viewer,(string)$respond['key']);
$restored=action_center_compose($pdo,$viewer,null,100);
p35(p35type($restored,'team_activity')!==null,'restoring the Cognitive observation restores the action');

$latestMessageId=(int)$message['message']['id'];
$pdo->prepare('UPDATE conversation_members SET last_read_message_id=?,last_read_at=NOW() WHERE conversation_id=? AND user_id=?')->execute([$latestMessageId,$conversation['id'],$viewer['id']]);
$readCenter=action_center_compose($pdo,$viewer,null,100);
p35(p35type($readCenter,'team_activity')===null,'reading Team messages resolves Respond without Action Center persistence');

$pdo->prepare("UPDATE agent_action_proposals SET status='rejected',rejected_at=NOW() WHERE public_id=? AND proposed_by_user_id=?")->execute([$proposalPublic,$viewer['id']]);
$rejectedCenter=action_center_compose($pdo,$viewer,null,100);
p35(p35type($rejectedCenter,'pending_agent_action')===null,'rejecting Agent proposal resolves Confirm without Action Center persistence');
p35(p35type($rejectedCenter,'research_workflow')!==null,'independent Research lifecycle work remains after unrelated actions resolve');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$viewer['id']]);
$revoked=action_center_compose($pdo,$viewer,null,100);
p35(p35type($revoked,'research_workflow')===null&&p35type($revoked,'team_activity')===null,'Team access revocation removes Team and Research actions immediately');

$outside=action_center_compose($pdo,$outsider,null,100);
p35($outside['total']===0,'outsider receives no actions from another user Team or Research');

$runtime=file_get_contents($root.'/app/action-center.php');
p35(!str_contains($runtime,'INSERT INTO ')&&!str_contains($runtime,'UPDATE ')&&!str_contains($runtime,'DELETE FROM '),'Action Center composer creates no persistence layer');
p35(!str_contains($runtime,'openai')&&!str_contains($runtime,'anthropic')&&!str_contains($runtime,'gemini'),'Action Center uses no separate AI ranking model');

echo "Phase 35 Unified Action Center & Attention Routing MariaDB suite passed.\n";
