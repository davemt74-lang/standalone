<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $p)use($root,&$fail): string{$f=$root.'/'.$p;if(!is_file($f)){$fail[]='Missing '.$p;return '';}return (string)file_get_contents($f);};
$need=function(string $p,string $n,string $m)use($read,&$fail): void{$c=$read($p);if($c!==''&&!str_contains($c,$n))$fail[]=$m;};
$avoid=function(string $p,string $n,string $m)use($read,&$fail): void{$c=$read($p);if($c!==''&&str_contains($c,$n))$fail[]=$m;};

$need('app/research-agent-stories.php','function research_agent_story_groups','Stories V2 must group canonical stories by Research Agent.');
$need('app/research-agent-stories.php',"'unread_count'=>0",'Grouped Stories must expose an unread count.');
$need('app/research-agent-stories.php',"'/home.php?story='",'Story deep links must open the exact Home Story viewer.');
$need('home.php','data-story-agent','Home must render one Story item per Research Agent group.');
$need('home.php','data-story-viewer','Home must include the Story viewer.');
$need('home.php','data-story-payload','Home must expose permission-checked Story payload to the viewer.');
$need('home.php','data-open-story','Exact Story deep links must auto-open the requested Story.');
$need('home.php','data-research-canvas-controls hidden','Research Agent canvas controls must be hidden on the normal Home feed.');
$need('assets/js/research-agent-stories.js',"setTimeout(()=>next(),8000)",'Stories must auto-advance.');
$need('assets/js/research-agent-stories.js',"e.key==='ArrowRight'",'Stories must support keyboard next navigation.');
$need('assets/js/research-agent-stories.js',"post('view'",'Story viewer must persist viewed state.');
$need('assets/js/research-agent-stories.js',"post('dismiss'",'Story viewer must preserve dismiss behavior.');
$need('assets/js/agent-chat.js','if(canvasControls)canvasControls.hidden=true','Leaving Agent mode must hide Research Agent controls.');
$need('assets/js/agent-chat.js','if(canvasControls)canvasControls.hidden=false','Opening Agent mode must reveal Research Agent controls.');
$need('assets/css/app.css','.researchAgentCanvasTopActions[hidden]{display:none!important}','Hidden Agent controls must not be overridden by authored display rules.');
$need('assets/css/app.css','/* Research Agent Stories V2 — grouped viewer + feed isolation */','Stories V2 must have dedicated viewer styling.');
$need('app/notifications.php',"if($type==='research_agent_story')",'Notifications must continue routing through canonical Story access.');
$need('app/unified-activity.php','research_agent_story_activity','Activity must continue consuming canonical Story activity.');
$avoid('home.php','homeAgentStoryCopy','The Home Stories rail must remain image/name only.');

if($fail){fwrite(STDERR,implode("\n",array_unique($fail))."\n");exit(1);}echo "Phase 76 Research Agent Stories V2 contract passed.\n";
