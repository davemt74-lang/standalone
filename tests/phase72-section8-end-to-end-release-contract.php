<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$body=$read($path);if($body!==''&&!str_contains($body,$needle))$fail[]=$message;};

foreach([
 'database/migrations/20260927_093_research_action_plan_ledger_foundation.sql',
 'database/migrations/20260927_094_research_action_plan_milestones_tasks_dependencies.sql',
 'database/migrations/20260927_095_research_action_plan_program_follow_through.sql',
 'database/migrations/20260928_096_research_action_plan_execution_evidence_variance.sql',
 'database/migrations/20260928_097_research_action_plan_team_command_review.sql',
 'database/migrations/20260928_098_research_action_plan_outcome_handoff.sql',
 'app/research-action-plans.php','app/research-action-plan-variance.php','app/research-action-plan-cognition.php','app/research-action-plan-outcomes.php',
 'api/research-action-plans.php','research-action-plans.php',
 'tests/phase72-section8-end-to-end-release-db.php','tests/ci/phase72-final-supported-upgrades.php'
] as $path)if(!is_file($root.'/'.$path))$fail[]='Phase 72 final release file missing '.$path;

foreach(range(1,7) as $section){
  $matches=glob($root.'/tests/phase72-section'.$section.'-*.php')?:[];
  if(count($matches)<2)$fail[]='Phase 72 Section '.$section.' must retain both contract and DB coverage.';
}

$need('docs/phase-72-decision-to-action-execution-strategic-follow-through.md','## Section 8 — End-to-End Hardening & Release','Phase 72 final release documentation is missing.');
$need('docs/phase-72-decision-to-action-execution-strategic-follow-through.md','migration 098 is the final Phase 72 schema boundary','Phase 72 final schema boundary must be explicit.');
$need('tests/ci/run-full-regression.sh','phase72-section8-end-to-end-release-db.php','Full regression must execute the Phase 72 final integrated journey.');
$need('.github/workflows/full-regression.yml','phase72-final-supported-upgrades.php','MySQL must execute the supported Phase 72 upgrade matrix.');
$need('.github/workflows/package-two-zips.yml','phase72-section8-end-to-end-release-contract.php','Production package must include the final Phase 72 release contract.');
$need('.github/workflows/package-two-zips.yml','phase72-final-supported-upgrades.php','Production package must include the supported Phase 72 upgrade matrix.');
$need('tests/ci/package-smoke.sh','Phase 72 End-to-End Hardening & Release package extensions passed.','Package smoke must explicitly validate final Phase 72 contents.');
$need('tests/ci/package-smoke.sh','database/migrations/20260928_098_research_action_plan_outcome_handoff.sql','Package smoke must require the final Phase 72 migration.');
$need('tests/ci/package-smoke.sh','app/research-action-plan-outcomes.php','Package smoke must require final Action Plan outcome runtime.');
$need('tests/ci/package-smoke.sh','research-action-plans.php','Package smoke must require the Action Plan Command Center.');
$need('app/agent-chat.php','Action Plan Strategic Memory is also read-only context','Agent Chat must preserve read-only Action Plan strategic authority.');
$need('app/cognitive-feed.php','research_action_plan_cognitive_observations','Now must retain Action Plan strategic attention integration.');
$need('app/research-reviews.php',"$type==='action_plan'",'Native Team Review must retain Action Plan subjects.');
$need('app/research-action-plans.php','research_action_plan_command_center','Action Plan Command Center model must remain present.');
$need('app/research-action-plan-outcomes.php','research_decision_record_outcome','Final Action Plan handoff must continue through existing Decision Outcome Memory.');
$need('docs/RELEASE-V1.1.md','migration 098','V1.1 deployment runbook must identify the current Phase 72 migration boundary.');
$need('docs/RELEASE-V1.1.md','Decision → Action Plan → Execution → Outcome Memory','V1.1 deployment runbook must contain the Phase 72 post-deploy learning journey.');

if(is_file($root.'/database/migrations/20260928_099_phase72_release.sql')||is_file($root.'/database/migrations/20260928_099_research_action_plan_release.sql'))$fail[]='Section 8 must remain schema-free; migration 099 is not allowed.';
foreach(['research-action-plan-worker.php','research-action-plans-worker.php','action-plan-worker.php','action-plan-outcome-worker.php','action-plan-cognition-worker.php'] as $worker)
  if(is_file($root.'/worker/'.$worker))$fail[]='Phase 72 must not ship a parallel Action Plan worker: '.$worker;

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 72 Section 8 End-to-End Hardening & Release contracts passed.\n";
