<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};

foreach([
 'tests/phase78-section3-story-composer-contract.php',
 'tests/phase78-section4-agent-story-intelligence-contract.php',
 'tests/phase78-section5-story-social-activity-contract.php',
 'tests/phase78-section6-story-approval-contract.php'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Missing Phase 78 release contract: '.$file;

$need('app/research-agent-stories.php','research_agent_story_publish_due','78.7 requires scheduled publishing runtime.');
$need('app/research-agent-stories.php','research_agent_story_intelligence_decision','78.7 requires duplicate/follow-up intelligence.');
$need('app/research-agent-stories.php','research_agent_story_notify_social($pdo,$viewer,$agent,$story);','78.7 requires social delivery for every published proactive Story.');
$need('app/research-agent-stories.php','function research_agent_story_activity','78.7 requires unified Activity integration.');
$need('app/research-agent-stories.php','function research_agent_story_review_manual','78.7 requires explicit Story approval/rejection.');
$need('app/research-agent-stories.php','requires approval before publishing','78.7 must prevent approval bypass.');
$need('app/research-agent-stories.php',"status='published'",'Public Story reads must remain published-only.');
$need('app/notifications.php',"if($type==='research_agent_story')return function_exists('research_agent_story_access')",'Notification access must remain governed by Story visibility.');
$need('research-agent-edit.php','Approve & publish','Agent Edit must retain explicit Story approval.');
$need('research-agent-edit.php','name="scheduled_at"','Agent Edit must retain scheduling.');
$need('home.php','data-story-why','Home Story viewer must retain Story intelligence context.');
$need('assets/js/research-agent-stories.js','story.why_it_matters','Story viewer must retain why-this-matters rendering.');
if(file_get_contents($root.'/assets/css/app.css')!==file_get_contents($root.'/extension/landing-app.css'))$fail[]='Website and extension landing CSS must remain byte-for-byte synchronized.';

foreach([
 'database/migrations/20260930_109_research_agent_story_composer.sql',
 'database/migrations/20260930_110_research_agent_story_intelligence.sql',
 'database/migrations/20260930_111_research_agent_story_approval.sql'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Missing Phase 78 migration: '.$file;

if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 78 Section 7 End-to-End Story Hardening & Release contract passed.\n";
