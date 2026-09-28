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

$must('database/migrations/20260928_096_research_action_plan_execution_evidence_variance.sql',[
 'CREATE TABLE IF NOT EXISTS research_action_plan_execution_baselines',
 'CREATE TABLE IF NOT EXISTS research_action_plan_execution_observations',
 'CREATE TABLE IF NOT EXISTS research_action_plan_variances',
 "ENUM('schedule_delay','target_miss','assumption_changed','new_evidence','risk_realized','scope_change','execution_deviation')",
 'uq_rap_exec_baseline_revision','uq_rap_exec_observation_request','uq_rap_variance_fingerprint'
],'Phase 72 Section 4 migration');

$must('app/research-action-plan-variance.php',[
 'research_action_plan_variance_ready','research_action_plan_capture_execution_baseline',
 'research_action_plan_record_execution_observation','research_action_plan_refresh_execution_variances',
 'research_action_plan_resolve_execution_variance','research_action_plan_execution_variance_detail',
 'Agent actions cannot resolve an execution variance without explicit human governance.'
],'Execution evidence and variance runtime');

$must('app/research-action-plans.php',[
 'research_action_plan_capture_execution_baseline',"\$status==='active'"
],'Action Plan activation baseline integration');

$must('api/research-action-plans.php',[
 "'execution_variance_detail'","\$action==='record_execution_observation'","\$action==='refresh_execution_variances'","\$action==='resolve_execution_variance'"
],'Execution evidence and variance API');

$must('app/bootstrap.php',["require_once __DIR__ . '/research-action-plan-variance.php';"],'Section 4 bootstrap');
$must('docs/phase-72-decision-to-action-execution-strategic-follow-through.md',[
 '## Section 4 — Execution Evidence & Variance','immutable execution baseline','expected-vs-actual','no new worker, queue, scheduler'
],'Phase 72 Section 4 architecture');

$avoid('app/research-action-plan-variance.php',[
 'research_program_enqueue(','research_task_queue(','research_action_plan_variance_worker','research_action_plan_variance_jobs'
],'Section 4 observation-only isolation');
foreach(['research-action-plan-variance-worker.php','action-plan-variance-worker.php'] as $worker)
  if(is_file($root.'/worker/'.$worker))$fail[]='Phase 72 Section 4 must not add a variance worker: '.$worker;

$must('tests/ci/run-full-regression.sh',['tests/phase72-section4-execution-evidence-variance-db.php'],'Section 4 regression gate');
$must('.github/workflows/full-regression.yml',['phase72-section4-upgrade-from-095.php'],'Section 4 MySQL upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260928_096_research_action_plan_execution_evidence_variance.sql','app/research-action-plan-variance.php',
 'phase72-section4-execution-evidence-variance-contract.php','phase72-section4-execution-evidence-variance-db.php','phase72-section4-upgrade-from-095.php'
],'Section 4 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 72 Section 4 Execution Evidence & Variance contracts passed.\n";
