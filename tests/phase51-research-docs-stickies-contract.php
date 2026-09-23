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
 'database/migrations/20260923_049_research_docs_floating_stickies.sql',
 'app/research-agent-workspace.php','api/research-workspace-objects.php',
 'assets/js/research-agent-workspace-ui.js','assets/js/agent-chat.js',
 'tests/phase51-research-docs-stickies-db.php'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 51 file missing: '.$file;

$need('database/migrations/20260923_049_research_docs_floating_stickies.sql','research_workspace_documents','Research Docs must have durable storage.');
$need('database/migrations/20260923_049_research_docs_floating_stickies.sql','research_workspace_document_revisions','Research Docs must have revision history.');
$need('database/migrations/20260923_049_research_docs_floating_stickies.sql','research_workspace_stickies','Floating stickies must have durable layout storage.');
$need('app/research-agent-workspace.php','function research_agent_workspace_clean_html','Research Docs must sanitize stored rich HTML.');
$need('app/research-agent-workspace.php','function research_agent_workspace_save_document','Research Docs must support versioned saves.');
$need('app/research-agent-workspace.php','This document changed in another session','Document saves must reject stale revisions.');
$need('app/research-agent-workspace.php','function research_agent_workspace_post_document_to_chat','Agent-created documents must be posted into the owning Agent conversation.');
$need('app/research-agent-workspace.php',"'agent_document_created'",'Agent document delivery must emit a durable conversation event.');
$need('app/agent-actions.php',"'research.create_document'",'Governed Agent actions must support Research document creation.');
$need('app/agent-actions.php','research_agent_workspace_post_document_to_chat','Confirmed Agent document creation must post a new chat document card.');
$need('app/conversations.php','object_handoff_message_attachments','Agent conversations must resolve durable object attachments.');
$need('app/object-handoff.php',"'document'=>'Research document'",'Research documents must be first-class handoff objects.');
$need('app/agent-chat.php',"if(\$type==='document'",'Research documents must be valid Agent context.');
$need('api/research-workspace-objects.php',"\$action==='save_document'",'Workspace API must expose document autosave.');
$need('api/research-workspace-objects.php',"\$action==='restore_document_revision'",'Workspace API must expose revision restore.');
$need('api/research-workspace-objects.php',"\$action==='update_sticky'",'Workspace API must persist floating sticky layout changes.');
$need('home.php','data-research-workspace-view="docs"','Research Agent canvas must have Docs mode.');
$need('home.php','data-research-document-workspace','Documents must open inside the Research Agent canvas.');
$need('home.php','data-research-document-agent-pane','Open documents must retain an attached Agent pane.');
$need('home.php','data-research-sticky-layer','Sticky notes must float over the Research Agent canvas.');
$need('assets/js/research-agent-workspace-ui.js','documentAgentPane?.appendChild(messages)','Document mode must move the live Agent conversation beside the document.');
$need('assets/js/research-agent-workspace-ui.js','ResizeObserver','Floating sticky size must persist after resize.');
$need('assets/js/research-agent-workspace-ui.js','setPointerCapture','Sticky notes must support pointer drag positioning.');
$need('assets/js/research-agent-workspace-ui.js',"['yellow','pink','blue','green','purple','gray']", 'Sticky notes must support the approved multi-color palette.');
$need('assets/js/research-agent-workspace-ui.js','selectedDocumentText','Document selection must be available to Ask Agent / Create sticky.');
$need('assets/js/research-agent-workspace-ui.js',"annotated:research-document-created',()=>{primeWorkspace();}",'Agent-created documents should remain in chat until the user opens them.');
$need('assets/js/agent-chat.js','agentDocumentCard','Agent-created Research documents must render as rich chat cards.');
$need('assets/js/agent-chat.js',"'research.create_document':'Create research document'",'Agent action UI must describe document creation.');
$need('assets/css/app.css','grid-template-columns:minmax(0,7fr) minmax(260px,3fr)','Document workspace must use the intended document + Agent split.');
$need('assets/css/app.css','.researchStickyLayer{position:absolute','Sticky layer must float above the canvas instead of becoming file-list layout.');
$need('home.php','agent-chat.js?v=40.0','Research Docs must ship with a fresh Agent Chat cache key.');
$avoid('assets/js/research-agent-workspace-ui.js',"annotated:research-document-created',e=>{primeWorkspace();if", 'Agent-created documents must not auto-open and pull the user out of chat.');

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 51 Research Docs + Floating Sticky Notes contract passed.\n";
