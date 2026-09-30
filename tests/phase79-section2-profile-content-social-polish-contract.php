<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$profile=(string)file_get_contents($root.'/profile.php');
$showcase=(string)file_get_contents($root.'/app/profile-showcase.php');
$css=(string)file_get_contents($root.'/assets/css/profile-v2.css');
$migration=(string)file_get_contents($root.'/database/migrations/20260930_113_profile_content_social_polish.sql');

foreach([
  "profile-v2.css?v=79.2",
  "data-profile-edit-open",
  "data-profile-edit-dialog",
  "profile_update",
  "profileStoryRail",
  "profileStoryRing",
  "research_agent_story",
  "renderAgentCard",
  "renderStoryCard",
  "of 6",
  "Network"
] as $needle)if(!str_contains($profile,$needle))$fail[]='Profile 79.2 missing '.$needle;

foreach([
  "research_agent",
  ">=6",
  "feature up to 6 public profile items",
  "profile_showcase_activity(array \$profile,array \$collections,array \$stories=[]",
  "ra.visibility='public'",
  "research_agent_story_published_filter"
] as $needle)if(!str_contains($showcase,$needle))$fail[]='Profile showcase 79.2 missing '.$needle;

if(!str_contains($migration,"ENUM('annotation','research_report','collection','research_agent')"))$fail[]='Migration 113 must extend featureable object types with Research Agent.';
foreach(['.profileStoryRail','.profileStoryRing','.profileEditDialog','.profileAgentMeta','.profileAboutCard'] as $needle)if(!str_contains($css,$needle))$fail[]='Profile 79.2 CSS missing '.$needle;

if(!str_contains($profile,"research_agent_profile_list(\$pdo,(int)\$p['id'],null,30)"))$fail[]='Profile Research Agents must remain public-only.';
if(!str_contains($showcase,"ra.visibility='public'"))$fail[]='Profile Stories must remain restricted to public Research Agents.';
if(str_contains($profile,"research_agent_profile_list(\$pdo,(int)\$p['id'],\$viewer"))$fail[]='Profile must not widen Research Agent visibility based on the signed-in viewer.';

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 79 Section 2 Profile Content System & Social Polish contract passed.\n";
