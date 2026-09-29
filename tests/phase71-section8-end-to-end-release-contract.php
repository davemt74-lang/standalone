<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$body=$read($path);if($body!==''&&!str_contains($body,$needle))$fail[]=$message;};

foreach([
 'database/migrations/20260927_087_research_decisions_conclusions_foundation.sql',
 'database/migrations/20260927_088_research_mission_decision_handoff.sql',
 'database/migrations/20260927_089_research_decision_evidence_challenge_graph.sql',
 'database/migrations/20260927_090_research_decision_outcome_memory.sql',
 'database/migrations/20260927_091_research_decision_evolution_reconsideration.sql',
 'database/migrations/20260927_092_research_decision_command_center_team_review.sql',
 'app/research-decisions.php','api/research-decisions.php','research-decisions.php',
 'tests/phase71-section8-end-to-end-release-db.php','tests/ci/phase71-final-supported-upgrades.php'
] as $path)if(!is_file($root.'/'.$path))$fail[]='Phase 71 final release file missing '.$path;

foreach(range(1,7) as $section){
  $matches=glob($root.'/tests/phase71-section'.$section.'-*.php')?:[];
  if(count($matches)<2)$fail[]='Phase 71 Section '.$section.' must retain both contract and DB coverage.';
}

$need('docs/phase-71-research-decisions-conclusions-outcome-memory.md','## Section 8 — End-to-End Hardening & Release','Phase 71 final release documentation is missing.');
$need('docs/phase-71-research-decisions-conclusions-outcome-memory.md','migration 092 is the final Phase 71 schema boundary','Phase 71 final schema boundary must be explicit.');
$need('tests/ci/run-full-regression.sh','phase71-section8-end-to-end-release-db.php','Full regression must execute the Phase 71 final integrated journey.');
$need('.github/workflows/full-regression.yml','phase71-final-supported-upgrades.php','MySQL must execute the supported Phase 71 upgrade matrix.');
$need('.github/workflows/package-two-zips.yml','phase71-section8-end-to-end-release-contract.php','Production package must include the final Phase 71 release contract.');
$need('.github/workflows/package-two-zips.yml','phase71-final-supported-upgrades.php','Production package must include the supported Phase 71 upgrade matrix.');
$need('tests/ci/package-smoke.sh','Phase 71 End-to-End Hardening & Release package extensions passed.','Package smoke must explicitly validate final Phase 71 contents.');
$need('research-agent-research.php','/research-decisions.php?agent=','Canonical Agent Research navigation must expose Decisions.');
$need('app/research-surface-map.php',"'research-decisions.php'=>['classification'=>'MERGE_UI','target'=>'agent.research.decisions'",'Decision Command Center must retain canonical Phase 74 placement.');
$need('research-project.php','/research-decisions.php','Research project lifecycle navigation must expose Decision governance.');
$need('research-reviews.php','Decision reconsiderations','Review Center must advertise Decision/reconsideration review support.');
$need('app/agent-chat.php','Decision Memory context is read-only in Agent Chat','Agent Chat must preserve read-only Decision authority.');
$need('app/cognitive-feed.php','research_decision_cognitive_observations','Now must retain Decision attention integration.');
$need('app/research-reports.php','research_decision_report_snapshot','Report Studio must retain Decision snapshot integration.');
$need('app/research-reviews.php','$type===\'decision\'','Native Team Review must retain Decision subjects.');
$need('app/research-reviews.php','$type===\'decision_reconsideration\'','Native Team Review must retain Decision reconsideration subjects.');

if(is_file($root.'/database/migrations/20260927_093_research_decision_release.sql'))$fail[]='Section 8 must remain schema-free; migration 093 is not allowed.';
foreach(['research-decision-worker.php','research-decisions-worker.php','decision-memory-worker.php','decision-reconsideration-worker.php'] as $worker)
  if(is_file($root.'/worker/'.$worker))$fail[]='Phase 71 must not ship a parallel Decision worker: '.$worker;

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 71 Section 8 End-to-End Hardening & Release contracts passed.\n";
