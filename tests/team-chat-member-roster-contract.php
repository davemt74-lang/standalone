<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&!str_contains($c,$needle))$fail[]=$message;};
$avoid=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&str_contains($c,$needle))$fail[]=$message;};

$need('home.php','id="teamChatMembers"','Home Team Chat rail must render a member roster container.');
$need('assets/js/team-chat.js','function renderTeamMembers','Team Chat client must render the complete member roster.');
$need('assets/js/team-chat.js',"state==='offline'?'Inactive'","Offline Team members must render an Inactive status badge.");
$need('assets/js/team-chat.js','renderTeamMembers(rows);','Presence refresh must also refresh the Team member roster.');
$need('assets/js/team-chat.js',"row.team_role||'member'",'Team Chat roster must expose Team role metadata.');
$need('app/conversations.php','tm.role team_role','Team Chat roster payload must include canonical Team roles.');
$need('app/conversations.php','$presenceReady=conversation_presence_ready($pdo);','Team membership visibility must be independent from live presence availability.');
$need('app/conversations.php',"'auto' status_mode,'' custom_status,0 active_now",'Presence-unavailable fallback must still return Team members as inactive.');
$avoid('app/conversations.php','if(!conversation_presence_ready($pdo)||($conversation[\'conversation_type\']??\'\')!==\'team\'','Team Chat roster must not disappear when presence tracking is unavailable.');
$need('tests/phase12a-conversations-db.php','Team Chat roster includes every active Team member regardless of login state','Database suite must cover offline member roster visibility.');
$need('tests/phase12a-conversations-db.php','member with no presence session remains visible as offline with Team role','Database suite must verify offline role/status payload.');
$need('assets/css/app.css','.teamChatMemberStatus.status-offline','Website CSS must style inactive Team members.');
$site=$read('assets/css/app.css');$ext=$read('extension/landing-app.css');
if($site!==''&&$ext!==''&&!hash_equals(hash('sha256',$site),hash('sha256',$ext)))$fail[]='Extension landing CSS must remain byte-identical to website CSS.';
$need('tests/ci/run-static-contracts.sh','team-chat-member-roster-contract.php','Static runner must include Team Chat member roster contract.');
$need('.github/workflows/package-two-zips.yml','team-chat-member-roster-contract.php','Release package must include Team Chat member roster contract.');
$need('tests/ci/package-smoke.sh','Team Chat member roster & offline presence package extensions passed.','Package smoke must validate Team Chat member roster patch.');
if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Team Chat member roster & offline presence contract passed.\n";
