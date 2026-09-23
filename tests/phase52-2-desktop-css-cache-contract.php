<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$home=(string)file_get_contents($root.'/home.php');
$css=(string)file_get_contents($root.'/assets/css/app.css');
$fail=[];

if(!str_contains($home,'/assets/css/app.css?v=55.1'))$fail[]='Home must force-refresh the Phase 54 stylesheet.';
if(str_contains($home,'href="/assets/css/app.css"'))$fail[]='Home must not ship an unversioned app.css reference.';
if(!str_contains($css,'.homeFeedPage.agentChatMode.researchDesktopMode .homeWorkspaceLayout'))$fail[]='Desktop full-column override must remain present.';
if(!str_contains($css,'.homeAgentCanvas.researchDesktopOpen>.researchAgentCanvasTopActions'))$fail[]='Desktop must continue hiding underlying Agent controls.';
if(!str_contains($css,'.researchDesktopBar{'))$fail[]='Desktop toolbar CSS must remain present.';
if(!str_contains($css,'background:transparent!important;'))$fail[]='Desktop toolbar must remain transparent.';

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 52.2 Desktop CSS cache contract passed.\n";
