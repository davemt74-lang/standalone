<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

$need('database/migrations/20260930_108_research_agent_story_policies.sql','CREATE TABLE IF NOT EXISTS research_agent_story_policies','Phase 78.2 must persist per-Agent Story publishing policy.');
$need('database/migrations/20260930_108_research_agent_story_policies.sql',"ENUM('draft_only','approval','auto_publish')",'Story policy must support draft-only, approval and auto-publish modes.');
$need('database/migrations/20260930_108_research_agent_story_policies.sql','daily_story_cap','Story policy must persist a daily Story cap.');
$need('database/migrations/20260930_108_research_agent_story_policies.sql','timezone_name','Story quiet hours must be timezone-aware.');
$need('app/research-agent-stories.php','function research_agent_story_policy_update','Phase 78.2 must expose a canonical policy update runtime.');
$need('app/research-agent-stories.php','function research_agent_story_policy_decision','Proactive Story creation must pass through a policy decision.');
$need('app/research-agent-stories.php','trigger_disabled','Policy must suppress disabled Story trigger categories.');
$need('app/research-agent-stories.php','below_threshold','Policy must enforce minimum importance.');
$need('app/research-agent-stories.php','quiet_hours','Policy must defer proactive Stories during quiet hours.');
$need('app/research-agent-stories.php','daily_cap','Policy must defer proactive Stories after the daily cap.');
$need('app/research-agent-stories.php',"publish_mode']==='auto_publish'",'Only auto-publish policy may publish proactive Stories without approval.');
$need('app/research-agent-stories.php','research_agent_story_policy_decision($pdo,$agent,$storyType,$priority)','Proactive Story creation must use the canonical policy decision.');
$need('research-agent-edit.php','STORY POLICY','Research Agent Edit must expose proactive Story publishing policy.');
$need('research-agent-edit.php','name="publish_mode"','Agent Edit must expose publishing mode.');
$need('research-agent-edit.php','name="min_priority"','Agent Edit must expose minimum importance.');
$need('research-agent-edit.php','name="daily_story_cap"','Agent Edit must expose daily Story cap.');
$need('research-agent-edit.php','name="quiet_hours_enabled"','Agent Edit must expose quiet hours.');
$need('research-agent-edit.php','name="story_timezone_name"','Agent Edit must expose Story policy timezone.');
foreach(['trigger_evidence','trigger_risk','trigger_question','trigger_decision','trigger_task','trigger_update'] as $trigger)$need('research-agent-edit.php','name="'.$trigger.'"','Agent Edit missing Story trigger '.$trigger.'.');
$need('assets/css/app.css','/* Phase 78.2 — Proactive Story Triggers & Publishing Policy */','Phase 78.2 must include responsive Story policy styling.');
if(is_file($root.'/assets/css/app.css')&&is_file($root.'/extension/landing-app.css')&&file_get_contents($root.'/assets/css/app.css')!==file_get_contents($root.'/extension/landing-app.css'))$fail[]='Website and extension landing CSS must remain byte-for-byte synchronized.';

if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 78 Section 2 Proactive Story Policy contract passed.\n";
