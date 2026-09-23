<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','functions','access','notifications','rate-limit','conversations','research-automation','agent-chat','research-agents','research-agent-workspace','workspace-context','object-handoff'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p50(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p50throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p50(research_agent_workspace_ready($pdo),'Phase 50A Research Agent workspace schema is available.');
$run='p50'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
    $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};

$owner=$makeUser('WorkspaceOwner','admin');$researcher=$makeUser('WorkspaceResearcher');$viewer=$makeUser('WorkspaceViewer');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Workspace Team']);$teamId=(int)$pdo->lastInsertId();
foreach([[$owner,'owner'],[$researcher,'researcher'],[$viewer,'viewer']] as [$member,$role])$pdo->prepare('INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,?)')->execute([$teamId,$member['id'],$role]);

$agent=research_agent_create($pdo,$owner,['name'=>'Team Research Agent','description'=>'Track the bookmarked evidence.','team_id'=>$teamPublic,'cadence'=>'manual','timezone_name'=>'UTC']);
p50(!empty($agent['public_id'])&&!empty($agent['conversation_public_id']),'Team Research Agent creates one durable Agent conversation and workspace.');
p50(research_agent_access($pdo,$researcher,(string)$agent['public_id'])!==null,'current Team researcher can access the Team Research Agent.');
p50(agent_chat_access($pdo,$researcher,(string)$agent['conversation_public_id'])!==null,'current Team researcher can open the Agent chat even when added through live Team membership.');

$project=research_agent_workspace_project($pdo,$researcher,(string)$agent['public_id']);
p50(($project['access_role']??'')==='researcher','Team researcher inherits Research project write role in the Agent workspace.');
$rootFolder=research_agent_workspace_create_folder($pdo,$researcher,$project,'Sources');
$childFolder=research_agent_workspace_create_folder($pdo,$researcher,$project,'Primary evidence',(string)$rootFolder['public_id']);
p50(($childFolder['parent_public_id']??'')===$rootFolder['public_id'],'Research Agent workspace supports nested folders.');

$url='https://example.com/'.$run.'/story?utm_source=test&b=2&a=1';
$bookmark=research_agent_workspace_create_bookmark($pdo,$researcher,$project,[
  'url'=>$url,'title'=>'Evidence story','description'=>'Useful primary source for the Team.',
  'parent_id'=>$childFolder['public_id'],'preview_image_url'=>'https://example.com/preview.jpg','favicon_url'=>'https://example.com/favicon.ico'
]);
p50(($bookmark['object_type']??'')==='bookmark'&&($bookmark['parent_public_id']??'')===$childFolder['public_id'],'Manual bookmark is stored as a first-class object inside the selected folder.');
p50(!str_contains((string)$bookmark['canonical_url'],'utm_source')&&str_contains((string)$bookmark['canonical_url'],'a=1')&&str_contains((string)$bookmark['canonical_url'],'b=2'),'Bookmark identity uses the canonical URL and strips tracking parameters.');
$duplicate=research_agent_workspace_create_bookmark($pdo,$researcher,$project,['url'=>'https://example.com/'.$run.'/story?b=2&a=1','title'=>'Evidence story updated']);
p50($duplicate['public_id']===$bookmark['public_id'],'Saving the same canonical website is idempotent inside one Research Agent.');
$q=$pdo->prepare('SELECT COUNT(*) FROM project_sources WHERE project_id=? AND source_id=?');$q->execute([$project['id'],$bookmark['source_id']]);
p50((int)$q->fetchColumn()===1,'Research bookmark links the canonical Source into the Agent project evidence.');
$q=$pdo->prepare("SELECT COUNT(*) FROM source_monitor_jobs WHERE source_id=? AND status IN ('queued','processing')");$q->execute([$bookmark['source_id']]);
p50((int)$q->fetchColumn()===1,'Research bookmark queues the canonical Source for monitoring exactly once.');

