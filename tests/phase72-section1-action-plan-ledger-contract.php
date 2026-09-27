<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{
    $path=$root.'/'.$file;if(!is_file($path)){$fail[]=$label.' missing '.$file;return;}
    $content=(string)file_get_contents($path);foreach($needles as $needle)if(!str_contains($content,$needle))$fail[]=$label.' missing '.$needle.' in '.$file;
};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{
    $path=$root.'/'.$file;if(!is_file($path))return;$content=(string)file_get_contents($path);foreach($needles as $needle)if(str_contains($content,$needle))$fail[]=$label.' must not contain '.$needle.' in '.$file;
};

$must('database/migrations/20260927_093_research_action_plan_ledger_foundation.sql',[
 'CREATE TABLE IF NOT EXISTS research_action_plans','CREATE TABLE IF NOT EXISTS research_action_plan_versions','CREATE TABLE IF NOT EXISTS research_action_plan_events',
 "ENUM('draft','proposed','active','paused','completed','cancelled','archived')",'source_decision_snapshot_json','source_decision_revision','idempotency_key'
],'Phase 72 Section 1 migration');
$must('app/research-action-plans.php',[
 'research_action_plans_ready','research_action_plan_from_decision','research_action_plan_update','research_action_plan_set_status',
 'research_action_plan_source_snapshot','research_action_plan_source_stale','research_action_plan_snapshot','research_action_plan_refresh_revision',
 'Agent actions cannot activate, complete, or cancel','At least one success measure is required before activation'
],'Action Plan Ledger runtime');
$must('api/research-action-plans.php',[
 "'list','summary','detail'",'$action===\'from_decision\'','$action===\'update\'','$action===\'set_status\'',
 'require_api_mutation_auth','rate_limit_api_or_429'
],'Action Plan Ledger API');
$must('app/bootstrap.php',["require_once __DIR__ . '/research-action-plans.php';"],'Action Plan bootstrap');
$must('docs/phase-72-decision-to-action-execution-strategic-follow-through.md',[
 '## Section 1 — Action Plan Ledger Foundation','every Action Plan begins Draft','stale Decision provenance blocks activation',
 'no Research Task or Research Program is created by the foundation','no scheduler, worker, cron, queue'
],'Phase 72 Section 1 architecture');
$avoid('app/research-action-plans.php',[
 'research_task_create(','research_program_create(','research_program_enqueue(','research_action_plan_worker','research_action_plan_jobs'
],'Action Plan foundation isolation');
foreach(['research-action-plan-worker.php','research-action-plans-worker.php','action-plan-worker.php'] as $worker)if(is_file($root.'/worker/'.$worker))$fail[]='Phase 72 Section 1 must not add an Action Plan worker: '.$worker;
$must('tests/ci/run-full-regression.sh',['tests/phase72-section1-action-plan-ledger-db.php'],'Phase 72 Section 1 regression gate');
$must('.github/workflows/full-regression.yml',['phase72-section1-upgrade-from-092.php'],'Phase 72 Section 1 MySQL upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260927_093_research_action_plan_ledger_foundation.sql','app/research-action-plans.php','api/research-action-plans.php',
 'phase72-section1-action-plan-ledger-contract.php','phase72-section1-action-plan-ledger-db.php','phase72-section1-upgrade-from-092.php'
],'Phase 72 Section 1 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 72 Section 1 Action Plan Ledger Foundation contracts passed.\n";
