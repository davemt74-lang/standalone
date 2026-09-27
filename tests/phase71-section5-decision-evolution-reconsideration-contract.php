<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p)){$fail[]=$label.' missing '.$file;return;}$c=(string)file_get_contents($p);foreach($needles as $n)if(!str_contains($c,$n))$fail[]=$label.' missing '.$n.' in '.$file;};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p))return;$c=(string)file_get_contents($p);foreach($needles as $n)if(str_contains($c,$n))$fail[]=$label.' must not contain '.$n.' in '.$file;};

$must('database/migrations/20260927_091_research_decision_evolution_reconsideration.sql',[
 'CREATE TABLE IF NOT EXISTS research_decision_reconsiderations','CREATE TABLE IF NOT EXISTS research_decision_reconsideration_events',
 "ENUM('manual','evidence_change','challenge','outcome','reversal_condition','assumption')",
 "ENUM('undetermined','retain','reopen','supersede','defer')",'opening_snapshot_json','opening_context_hash','applied_at'
],'Phase 71 Section 5 migration');
$must('app/research-decisions.php',[
 'research_decision_reconsiderations_ready','research_decision_reconsideration_signals','research_decision_open_reconsideration',
 'research_decision_set_reconsideration_status','research_decision_apply_reconsideration','research_decision_evolution_timeline',
 'Decision changed after reconsideration opened','An applied reconsideration is immutable'
],'Decision evolution/reconsideration runtime');
$must('api/research-decisions.php',[
 "'reconsideration_detail','reconsideration_signals','evolution'",'$action===\'open_reconsideration\'',
 '$action===\'set_reconsideration_status\'','$action===\'apply_reconsideration\''
],'Decision reconsideration API');
$must('docs/phase-71-research-decisions-conclusions-outcome-memory.md',[
 '## Section 5 — Decision Evolution & Reconsideration','Signals are descriptive','A separate explicit Apply action is required',
 'stale cases cannot be applied'
],'Phase 71 Section 5 architecture');
$avoid('app/research-decisions.php',[
 'research_decision_reconsideration_worker','research_decision_reconsideration_jobs','research_program_enqueue('
],'Decision reconsideration isolation');
$must('tests/ci/run-full-regression.sh',['tests/phase71-section5-decision-evolution-reconsideration-db.php'],'Phase 71 Section 5 regression gate');
$must('.github/workflows/full-regression.yml',['phase71-section5-upgrade-from-090.php'],'Phase 71 Section 5 MySQL upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260927_091_research_decision_evolution_reconsideration.sql','phase71-section5-decision-evolution-reconsideration-contract.php',
 'phase71-section5-decision-evolution-reconsideration-db.php','phase71-section5-upgrade-from-090.php'
],'Phase 71 Section 5 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 71 Section 5 Decision Evolution & Reconsideration contracts passed.\n";
