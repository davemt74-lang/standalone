<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use($root,&$fail): void{
    $path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}
    if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;
};

$need('assets/js/research-agent-workspace-ui.js',"document.body.classList.add('researchDesktopMode')",'Opening the Desktop must mark the page for full-column layout.');
$need('assets/js/research-agent-workspace-ui.js',"document.body.classList.remove('researchDesktopMode')",'Closing the Desktop must restore the normal Agent layout.');
$need('assets/js/agent-chat.js',"location.href='/research.php'",'Research Agent X must return to the main Research page.');
$need('assets/css/app.css','.homeFeedPage.agentChatMode.researchDesktopMode .homeWorkspaceLayout','Desktop mode must override the old centered Home/Agent max width.');
$need('assets/css/app.css','max-width:none!important;','Desktop layout must remove the centered max-width cap.');
$need('assets/css/app.css','.homeAgentCanvas.researchDesktopOpen>.researchAgentCanvasTopActions','Underlying Agent controls must be hidden while the Desktop is open.');
$need('assets/css/app.css','.researchDesktopBar{','Desktop must retain its toolbar structure.');
$need('assets/css/app.css','background:transparent!important;','Desktop toolbar/header must be transparent.');
$need('assets/css/app.css','border-bottom:0!important;','Desktop toolbar/header must not render the old boxed divider.');
$need('assets/css/app.css','box-shadow:none!important;','Desktop toolbar/header must not render the old card shadow.');
$need('home.php','agent-chat.js?v=42.0','Research Agent close behavior must ship with a fresh cache key.');
$need('home.php','research-agent-workspace-ui.js?v=54.0','Desktop layout behavior must ship with a fresh cache key.');

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 52.1 Desktop layout regression contract passed.\n";
