<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $p)use($root,&$fail): string{$f=$root.'/'.$p;if(!is_file($f)){$fail[]='Missing '.$p;return '';}return (string)file_get_contents($f);};
$need=function(string $p,string $n,string $m)use($read,&$fail): void{$c=$read($p);if($c!==''&&!str_contains($c,$n))$fail[]=$m;};

$need('home.php','hasTeamChatRail','Home must mark the layout when Team Chat is available.');
$need('home.php','/assets/css/home-team-chat.css?v=74.4','Home must load the Team Chat visibility override after app.css.');
$need('home.php','data-team-chat-rail','Home must render the canonical Team Chat rail.');
$need('home.php','/assets/js/team-chat.js?v=36.0','Home must load the Team Chat client when Team conversations exist.');
$need('assets/css/home-team-chat.css','.homeWorkspaceLayout.hasTeamChatRail','Team Chat CSS must keep a dedicated desktop rail state.');
$need('assets/css/home-team-chat.css','>.homeRightRail.teamChatRightRail','Team Chat CSS must explicitly restore the rail hidden by single-column Home styles.');
$need('home.php','data-team-chat-mini-rail','Home must render the 50px collapsed Team Chat rail.');
$need('assets/css/home-team-chat.css','width:50px!important','Collapsed Team Chat rail must be 50px wide.');
$need('assets/css/home-team-chat.css','transform:translateX(320px)!important','Desktop Team Chat must collapse to the right rail.');
$need('assets/css/home-team-chat.css','body.teamChatDesktopOpen','Desktop Team Chat must expose an explicit open state.');
$need('assets/js/team-chat.js',"miniRail?.addEventListener('click'","Clicking the collapsed Team Chat rail must open the chat panel.");
$need('assets/js/team-chat.js','renderMiniMembers','Collapsed Team Chat rail must render member avatars and presence.');
$need('assets/css/home-team-chat.css','@media(max-width:900px)','Team Chat rail must preserve mobile behavior.');
$need('assets/js/team-chat.js',"mobileOpen?.addEventListener('click'","Team Chat mobile opener must remain wired.");
$need('assets/js/team-chat.js',"mobileClose?.addEventListener('click'","Team Chat mobile close control must remain wired.");
$need('app/conversations.php','function conversation_team_list','Team Chat must continue to use canonical Team conversation discovery.');
if($fail){fwrite(STDERR,implode("\n",array_unique($fail))."\n");exit(1);}echo "Home Team Chat rail visibility contract passed.\n";
