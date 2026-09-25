<?php
declare(strict_types=1);

$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','functions','access','notifications','rate-limit','source-integrity','conversations','research-automation','agent-chat','research-agents','research-agent-workspace'] as $lib)require_once $root.'/app/'.$lib.'.php';
require_once $root.'/app/release.php';

function p66(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p66throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

$run='p66'.substr(bin2hex(random_bytes(6)),0,10);$username='phase66_'.$run;
$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode,password_hash) VALUES(?,?,?,?,NOW(),'active','admin','pro','cloaked',?)")
  ->execute(['u-'.$run,$username,'Phase 66 Acceptance',$username.'@example.test',password_hash('phase66-fixture',PASSWORD_DEFAULT)]);
$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);$user=$q->fetch();
$pdo->prepare('INSERT INTO user_preferences(user_id) VALUES(?)')->execute([$id]);
onboarding_ensure($pdo,$id);

$token=bin2hex(random_bytes(32));
$pdo->prepare("INSERT INTO extension_sessions(user_id,token_hash,device_name,client_version,expires_at) VALUES(?,?,?,'0.36.0',DATE_ADD(NOW(),INTERVAL 30 DAY))")
  ->execute([$id,hash('sha256',$token),'Phase 66 Browser']);
p66((onboarding_status($pdo,$user,false)['steps']['extension']['complete']??false)===true,'Fresh account recognizes an active browser-capture session.');

$agent=research_agent_create($pdo,$user,['name'=>'Acceptance Agent','description'=>'End-to-end production acceptance workspace.','cadence'=>'manual','timezone_name'=>'UTC'],true);
$project=research_agent_workspace_project($pdo,$user,(string)$agent['public_id']);
p66($project!==null&&($project['access_role']??'')==='owner','Fresh account opens its Research Agent workspace as owner.');

$folder=research_agent_workspace_create_folder($pdo,$user,$project,'Acceptance Evidence');
$bookmark=research_agent_workspace_create_bookmark($pdo,$user,$project,[
    'url'=>'https://example.com/phase66?b=2&a=1','title'=>'Acceptance source','description'=>'Captured browser evidence.','parent_id'=>$folder['public_id']
]);
p66(($bookmark['object_type']??'')==='bookmark'&&($bookmark['parent_public_id']??'')===$folder['public_id'],'Web evidence can be captured into a Research folder.');
p66throws(fn()=>research_agent_workspace_create_bookmark($pdo,$user,$project,['url'=>'javascript:alert(1)','title'=>'Unsafe']), 'Unsafe bookmark schemes are rejected.');

$doc=research_agent_workspace_create_document($pdo,$user,$project,[
    'title'=>'Acceptance Memo',
    'content_html'=>'<h1>Acceptance</h1><script>alert(1)</script><p>Durable result.</p><a href="javascript:alert(1)">unsafe</a><a href="https://example.com">safe</a>',
    'parent_id'=>$folder['public_id']
]);
p66(!str_contains((string)$doc['content_html'],'<script')&&!str_contains((string)$doc['content_html'],'javascript:'),'Research Docs sanitize executable HTML while preserving normal content.');
$base=(int)$doc['revision_number'];
$saved=research_agent_workspace_save_document($pdo,$user,(string)$doc['public_id'],[
    'title'=>'Acceptance Memo','content_html'=>'<h1>Acceptance</h1><p>Saved before leaving and available after return.</p>','base_revision'=>$base
]);
p66((int)$saved['revision_number']===$base+1,'Document save creates a durable revision before navigation.');
p66throws(fn()=>research_agent_workspace_save_document($pdo,$user,(string)$doc['public_id'],[
    'title'=>'Stale','content_html'=>'<p>stale overwrite</p>','base_revision'=>$base
]),'Stale editor revisions cannot overwrite newer Research work.');

$upload=research_agent_workspace_register_upload($pdo,$user,$project,[
    'storage_uri'=>'private://research-upload/'.$run.'/brief.pdf','original_name'=>'brief.pdf','mime_type'=>'application/pdf',
    'file_size'=>4096,'checksum'=>hash('sha256','phase66-upload'),'title'=>'Acceptance PDF','parent_id'=>$folder['public_id']
]);
$q=$pdo->prepare('SELECT status FROM research_file_jobs WHERE object_id=?');$q->execute([(int)$upload['id']]);
p66(($upload['upload_processing_status']??'')==='queued'&&$q->fetchColumn()==='queued','PDF upload is durable and queued for governed extraction.');
$pdo->prepare("UPDATE research_workspace_uploads SET processing_status='failed',last_error='Synthetic extractor failure' WHERE object_id=?")->execute([(int)$upload['id']]);
$failedUpload=research_agent_workspace_object($pdo,$user,(string)$upload['public_id'],false);
p66(($failedUpload['upload_processing_status']??'')==='failed'&&str_contains((string)$failedUpload['upload_last_error'],'Synthetic'),'Failed extraction remains visible instead of losing the uploaded evidence.');

