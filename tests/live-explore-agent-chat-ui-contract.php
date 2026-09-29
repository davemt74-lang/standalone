<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&!str_contains($c,$needle))$fail[]=$message;};
$avoid=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&str_contains($c,$needle))$fail[]=$message;};

$need('live.php','class="liveRoomPage"','Live source room must use the redesigned room workspace.');
$need('live.php','Participants','Live room must expose a participant rail.');
$need('live.php','Invite to this room','Live room must expose a functional invite rail.');
$need('live.php',"post('invite'",'Live invite control must invoke the authenticated server action.');
$need('app/live.php','function live_room_invite_candidates','Live engine must provide permission-filtered invite candidates.');
$need('app/live.php','function live_room_invite','Live engine must create authorized room invitations.');
$need('app/live.php','profile_image_url','Live participant/message payloads must include available profile avatars.');
$need('app/notifications.php',"'live_room_invite'",'Live invite notifications must resolve back into authorized Live rooms.');
$avoid('live.php','sourceLogo','Live room must not invent or render a source logo.');

$need('explore.php','class="discoveryShell exploreDashboard"','Explore must use the compact discovery dashboard.');
$avoid('explore.php','Research the web in context.','Explore must not retain the oversized marketing hero.');
$avoid('explore.php','class="searchBar exploreSearch"','Explore must not duplicate the global header search.');
$need('explore.php','Researchers to discover','Explore must retain people discovery.');
$need('explore.php','Sources gaining attention','Explore must retain trending sources.');
$need('explore.php','Growing research threads','Explore must retain topic/entity discovery.');
$need('explore.php','Recommended annotations','Explore must retain personalized recommendations.');
$need('explore.php','PUBLISHED RESEARCH','Explore must retain published Research discovery.');

$need('assets/css/app.css','/* Agent Chat — AI conversation layout */','Agent Chat must have the AI-first conversation style layer.');
$need('assets/css/app.css','.agentChatMessage.is-agent{padding:0;background:transparent!important;border:0!important;box-shadow:none!important','Assistant response container must be explicitly borderless and transparent.');
$need('assets/css/app.css','.agentChatMessage.is-agent .agentChatMessageBody{max-width:none;padding:0 0 0 34px!important;background:transparent!important;border:0!important;box-shadow:none!important','Assistant response body must not render as a white bubble/card.');
$need('assets/css/app.css','.agentChatMessage.is-user{display:grid;justify-items:end}','User prompts must remain visually distinct from Agent responses.');
$need('assets/css/app.css','.homeFeedPage.agentChatMode .homeAgentDock','Agent Chat composer must retain a dedicated AI-chat dock.');
$need('home.php','/assets/css/app.css?v=76.0','Home must load the refreshed chat styles.');
$need('assets/css/app.css','.researchAdvancedTools{margin-top:0;margin-left:auto;justify-self:end;align-self:center;text-align:right}','Advanced Research tools control must float to the right on desktop.');

$site=$read('assets/css/app.css');$ext=$read('extension/landing-app.css');
if($site!==''&&$ext!==''&&!hash_equals(hash('sha256',$site),hash('sha256',$ext)))$fail[]='Extension landing CSS must remain byte-identical to website CSS.';
$need('tests/phase8-live-db.php','authorized Team Live invite creates a notification','Live database suite must cover functional room invitations.');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Live, Explore & Agent Chat UI refresh contract passed.\n";
