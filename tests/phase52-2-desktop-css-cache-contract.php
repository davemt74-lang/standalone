<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$home=(string)file_get_contents($root.'/home.php');
$css=(string)file_get_contents($root.'/assets/css/app.css');
$fail=[];

foreach([
  '/assets/css/app.css?v=58.0'=>'Home must force-refresh the Phase 57 stylesheet.',
  '/assets/js/agent-chat.js?v=55.1'=>'Home must force-refresh the Phase 55.1 Agent Chat runtime.',
  '/assets/js/research-agent-workspace-ui.js?v=58.0'=>'Home must force-refresh the Phase 55.2 Research workspace runtime.',
] as $needle=>$message)if(!str_contains($home,$needle))$fail[]=$message;
foreach([
  '/assets/css/app.css?v=52.2',
  '/assets/css/app.css?v=54.0',
  '/assets/css/app.css?v=55.1',
  '/assets/js/agent-chat.js?v=42.0',
  '/assets/js/research-agent-workspace-ui.js?v=54.0',
  '/assets/js/research-agent-workspace-ui.js?v=55.1',
] as $stale)if(str_contains($home,$stale))$fail[]='Home must not retain stale UI cache key: '.$stale;
if(str_contains($home,'href="/assets/css/app.css"'))$fail[]='Home must not ship an unversioned app.css reference.';
$controlsPos=strpos($home,'data-research-canvas-controls');
$feedPos=strpos($home,'id="homeFeedCanvas"');
$agentCanvasPos=strpos($home,'id="homeAgentCanvas"');
if($controlsPos===false||$feedPos===false||$agentCanvasPos===false||!($controlsPos<$feedPos&&$controlsPos<$agentCanvasPos))$fail[]='Library/Desktop/Close controls must live at the workspace canvas level, outside Agent Chat canvas markup.';
if(!str_contains($css,'.homeFeedPage.agentChatMode.researchDesktopMode .homeWorkspaceLayout'))$fail[]='Desktop full-column override must remain present.';
if(!str_contains($css,'.homeAgentCanvas.researchDesktopOpen>.researchAgentCanvasTopActions'))$fail[]='Desktop must continue hiding underlying Agent controls.';
if(!str_contains($css,'.researchDesktopBar{'))$fail[]='Desktop toolbar CSS must remain present.';
if(!str_contains($css,'background:transparent!important;'))$fail[]='Desktop toolbar must remain transparent.';

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 52.2 Desktop CSS cache contract passed.\n";
