<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p)){$fail[]=$label.' missing '.$file;return;}$c=(string)file_get_contents($p);foreach($needles as $n)if(!str_contains($c,$n))$fail[]=$label.' missing '.$n.' in '.$file;};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p))return;$c=(string)file_get_contents($p);foreach($needles as $n)if(str_contains($c,$n))$fail[]=$label.' must not contain '.$n.' in '.$file;};

$must('database/migrations/20260927_088_research_mission_decision_handoff.sql',[
 'CREATE TABLE IF NOT EXISTS research_decision_handoffs','mission_revision','mission_state_hash','snapshot_json','idempotency_key',
 'UNIQUE KEY uq_research_decision_handoff_idempotency'
],'Phase 71 Section 2 migration');
$must('app/research-decisions.php',[
 'research_decision_handoffs_ready','research_decision_handoff_for_decision','research_decision_mission_snapshot',
 'research_decision_mission_refs','research_decision_from_mission','decision_created_from_mission','FOR UPDATE'
],'Mission Decision handoff runtime');
$must('api/research-decisions.php',['$action===\'from_mission\''],'Mission Decision handoff API');
$must('docs/phase-71-research-decisions-conclusions-outcome-memory.md',[
 '## Section 2 — Mission → Decision Handoff','resulting Decision is created as **Proposed**','Mission confidence is not silently converted',
 'later Mission revisions cannot mutate an earlier handoff snapshot'
],'Phase 71 Section 2 architecture');
$avoid('app/research-decisions.php',['research_decision_handoff_worker','research_decision_handoff_jobs','research_program_enqueue('],'Mission Decision handoff isolation');
$must('tests/ci/run-full-regression.sh',['tests/phase71-section2-mission-decision-handoff-db.php'],'Phase 71 Section 2 regression gate');
$must('.github/workflows/full-regression.yml',['phase71-section2-upgrade-from-087.php'],'Phase 71 Section 2 MySQL upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260927_088_research_mission_decision_handoff.sql','phase71-section2-mission-decision-handoff-contract.php',
 'phase71-section2-mission-decision-handoff-db.php','phase71-section2-upgrade-from-087.php'
],'Phase 71 Section 2 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 71 Section 2 Mission to Decision Handoff contracts passed.\n";
