<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$profile=(string)file_get_contents($root.'/profile.php');
$showcase=(string)file_get_contents($root.'/app/profile-showcase.php');
$css=(string)file_get_contents($root.'/assets/css/profile-social.css');
$migration=(string)file_get_contents($root.'/database/migrations/20260930_113_profile_content_social_polish.sql');

foreach(['profile-social.css?v=1.0','data-profile-edit-open','data-profile-edit-dialog','profile_update','annotatedProfileStoryRail','annotatedProfileStoryRing','research_agent_story','renderAgent','renderStory','of 6','Network'] as $needle)if(!str_contains($profile,$needle))$fail[]='Rewritten profile content missing '.$needle;
foreach(['research_agent','>=6','feature up to 6 public profile items',"profile_showcase_activity(array \$profile,array \$collections,array \$stories=[]","ra.visibility='public'",'research_agent_story_published_filter'] as $needle)if(!str_contains($showcase,$needle))$fail[]='Profile showcase runtime missing '.$needle;
if(!str_contains($migration,"ENUM('annotation','research_report','collection','research_agent')"))$fail[]='Migration 113 must support featureable public Research Agents.';
foreach(['.annotatedProfileStoryRail','.annotatedProfileStoryRing','.annotatedProfileEditDialog','.annotatedProfileAgentIdentity','.annotatedProfileAbout'] as $needle)if(!str_contains($css,$needle))$fail[]='Social profile CSS missing '.$needle;
if(!str_contains($profile,"research_agent_profile_list(\$pdo,(int)\$profile['id'],null,40)"))$fail[]='Research Agents must remain public-only.';
if(str_contains($profile,"research_agent_profile_list(\$pdo,(int)\$profile['id'],\$viewer"))$fail[]='Profile must never widen Agent visibility based on viewer.';
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 79 profile content contract passed on full rewrite.\n";
