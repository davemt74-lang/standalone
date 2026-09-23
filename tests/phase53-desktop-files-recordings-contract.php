<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use($root,&$fail): void{
    $path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}
    if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;
};
$avoid=function(string $file,string $needle,string $message)use($root,&$fail): void{
    $path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;
};

foreach([
 'database/migrations/20260923_051_research_desktop_uploads_recordings.sql',
 'docs/phase-53-desktop-files-recordings.md',
 'api/research-workspace-upload.php','research-workspace-file.php',
 'worker/research-file-worker.php','worker/research-transcription-worker.php',
 'tests/phase53-desktop-files-recordings-db.php'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 53 file missing: '.$file;

$migration=(string)file_get_contents($root.'/database/migrations/20260923_051_research_desktop_uploads_recordings.sql');
foreach(['research_workspace_uploads','research_workspace_recordings','research_workspace_recording_transcripts','research_file_jobs','research_transcription_jobs'] as $table)
    if(!str_contains($migration,'CREATE TABLE IF NOT EXISTS '.$table))$fail[]='Phase 53 table missing: '.$table;
$need('database/migrations/20260923_051_research_desktop_uploads_recordings.sql','folder_object_id BIGINT UNSIGNED','Linked/non-file Desktop objects must have durable folder placement.');


$need('home.php','data-research-desktop-upload','Research Desktop must expose + Upload.');
$need('home.php','data-research-desktop-recording','Research Desktop must expose + Recording.');
$need('home.php','data-research-recording-window','Desktop must provide a recording window.');
$need('home.php','data-research-transcript-window','Desktop must provide a transcript window.');
$need('home.php','Save &amp; Transcribe','Recording flow must clearly save and transcribe.');
$need('assets/js/research-agent-workspace-ui.js','new MediaRecorder','Browser recording must use MediaRecorder.');
$need('assets/js/research-agent-workspace-ui.js','getUserMedia({audio:true})','Recording must request microphone audio only.');
$need('assets/js/research-agent-workspace-ui.js',"surface?.addEventListener('drop'",'Desktop must accept OS drag/drop uploads.');
$need('assets/js/research-agent-workspace-ui.js','folderDropTarget','Internal Desktop drags must resolve folder drop targets.');
$need('assets/js/research-agent-workspace-ui.js','moveDesktopObject','Documents, bookmarks, uploads, recordings, folders, annotations, and stickies must share one folder-move path.');
$need('assets/js/research-agent-workspace-ui.js',"item.object_type==='annotation'",'Linked annotations must remain first-class movable Desktop objects.');
$need('assets/js/research-agent-workspace-ui.js',"object_type:'sticky'",'Sticky notes must support drag-to-folder.');
$need('assets/js/research-agent-workspace-ui.js',"stickies.filter(item=>String(item.parent_public_id||'')===String(currentFolder||''))",'Folder views must render contained sticky notes.');
$need('assets/css/app.css','.researchDesktopIcon[data-object-type="folder"].is-folder-drop-target','Folders must visibly highlight as drop targets.');
$need('app/research-agent-workspace.php','function research_agent_workspace_desktop_move','Server runtime must authorize and persist every Desktop folder move.');

$need('assets/js/research-agent-workspace-ui.js','dropFolderId','Dropping files on a folder must target that folder.');
$need('assets/js/research-agent-workspace-ui.js','/api/research-workspace-upload.php','Desktop files and recordings must use the governed upload endpoint.');
$need('assets/js/research-agent-workspace-ui.js','Save & Transcribe','Desktop recording runtime must preserve the transcription workflow language.');
$need('assets/js/research-agent-workspace-ui.js',"'retry_transcription'",'Failed or blocked transcription must be retryable.');
$need('assets/js/research-agent-workspace-ui.js',"'transcript_to_document'",'Ready transcripts must convert to durable Research Docs.');
$need('assets/js/research-agent-workspace-ui.js',"askAgentAbout('recording'",'Recordings must hand explicit transcript context to the owning Agent.');
$need('assets/js/agent-chat.js','agentResearchMediaCard','Files and recordings must render as live Agent Chat cards.');

$need('api/research-workspace-upload.php','private_storage_allocate','Uploaded bytes must use private storage.');
$need('api/research-workspace-upload.php','new finfo(FILEINFO_MIME_TYPE)','Uploads must be server-side MIME validated.');
$need('api/research-workspace-upload.php','hash_file(\'sha256\'','Uploads must record SHA-256 integrity.');
$need('app/bootstrap.php',"$requestPath==='/api/research-workspace-upload.php'",'Large request allowance must be scoped only to the Research upload endpoint.');
$need('research-workspace-file.php','research_agent_workspace_object','File streaming must re-check object access.');
$need('research-workspace-file.php','stream_private_file','Research files must use private range-capable streaming.');

$need('app/research-agent-workspace.php','function research_agent_workspace_register_upload','Research uploads must be durable workspace objects.');
$need('app/research-agent-workspace.php','function research_agent_workspace_register_recording','Research recordings must be durable workspace objects.');
$need('app/research-agent-workspace.php','function research_agent_workspace_upload_context','Ready extracted file text must resolve into Agent context.');
$need('app/research-agent-workspace.php','function research_agent_workspace_recording_context','Ready transcripts must resolve into Agent context.');
$need('app/research-agent-workspace.php','function research_agent_workspace_project_context','Owning Research Agent must receive bounded workspace retrieval.');
$need('app/research-agent-workspace.php','function research_agent_workspace_transcript_to_document','Transcript-to-Doc conversion must use the shared document model.');
$need('app/agent-chat.php',"if(\$type==='upload'",'Agent Chat must accept explicit Research file context.');
$need('app/agent-chat.php',"if(\$type==='recording'",'Agent Chat must accept explicit recording context.');
$need('app/object-handoff.php',"'upload'=>'Research file'",'Research files must be unified handoff objects.');
$need('app/object-handoff.php',"'recording'=>'Recording'",'Recordings must be unified handoff objects.');
$need('app/workspace-context.php',"['upload','recording']", 'Workspace continuity must resolve media objects by reference.');
$avoid('app/workspace-context.php','upload_extracted_text','Workspace continuity must not copy uploaded file text.');
$avoid('app/workspace-context.php','transcript_text','Workspace continuity must not copy transcript text.');

$need('worker/research-file-worker.php',"job_claim(\$pdo,'research_file_jobs'",'Research file extraction must use the leased job queue.');
$need('worker/research-file-worker.php','ZipArchive','DOCX extraction must use the document archive rather than unsafe shell parsing.');
$need('worker/research-file-worker.php','pdftotext','PDF extraction must have a local/configured text path.');
$need('worker/research-transcription-worker.php',"job_claim(\$pdo,'research_transcription_jobs'",'Research transcription must use the leased job queue.');
$need('worker/research-transcription-worker.php',"\$config['transcription']", 'Research recordings must reuse the provider-neutral transcription configuration.');
$need('app/jobs.php',"'research_file_jobs'",'Research file jobs must participate in generic lease recovery.');
$need('app/jobs.php',"'research_transcription_jobs'",'Research transcription jobs must participate in generic lease recovery.');

$need('home.php','/assets/css/app.css?v=53.0','Phase 53 stylesheet must have a fresh cache key.');
$need('home.php','research-agent-workspace-ui.js?v=53.0','Phase 53 Desktop runtime must have a fresh cache key.');
$need('home.php','agent-chat.js?v=42.0','Phase 53 Agent Chat media cards must have a fresh cache key.');

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 53 Desktop Files, Uploads & Recordings contract passed.\n";
