<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

$need('database/migrations/20260923_061_profile_public_identity_showcase.sql','profile_pins','Profile showcase pins must remain canonical.');
$need('app/profile-showcase.php','profile_showcase_pin_set','Profile must use governed showcase pin writes.');
$need('app/profile-showcase.php','profile_showcase_public_collections','Profile collections must resolve from public collections.');
$need('app/profile-showcase.php','profile_showcase_people','Connections must preserve visibility and block rules.');
foreach(['annotatedProfileTabs','Activity','Annotations','Research','Collections','About','View as public','annotatedProfileGrid','Feature'] as $needle)$need('profile.php',$needle,'Rewritten profile showcase missing '.$needle);
$need('profile.php',"\$GLOBALS['annotated_shell_mode']='header_only';",'Profile must use universal header-only shell.');
$avoid('profile.php',"\$GLOBALS['annotated_shell_disabled']=true;",'Profile must not disable universal header.');
$avoid('profile.php','profileV2','Profile must not depend on patched V2 layout.');
$need('assets/css/profile-social.css','.annotatedProfileGrid','Rewritten profile must own its isolated showcase grid.');
$need('assets/css/profile-social.css','.annotatedProfileAbout','Rewritten profile must own its About surface.');
$css=(string)file_get_contents($root.'/assets/css/app.css');$ext=(string)file_get_contents($root.'/extension/landing-app.css');
if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Profile showcase contract passed on rewritten profile.\n";
