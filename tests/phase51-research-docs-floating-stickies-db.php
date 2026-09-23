<?php
declare(strict_types=1);

$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','functions','access','notifications','rate-limit','conversations','research-automation','agent-chat','research-agents','research-agent-workspace','workspace-context','object-handoff'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p51(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p51throws(callable $fn,string $message,string $contains=''): void {
    try{$fn();}catch(Throwable $e){if($contains!==''&&!str_contains($e->getMessage(),$contains))throw new RuntimeException('FAIL: '.$message.' (unexpected error: '.$e->getMessage().')');echo "PASS: $message\n";return;}
    throw new RuntimeException('FAIL: '.$message);
}

p51(research_agent_workspace_ready($pdo),'Phase 51 document and sticky workspace schema is available.');
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

$agent=research_agent_create($pdo,$owner,['name'=>'Docs Research Agent','description'=>'Build editable research documents.','team_id'=>$teamPublic,'cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$researcher,(string)$agent['public_id']);
p51(is_array($project)&&($project['access_role']??'')==='researcher','Team researcher receives write access to the Research Agent workspace.');

$folder=research_agent_workspace_create_folder($pdo,$researcher,$project,'Drafts');
$doc=research_agent_workspace_create_document($pdo,$researcher,$project,[
  'title'=>'Research Brief',
  'document_type'=>'research_brief',
  'parent_id'=>$folder['public_id'],
  'summary'=>'Initial evidence brief.',
  'content_html'=>'<h1>Research Brief</h1><script>alert(1)</script><p onclick="bad()">Safe <strong>evidence</strong>.</p><a href="javascript:alert(1)">bad link</a>'
]);
p51(($doc['object_type']??'')==='document'&&($doc['document_type']??'')==='research_brief'&&(int)($doc['revision_number']??0)===1,'Manual Research document is a first-class versioned workspace object.');
p51(($doc['parent_public_id']??'')===$folder['public_id'],'Research document can live inside an Agent folder.');
p51(!str_contains((string)$doc['content_html'],'<script')&&!str_contains((string)$doc['content_html'],'onclick=')&&!str_contains((string)$doc['content_html'],'javascript:'),'Research document HTML is sanitized before durable storage.');
p51(str_contains((string)$doc['document_plain_text'],'Safe evidence'),'Sanitized Research document keeps an Agent-retrievable plain-text representation.');

$viewerDoc=research_agent_workspace_object($pdo,$viewer,(string)$doc['public_id'],false);
p51(($viewerDoc['public_id']??'')===$doc['public_id'],'Team viewer can read a Team Research document.');
p51throws(fn()=>research_agent_workspace_save_document($pdo,$viewer,(string)$doc['public_id'],[
  'title'=>'Viewer edit','content_html'=>'<p>Viewer cannot save.</p>','base_revision'=>1
]),'Team viewer cannot edit a Research document.');

$updated=research_agent_workspace_save_document($pdo,$researcher,(string)$doc['public_id'],[
  'title'=>'Research Brief — Updated','content_html'=>'<h1>Research Brief</h1><p><strong>Updated</strong> evidence.</p>','summary'=>'Second version.','base_revision'=>1
]);
p51((int)$updated['revision_number']===2&&str_contains((string)$updated['document_plain_text'],'Updated evidence'),'Document autosave creates a new durable revision.');
p51throws(fn()=>research_agent_workspace_save_document($pdo,$researcher,(string)$doc['public_id'],[
  'title'=>'Stale edit','content_html'=>'<p>This must not overwrite version 2.</p>','base_revision'=>1
]),'Stale Team editor cannot silently overwrite a newer document revision.','changed in another session');

$revisions=research_agent_workspace_document_revisions($pdo,$researcher,(string)$doc['public_id'],20);
p51(count($revisions)>=2&&(int)$revisions[0]['revision_number']===2,'Document version history exposes newest revision first.');
$firstRevision=null;foreach($revisions as $revision)if((int)$revision['revision_number']===1){$firstRevision=$revision;break;}
p51(is_array($firstRevision),'Initial document revision remains available for history restore.');
$restored=research_agent_workspace_restore_document_revision($pdo,$researcher,(string)$doc['public_id'],(string)$firstRevision['public_id'],2);
p51((int)$restored['revision_number']===3&&($restored['title']??'')==='Research Brief','Restoring history creates a new current revision instead of rewriting prior history.');

$sticky=research_agent_workspace_create_sticky($pdo,$researcher,$project,[
  'body'=>'Check the primary source.','color'=>'pink','x'=>72,'y'=>128,'width'=>260,'height'=>210
]);
p51(($sticky['object_type']??'')==='sticky'&&($sticky['sticky_color']??'')==='pink'&&(int)$sticky['sticky_x']===72&&(int)$sticky['sticky_y']===128,'Floating sticky note persists body, color, and canvas position.');
$sticky=research_agent_workspace_update_sticky($pdo,$researcher,(string)$sticky['public_id'],[
  'body'=>'Check the primary source and quote.','color'=>'blue','x'=>240,'y'=>315,'width'=>300,'height'=>245,'z'=>44
]);
p51(($sticky['sticky_color']??'')==='blue'&&(int)$sticky['sticky_x']===240&&(int)$sticky['sticky_y']===315&&(int)$sticky['sticky_width']===300&&(int)$sticky['sticky_height']===245&&(int)$sticky['sticky_z']===44,'Drag, resize, recolor, edit, and z-order changes persist for a sticky note.');
$viewerProject=research_agent_workspace_project($pdo,$viewer,(string)$agent['public_id']);
$viewerStickies=research_agent_workspace_stickies($pdo,$viewer,$viewerProject);
p51(count(array_filter($viewerStickies,fn($row)=>($row['public_id']??'')===$sticky['public_id']))===1,'Team viewer can see floating sticky notes.');
p51throws(fn()=>research_agent_workspace_update_sticky($pdo,$viewer,(string)$sticky['public_id'],['body'=>'Viewer edit']), 'Team viewer cannot edit sticky notes.');

research_agent_workspace_trash($pdo,$researcher,(string)$sticky['public_id']);
p51(research_agent_workspace_object($pdo,$researcher,(string)$sticky['public_id'],false)===null,'Deleting a sticky uses existing Trash instead of hard-delete.');
research_agent_workspace_restore($pdo,$researcher,(string)$sticky['public_id']);
p51(research_agent_workspace_object($pdo,$researcher,(string)$sticky['public_id'],false)!==null,'Trashed sticky note can be restored.');

$documentContext=research_agent_workspace_document_context($pdo,$researcher,(string)$doc['public_id']);
p51(($documentContext['type']??'')==='document'&&str_contains((string)$documentContext['text'],'Research Brief'),'Research Agent can retrieve a permission-checked document as explicit context.');
$workspaceContext=workspace_context_object($pdo,$researcher,'document',(string)$doc['public_id']);
p51(($workspaceContext['type']??'')==='document'&&($workspaceContext['research']['public_id']??'')===$agent['project_public_id'],'Shared workspace state resolves a document by reference back to its Research Agent project.');

$conversation=agent_chat_access($pdo,$researcher,(string)$agent['conversation_public_id']);
p51(is_array($conversation),'Research Agent persistent conversation is available for Agent-created document delivery.');
$userMessage=conversation_message_create($pdo,$researcher,(string)$agent['conversation_public_id'],'Create a research memo from this evidence.',null,'p51-'.$run);
$assistant=agent_chat_insert_agent_message($pdo,$conversation,'I created a Research memo from the evidence.',(int)$userMessage['id']);

$pdo->beginTransaction();
try{
    $agentDoc=agent_action_execute_capability($pdo,$researcher,$project,'research.create_document',[
      'title'=>'Agent Evidence Memo','body'=>"Evidence summary\n\nThe source needs follow-up.",'summary'=>'Agent-generated working memo.','document_type'=>'memo'
    ]);
    research_agent_workspace_attach_document_to_agent_message($pdo,$researcher,(string)$agentDoc['public_id'],(int)$assistant['id']);
    $pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

$agentDocRow=research_agent_workspace_object($pdo,$researcher,(string)$agentDoc['public_id'],false);
p51((int)($agentDocRow['created_by_agent']??0)===1&&($agentDocRow['document_type']??'')==='memo','Governed Agent document creation persists an editable Agent-authored document.');
$rows=agent_chat_message_rows($pdo,$researcher,(string)$agent['conversation_public_id'],null,40);
$foundCard=false;
foreach((array)($rows['messages']??[]) as $message){
    if((int)($message['id']??0)!==(int)$assistant['id'])continue;
    foreach((array)($message['attachments']??[]) as $attachment){
        if(($attachment['type']??'')==='document'&&($attachment['public_id']??'')===$agentDoc['public_id']&&($attachment['available']??false)){$foundCard=true;break 2;}
    }
}
p51($foundCard,'Agent-created Research document persists on the originating Agent message as a resolvable rich document attachment.');
$handoff=object_handoff_resolve($pdo,$researcher,'document',(string)$agentDoc['public_id']);
p51(($handoff['type']??'')==='document'&&str_contains((string)$handoff['url'],'&doc='),'Document handoff opens back into the same Research Agent canvas.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$researcher['id']]);
p51(research_agent_workspace_object($pdo,$researcher,(string)$doc['public_id'],false)===null,'Removed Team member immediately loses Research document access.');
p51(research_agent_workspace_object($pdo,$researcher,(string)$sticky['public_id'],false)===null,'Removed Team member immediately loses sticky-note access.');

echo "Phase 51 Research Docs & Floating Sticky Notes MariaDB suite passed.\n";
