<?php
declare(strict_types=1);
$fail=[];
$root=dirname(__DIR__);
$profile=file_get_contents($root.'/profile.php');
$discovery=file_get_contents($root.'/app/public-discovery.php');
$showcase=file_get_contents($root.'/app/profile-showcase.php');
$settings=file_get_contents($root.'/settings.php');
$storage=file_get_contents($root.'/app/storage.php');
$migration=file_get_contents($root.'/database/migrations/20260930_112_profile_v2_cover_identity.sql');
$css=file_get_contents($root.'/assets/css/profile-v2.css');

$must=[
  "\$GLOBALS['annotated_shell_mode']='header_only';",
  "'stories'=>'Stories'",
  "'agents'=>'Research Agents'",
  "'research'=>'Research'",
  "'annotations'=>'Annotations'",
  "'collections'=>'Collections'",
  "'about'=>'About'",
  'profile_cover_image_url',
  'profile-v2.css?v=79.3',
  "research_agent_profile_list(\$pdo,(int)\$p['id'],null,30)"
];
foreach($must as $needle)if(!str_contains((string)$profile,$needle))$fail[]='profile missing '.$needle;
if(!str_contains((string)$showcase,"ra.visibility='public'"))$fail[]='public Story feed must require public Research Agents';
if(!str_contains((string)$showcase,'research_agent_story_published_filter'))$fail[]='public Story feed must use canonical published Story filter';
if(!str_contains((string)$discovery,'profile_cover_image_url'))$fail[]='public discovery must expose profile cover';
if(!str_contains((string)$settings,'profile_cover'))$fail[]='settings must allow profile cover editing';
if(!str_contains((string)$storage,'function profile_cover_upload'))$fail[]='cover upload helper missing';
if(!str_contains((string)$migration,'ADD COLUMN profile_cover_image_url'))$fail[]='cover migration missing';
if(!str_contains((string)$css,'.profileHeroBackdrop'))$fail[]='profile cover CSS missing';
if(!str_contains((string)$css,'width:100%'))$fail[]='profile layout must support full-width cover';

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 79 Section 1 Unified Public Profile contract passed.\n";
