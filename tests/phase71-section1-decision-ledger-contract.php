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

$must('database/migrations/20260927_087_research_decisions_conclusions_foundation.sql',[
 'CREATE TABLE IF NOT EXISTS research_decisions','CREATE TABLE IF NOT EXISTS research_decision_versions',
 'CREATE TABLE IF NOT EXISTS research_decision_refs','CREATE TABLE IF NOT EXISTS research_decision_events',
 "ENUM('decision','conclusion','recommendation')","ENUM('supports','contradicts','context','source','assumption','alternative')"
],'Phase 71 Section 1 migration');
$must('app/research-decisions.php',[
 'research_decisions_ready','research_decision_create','research_decision_update','research_decision_set_status',
 'research_decision_snapshot','research_decision_refresh_revision','research_decision_ref_access',
 'research_decision_add_ref','research_decision_remove_ref','research_decision_detail','research_decision_summary'
],'Decision ledger runtime');
$must('api/research-decisions.php',[
 "'list','summary','detail'",'$action===\'create\'','$action===\'update\'','$action===\'set_status\'','$action===\'add_ref\'','$action===\'remove_ref\'',
 'require_api_mutation_auth','rate_limit_api_or_429'
],'Decision ledger API');
$must('app/bootstrap.php',["require_once __DIR__ . '/research-decisions.php';"],'Decision bootstrap');
$must('docs/phase-71-research-decisions-conclusions-outcome-memory.md',[
 '## Section 1 — Decision & Conclusion Ledger Foundation','Existing Outcome Learning history is not migrated','No decision is created automatically by a Mission',
 'No scheduler, worker, cron, queue'
],'Phase 71 Section 1 architecture');
$avoid('app/research-decisions.php',['research_decision_jobs','research_decision_worker','research_program_enqueue(','research_outcome_events SET'],'Decision foundation isolation');
foreach(['research-decision-worker.php','research-decisions-worker.php','decision-worker.php'] as $worker)if(is_file($root.'/worker/'.$worker))$fail[]='Phase 71 Section 1 must not add a Decision worker: '.$worker;
$must('tests/ci/run-full-regression.sh',['tests/phase71-section1-decision-ledger-db.php'],'Phase 71 Section 1 regression gate');
$must('.github/workflows/full-regression.yml',['phase71-section1-upgrade-from-086.php'],'Phase 71 Section 1 MySQL upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260927_087_research_decisions_conclusions_foundation.sql','app/research-decisions.php','api/research-decisions.php',
 'phase71-section1-decision-ledger-contract.php','phase71-section1-decision-ledger-db.php','phase71-section1-upgrade-from-086.php'
],'Phase 71 Section 1 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 71 Section 1 Decision & Conclusion Ledger contracts passed.\n";
