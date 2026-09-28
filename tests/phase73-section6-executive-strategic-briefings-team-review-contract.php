<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p)){$fail[]=$label.' missing '.$file;return;}$c=(string)file_get_contents($p);foreach($needles as $n)if(!str_contains($c,$n))$fail[]=$label.' missing '.$n.' in '.$file;};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p))return;$c=(string)file_get_contents($p);foreach($needles as $n)if(str_contains($c,$n))$fail[]=$label.' must not contain '.$n.' in '.$file;};

$must('database/migrations/20260928_103_executive_strategic_briefings_team_review.sql',[
 'research_intelligence_strategic_briefings','executive_briefing_id','source_strategic_review_id','collaborative_review_id',
 'packet_json JSON NOT NULL','packet_hash CHAR(64) NOT NULL','dedupe_key CHAR(64) NOT NULL UNIQUE','uq_p73_strategic_briefing_executive'
],'Phase 73 Section 6 migration');
$must('app/research-intelligence-strategic-briefings.php',[
 'research_intelligence_strategic_briefings_ready','research_intelligence_strategic_briefing_render_document',
 'research_intelligence_strategic_briefing_create','research_intelligence_strategic_briefing_access',
 'research_intelligence_strategic_briefing_assert_publication_ready','research_intelligence_portfolio_strategic_briefing_summary',
 'research_intelligence_organization_strategic_briefing_center',
 "research_review_create($pdo,$viewer,'document'","'unanimous_approval'","'current_drift'"
],'Phase 73 Section 6 runtime');
$avoid('app/research-intelligence-strategic-briefings.php',[
 'research_decision_set_status(','research_action_plan_set_status(','research_action_plan_resolve_execution_variance(',
 'research_decision_record_outcome(','research_action_plan_record_outcome_handoff(','ai_run(','INSERT INTO research_tasks',
 'UPDATE research_decisions','UPDATE research_action_plans','INSERT INTO research_action_plans'
],'Phase 73 Section 6 authority');
$must('app/research-intelligence-portfolios.php',[
 'research_intelligence_strategic_briefing_assert_publication_ready'
],'Strategic Briefing publication gate');
$must('app/bootstrap.php',["research-intelligence-strategic-briefings.php"],'Section 6 bootstrap');
$must('api/research-intelligence-portfolios.php',["'strategic_briefing_create'","research_intelligence_strategic_briefing_create"],'Section 6 API');
$must('app/research-intelligence-operations.php',[
 "'strategic_briefings'=>","'strategic_briefing_summary'=>","'strategic_briefing_attention'=>"
],'Section 6 Portfolio and Command Center integration');
$must('research-intelligence-portfolios.php',[
 'PHASE 73 · EXECUTIVE STRATEGIC BRIEFINGS &amp; TEAM REVIEW','Create Executive Strategic Briefing','Open Team Review','PUBLICATION READY'
],'Section 6 Portfolio UI');
$must('research-intelligence-command-center.php',[
 'EXECUTIVE STRATEGIC BRIEFINGS &amp; TEAM REVIEW','Team-reviewed leadership briefs','strategic_briefing_attention','Strategic briefs ready'
],'Section 6 Command Center UI');
$must('docs/phase-73-portfolio-decision-intelligence-organizational-learning.md',[
 '## Section 6 — Executive Strategic Briefings & Team Review','Migration 103','unanimously approved',
 'document edit makes the Team Review stale','Phase 59 remains the only publication workflow','no new scheduler, worker, queue, or publishing engine'
],'Section 6 architecture');
$must('tests/ci/run-static-contracts.sh',['phase73-section6-executive-strategic-briefings-team-review-contract.php'],'Section 6 static gate');
$must('tests/ci/run-full-regression.sh',['phase73-section6-executive-strategic-briefings-team-review-db.php'],'Section 6 DB gate');
$must('.github/workflows/full-regression.yml',['phase73-section6-upgrade-from-102.php'],'Section 6 upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260928_103_executive_strategic_briefings_team_review.sql','app/research-intelligence-strategic-briefings.php',
 'phase73-section6-executive-strategic-briefings-team-review-contract.php','phase73-section6-executive-strategic-briefings-team-review-db.php','phase73-section6-upgrade-from-102.php'
],'Section 6 package gate');
if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 73 Section 6 Executive Strategic Briefings & Team Review contracts passed.\n";
