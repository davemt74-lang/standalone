<?php
declare(strict_types=1);

$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root): void{
    $path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}
    if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;
};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root): void{
    $path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;
};

foreach([
 'database/migrations/20260923_049_research_docs_floating_stickies.sql',
 'tests/phase51-research-docs-floating-stickies-db.php',
 'docs/phase-51-research-docs-floating-stickies.md'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 51 file missing: '.$file;

$need('database/migrations/20260923_049_research_docs_floating_stickies.sql','research_workspace_documents','Research Docs require a durable document store.');
$need('database/migrations/20260923_049_research_docs_floating_stickies.sql','research_workspace_document_revisions','Research Docs require durable revision history.');
$need('database/migrations/20260923_049_research_docs_floating_stickies.sql','research_workspace_stickies','Floating sticky notes require durable canvas state.');
$need('app/research-agent-workspace.php','research_agent_workspace_create_document','Research Agent workspace must create editable documents.');
$need('app/research-agent-workspace.php','FOR UPDATE','Document saves must serialize revision checks for collaborative editing.');
$need('app/research-agent-workspace.php','research_agent_workspace_restore_document_revision','Document history must support restoring a prior version as a new revision.');
$need('app/research-agent-workspace.php',"['yellow','pink','blue','green','purple','gray']",'Sticky notes must support the six approved colors.');
$need('app/research-agent-workspace.php','research_agent_workspace_update_sticky','Sticky position, size, color, body, and z-order must be persistable.');
$need('api/research-workspace-objects.php',"if($action==='create_document')",'Workspace API must expose manual document creation.');
$need('api/research-workspace-objects.php',"if($action==='update_sticky')",'Workspace API must persist sticky interactions.');
$need('home.php','data-research-workspace-view="docs"','Research Agent canvas must expose Docs alongside Chat and Files.');
$need('home.php','data-research-document-workspace','Documents must open inside the Research Agent canvas.');
$need('home.php','data-research-document-agent-pane','The Research Agent conversation must remain attached beside an open document.');
$need('home.php','data-research-sticky-layer','Sticky notes must float over the Research Agent canvas rather than live in a separate page.');
$need('assets/js/research-agent-workspace-ui.js','appendChild(messages)','Opening a document must reuse the live Agent conversation in the document workspace.');
$need('assets/js/research-agent-workspace-ui.js','base_revision:documentRevision','Autosave must use optimistic document revision checks.');
$need('assets/js/research-agent-workspace-ui.js','ResizeObserver','Sticky note resize state must be persisted.');
$need('assets/js/research-agent-workspace-ui.js','setPointerCapture','Sticky notes must support pointer-based drag/drop positioning.');
$need('assets/js/research-agent-workspace-ui.js','data-doc-ask-selection','Document text selection must integrate with the Research Agent.');
$need('assets/js/research-agent-workspace-ui.js','createSticky(selection)','Document selections must be convertible into floating sticky notes.');
$need('assets/css/app.css','.researchStickyLayer{position:absolute','Sticky layer must be an overlay on the Research Agent canvas.');
$need('assets/css/app.css','.researchDocumentWorkspace{display:grid','Document mode must provide same-canvas editor + Agent layout.');
$need('app/agent-actions.php',"'research.create_document'",'Agent action capabilities must include governed Research document creation.');
$need('app/agent-actions.php','research_agent_workspace_attach_document_to_agent_message','Confirmed Agent-created documents must attach to the originating Agent message.');
$need('app/conversations.php',"object_handoff_message_attachments($pdo,$viewer,array_column($rows,'id'))",'Agent conversation reloads must resolve durable document attachments.');
$need('app/object-handoff.php',"'document'=>'Research document'",'Unified object handoff must resolve Research documents.');
$need('app/agent-chat.php',"if($type==='document'",'Research documents must be usable as Agent context.');
$need('app/workspace-context.php',"if($type==='document'",'Document continuity must use shared workspace references.');
$avoid('app/workspace-context.php','content_html','Ephemeral workspace context must never persist document content.');
$avoid('app/workspace-context.php','document_plain_text','Ephemeral workspace context must never persist document text.');
$need('assets/js/agent-chat.js','agentDocumentCard','Agent-created documents must render as rich cards in the main Research Agent chat canvas.');
$need('assets/js/agent-chat.js','AnnotatedResearchWorkspace.openDocument','Document cards must open into the same Research Agent canvas when available.');

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 51 Research Docs & Floating Sticky Notes contract passed.\n";
