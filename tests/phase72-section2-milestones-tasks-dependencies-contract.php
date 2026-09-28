<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{
  $path=$root.'/'.$file;if(!is_file($path)){$fail[]=$label.' missing '.$file;return;}
  $c=(string)file_get_contents($path);foreach($needles as $needle)if(!str_contains($c,$needle))$fail[]=$label.' missing '.$needle.' in '.$file;
};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{
  $path=$root.'/'.$file;if(!is_file($path))return;$c=(string)file_get_contents($path);foreach($needles as $needle)if(str_contains($c,$needle))$fail[]=$label.' must not contain '.$needle.' in '.$file;
};

$must('database/migrations/20260927_094_research_action_plan_milestones_tasks_dependencies.sql',[
 'ADD COLUMN execution_task_plan_id','CREATE TABLE IF NOT EXISTS research_action_plan_milestones',
 'CREATE TABLE IF NOT EXISTS research_action_plan_milestone_dependencies','CREATE TABLE IF NOT EXISTS research_action_plan_task_links',
 'REFERENCES research_task_plans(id)','REFERENCES research_tasks(id)'
],'Phase 72 Section 2 migration');

$must('app/research-action-plans.php',[
 'research_action_plan_execution_ready','research_action_plan_ensure_task_plan','research_action_plan_create_milestone',
 'research_action_plan_add_milestone_dependency','research_action_plan_add_task','research_action_plan_set_task_dependencies',
 'research_action_plan_task_execution_ready','research_action_plan_set_milestone_status','research_action_plan_execution_transition_guard',
 'research_action_plan_sync_execution_task_plan','Milestone dependency would create a cycle','Task dependency would create a cycle',
 'Milestone dependencies can only change before the milestone starts','The Action Plan must be Active before milestone execution can start or complete.'
],'Action Plan execution orchestration');

$must('app/research-tasks.php',[
 "function_exists('research_action_plan_task_execution_ready')",
 'Waiting for Action Plan or milestone dependencies.'
],'Existing Research Task queue Action Plan gate');

$must('worker/research-task-worker.php',[
 "function_exists('research_action_plan_task_execution_ready')",
 'Waiting for Action Plan or milestone dependencies.'
],'Existing Research Task worker Action Plan gate');

$must('api/research-action-plans.php',[
 "'execution_detail','milestone_detail'","$action==='create_milestone'","$action==='set_milestone_status'",
 "$action==='add_milestone_dependency'","$action==='remove_milestone_dependency'","$action==='add_task'","$action==='set_task_dependencies'"
],'Phase 72 Section 2 API');

$must('docs/phase-72-decision-to-action-execution-strategic-follow-through.md',[
 '## Section 2 — Milestones, Tasks & Dependencies','Existing Research Tasks remain authoritative',
 'The existing Research Task queue and worker both enforce Action Plan readiness',
 'no parallel task engine is introduced'
],'Phase 72 Section 2 architecture');

$avoid('app/research-action-plans.php',[
 'INSERT INTO research_task_jobs(','ai_queue_job(','research_action_plan_task_jobs'
],'Action Plan execution must not bypass existing Task runtime');
foreach(['research-action-plan-worker.php','research-action-plans-worker.php','action-plan-worker.php','action-plan-task-worker.php'] as $worker)
  if(is_file($root.'/worker/'.$worker))$fail[]='Phase 72 Section 2 must not add a parallel Action Plan worker: '.$worker;

$must('tests/ci/run-full-regression.sh',['tests/phase72-section2-milestones-tasks-dependencies-db.php'],'Phase 72 Section 2 regression gate');
$must('.github/workflows/full-regression.yml',['phase72-section2-upgrade-from-093.php'],'Phase 72 Section 2 MySQL upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260927_094_research_action_plan_milestones_tasks_dependencies.sql','phase72-section2-milestones-tasks-dependencies-contract.php',
 'phase72-section2-milestones-tasks-dependencies-db.php','phase72-section2-upgrade-from-093.php'
],'Phase 72 Section 2 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 72 Section 2 Milestones, Tasks & Dependencies contracts passed.\n";
