<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$need=function(string $file,string $needle,string $msg)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$msg;};
$m='database/migrations/20261001_124_phase80_sponsored_projects_agents_submissions.sql';
foreach(['sponsored_research_agent_assignments','sponsored_research_project_submissions','sponsored_research_project_submission_assets','system_report','document','report_version'] as $n)$need($m,$n,'Migration 124 missing '.$n);
foreach(['function sponsored_project_assign_agent','function sponsored_project_submit','function sponsored_project_admin_projects','function sponsored_project_admin_submissions','function sponsored_project_admin_status'] as $n)$need('app/sponsored-research-projects.php',$n,'Sponsored Projects runtime missing '.$n);
$need('app/sponsored-research-projects.php','Choose one of your active Research Agents.','Agent assignment must be owner-scoped.');
$need('research-sponsored-projects.php','Assign Research Agent','Researcher Sponsored Projects marketplace is missing Agent assignment.');
$need('research-sponsored-projects.php','Research Document attached automatically','User submission UI must expose attached Research Documents.');
$need('admin/sponsored-projects.php','Documents & Reports','Admin project detail must list documents/reports for every submission.');
$need('admin/sponsored-projects.php','Assigned Research Agents','Admin project detail must track assigned Agents.');
$need('app/admin-ui.php',"'sponsored_projects'=>['label'=>'Sponsored Projects'",'Sponsored Projects Admin navigation is missing.');
$need('app/admin-access.php',"'/admin/sponsored-projects.php'=>['admin.research_data.view','admin.research_data.manage']", 'Sponsored Projects Admin capability boundary is missing.');
$need('app/research-surface-map.php',"'research-sponsored-projects.php'",'Sponsored Projects researcher route is missing from Research surface map.');
if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Phase 80 Section 9 Sponsored Projects contract passed.\n";
