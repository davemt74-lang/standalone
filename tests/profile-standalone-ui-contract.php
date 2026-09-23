<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$profile=(string)file_get_contents($root.'/profile.php');
$css=(string)file_get_contents($root.'/assets/css/app.css');
$ext=(string)file_get_contents($root.'/extension/landing-app.css');
$fail=[];

$disable=strpos($profile,"\$GLOBALS['annotated_shell_disabled']=true;");
$bootstrap=strpos($profile,"require __DIR__.'/app/bootstrap.php';");
if($disable===false||$bootstrap===false||$disable>$bootstrap)$fail[]='Profile must disable the universal shell before bootstrap/current_user can activate it.';
foreach(['appShellContent','appShellSidebar','appShellHeader','<header class="topbar"','class="panel profilePage"'] as $forbidden){
    if(str_contains($profile,$forbidden))$fail[]='Standalone profile still contains legacy/global shell marker: '.$forbidden;
}
foreach(['profileStandaloneBody','profileStandalonePage','profileHero','profileStatsBar','profileContent','profileFeed','profileStandaloneFooter'] as $required){
    if(!str_contains($profile,$required))$fail[]='Profile redesign markup missing: '.$required;
}
foreach(['profilePrimaryAction','copyProfile','annotation_ui_card','annotation_ui_scripts'] as $required){
    if(!str_contains($profile,$required))$fail[]='Profile behavior/content contract missing: '.$required;
}
foreach(['.profileStandaloneBody','.profileHero','.profileAvatar','.profileStatsBar','.profileContent','.profileStandaloneFooter','@media(max-width:520px)'] as $required){
    if(!str_contains($css,$required))$fail[]='Profile responsive style missing: '.$required;
}
if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';

if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}
echo "Standalone profile UI contract passed: no universal header/sidebar and responsive profile surface is present.\n";
