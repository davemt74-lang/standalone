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

$must('database/migrations/20260927_095_research_action_plan_program_follow_through.sql',[
 'CREATE TABLE IF NOT EXISTS research_action_plan_program_links',
 "ENUM('execution_review','success_measure_check','evidence_refresh','decision_follow_up')",
 'UNIQUE KEY uq_research_action_plan_program(program_id)',
 'UNIQUE KEY uq_research_action_plan_program_role(action_plan_id,program_role)',
 "'action_plan_status_changed'","'action_plan_source_stale'","'milestone_completed'","'execution_progress_changed'"
],'Phase 72 Section 3 migration');

$must('app/research-action-plans.php',[
 'research_action_plan_follow_through_ready','research_action_plan_program_roles','research_action_plan_program_defaults',
 'research_action_plan_create_program','research_action_plan_link_program','research_action_plan_set_program_sync',
 'research_action_plan_unlink_program','research_action_plan_sync_programs','research_action_plan_program_snapshot',
 'research_action_plan_program_compare_snapshots','agent_created_follow_through_requires_human_activation'
],'Action Plan follow-through runtime');

$must('app/research-programs.php',[
 "'action_plan'=>null",'research_action_plan_program_snapshot','research_action_plan_program_compare_snapshots'
],'Existing Research Program snapshot/delta integration');

$must('api/research-action-plans.php',[
 "'follow_through'","$action==='create_follow_through_program'","$action==='link_follow_through_program'",
 "$action==='set_follow_through_sync'","$action==='unlink_follow_through_program'"
],'Action Plan follow-through API');

$must('docs/phase-72-decision-to-action-execution-strategic-follow-through.md',[
 '## Section 3 — Programs & Recurring Follow-Through','Existing Research Programs remain authoritative',
 'Agent-originated Program creation is permitted only as a governed draft action',
 'no Action Plan-specific scheduler, cron, queue, Program worker, or run table is introduced'
],'Phase 72 Section 3 architecture');

$avoid('app/research-action-plans.php',[
 'research_program_enqueue(','research_program_enqueue_due(','research_program_claim(','INSERT INTO research_program_runs',
 'research_action_plan_program_runs','research_action_plan_program_jobs'
],'Action Plan follow-through scheduler isolation');
foreach(['research-action-plan-program-worker.php','action-plan-program-worker.php','action-plan-scheduler.php'] as $worker)
  if(is_file($root.'/worker/'.$worker))$fail[]='Phase 72 Section 3 must not add a parallel Program scheduler/worker: '.$worker;

$must('tests/ci/run-full-regression.sh',['tests/phase72-section3-programs-recurring-follow-through-db.php'],'Phase 72 Section 3 regression gate');
$must('.github/workflows/full-regression.yml',['phase72-section3-upgrade-from-094.php'],'Phase 72 Section 3 MySQL upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260927_095_research_action_plan_program_follow_through.sql',
 'phase72-section3-programs-recurring-follow-through-contract.php',
 'phase72-section3-programs-recurring-follow-through-db.php',
 'phase72-section3-upgrade-from-094.php'
],'Phase 72 Section 3 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 72 Section 3 Programs & Recurring Follow-Through contracts passed.\n";
