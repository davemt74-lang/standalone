<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p)){$fail[]=$label.' missing '.$file;return;}$c=(string)file_get_contents($p);foreach($needles as $n)if(!str_contains($c,$n))$fail[]=$label.' missing '.$n.' in '.$file;};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p))return;$c=(string)file_get_contents($p);foreach($needles as $n)if(str_contains($c,$n))$fail[]=$label.' must not contain '.$n.' in '.$file;};

$must('database/migrations/20260927_089_research_decision_evidence_challenge_graph.sql',[
 'CREATE TABLE IF NOT EXISTS research_decision_challenges','CREATE TABLE IF NOT EXISTS research_decision_challenge_refs',
 "ENUM('contradiction','assumption','uncertainty','alternative','reversal_condition','question')",
 "ENUM('supports_challenge','counters_challenge','context','source')"
],'Phase 71 Section 3 migration');
$must('app/research-decisions.php',[
 'research_decision_challenges_ready','research_decision_add_challenge','research_decision_update_challenge',
 'research_decision_set_challenge_status','research_decision_add_challenge_ref','research_decision_remove_challenge_ref',
 'research_decision_evidence_graph','research_decision_challenge_config_rows'
],'Decision challenge graph runtime');
$must('api/research-decisions.php',[
 "'list','summary','detail','graph'",'$action===\'add_challenge\'','$action===\'update_challenge\'',
 '$action===\'set_challenge_status\'','$action===\'add_challenge_ref\'','$action===\'remove_challenge_ref\''
],'Decision challenge graph API');
$must('docs/phase-71-research-decisions-conclusions-outcome-memory.md',[
 '## Section 3 — Decision Evidence & Challenge Graph','reversal conditions','challenge status never automatically changes Decision status',
 'cross-project challenge references are rejected'
],'Phase 71 Section 3 architecture');
$avoid('app/research-decisions.php',[
 'research_decision_challenge_worker','research_decision_challenge_jobs','research_program_enqueue(','UPDATE research_decisions SET status=\'accepted\''
],'Challenge graph isolation');
$must('tests/ci/run-full-regression.sh',['tests/phase71-section3-decision-evidence-challenge-graph-db.php'],'Phase 71 Section 3 regression gate');
$must('.github/workflows/full-regression.yml',['phase71-section3-upgrade-from-088.php'],'Phase 71 Section 3 MySQL upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260927_089_research_decision_evidence_challenge_graph.sql','phase71-section3-decision-evidence-challenge-graph-contract.php',
 'phase71-section3-decision-evidence-challenge-graph-db.php','phase71-section3-upgrade-from-088.php'
],'Phase 71 Section 3 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 71 Section 3 Decision Evidence & Challenge Graph contracts passed.\n";
