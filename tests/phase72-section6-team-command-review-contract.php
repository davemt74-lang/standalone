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

$must('database/migrations/20260928_097_research_action_plan_team_command_review.sql',[
 "'action_plan'",'ALTER TABLE research_reviews','MODIFY COLUMN subject_type'
],'Phase 72 Section 6 migration');

$must('app/research-action-plans.php',[
 'research_action_plan_review_snapshot','research_action_plan_review_state_hash','research_action_plan_review_overview',
 'research_action_plan_command_center','strategic_state','attention_reasons','open_variances','review_url'
],'Action Plan Team Command model');

$must('app/research-reviews.php',[
 "$type==='action_plan'",'research_action_plan_review_state_hash','Action Plan revision','/research-action-plans.php?action_plan='
],'Native Action Plan Team Review');

$must('api/research-action-plans.php',[
 "'command_center'","$action==='command_center'",'research_action_plan_command_center'
],'Action Plan Command Center API');

$must('research-action-plans.php',[
 'Action Plan Command Center','Request Team Review','Team Review is advisory','Explicit Action Plan action',
 'Decision Review','Open variances'
],'Action Plan Command Center UI');

$must('research-reviews.php',['Action Plans','Action Plan'],'Review Center Action Plan discoverability');
$must('docs/phase-72-decision-to-action-execution-strategic-follow-through.md',[
 '## Section 6 — Team Command & Review','Team Review remains advisory','state-hash pinned',
 'no parallel review engine is introduced'
],'Phase 72 Section 6 architecture');

$avoid('app/research-reviews.php',[
 'research_action_plan_set_status(','research_action_plan_resolve_execution_variance(','research_decision_set_status('
],'Review Center advisory isolation');

$must('tests/ci/run-static-contracts.sh',['phase72-section6-team-command-review-contract.php'],'Section 6 static gate');
$must('tests/ci/run-full-regression.sh',['phase72-section6-team-command-review-db.php'],'Section 6 regression gate');
$must('.github/workflows/full-regression.yml',['phase72-section6-upgrade-from-096.php'],'Section 6 MySQL upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260928_097_research_action_plan_team_command_review.sql','research-action-plans.php',
 'phase72-section6-team-command-review-contract.php','phase72-section6-team-command-review-db.php','phase72-section6-upgrade-from-096.php'
],'Section 6 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 72 Section 6 Team Command & Review contracts passed.\n";
