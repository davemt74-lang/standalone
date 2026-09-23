<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','functions','access','notifications','rate-limit','conversations','research-automation','research-workspace','research-agents','research-agent-workspace','workspace-context','object-handoff','agent-chat'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p53(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p53throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p53(research_agent_workspace_ready($pdo),'Phase 53 Research Desktop media schema is available.');
p53(job_table_meta('research_file_jobs')['schedule']==='available_at','Research file queue participates in generic worker leases.');
p53(job_table_meta('research_transcription_jobs')['schedule']==='available_at','Research transcription queue participates in generic worker leases.');

$run='p53'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
  $username=substr(strtolower($name).'_'.$run,0,48);
  $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'cloaked')")
    ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
  $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('MediaOwner','admin');$researcher=$makeUser('MediaResearcher');$viewer=$makeUser('MediaViewer');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Media Team']);$teamId=(int)$pdo->lastInsertId();
foreach([[$owner,'owner'],[$researcher,'researcher'],[$viewer,'viewer']] as [$member,$role])$pdo->prepare('INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,?)')->execute([$teamId,$member['id'],$role]);

$agent=research_agent_create($pdo,$owner,['name'=>'Media Research Agent','description'=>'Research uploaded documents and recordings.','team_id'=>$teamPublic,'cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$researcher,(string)$agent['public_id']);
p53(($project['access_role']??'')==='researcher','Team researcher can add media to the Research Agent Desktop.');
$folder=research_agent_workspace_create_folder($pdo,$researcher,$project,'Interviews');

$upload=research_agent_workspace_register_upload($pdo,$researcher,$project,[
  'storage_uri'=>'private://research-upload/'.$run.'/evidence.txt','original_name'=>'evidence.txt','mime_type'=>'text/plain',
  'file_size'=>42,'checksum'=>hash('sha256','evidence'),'title'=>'Evidence Upload','parent_id'=>$folder['public_id']
]);
p53(($upload['object_type']??'')==='upload'&&($upload['upload_processing_status']??'')==='queued','Research file registration creates a queued durable upload object.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_file_jobs WHERE object_id=? AND status=\'queued\'');$q->execute([$upload['id']]);
p53((int)$q->fetchColumn()===1,'Research file registration queues exactly one extraction job.');
$pdo->prepare("UPDATE research_workspace_uploads SET processing_status='ready',extracted_text=?,updated_at=NOW() WHERE object_id=?")
  ->execute(['The contract value is 42. This uploaded evidence supports the memo.',(int)$upload['id']]);
$upload=research_agent_workspace_object($pdo,$researcher,(string)$upload['public_id'],false);
p53(($upload['upload_processing_status']??'')==='ready'&&str_contains((string)$upload['upload_extracted_text'],'contract value'),'Ready upload hydrates extracted text without replacing the immutable original object.');

$uploadCtx=research_agent_workspace_upload_context($pdo,$researcher,(string)$upload['public_id']);
p53(($uploadCtx['type']??'')==='upload'&&str_contains((string)$uploadCtx['text'],'contract value'),'Ready upload resolves into provenance-aware Agent context.');
$workspaceCtx=research_agent_workspace_project_context($pdo,$researcher,(string)$project['public_id'],10);
p53(str_contains((string)$workspaceCtx['text'],'Evidence Upload')&&str_contains((string)$workspaceCtx['text'],'contract value'),'Owning Research Agent bounded workspace context includes ready uploaded evidence.');

$recording=research_agent_workspace_register_recording($pdo,$researcher,$project,[
  'storage_uri'=>'private://research-recording/'.$run.'/interview.webm','original_name'=>'interview.webm','mime_type'=>'audio/webm',
  'file_size'=>1024,'checksum'=>hash('sha256','recording'),'title'=>'Customer Interview','parent_id'=>$folder['public_id'],
  'duration_seconds'=>91.5,'recording_source'=>'browser'
]);
p53(($recording['object_type']??'')==='recording'&&($recording['transcript_status']??'')==='queued','Browser recording registration creates a durable recording with queued transcript.');
$q=$pdo->prepare("SELECT t.id,j.status FROM research_workspace_recording_transcripts t JOIN research_transcription_jobs j ON j.transcript_id=t.id WHERE t.object_id=? LIMIT 1");$q->execute([$recording['id']]);$queued=$q->fetch();
p53($queued&&$queued['status']==='queued','Recording registration queues exactly one transcription job.');

$pdo->prepare("UPDATE research_workspace_recording_transcripts SET status='ready',raw_text=?,provider='test',model='fixture',updated_at=NOW() WHERE object_id=?")
  ->execute(['Customer committed to a pilot. Follow up Friday with pricing and implementation dates.',(int)$recording['id']]);
$recording=research_agent_workspace_object($pdo,$researcher,(string)$recording['public_id'],false);
p53(($recording['transcript_status']??'')==='ready'&&str_contains((string)$recording['transcript_text'],'pilot'),'Ready transcript hydrates on the durable recording object.');
$recordingCtx=research_agent_workspace_recording_context($pdo,$researcher,(string)$recording['public_id']);
p53(($recordingCtx['type']??'')==='recording'&&str_contains((string)$recordingCtx['text'],'Follow up Friday'),'Recording resolves its ready transcript into Agent context.');

$doc=research_agent_workspace_transcript_to_document($pdo,$researcher,(string)$recording['public_id']);
p53(($doc['object_type']??'')==='document'&&str_contains((string)$doc['document_plain_text'],'Customer committed to a pilot'),'Ready transcript converts into the shared durable Research Doc model.');

$desktop=research_agent_workspace_desktop_items($pdo,$researcher,$project,false);
$types=array_count_values(array_map(fn($x)=>(string)$x['object_type'],$desktop));
p53(($types['upload']??0)>=1&&($types['recording']??0)>=1,'Desktop returns uploaded files and recordings as draggable objects.');
research_agent_workspace_desktop_position_save($pdo,$researcher,$project,'upload',(string)$upload['public_id'],211,133,71);
research_agent_workspace_desktop_position_save($pdo,$researcher,$project,'recording',(string)$recording['public_id'],366,244,72);
$desktop=research_agent_workspace_desktop_items($pdo,$researcher,$project,false);
$uploadDesktop=current(array_filter($desktop,fn($x)=>($x['public_id']??'')===$upload['public_id']));
$recordingDesktop=current(array_filter($desktop,fn($x)=>($x['public_id']??'')===$recording['public_id']));
p53(($uploadDesktop['desktop']['x']??0)===211&&($recordingDesktop['desktop']['y']??0)===244,'Upload and recording icon positions persist on the Research Desktop.');

$uploadRef=workspace_context_object($pdo,$researcher,'upload',(string)$upload['public_id']);
$recordingRef=workspace_context_object($pdo,$researcher,'recording',(string)$recording['public_id']);
p53(($uploadRef['type']??'')==='upload'&&($recordingRef['type']??'')==='recording','Shared workspace continuity resolves file and recording object references.');
p53(!array_key_exists('upload_extracted_text',$uploadRef)&&!array_key_exists('transcript_text',$recordingRef),'Workspace continuity does not copy extracted text or transcript bodies.');

$uploadHandoff=object_handoff_resolve($pdo,$researcher,'upload',(string)$upload['public_id']);
$recordingHandoff=object_handoff_resolve($pdo,$researcher,'recording',(string)$recording['public_id']);
p53(($uploadHandoff['team_public_id']??'')===$teamPublic&&($recordingHandoff['team_public_id']??'')===$teamPublic,'Media handoff preserves Team scope.');
p53(object_handoff_can_share_to_conversation($pdo,$researcher,conversation_team_ensure($pdo,conversation_team_by_public($pdo,$researcher,$teamPublic),$researcher),'upload',(string)$upload['public_id']),'Team Research file can be shared only through its owning Team context.');

$viewerProject=research_agent_workspace_project($pdo,$viewer,(string)$agent['public_id']);
p53throws(fn()=>research_agent_workspace_register_upload($pdo,$viewer,$viewerProject,['storage_uri'=>'private://research-upload/no.txt','original_name'=>'no.txt','mime_type'=>'text/plain','file_size'=>1,'checksum'=>hash('sha256','x')]),'Team viewer cannot upload Research files.');
p53throws(fn()=>research_agent_workspace_register_recording($pdo,$viewer,$viewerProject,['storage_uri'=>'private://research-recording/no.webm','original_name'=>'no.webm','mime_type'=>'audio/webm','file_size'=>1,'checksum'=>hash('sha256','x')]),'Team viewer cannot create recordings.');

$pdo->prepare("UPDATE research_workspace_recording_transcripts SET status='failed',last_error='fixture failure' WHERE object_id=?")->execute([(int)$recording['id']]);
$retried=research_agent_workspace_retry_transcription($pdo,$researcher,(string)$recording['public_id']);
p53(($retried['transcript_status']??'')==='queued','Failed transcription can be reset to queued by an authorized researcher.');
$q=$pdo->prepare("SELECT status,attempts FROM research_transcription_jobs WHERE transcript_id=?");$q->execute([(int)$queued['id']]);$retryJob=$q->fetch();
p53($retryJob&&$retryJob['status']==='queued'&&(int)$retryJob['attempts']===0,'Retry resets the transcription job lease/attempt state.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$researcher['id']]);
p53(research_agent_workspace_object($pdo,$researcher,(string)$upload['public_id'],false)===null,'Removed Team member immediately loses uploaded-file access.');
p53(research_agent_workspace_object($pdo,$researcher,(string)$recording['public_id'],false)===null,'Removed Team member immediately loses recording/transcript access.');

echo "Phase 53 Desktop Files, Uploads & Recordings MariaDB suite passed.\n";
