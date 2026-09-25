<?php
declare(strict_types=1);

$root=dirname(__DIR__);$fail=[];
$read=function(string $file)use($root,&$fail): string{
    $path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return '';}return (string)file_get_contents($path);
};
$need=function(string $file,string $needle,string $message)use($read,&$fail): void{
    $content=$read($file);if($content!==''&&!str_contains($content,$needle))$fail[]=$message;
};

$js=$read('assets/js/research-agent-workspace-ui.js');
$api=$read('api/research-workspace-objects.php');
$workspace=$read('app/research-agent-workspace.php');
$home=$read('home.php');

foreach([
    "function documentIdFromUrl()",
    "function updateDocumentHistory(publicId,mode='push')",
    "history[method]({annotatedResearchDocument:",
    "window.addEventListener('popstate',async()=>",
    "openDocument(requested,{historyMode:'none'})",
    "closeDocument(true,{historyMode:'none'})",
    "if(initialDocument)openDocument(initialDocument,{historyMode:'replace'})",
] as $needle)if(!str_contains($js,$needle))$fail[]='Research document browser-history continuity missing '.$needle;

foreach([
    "Save failed. The current document remains open with your unsaved changes.",
    "Save failed. The document remains open with your unsaved changes.",
    "Save failed. The Research Desktop remains open so your unsaved document is preserved.",
    "async function leaveLibraryViewer()",
    "Save failed. Your document is still open with unsaved changes.",
    "libraryViewerBack?.addEventListener('click',async()=>{await leaveLibraryViewer();})",
    "if(await closeLibrary())await openDesktop()",
] as $needle)if(!str_contains($js,$needle))$fail[]='Unsaved-work navigation guard missing '.$needle;

foreach([
    "surface?.addEventListener('drop'",
    "uploadFiles(e.dataTransfer.files",
    "data-research-transcript-summary",
    "data-research-transcript-tasks",
    "annotated:research-document-open",
    "annotated:agent-chat-feed-restored",
] as $needle)if(!str_contains($js,$needle))$fail[]='Core Research Desktop journey missing '.$needle;

$need('app/research-agent-workspace.php','function research_agent_workspace_object_in_project','Workspace runtime must provide project-scoped object resolution.');
foreach([
    "\$scopedObject=function",
    "\$item=research_agent_workspace_object_in_project",
    "\$scopedObject('document',false,'Document not found.')",
    "\$scopedObject('recording',false,'Recording not found.')",
    "\$scopedObject('sticky',false,'Sticky note not found.')",
    "\$scopedObject(null,true,'Workspace item not found.')",
] as $needle)if(!str_contains($api,str_replace('\\$','$',$needle)))$fail[]='Workspace API scope guard missing '.str_replace('\\$','$',$needle);

foreach([
    "&&hash_equals((string)\$requestedResearchAgent['project_public_id'],(string)\$candidateDocument['project_public_id'])",
    "data-research-document=",
    "data-research-agent-conversation=",
] as $needle)if(!str_contains($home,str_replace('\\$','$',$needle)))$fail[]='Home Research Agent deep-link boundary missing '.str_replace('\\$','$',$needle);

$need('tests/ci/run-static-contracts.sh','php tests/phase64-core-product-workflow-contract.php','Static CI must execute Phase 64 contract.');
$need('tests/ci/run-full-regression.sh','tests/phase64-core-product-workflow-db.php','Full regression must execute Phase 64 database journey.');
$need('.github/workflows/full-regression.yml','php tests/phase64-core-product-workflow-db.php','MySQL 8 gate must execute Phase 64 database journey.');
$need('.github/workflows/package-two-zips.yml','docs/phase-64-core-product-experience-workflow-hardening.md','Production package must include Phase 64 documentation.');
$need('.github/workflows/package-two-zips.yml','tests/phase64-core-product-workflow-contract.php','Production package must include Phase 64 static contract.');
$need('.github/workflows/package-two-zips.yml','tests/phase64-core-product-workflow-db.php','Production package must include Phase 64 database journey.');
$need('tests/ci/package-smoke.sh','phase-64-core-product-experience-workflow-hardening.md','Package smoke must require Phase 64 documentation.');

if($fail){foreach(array_values(array_unique($fail)) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 64 Core Product Experience & End-to-End Workflow Hardening static contract passed.\n";
