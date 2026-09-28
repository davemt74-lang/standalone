<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$body=$read($path);if($body!==''&&!str_contains($body,$needle))$fail[]=$message;};

foreach([
 'database/migrations/20260928_099_portfolio_native_decision_handoff.sql',
 'database/migrations/20260928_100_cross_decision_pattern_memory.sql',
 'database/migrations/20260928_101_strategic_dependency_conflict_graph.sql',
 'database/migrations/20260928_102_recurring_strategic_review.sql',
 'database/migrations/20260928_103_executive_strategic_briefings_team_review.sql',
 'app/research-intelligence-decision-rollups.php','app/research-intelligence-pattern-memory.php','app/research-intelligence-strategic-graph.php',
 'app/research-intelligence-strategic-reviews.php','app/research-intelligence-strategic-briefings.php','app/research-intelligence-organizational-cognition.php',
 'tests/phase73-section8-end-to-end-release-db.php','tests/ci/phase73-final-supported-upgrades.php'
] as $path)if(!is_file($root.'/'.$path))$fail[]='Phase 73 final release file missing '.$path;

foreach(range(1,7) as $section){
  $matches=glob($root.'/tests/phase73-section'.$section.'-*.php')?:[];
  if(count($matches)<2)$fail[]='Phase 73 Section '.$section.' must retain contract and DB coverage.';
}

$need('docs/phase-73-portfolio-decision-intelligence-organizational-learning.md','## Section 8 — End-to-End Hardening & Release','Phase 73 final release documentation is missing.');
$need('docs/phase-73-portfolio-decision-intelligence-organizational-learning.md','migration 103 is the final Phase 73 schema boundary','Phase 73 final schema boundary must be explicit.');
$need('docs/phase-73-portfolio-decision-intelligence-organizational-learning.md','Portfolio → Decision → Action Plan → Outcome Memory → Pattern Memory → Strategic Review → Executive Strategic Briefing → Organizational Cognition','Phase 73 final integrated organizational-learning chain must be explicit.');
$need('tests/ci/run-static-contracts.sh','phase73-section8-end-to-end-release-contract.php','Static contracts must execute the Phase 73 final release contract.');
$need('tests/ci/run-full-regression.sh','phase73-section8-end-to-end-release-db.php','Full regression must execute the Phase 73 integrated release journey.');
$need('.github/workflows/full-regression.yml','phase73-final-supported-upgrades.php','MySQL must execute the final supported Phase 73 upgrade matrix.');
$need('.github/workflows/package-two-zips.yml','phase73-section8-end-to-end-release-contract.php','Production package must include the Phase 73 final release contract.');
$need('.github/workflows/package-two-zips.yml','phase73-section8-end-to-end-release-db.php','Production package must include the Phase 73 final integrated journey.');
$need('.github/workflows/package-two-zips.yml','phase73-final-supported-upgrades.php','Production package must include the Phase 73 final supported upgrade matrix.');
$need('tests/ci/package-smoke.sh','database/migrations/20260928_103_executive_strategic_briefings_team_review.sql','Package smoke must require the final Phase 73 migration.');
$need('tests/ci/package-smoke.sh','app/research-intelligence-strategic-briefings.php','Package smoke must require Strategic Briefing runtime.');
$need('tests/ci/package-smoke.sh','app/research-intelligence-organizational-cognition.php','Package smoke must require Organizational Cognition runtime.');
$need('tests/ci/package-smoke.sh','Phase 73 Section 6 Executive Strategic Briefings & Team Review package extensions passed.','Package smoke must explicitly validate Phase 73 Section 6.');
$need('tests/ci/package-smoke.sh','Phase 73 Section 7 Organizational Agent Cognition & Governed Follow-Through package extensions passed.','Package smoke must explicitly validate Phase 73 Section 7.');
$need('tests/ci/package-smoke.sh','Phase 73 End-to-End Hardening & Release package extensions passed.','Package smoke must explicitly validate final Phase 73 contents.');
$need('docs/RELEASE-V1.1.md','migration 103','V1.1 deployment runbook must identify migration 103 as current schema boundary.');
$need('docs/RELEASE-V1.1.md','Phase 73 CI','V1.1 deployment runbook must document the Phase 73 final upgrade matrix.');
$need('docs/RELEASE-V1.1.md','Organizational Cognition','V1.1 post-deploy runbook must include organizational cognition validation.');
$need('app/agent-chat.php','Organizational Strategic Cognition is read-only reasoning context','Agent Chat must retain the Section 7 read-only authority boundary.');
$need('app/research-intelligence-organizational-cognition.php','research_intelligence_organizational_portfolio_cognition','Final release must retain organizational cognition.');
$need('app/research-intelligence-strategic-briefings.php','research_intelligence_strategic_briefing_assert_publication_ready','Final release must retain Strategic Briefing publication governance.');
$need('app/research-intelligence-strategic-reviews.php','research_intelligence_strategic_review_packet','Final release must retain frozen strategic packet hashing.');
$need('app/research-intelligence-strategic-graph.php','research_intelligence_strategic_edge_upsert','Final release must retain explicit strategic relationship governance.');
$need('app/research-intelligence-pattern-memory.php','research_intelligence_portfolio_pattern_refresh','Final release must retain exact Pattern Memory.');
$need('app/research-intelligence-decision-rollups.php','research_intelligence_portfolio_decision_execution_rollup','Final release must retain native Decision/Action Plan rollups.');

if(glob($root.'/database/migrations/*_104_*.sql'))$fail[]='Phase 73 Section 8 must remain schema-free; migration 104 is not allowed.';
foreach(['research-intelligence-worker.php','strategic-review-worker.php','strategic-briefing-worker.php','organizational-cognition-worker.php','portfolio-decision-worker.php'] as $worker)
  if(is_file($root.'/worker/'.$worker))$fail[]='Phase 73 must not ship a parallel authority worker: '.$worker;

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 73 Section 8 End-to-End Hardening & Release contracts passed.\n";
