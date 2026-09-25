<?php
declare(strict_types=1);

$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','functions','access','notifications','rate-limit','conversations','research-automation','agent-chat','research-agents','research-agent-workspace','workspace-context','object-handoff'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p64(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p64throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p64(research_agent_workspace_ready($pdo),'Research Agent workspace is available for the Phase 64 journey.');
$run='p64'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
    $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('ContinuityOwner','admin');$researcher=$makeUser('ContinuityResearcher');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Continuity Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$researcher['id']]);

$agentA=research_agent_create($pdo,$owner,['name'=>'Continuity Agent A','description'=>'Primary workflow continuity fixture.','team_id'=>$teamPublic,'cadence'=>'manual','timezone_name'=>'UTC']);
$agentB=research_agent_create($pdo,$owner,['name'=>'Continuity Agent B','description'=>'Cross-workspace isolation fixture.','team_id'=>$teamPublic,'cadence'=>'manual','timezone_name'=>'UTC']);
$projectA=research_agent_workspace_project($pdo,$researcher,(string)$agentA['public_id']);
$projectB=research_agent_workspace_project($pdo,$researcher,(string)$agentB['public_id']);
p64(($projectA['access_role']??'')==='researcher'&&($projectB['access_role']??'')==='researcher','Team researcher resolves both authorized Research Agent workspaces.');

$folder=research_agent_workspace_create_folder($pdo,$researcher,$projectA,'Working set');
$docA=research_agent_workspace_create_document($pdo,$researcher,$projectA,['title'=>'Continuity Memo','content_html'=>'<p>Initial continuity evidence.</p>']);
$docB=research_agent_workspace_create_document($pdo,$researcher,$projectB,['title'=>'Other Workspace Memo','content_html'=>'<p>Must stay in workspace B.</p>']);

p64((research_agent_workspace_object_in_project($pdo,$researcher,$projectA,(string)$docA['public_id'],'document',false)['public_id']??'')===$docA['public_id'],'Active workspace resolves its own Research Doc.');
p64(research_agent_workspace_object_in_project($pdo,$researcher,$projectA,(string)$docB['public_id'],'document',false)===null,'Active workspace rejects an otherwise-authorized document from another Research Agent project.');

$base=(int)$docA['revision_number'];
$saved=research_agent_workspace_save_document($pdo,$researcher,(string)$docA['public_id'],[
    'title'=>'Continuity Memo',
    'content_html'=>'<h1>Continuity Memo</h1><p>Saved before navigation and available after return.</p>',
    'base_revision'=>$base
]);
p64((int)$saved['revision_number']===$base+1&&str_contains((string)$saved['document_plain_text'],'available after return'),'Document save creates a durable revision before navigation.');
p64throws(fn()=>research_agent_workspace_save_document($pdo,$researcher,(string)$docA['public_id'],[
    'title'=>'Stale write','content_html'=>'<p>stale</p>','base_revision'=>$base
]),'Stale browser/editor revision cannot overwrite newer Research Doc content.');

research_agent_workspace_desktop_move($pdo,$researcher,$projectA,'document',(string)$docA['public_id'],(string)$folder['public_id']);
unset($projectA,$saved);
$returnedProject=research_agent_workspace_project($pdo,$researcher,(string)$agentA['public_id']);
$returnedDoc=research_agent_workspace_object_in_project($pdo,$researcher,$returnedProject,(string)$docA['public_id'],'document',false);
p64($returnedDoc!==null&&($returnedDoc['parent_public_id']??'')===$folder['public_id']&&str_contains((string)$returnedDoc['document_plain_text'],'available after return'),'Returning later re-resolves the same document, revision content, and Desktop folder placement from authoritative IDs.');

$recording=research_agent_workspace_register_recording($pdo,$researcher,$returnedProject,[
    'storage_uri'=>'private://research-recording/'.$run.'/continuity.webm',
    'original_name'=>'continuity.webm','mime_type'=>'audio/webm','file_size'=>1024,
    'checksum'=>hash('sha256','phase64-recording'),'title'=>'Continuity Interview',
    'duration_seconds'=>73,'recording_source'=>'upload','parent_id'=>$folder['public_id']
]);
$pdo->prepare("UPDATE research_workspace_recording_transcripts SET status='ready',raw_text=?,provider='fixture',model='fixture',updated_at=NOW() WHERE object_id=?")
  ->execute(['The participant approved the pilot and assigned a Friday follow-up.',(int)$recording['id']]);
$recording=research_agent_workspace_object_in_project($pdo,$researcher,$returnedProject,(string)$recording['public_id'],'recording',false);
p64(($recording['transcript_status']??'')==='ready'&&str_contains((string)$recording['transcript_text'],'Friday follow-up'),'Ready recording transcript remains attached to the durable recording object.');

$transcriptDoc=research_agent_workspace_transcript_to_document($pdo,$researcher,(string)$recording['public_id']);
p64(str_contains((string)$transcriptDoc['document_plain_text'],'participant approved the pilot'),'Ready transcript converts into a durable Research Doc for continued work.');
p64(research_agent_workspace_object_in_project($pdo,$researcher,$projectB,(string)$transcriptDoc['public_id'],'document',false)===null,'Transcript-derived document cannot be resolved through another active Research Agent workspace.');

research_agent_workspace_trash($pdo,$researcher,(string)$docA['public_id']);
p64(research_agent_workspace_object_in_project($pdo,$researcher,$returnedProject,(string)$docA['public_id'],'document',false)===null,'Active-only resolution hides a trashed document.');
p64(research_agent_workspace_object_in_project($pdo,$researcher,$returnedProject,(string)$docA['public_id'],'document',true)!==null,'Explicit restore path can resolve the same trashed document without weakening normal active reads.');
research_agent_workspace_restore($pdo,$researcher,(string)$docA['public_id']);

$ctx=workspace_context_object($pdo,$researcher,'document',(string)$transcriptDoc['public_id']);
p64(($ctx['research']['public_id']??'')===$returnedProject['public_id'],'Agent/workspace handoff re-resolves the transcript-derived document to its authoritative Research project.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$researcher['id']]);
p64(research_agent_workspace_object_in_project($pdo,$researcher,$returnedProject,(string)$docA['public_id'],'document',false)===null,'Team removal immediately revokes return-later document access.');
p64(research_agent_workspace_object($pdo,$researcher,(string)$recording['public_id'],false)===null,'Team removal immediately revokes recording and transcript access.');

echo "Phase 64 Core Product Experience & End-to-End Workflow Hardening database journey passed.\n";
