<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$profile=(string)file_get_contents($root.'/profile.php');
$css=(string)file_get_contents($root.'/assets/css/app.css');
$ext=(string)file_get_contents($root.'/extension/landing-app.css');
$v2=(string)file_get_contents($root.'/assets/css/profile-v2.css');
$fail=[];

$headerOnly=strpos($profile,"\$GLOBALS['annotated_shell_mode']='header_only';");
$bootstrap=strpos($profile,"require __DIR__.'/app/bootstrap.php';");
if($headerOnly===false||$bootstrap===false||$headerOnly>$bootstrap)$fail[]='Profile must enable the universal header-only shell before bootstrap/current_user can activate it.';
if(str_contains($profile,"\$GLOBALS['annotated_shell_disabled']=true;"))$fail[]='Profile must not opt out of the universal header.';
foreach(['appShellSidebar','<header class="topbar"','class="panel profilePage"'] as $forbidden){
    if(str_contains($profile,$forbidden))$fail[]='Profile still contains obsolete shell marker: '.$forbidden;
}
foreach(['profileStandaloneBody','profileStandalonePage','profileV2Page','profileHero','profileHeroBackdrop','profileStatsBar','profileTabs','profileContent','profileFeed','profileGuestHeader'] as $required){
    if(!str_contains($profile,$required))$fail[]='Profile redesign markup missing: '.$required;
}
foreach(['profilePrimaryAction','copyProfile','annotation_ui_card','annotation_ui_scripts','profile-v2.css?v=79.3'] as $required){
    if(!str_contains($profile,$required))$fail[]='Profile behavior/content contract missing: '.$required;
}
foreach(['.profileV2Page','.profileHeroBackdrop','.profileAvatar','.profileStatsBar','.profileTabs','.profileStoryGrid','@media(max-width:640px)'] as $required){
    if(!str_contains($v2,$required))$fail[]='Profile V2 responsive style missing: '.$required;
}
foreach(['.appShellHeaderOnly .appShellHeader','.appShellHeaderOnly .appHeaderBrandMobile','.appShellHeaderOnly .appShellContent','grid-template-columns:auto minmax(260px,640px) auto'] as $required){
    if(!str_contains($v2,$required))$fail[]='Profile header-only shell hotfix missing: '.$required;
}
if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';

if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}
echo "Unified public profile UI contract passed: canonical header-only shell, full-width cover, tabs, and responsive profile surface are present.\n";
