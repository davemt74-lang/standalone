<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$app=(string)file_get_contents($root.'/assets/css/app.css');
$ext=(string)file_get_contents($root.'/extension/landing-app.css');
$profile=(string)file_get_contents($root.'/profile.php');

if(!hash_equals(hash('sha256',$app),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';
foreach([
  'body.profileStandaloneBody:not(.profileV2Body) .profileHero',
  'body.profileStandaloneBody:not(.profileV2Body) .profileStandalonePage',
  'body.profileStandaloneBody:not(.profileV2Body) .profileIdentity',
  'body.profileStandaloneBody:not(.profileV2Body) .profileAvatar'
] as $needle)if(!str_contains($app,$needle))$fail[]='Legacy profile CSS isolation missing '.$needle;

if(str_contains($app,"\n.profileHero{position:relative;overflow:hidden;border:1px solid #deded8"))$fail[]='Unscoped legacy profile hero rule must not target Profile V2.';
if(!str_contains($profile,'/assets/css/app.css?v=profile-301'))$fail[]='Profile shared CSS cache tag must advance after isolation.';
if(!str_contains($profile,'profile-v2.css?v=79.3'))$fail[]='Profile V2 stylesheet must remain explicit.';

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Profile V2 CSS isolation contract passed.\n";