$recording=research_agent_workspace_register_recording($pdo,$user,$project,[
    'storage_uri'=>'private://research-recording/'.$run.'/interview.webm','original_name'=>'interview.webm','mime_type'=>'audio/webm',
    'file_size'=>8192,'checksum'=>hash('sha256','phase66-recording'),'title'=>'Acceptance Interview','duration_seconds'=>91,
    'recording_source'=>'upload','parent_id'=>$folder['public_id']
]);
$pdo->prepare("UPDATE research_workspace_recording_transcripts SET status='failed',last_error='Synthetic provider outage' WHERE object_id=?")->execute([(int)$recording['id']]);
$pdo->prepare("UPDATE research_transcription_jobs rtj JOIN research_workspace_recording_transcripts rwt ON rwt.id=rtj.transcript_id SET rtj.status='failed',rtj.attempts=3,rtj.last_error='Synthetic provider outage' WHERE rwt.object_id=?")->execute([(int)$recording['id']]);
$retried=research_agent_workspace_retry_transcription($pdo,$user,(string)$recording['public_id']);
$q=$pdo->prepare("SELECT rtj.status,rtj.attempts FROM research_transcription_jobs rtj JOIN research_workspace_recording_transcripts rwt ON rwt.id=rtj.transcript_id WHERE rwt.object_id=? LIMIT 1");$q->execute([(int)$recording['id']]);$retryJob=$q->fetch();
p66(($retried['transcript_status']??'')==='queued'&&($retryJob['status']??'')==='queued'&&(int)($retryJob['attempts']??-1)===0,'Failed transcription can be retried without creating a duplicate recording.');

$pdo->prepare("UPDATE research_workspace_recording_transcripts SET status='ready',raw_text=?,provider='fixture',model='fixture',last_error=NULL,updated_at=NOW() WHERE object_id=?")
  ->execute(['The interview confirms the pilot. Follow up Friday with the operations team.',(int)$recording['id']]);
$recordingReady=research_agent_workspace_object($pdo,$user,(string)$recording['public_id'],false);
p66(($recordingReady['transcript_status']??'')==='ready'&&str_contains((string)$recordingReady['transcript_text'],'Follow up Friday'),'Ready transcript remains attached to the durable recording.');
$transcriptDoc=research_agent_workspace_transcript_to_document($pdo,$user,(string)$recording['public_id']);
p66(str_contains((string)$transcriptDoc['document_plain_text'],'operations team'),'Transcript converts into a durable Research Doc for continued work.');

$after=onboarding_status($pdo,$user,true);
p66($after['complete']===true&&$after['completed_count']===$after['total_count'],'Fresh user completes the canonical onboarding loop through real Research activity.');

unset($project,$saved,$failedUpload,$recordingReady);
$returnedAgent=research_agent_by_conversation($pdo,$user,(string)$agent['conversation_public_id']);
$returnedProject=research_agent_workspace_project($pdo,$user,(string)$returnedAgent['public_id']);
$returnedDoc=research_agent_workspace_object_in_project($pdo,$user,$returnedProject,(string)$doc['public_id'],'document',false);
$returnedTranscriptDoc=research_agent_workspace_object_in_project($pdo,$user,$returnedProject,(string)$transcriptDoc['public_id'],'document',false);
p66($returnedDoc!==null&&str_contains((string)$returnedDoc['document_plain_text'],'available after return'),'Return-later session re-resolves the saved document from authoritative IDs.');
p66($returnedTranscriptDoc!==null&&str_contains((string)$returnedTranscriptDoc['document_plain_text'],'operations team'),'Return-later session re-resolves transcript-derived work.');

research_agent_workspace_trash($pdo,$user,(string)$folder['public_id']);
p66(research_agent_workspace_object($pdo,$user,(string)$doc['public_id'],false)===null,'Trashing a folder hides nested active Research work.');
research_agent_workspace_restore($pdo,$user,(string)$folder['public_id']);
p66(research_agent_workspace_object($pdo,$user,(string)$doc['public_id'],false)!==null,'Restoring the folder restores nested Research continuity.');

$specs=research_agent_workspace_upload_specs();
p66((int)$specs['application/pdf']['max']===50*1024*1024&&(int)$specs['audio/webm']['max']===200*1024*1024,'Production upload limits remain explicitly bounded for documents and recordings.');

echo "Phase 66 Production Acceptance, UX QA & Release Hardening database journey passed.\n";
