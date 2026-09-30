<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$profile=(string)file_get_contents($root.'/profile.php');
$discovery=(string)file_get_contents($root.'/app/public-discovery.php');
$showcase=(string)file_get_contents($root.'/app/profile-showcase.php');
$settings=(string)file_get_contents($root.'/settings.php');
$storage=(string)file_get_contents($root.'/app/storage.php');
$migration=(string)file_get_contents($root.'/database/migrations/20260930_112_profile_v2_cover_identity.sql');
$css=(string)file_get_contents($root.'/assets/css/profile-social.css');

foreach(["\$GLOBALS['annotated_shell_mode']='header_only';","'stories'=>'Stories'","'agents'=>'Research Agents'","'research'=>'Research'","'annotations'=>'Annotations'","'collections'=>'Collections'","'about'=>'About'",'profile_cover_image_url','profile-social.css?v=1.0',"research_agent_profile_list(\$pdo,(int)\$profile['id'],null,40)"] as $needle)if(!str_contains($profile,$needle))$fail[]='profile missing '.$needle;
if(!str_contains($showcase,"ra.visibility='public'"))$fail[]='Public Story feed must require public Research Agents.';
if(!str_contains($showcase,'research_agent_story_published_filter'))$fail[]='Public Story feed must use canonical published Story filter.';
if(!str_contains($discovery,'profile_cover_image_url'))$fail[]='Public discovery must expose cover image.';
if(!str_contains($settings,'profile_cover'))$fail[]='Settings must retain cover editing.';
if(!str_contains($storage,'function profile_cover_upload'))$fail[]='Cover upload helper missing.';
if(!str_contains($migration,'ADD COLUMN profile_cover_image_url'))$fail[]='Cover migration missing.';
if(!str_contains($css,'.annotatedProfileCover'))$fail[]='Full-width social cover CSS missing.';
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 79 unified public profile contract passed on full rewrite.\n";
