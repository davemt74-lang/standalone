<?php
declare(strict_types=1);

$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$file=$root.'/'.$path;if(!is_file($file)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($file);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$body=$read($path);if($body!==''&&!str_contains($body,$needle))$fail[]=$message;};
$avoid=function(string $path,string $needle,string $message)use($read,&$fail): void{$body=$read($path);if($body!==''&&str_contains($body,$needle))$fail[]=$message;};

$workspace=$read('assets/js/research-agent-workspace-ui.js');
$chat=$read('assets/js/agent-chat.js');
$upload=$read('api/research-workspace-upload.php');
$file=$read('research-workspace-file.php');
$runtime=$read('app/research-agent-workspace.php');
$home=$read('home.php');

foreach([
    'function hasUnsafeExitState()',
    'documentDirty||!!libraryDocDirty',
    "mediaRecorder?.state==='recording'",
    "window.addEventListener('beforeunload'",
    'event.preventDefault();',
    "event.returnValue='';",
] as $needle)if(!str_contains($workspace,$needle))$fail[]='Browser-leave protection missing '.$needle;

foreach([
    'Save failed. The Research Desktop remains open so your unsaved document is preserved.',
    'Save failed. Your document is still open with unsaved changes.',
    "window.addEventListener('popstate'",
    "initialWorkspace==='desktop'",
    "initialWorkspace==='library'",
] as $needle)if(!str_contains($workspace,$needle))$fail[]='Phase 64/65 navigation safety missing '.$needle;

foreach([
    'function leaveResearchAgentToFeed',
    'closeLibrary?.()',
    'closeDesktop?.()',
    "['agent','workspace','doc']",
] as $needle)if(!str_contains($chat,$needle))$fail[]='Safe Agent-to-Home continuity missing '.$needle;

foreach([
    "UPLOAD_ERR_PARTIAL=>'The file upload was interrupted.'",
    'new finfo(FILEINFO_MIME_TYPE)',
    "if(!$spec)throw new InvalidArgumentException('Unsupported file type.",
    "if($size>(int)$spec['max'])",
    'private_storage_allocate',
    "hash_file('sha256'",
] as $needle)if(!str_contains($upload,$needle))$fail[]='Production upload protection missing '.$needle;

foreach([
    "application/pdf'=>['ext'=>'pdf','kind'=>'upload','max'=>50*1024*1024]",
    "audio/webm'=>['ext'=>'webm','kind'=>'recording','max'=>200*1024*1024]",
    'function research_agent_workspace_retry_transcription',
    "'transcript_to_document'",
    'function research_agent_workspace_clean_html',
    "preg_match('/^(javascript|data|vbscript):/i'",
    'function research_agent_workspace_object_in_project',
] as $needle)if(!str_contains($runtime,$needle))$fail[]='Research acceptance boundary missing '.$needle;

$need('research-workspace-file.php','research_agent_workspace_object','Private file streaming must re-check object access.');
$need('research-workspace-file.php','stream_private_file','Private evidence must use governed streaming.');
foreach(['data-research-initial-workspace','workspace=desktop','workspace=library','homeContinueResearch'] as $needle)if(!str_contains($home,$needle))$fail[]='Home continuation acceptance hook missing '.$needle;

$need('tests/ci/run-full-regression.sh','tests/phase66-production-acceptance-db.php','Full regression must execute Phase 66 production acceptance.');
$need('.github/workflows/full-regression.yml','php tests/phase66-production-acceptance-db.php','MySQL 8 must execute Phase 66 production acceptance.');
$need('.github/workflows/package-two-zips.yml','docs/phase-66-production-acceptance-ux-qa-release-hardening.md','Package must include Phase 66 acceptance documentation.');
$need('.github/workflows/package-two-zips.yml','tests/phase66-production-acceptance-contract.php','Package must include Phase 66 static acceptance contract.');
$need('.github/workflows/package-two-zips.yml','tests/phase66-production-acceptance-db.php','Package must include Phase 66 DB acceptance journey.');
$need('tests/ci/package-smoke.sh','phase-66-production-acceptance-ux-qa-release-hardening.md','Package smoke must require Phase 66 acceptance documentation.');
$avoid('RELEASE-MANIFEST.json','20260925_080','Phase 66 must not invent a schema migration.');

if($fail){foreach(array_values(array_unique($fail)) as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 66 Production Acceptance, UX QA & Release Hardening static contract passed.\n";
