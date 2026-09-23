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
 'database/migrations/20260923_050_research_agent_desktop.sql',
 'docs/phase-52-research-agent-desktop.md',
 'app/research-agent-workspace.php','api/research-workspace-objects.php',
 'assets/js/research-agent-workspace-ui.js','tests/phase52-research-agent-desktop-db.php'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 52 file missing: '.$file;

$need('home.php','data-research-desktop-open','Research Agent chat must expose a DESKTOP control.');
$need('home.php','researchAgentCanvasTopActions','DESKTOP and close controls must share the Agent canvas top-action area.');
$need('home.php','data-research-desktop','Research Agent must include a dedicated full-width Desktop layer.');
$need('home.php','data-research-desktop-icons','Desktop must have an icon layer.');
$need('home.php','data-research-desktop-stickies','Desktop must have a floating sticky-note layer.');
$need('home.php','data-research-document-window','Research Docs must open as Desktop windows.');
$avoid('home.php','data-research-workspace-view="docs"','Legacy inline Docs navigation must be removed from the Agent canvas.');
$avoid('home.php','data-research-workspace-view="files"','Legacy inline Files navigation must be removed from the Agent canvas.');
$avoid('home.php','data-research-workspace-view="trash"','Legacy inline Trash navigation must be removed from the Agent canvas.');

$need('database/migrations/20260923_050_research_agent_desktop.sql','research_workspace_desktop_positions','Desktop icon positions must persist durably.');
$need('app/research-agent-workspace.php','function research_agent_workspace_desktop_items','Desktop must resolve workspace objects and linked annotations.');
$need('app/research-agent-workspace.php','function research_agent_workspace_desktop_position_save','Desktop positions must be permission-checked server-side.');
$need('app/research-agent-workspace.php',"'annotation'","Linked annotations must be first-class Desktop icons.");
$need('api/research-workspace-objects.php',"\$action==='desktop'",'Desktop must have a read endpoint.');
$need('api/research-workspace-objects.php',"\$action==='save_desktop_position'",'Desktop drag positions must have a mutation endpoint.');

$need('assets/js/research-agent-workspace-ui.js',"icon.addEventListener('dblclick'",'Desktop icons must open on double-click.');
$need('assets/js/research-agent-workspace-ui.js',"icon.setPointerCapture",'Desktop icons must support pointer drag arrangement.');
$need('assets/js/research-agent-workspace-ui.js',"'save_desktop_position'",'Desktop icon positions must persist after drag.');
$need('assets/js/research-agent-workspace-ui.js',"object_type==='trash'",'Desktop must expose Trash as a system icon.');
$need('assets/js/research-agent-workspace-ui.js',"item.object_type==='annotation'",'Desktop annotations must open their canonical annotation page.');
$need('assets/js/research-agent-workspace-ui.js',"recording:'🎙️'",'Desktop icon renderer must support recording objects.');
$need('assets/js/research-agent-workspace-ui.js',"upload:'📦'",'Desktop icon renderer must support uploaded-file objects.');
$need('assets/js/research-agent-workspace-ui.js',"['yellow','pink','blue','green','purple','gray']", 'Desktop stickies must retain the approved six-color palette.');
$need('assets/js/research-agent-workspace-ui.js','ResizeObserver','Desktop sticky size changes must persist.');
$need('assets/js/research-agent-workspace-ui.js','data-research-document-window','Research Doc runtime must target the Desktop document window.');
$need('assets/js/research-agent-workspace-ui.js','closeDesktop();document.dispatchEvent','Ask Agent from a document must return to the Agent chat timeline.');

$need('assets/css/app.css','/* Phase 52 — Research Agent Desktop */','Desktop must have a scoped visual layer.');
$need('assets/css/app.css','.researchDesktop{position:absolute;inset:0','Desktop must cover the Research Agent canvas instead of nesting as a file list.');
$need('assets/css/app.css','.researchDesktopIcon{appearance:none!important;position:absolute!important','Desktop objects must render as draggable Windows-style icons.');
$need('assets/css/app.css','.researchDesktopSticky-yellow{background:#fff29a','Traditional yellow digital sticky styling must be explicit.');
$need('assets/css/app.css','.researchDesktopDocumentWindow{position:absolute','Research Docs must render in a movable desktop window.');
$need('home.php','research-agent-workspace-ui.js?v=53.0','Desktop runtime must ship with a fresh browser cache key.');

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 52 Research Agent Desktop contract passed.\n";
