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

$must('database/migrations/20260928_098_research_action_plan_outcome_handoff.sql',[
 'CREATE TABLE IF NOT EXISTS research_action_plan_outcome_links','execution_baseline_id','handoff_state_hash','handoff_snapshot_json',
 'uq_rap_outcome_plan','uq_rap_outcome_decision_outcome'
],'Phase 72 Section 7 migration');

$must('app/research-action-plan-outcomes.php',[
 'research_action_plan_outcomes_ready','research_action_plan_outcome_link','research_action_plan_outcome_preview',
 'research_action_plan_record_outcome_handoff','Complete the Action Plan before recording its final Decision outcome.',
 'An explicit human outcome assessment is required.','Actual outcome summary is required.',
 'research_decision_record_outcome','action_plan_outcome_handoff:','Agent actions cannot record a final Action Plan outcome'
],'Section 7 outcome handoff runtime');

$must('app/research-outcomes.php',["$type==='action_plan'",'research_action_plan_access'],'Outcome Learning Action Plan reference');
$must('api/research-action-plans.php',["'outcome_preview'","$action==='record_outcome_handoff'",'research_action_plan_record_outcome_handoff'],'Section 7 Action Plan API');
$must('app/research-action-plan-cognition.php',['Final Outcome Memory:','Lessons learned:','final outcome recording'],'Section 7 Agent cognition');
$must('app/research-action-plans.php',['completed_without_outcome','outcomes_recorded',"'outcomes'","'outcome_handoff'"],'Section 7 Command Center learning gap');
$must('research-action-plans.php',['COMPLETION → OUTCOME MEMORY','Choose assessment','Actual result','Record final outcome','FINAL OUTCOME MEMORY'],'Section 7 completion UI');
$must('docs/phase-72-decision-to-action-execution-strategic-follow-through.md',[
 '## Section 7 — Completion, Outcome Handoff & Decision Learning','existing Decision Outcome Memory','explicit human assessment',
 'never automatically reopens or changes the source Decision'
],'Phase 72 Section 7 architecture');

$avoid('app/research-action-plan-outcomes.php',[
 'research_decision_set_status(','research_decision_apply_reconsideration(','research_action_plan_set_status(',
 'CREATE TABLE','research_action_plan_outcome_worker','research_program_enqueue('
],'Section 7 authority isolation');

$must('tests/ci/run-static-contracts.sh',['phase72-section7-outcome-handoff-contract.php'],'Section 7 static gate');
$must('tests/ci/run-full-regression.sh',['phase72-section7-outcome-handoff-db.php'],'Section 7 regression gate');
$must('.github/workflows/full-regression.yml',['phase72-section7-upgrade-from-097.php'],'Section 7 MySQL upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260928_098_research_action_plan_outcome_handoff.sql','app/research-action-plan-outcomes.php',
 'phase72-section7-outcome-handoff-contract.php','phase72-section7-outcome-handoff-db.php','phase72-section7-upgrade-from-097.php'
],'Section 7 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 72 Section 7 Completion, Outcome Handoff & Decision Learning contracts passed.\n";
