<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$profile=(string)file_get_contents($root.'/profile.php');
$css=(string)file_get_contents($root.'/assets/css/profile-social.css');
$app=(string)file_get_contents($root.'/assets/css/app.css');
$ext=(string)file_get_contents($root.'/extension/landing-app.css');

$headerOnly=strpos($profile,"\$GLOBALS['annotated_shell_mode']='header_only';");
$bootstrap=strpos($profile,"require __DIR__.'/app/bootstrap.php';");
if($headerOnly===false||$bootstrap===false||$headerOnly>$bootstrap)$fail[]='Profile must enable header-only shell before bootstrap.';
foreach(['profileStandaloneBody','profileV2Body','profileV2Page','profileHero','profileTabs','profileContent'] as $old)if(str_contains($profile,$old))$fail[]='Rewritten profile must not use legacy layout class '.$old;
foreach(['annotatedProfileBody','annotatedProfileHero','annotatedProfileCover','annotatedProfileHeroContent','annotatedProfileStats','annotatedProfileTabs','annotatedProfileCanvas','annotatedProfileGuestHeader'] as $required)if(!str_contains($profile,$required))$fail[]='Rewritten profile markup missing '.$required;
foreach(['annotation_ui_card','annotation_ui_scripts','profile-social.css?v=1.0','copyProfile'] as $required)if(!str_contains($profile,$required))$fail[]='Rewritten profile behavior missing '.$required;
foreach(['.annotatedProfileHero','.annotatedProfileCover','.annotatedProfileTabs','.annotatedProfileStoryGrid','.annotatedProfileEditDialog','@media(max-width:560px)'] as $required)if(!str_contains($css,$required))$fail[]='Social profile stylesheet missing '.$required;
if(is_file($root.'/assets/css/profile-v2.css'))$fail[]='Obsolete patched Profile V2 stylesheet must be removed.';
if(!hash_equals(hash('sha256',$app),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}
echo "Rewritten social profile UI contract passed.\n";
