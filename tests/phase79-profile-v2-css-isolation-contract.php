<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$app=(string)file_get_contents($root.'/assets/css/app.css');
$ext=(string)file_get_contents($root.'/extension/landing-app.css');
$profile=(string)file_get_contents($root.'/profile.php');
$social=(string)file_get_contents($root.'/assets/css/profile-social.css');

if(!hash_equals(hash('sha256',$app),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';
if(is_file($root.'/assets/css/profile-v2.css'))$fail[]='Patched Profile V2 stylesheet must be removed after full rewrite.';
foreach(['profileStandaloneBody','profileV2Body','profileV2Page','profileHero','profileTabs','profileContent'] as $old)if(str_contains($profile,$old))$fail[]='Rewritten profile must not reuse old layout class '.$old;
foreach(['annotatedProfileBody','annotatedProfileHero','annotatedProfileTabs','annotatedProfileCanvas'] as $fresh)if(!str_contains($profile,$fresh))$fail[]='Fresh profile namespace missing '.$fresh;
if(!str_contains($social,'.annotatedProfileBody'))$fail[]='Fresh profile stylesheet missing root namespace.';
if(!str_contains($profile,'/assets/css/app.css?v=profile-social-1'))$fail[]='Profile shared CSS cache marker missing.';
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Full profile rewrite isolation contract passed.\n";
