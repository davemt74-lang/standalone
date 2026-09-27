<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p)){$fail[]=$label.' missing '.$file;return;}$c=(string)file_get_contents($p);foreach($needles as $n)if(!str_contains($c,$n))$fail[]=$label.' missing '.$n.' in '.$file;};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p))return;$c=(string)file_get_contents($p);foreach($needles as $n)if(str_contains($c,$n))$fail[]=$label.' must not contain '.$n.' in '.$file;};

$must('database/migrations/20260927_090_research_decision_outcome_memory.sql',[
 'CREATE TABLE IF NOT EXISTS research_decision_outcomes','CREATE TABLE IF NOT EXISTS research_decision_outcome_versions',
 "ENUM('unresolved','success','partial','failure','mixed')","FOREIGN KEY(outcome_event_id) REFERENCES research_outcome_events"
],'Phase 71 Section 4 migration');
$must('app/research-outcomes.php',["$type==='decision'",'research_decision_access'],'Outcome Learning Decision reference compatibility');
$must('app/research-decisions.php',[
 'research_decision_outcomes_ready','research_decision_record_outcome','research_decision_update_outcome',
 'research_decision_outcome_versions','research_decision_outcome_summary','research_outcome_record',
 'decision_outcome_recorded','decision_outcome_updated'
],'Decision Outcome Memory runtime');
$must('api/research-decisions.php',[
 "'outcome_detail','outcome_summary'",'$action===\'record_outcome\'','$action===\'update_outcome\''
],'Decision Outcome Memory API');
$must('docs/phase-71-research-decisions-conclusions-outcome-memory.md',[
 '## Section 4 — Outcome Memory','research_outcome_events','outcome revisions do not rewrite Decision configuration revisions',
 'no scheduler, worker, cron, queue'
],'Phase 71 Section 4 architecture');
$avoid('app/research-decisions.php',[
 'research_decision_outcome_worker','research_decision_outcome_jobs','research_program_enqueue(',
 "UPDATE research_decisions SET status='accepted'","UPDATE research_decisions SET status='rejected'"
],'Outcome Memory isolation');
$must('tests/ci/run-full-regression.sh',['tests/phase71-section4-outcome-memory-db.php'],'Phase 71 Section 4 regression gate');
$must('.github/workflows/full-regression.yml',['phase71-section4-upgrade-from-089.php'],'Phase 71 Section 4 MySQL upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260927_090_research_decision_outcome_memory.sql','phase71-section4-outcome-memory-contract.php',
 'phase71-section4-outcome-memory-db.php','phase71-section4-upgrade-from-089.php'
],'Phase 71 Section 4 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 71 Section 4 Outcome Memory contracts passed.\n";
