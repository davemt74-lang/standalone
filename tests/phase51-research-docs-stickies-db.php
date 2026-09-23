<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','functions','access','notifications','rate-limit','conversations','research-automation','research-workspace','research-agents','research-agent-workspace','object-handoff','agent-actions','agent-chat'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p51(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p51throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p51(research_agent_workspace_ready($pdo),'Phase 51 Research Docs and sticky schema is available.');
$run='p51'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
    $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('DocsOwner','admin');$researcher=$makeUser('DocsResearcher');$viewer=$makeUser('DocsViewer');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Docs Team']);$teamId=(int)$pdo->lastInsertId();
foreach([[$owner,'owner'],[$researcher,'researcher'],[$viewer,'viewer']] as [$member,$role])$pdo->prepare('INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,?)')->execute([$teamId,$member['id'],$role]);
$agent=research_agent_create($pdo,$owner,['name'=>'Docs Research Agent','description'=>'Build durable research documents.','team_id'=>$teamPublic,'cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$researcher,(string)$agent['public_id']);
p51(($project['access_role']??'')==='researcher','Team researcher can write the Research Agent workspace.');

$doc=research_agent_workspace_create_document($pdo,$researcher,$project,[
  'title'=>'Evidence Memo','document_type'=>'memo','summary'=>'Initial memo summary',
  'content_html'=>'<h1 onclick="evil()">Evidence Memo</h1><p><strong>Safe evidence</strong></p><script>alert(1)</script><p><a href="javascript:alert(1)">bad</a></p><table><tr><td>Cell</td></tr></table>'
]);
p51(($doc['object_type']??'')==='document'&&(int)($doc['revision_number']??0)===1,'Manual Research document is persisted at revision 1.');
p51(!str_contains((string)$doc['content_html'],'script')&&!str_contains((string)$doc['content_html'],'onclick')&&!str_contains((string)$doc['content_html'],'javascript:'),'Stored rich document HTML strips executable content.');
p51(str_contains((string)$doc['content_html'],'<h1>')&&str_contains((string)$doc['content_html'],'<table>'),'Stored rich document HTML preserves approved research formatting.');

$saved=research_agent_workspace_save_document($pdo,$researcher,(string)$doc['public_id'],[
  'title'=>'Evidence Memo revised','content_html'=>'<h1>Evidence Memo</h1><p>Revised evidence and analysis.</p>','base_revision'=>1
]);
p51((int)$saved['revision_number']===2&&str_contains((string)$saved['document_plain_text'],'Revised evidence'),'Document autosave creates a new durable revision and plain-text retrieval view.');
p51(count(research_agent_workspace_document_revisions($pdo,$researcher,(string)$doc['public_id'],20))===2,'Document history contains both durable versions.');
p51throws(fn()=>research_agent_workspace_save_document($pdo,$researcher,(string)$doc['public_id'],['title'=>'Stale edit','content_html'=>'<p>stale</p>','base_revision'=>1]),'Stale document edits are rejected instead of overwriting newer work.');
$history=research_agent_workspace_document_revisions($pdo,$researcher,(string)$doc['public_id'],20);$old=end($history);
$restored=research_agent_workspace_restore_document_revision($pdo,$researcher,(string)$doc['public_id'],(string)$old['public_id'],2);
p51((int)$restored['revision_number']===3&&$restored['title']==='Evidence Memo','Restoring history creates a new current revision rather than rewriting history.');

$viewerProject=research_agent_workspace_project($pdo,$viewer,(string)$agent['public_id']);
$viewerDoc=research_agent_workspace_object($pdo,$viewer,(string)$doc['public_id'],false);
p51(($viewerDoc['object_type']??'')==='document','Team viewer can read Research documents.');
p51throws(fn()=>research_agent_workspace_save_document($pdo,$viewer,(string)$doc['public_id'],['title'=>'Viewer edit','content_html'=>'<p>no</p>','base_revision'=>3]),'Team viewer cannot edit Research documents.');

$sticky=research_agent_workspace_create_sticky($pdo,$researcher,$project,['body'=>'Verify source dates','color'=>'pink','x'=>120,'y'=>180,'width'=>280,'height'=>210]);
p51(($sticky['object_type']??'')==='sticky'&&($sticky['sticky_color']??'')==='pink','Floating sticky is persisted with its selected color.');
$sticky=research_agent_workspace_update_sticky($pdo,$researcher,(string)$sticky['public_id'],['body'=>'Verify source dates and author','color'=>'blue','x'=>333,'y'=>444,'width'=>310,'height'=>230,'z'=>17]);
p51((int)$sticky['sticky_x']===333&&(int)$sticky['sticky_y']===444&&(int)$sticky['sticky_width']===310&&(int)$sticky['sticky_height']===230&&(int)$sticky['sticky_z']===17&&$sticky['sticky_color']==='blue','Sticky drag position, size, z-order, text, and color persist.');
p51throws(fn()=>research_agent_workspace_update_sticky($pdo,$viewer,(string)$sticky['public_id'],['body'=>'viewer edit']),'Team viewer cannot edit floating sticky notes.');
research_agent_workspace_trash($pdo,$researcher,(string)$sticky['public_id']);
p51(count(array_filter(research_agent_workspace_stickies($pdo,$researcher,$project),fn($x)=>($x['public_id']??'')===$sticky['public_id']))===0,'Deleting a sticky moves it out of the active floating layer.');
research_agent_workspace_restore($pdo,$researcher,(string)$sticky['public_id']);
p51(count(array_filter(research_agent_workspace_stickies($pdo,$researcher,$project),fn($x)=>($x['public_id']??'')===$sticky['public_id']))===1,'Trashed sticky can be restored to the floating layer.');

$context=research_agent_workspace_document_context($pdo,$researcher,(string)$doc['public_id']);
p51(($context['type']??'')==='document'&&str_contains((string)$context['text'],'Evidence Memo'),'Research document resolves into Agent context.');
$handoff=object_handoff_resolve($pdo,$researcher,'document',(string)$doc['public_id']);
p51(($handoff['type']??'')==='document'&&($handoff['team_public_id']??'')===$teamPublic,'Research document handoff preserves Team scope.');

$workspaceRef=workspace_context_object($pdo,$researcher,'document',(string)$doc['public_id']);
p51(($workspaceRef['type']??'')==='document'&&($workspaceRef['research']['public_id']??'')===$agent['project_public_id'],'Shared workspace continuity resolves document by reference to its Research Agent project.');
p51(!array_key_exists('content_html',$workspaceRef)&&!array_key_exists('document_plain_text',$workspaceRef),'Workspace continuity stores document references without copying document content.');

$pdo->beginTransaction();
try{
  $agentDoc=research_agent_workspace_create_document($pdo,$researcher,$project,['title'=>'Agent Brief','document_type'=>'research_brief','body'=>"Finding one.\n\nFinding two.",'summary'=>'Agent-created summary'],true);
  $posted=research_agent_workspace_post_document_to_chat($pdo,$researcher,(string)$agentDoc['public_id'],null);
  p51(is_array($posted)&&!empty($posted['public_id']),'Agent document creation is safe inside an existing governed transaction and posts a new Agent message.');
  $pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
$rows=agent_chat_message_rows($pdo,$researcher,(string)$agent['conversation_public_id'],null,80);
$agentDocMessages=array_values(array_filter((array)($rows['messages']??[]),function($message)use($agentDoc){
    foreach((array)($message['attachments']??[]) as $attachment)if(($attachment['type']??'')==='document'&&($attachment['public_id']??'')===$agentDoc['public_id'])return true;
    return false;
}));
p51(count($agentDocMessages)===1,'Agent-created Research document is posted once into the owning main Agent chat feed.');
$attachment=$agentDocMessages[0]['attachments'][0]??[];
p51(($attachment['title']??'')==='Agent Brief'&&($attachment['created_by_agent']??false)===true,'Agent chat resolves the document attachment into a live rich-card object.');

$pdo->beginTransaction();
try{
  $agentSticky=agent_action_execute_capability($pdo,$researcher,$project,'research.create_sticky',['body'=>'Pin the unresolved source gap.','color'=>'purple']);
  $pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
$agentStickyRow=research_agent_workspace_object($pdo,$researcher,(string)$agentSticky['public_id'],false);
p51(($agentSticky['type']??'')==='sticky'&&($agentStickyRow['sticky_color']??'')==='purple'&&str_contains((string)$agentStickyRow['sticky_body'],'unresolved source gap'),'Governed Research Agent action can create a confirmed floating sticky note inside the existing action transaction.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$researcher['id']]);
p51(research_agent_workspace_object($pdo,$researcher,(string)$doc['public_id'],false)===null,'Removed Team member immediately loses Research document access.');
p51(research_agent_workspace_object($pdo,$researcher,(string)$sticky['public_id'],false)===null,'Removed Team member immediately loses sticky-note access.');

echo "Phase 51 Research Docs + Floating Sticky Notes MariaDB suite passed.\n";
