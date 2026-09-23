<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

$need('database/migrations/20260923_061_profile_public_identity_showcase.sql','profile_pins','Profile Phase 2 must persist bounded public showcase pins.');
foreach(['profile_show_research','profile_show_collections','profile_show_about'] as $col)$need('database/migrations/20260923_061_profile_public_identity_showcase.sql',$col,'Profile Phase 2 preference missing: '.$col);
$need('app/profile-showcase.php','profile_showcase_pin_set','Profile Phase 2 must expose governed pin writes.');
$need('app/profile-showcase.php','profile_showcase_prune_pins','Profile Phase 2 must prune stale/private pins before enforcing the three-item limit.');
$need('app/profile-showcase.php','profile_showcase_public_collections','Profile collections must resolve from existing public collections.');
$need('app/profile-showcase.php','profile_showcase_people','Follower/following lists must resolve through profile visibility and block rules.');

foreach(['profileTabs','Activity','Annotations','Research','Collections','About','View as public','profilePinnedSection','Pin to profile'] as $needle)$need('profile.php',$needle,'Profile Phase 2 UI missing: '.$needle);
$need('profile.php',"\$GLOBALS['annotated_shell_disabled']=true;",'Profile Phase 2 must remain outside the universal shell.');
$avoid('profile.php','<header class="topbar"','Profile Phase 2 must not restore the public/global header.');
$need('profile-connections.php',"\$GLOBALS['annotated_shell_disabled']=true;",'Follower/following lists must remain standalone.');
$need('profile-connections.php','showing public profiles you can currently access','Connection lists must disclose their privacy-filtered boundary.');
$need('settings.php','Public profile showcase','Settings must expose public profile display controls.');
$need('settings.php','profile_show_research','Settings must persist Research showcase visibility.');
$need('settings.php','profile_show_collections','Settings must persist collection showcase visibility.');
$need('settings.php','profile_show_about','Settings must persist About showcase visibility.');

$profile=(string)file_get_contents($root.'/profile.php');
foreach(['profileColumns','PUBLISHED RESEARCH SIDEBAR','appShellContent','appShellSidebar','appShellHeader'] as $needle)if(str_contains($profile,$needle))$fail[]='Profile Phase 2 must not restore legacy profile/sidebar shell: '.$needle;

$css=(string)file_get_contents($root.'/assets/css/app.css');$ext=(string)file_get_contents($root.'/extension/landing-app.css');
if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';
foreach(['.profileTabs','.profilePinnedGrid','.profileShowcaseCard','.profileAboutCard','.profilePeopleList','@media(max-width:520px)'] as $needle)if(!str_contains($css,$needle))$fail[]='Profile Phase 2 responsive CSS missing: '.$needle;

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Profile Phase 2 static contract passed.\n";