$feed=research_agent_workspace_bookmark_feed($pdo,$researcher,30);
p50(count(array_filter($feed,fn($row)=>($row['public_id']??'')===$bookmark['public_id']))===1,'Team Research bookmark is visible in the authorized member Home feed.');
p50(count(array_filter(research_agent_workspace_bookmark_feed($pdo,$viewer,30),fn($row)=>($row['public_id']??'')===$bookmark['public_id']))===1,'Team viewer can read the Team Research bookmark in Home.');
$viewerProject=research_agent_workspace_project($pdo,$viewer,(string)$agent['public_id']);
p50(($viewerProject['access_role']??'')==='viewer','Team viewer resolves the Agent workspace as read-only.');
p50throws(fn()=>research_agent_workspace_create_folder($pdo,$viewer,$viewerProject,'Viewer write attempt'),'Team viewer cannot create workspace objects.');

$ctx=research_agent_workspace_bookmark_context($pdo,$researcher,(string)$bookmark['public_id']);
p50(($ctx['type']??'')==='bookmark'&&str_contains((string)$ctx['text'],'Evidence story updated'),'Bookmark resolves into explicit Agent Chat context with URL/title provenance.');
$workspaceCtx=workspace_context_object($pdo,$researcher,'bookmark',(string)$bookmark['public_id']);
p50(($workspaceCtx['research']['public_id']??'')===$agent['project_public_id'],'Shared workspace state resolves bookmark back to its Research Agent project.');
$handoff=object_handoff_resolve($pdo,$researcher,'bookmark',(string)$bookmark['public_id']);
p50(($handoff['type']??'')==='bookmark'&&($handoff['team_public_id']??'')===$teamPublic,'Unified handoff preserves Team scope for a Team bookmark.');

research_agent_workspace_move($pdo,$researcher,(string)$bookmark['public_id'],(string)$childFolder['public_id']);
$moved=research_agent_workspace_object($pdo,$researcher,(string)$bookmark['public_id'],false);
p50(($moved['parent_public_id']??'')===$childFolder['public_id'],'Bookmark can be moved into a nested folder.');

research_agent_workspace_trash($pdo,$researcher,(string)$rootFolder['public_id']);
p50(research_agent_workspace_object($pdo,$researcher,(string)$rootFolder['public_id'],false)===null,'Trashing a folder hides the folder from active workspace results.');
p50(research_agent_workspace_object($pdo,$researcher,(string)$childFolder['public_id'],false)===null&&research_agent_workspace_object($pdo,$researcher,(string)$bookmark['public_id'],false)===null,'Folder trash recursively hides nested folders and bookmarks.');
research_agent_workspace_restore($pdo,$researcher,(string)$rootFolder['public_id']);
p50(research_agent_workspace_object($pdo,$researcher,(string)$childFolder['public_id'],false)!==null&&research_agent_workspace_object($pdo,$researcher,(string)$bookmark['public_id'],false)!==null,'Restoring a folder recursively restores its nested workspace objects.');

$conversation=agent_chat_access($pdo,$researcher,(string)$agent['conversation_public_id']);
p50(is_array($conversation),'Research Agent chat resolves to its persistent conversation before message insertion.');
$userMessage=conversation_message_create($pdo,$researcher,(string)$agent['conversation_public_id'],'Please review the bookmark.',null,'p50-'.$run);
agent_chat_insert_agent_message($pdo,$conversation,'I reviewed the saved evidence.',(int)$userMessage['id']);
$chatFeed=research_agent_chat_feed($pdo,$researcher,(string)$agent['public_id'],6);
p50(count($chatFeed)>=2&&($chatFeed[count($chatFeed)-1]['speaker']??'')===$agent['name'],'Every Research Agent exposes its own persistent recent chat feed.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$researcher['id']]);
p50(research_agent_access($pdo,$researcher,(string)$agent['public_id'])===null,'removed Team member immediately loses Research Agent access.');
p50(agent_chat_access($pdo,$researcher,(string)$agent['conversation_public_id'])===null,'removed Team member immediately loses the Team Research Agent chat even if conversation membership was previously cached.');
p50(research_agent_workspace_object($pdo,$researcher,(string)$bookmark['public_id'],true)===null,'removed Team member immediately loses workspace/bookmark access.');
p50(count(array_filter(research_agent_workspace_bookmark_feed($pdo,$researcher,30),fn($row)=>($row['public_id']??'')===$bookmark['public_id']))===0,'removed Team member no longer sees the Team bookmark in Home feed.');

echo "Phase 50A Research Agent Workspace Core MariaDB suite passed.\n";
