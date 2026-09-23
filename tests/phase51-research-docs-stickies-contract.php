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
$need('app/agent-actions.php',"'research.create_sticky'",'Research Agents must be able to propose floating stickies through governed actions.');
$need('app/agent-actions.php','You are no longer a member of the Team that owns this Research Agent.','Pending Team Research Agent actions must re-check live Team membership.');
$need('app/agent-actions.php','research_agent_workspace_post_document_to_chat','Confirmed Agent document creation must post a new chat document card.');
$need('app/agent-chat.php',"\$type==='document'&&function_exists('object_handoff_resolve')",'Agent conversation reloads must enrich document attachments without changing legacy context attachments.');
$need('app/object-handoff.php',"'document'=>'Research document'",'Research documents must be first-class handoff objects.');
$need('app/agent-chat.php',"if(\$type==='document'",'Research documents must be valid Agent context.');
$need('app/workspace-context.php',"if(\$type==='document'",'Document workspace continuity must resolve by reference.');
$avoid('app/workspace-context.php','content_html','Workspace continuity must not persist Research document HTML.');
$avoid('app/workspace-context.php','document_plain_text','Workspace continuity must not persist Research document text.');
$need('api/research-workspace-objects.php',"\$action==='save_document'",'Workspace API must expose document autosave.');
$need('api/research-workspace-objects.php',"\$action==='restore_document_revision'",'Workspace API must expose revision restore.');
$need('api/research-workspace-objects.php',"\$action==='update_sticky'",'Workspace API must persist floating sticky layout changes.');
$need('home.php','data-research-desktop-open','Research Agent canvas must expose its workspace through the Desktop control.');
$need('home.php','data-research-document-window','Documents must open in a durable window inside the Research Agent Desktop.');
$need('assets/js/research-agent-workspace-ui.js','askAgentAbout','Open documents must retain a direct Ask Agent handoff.');
$need('home.php','data-research-desktop-stickies','Sticky notes must float on the Research Agent Desktop.');
$need('assets/js/research-agent-workspace-ui.js','closeDesktop();document.dispatchEvent(new CustomEvent(\'annotated:agent-chat-request\'','Document selection must hand back to the owning live Agent conversation.');
$need('assets/js/research-agent-workspace-ui.js','ResizeObserver','Floating sticky size must persist after resize.');
$need('assets/js/research-agent-workspace-ui.js','setPointerCapture','Sticky notes must support pointer drag positioning.');
$need('assets/js/research-agent-workspace-ui.js',"['yellow','pink','blue','green','purple','gray']", 'Sticky notes must support the approved multi-color palette.');
$need('assets/js/research-agent-workspace-ui.js','selectedDocumentText','Document selection must be available to Ask Agent / Create sticky.');
$need('assets/js/research-agent-workspace-ui.js',"annotated:research-document-created',()=>{if(desktopOpen)loadDesktop(false);}",'Agent-created documents should refresh the Desktop without auto-opening.');
$need('assets/js/agent-chat.js','agentDocumentCard','Agent-created Research documents must render as rich chat cards.');
$need('assets/js/agent-chat.js',"'research.create_document':'Create research document'",'Agent action UI must describe document creation.');
$need('assets/css/app.css','.researchDesktopDocumentWindow{position:absolute','Research documents must render as Desktop windows.');
$need('assets/css/app.css','.researchDesktopSticky{position:absolute','Sticky notes must remain floating draggable objects rather than file-list rows.');
$need('home.php','agent-chat.js?v=40.0','Research Docs must ship with a fresh Agent Chat cache key.');
$avoid('assets/js/research-agent-workspace-ui.js',"annotated:research-document-created',e=>openDocument", 'Agent-created documents must not auto-open and pull the user out of chat.');

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 51 Research Docs + Floating Sticky Notes contract passed.\n";
