<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p)){$fail[]=$label.' missing '.$file;return;}$c=(string)file_get_contents($p);foreach($needles as $n)if(!str_contains($c,$n))$fail[]=$label.' missing '.$n.' in '.$file;};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p))return;$c=(string)file_get_contents($p);foreach($needles as $n)if(str_contains($c,$n))$fail[]=$label.' must not contain '.$n.' in '.$file;};

$must('database/migrations/20260927_092_research_decision_command_center_team_review.sql',[
 "decision','decision_reconsideration",'ALTER TABLE research_reviews','MODIFY COLUMN subject_type'
],'Phase 71 Section 7 migration');
$must('app/research-decisions.php',[
 'research_decision_review_snapshot','research_decision_review_state_hash','research_decision_reconsideration_review_state_hash',
 'research_decision_review_overview','research_decision_command_center','attention_reasons'
],'Decision Command Center model');
$must('app/research-reviews.php',[
 '$type===\'decision\'','$type===\'decision_reconsideration\'','research_decision_review_state_hash',
 'research_decision_reconsideration_review_state_hash','/research-decisions.php?decision='
],'Native Decision Team Review');
$must('api/research-decisions.php',["'command_center'",'$action===\'command_center\''],'Decision Command Center API');
$must('research-decisions.php',[
 'Decision Command Center','Request Team Review','Request review of this reconsideration','Explicit Decision action',
 'Team review is advisory'
],'Decision Command Center UI');
$must('docs/phase-71-research-decisions-conclusions-outcome-memory.md',[
 '## Section 7 — Decision Command Center + Team Review','Team review remains advisory',
 'review staleness is deterministic and state-hash based','no parallel review engine is introduced'
],'Phase 71 Section 7 architecture');
$avoid('app/research-reviews.php',[
 'research_decision_set_status(','research_decision_apply_reconsideration(','research_decision_set_reconsideration_status('
],'Review Center advisory isolation');
$must('tests/ci/run-full-regression.sh',['tests/phase71-section7-decision-command-center-team-review-db.php'],'Phase 71 Section 7 regression gate');
$must('.github/workflows/full-regression.yml',['phase71-section7-upgrade-from-091.php'],'Phase 71 Section 7 MySQL upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260927_092_research_decision_command_center_team_review.sql','phase71-section7-decision-command-center-team-review-contract.php',
 'phase71-section7-decision-command-center-team-review-db.php','phase71-section7-upgrade-from-091.php','research-decisions.php'
],'Phase 71 Section 7 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 71 Section 7 Decision Command Center + Team Review contracts passed.\n";
