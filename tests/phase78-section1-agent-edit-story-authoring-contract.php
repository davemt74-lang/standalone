<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

$need('research-agent-edit.php','RESEARCH AGENT CONTROL CENTER','Agent Edit page must be a dedicated control center.');
$need('research-agent-edit.php','agent_profile_photo','Agent Edit must support profile photo upload.');
$need('research-agent-edit.php','name="visibility"','Agent Edit must manage Agent visibility.');
$need('research-agent-edit.php','name="cadence"','Agent Edit must manage monitoring cadence.');
$need('research-agent-edit.php','research-missions.php?agent=','Agent Edit must expose canonical Missions management.');
$need('research-agent-edit.php','research-tasks.php?agent=','Agent Edit must expose canonical Tasks/Plans management.');
$need('research-agent-edit.php','research-programs.php?agent=','Agent Edit must expose canonical Programs management.');
$need('research-agent-edit.php','research-intelligence-portfolios.php','Agent Edit must expose canonical Portfolio management.');
$need('research-agent-edit.php','research-agent-research.php?agent=','Agent Edit must expose the Agent project/research workspace.');
$need('research-agent-edit.php','Create a Story','Agent Edit must include manual Story authoring.');
$need('app/research-agents.php','function research_agent_edit_context','Phase 78.1 must centralize Agent edit context.');
$need('app/research-agents.php','function research_agent_update_settings','Phase 78.1 must centralize Agent settings updates.');
$need('app/research-agents.php','research_mission_list($pdo,$viewer,$publicId,100)','Agent edit context must reuse canonical Mission engine.');
$need('app/research-agents.php','research_task_plan_list($pdo,$viewer,$publicId,100)','Agent edit context must reuse canonical Task engine.');
$need('app/research-agents.php','research_program_list($pdo,$viewer,$publicId,100)','Agent edit context must reuse canonical Program engine.');
$need('app/research-agents.php','research_intelligence_portfolio_contains_project','Agent edit context must derive Portfolio membership canonically.');
$need('app/research-agent-stories.php','function research_agent_story_create_manual','Phase 78.1 must support manual Story creation.');
$need('app/research-agent-stories.php','function research_agent_story_drafts','Phase 78.1 must support Story drafts.');
$need('app/research-agent-stories.php',"status='published'",'Published Story reads must exclude drafts.');
$need('database/migrations/20260930_107_research_agent_edit_story_authoring.sql',"ENUM('draft','published','archived')",'Phase 78.1 must persist Story draft/publish state.');
$need('app/research-agent-shell-ui.php','data-research-agent-edit','Unified Agent shell must expose Edit.');
$need('research.php','researchHomeEditAgent','Research Home Agent cards must link to Edit.');
$need('app/research-surface-map.php',"'research-agent-edit.php'",'Phase 74 route map must classify the Agent Edit surface.');
$need('assets/css/app.css','/* Phase 78.1 — Research Agent Edit & Story Authoring Foundation */','Phase 78.1 must include responsive Edit UI styling.');
if(is_file($root.'/assets/css/app.css')&&is_file($root.'/extension/landing-app.css')&&file_get_contents($root.'/assets/css/app.css')!==file_get_contents($root.'/extension/landing-app.css'))$fail[]='Website and extension landing CSS must remain byte-for-byte synchronized.';
$avoid('research-agent-edit.php','INSERT INTO research_missions','Agent Edit must not create a shadow Mission engine.');
$avoid('research-agent-edit.php','INSERT INTO research_tasks','Agent Edit must not create a shadow Task engine.');
$avoid('research-agent-edit.php','INSERT INTO research_programs','Agent Edit must not create a shadow Program engine.');

if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 78 Section 1 Research Agent Edit & Story Authoring contract passed.\n";
