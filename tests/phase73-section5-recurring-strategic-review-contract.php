<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p)){$fail[]=$label.' missing '.$file;return;}$c=(string)file_get_contents($p);foreach($needles as $n)if(!str_contains($c,$n))$fail[]=$label.' missing '.$n.' in '.$file;};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p))return;$c=(string)file_get_contents($p);foreach($needles as $n)if(str_contains($c,$n))$fail[]=$label.' must not contain '.$n.' in '.$file;};

$must('database/migrations/20260928_102_recurring_strategic_review.sql',[
 "strategic_review'","research_intelligence_strategic_reviews","research_intelligence_strategic_review_settings",
 "cadence ENUM('every_cycle','weekly','monthly','quarterly')","dedupe_key CHAR(64) NOT NULL UNIQUE"
],'Phase 73 Section 5 migration');
$must('app/research-intelligence-strategic-reviews.php',[
 'research_intelligence_strategic_reviews_ready','research_intelligence_strategic_review_configure',
 'research_intelligence_strategic_review_packet','research_intelligence_strategic_review_create',
 'research_intelligence_strategic_review_maybe_create_for_cycle','research_intelligence_strategic_review_subject',
 'research_intelligence_organization_strategic_review_center',
 "'state_hash'=>hash('sha256'","No configured Strategic Review reviewer currently has access"
],'Phase 73 Section 5 runtime');
$avoid('app/research-intelligence-strategic-reviews.php',[
 'research_decision_set_status(','research_action_plan_set_status(','research_decision_record_outcome(',
 'research_action_plan_record_outcome_handoff(','ai_run(','INSERT INTO research_tasks','UPDATE research_decisions','UPDATE research_action_plans'
],'Phase 73 Section 5 authority');
$must('app/research-reviews.php',["'strategic_review'","research_intelligence_strategic_review_subject"],'Collaborative Review subject bridge');
$must('app/research-intelligence-operations.php',[
 'research_intelligence_strategic_review_maybe_create_for_cycle','strategic_review_cycle_failed',
 "'strategic_reviews'=>","'strategic_review_summary'=>","'strategic_review_attention'=>"
],'Portfolio cycle and Command Center integration');
$must('api/research-intelligence-portfolios.php',["'strategic_review_configure'","'strategic_review_create'"],'Section 5 API');
$must('research-intelligence-portfolios.php',[
 'PHASE 73 · RECURRING STRATEGIC REVIEW','Save Strategic Review settings','Create Strategic Review now','Open Review Center'
],'Section 5 Portfolio UI');
$must('app/research-portfolios-ui.php',[
 'STRATEGIC REVIEW','Human review attention'
],'Section 5 canonical Portfolio attention UI');
$must('research-intelligence-portfolios.php',['Open strategic reviews'],'Section 5 canonical Portfolio Overview metric');
$must('docs/phase-73-portfolio-decision-intelligence-organizational-learning.md',[
 '## Section 5 — Recurring Strategic Review','no new worker, cron, queue, or independent scheduler',
 'frozen `strategic_review` subject','current drift','Strategic Review never mutates Decision or Action Plan lifecycle state'
],'Section 5 architecture');
$must('tests/ci/run-static-contracts.sh',['phase73-section5-recurring-strategic-review-contract.php'],'Section 5 static gate');
$must('tests/ci/run-full-regression.sh',['phase73-section5-recurring-strategic-review-db.php'],'Section 5 DB gate');
$must('.github/workflows/full-regression.yml',['phase73-section5-upgrade-from-101.php'],'Section 5 upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260928_102_recurring_strategic_review.sql','app/research-intelligence-strategic-reviews.php',
 'phase73-section5-recurring-strategic-review-contract.php','phase73-section5-recurring-strategic-review-db.php','phase73-section5-upgrade-from-101.php'
],'Section 5 package gate');
if($fail){fwrite(STDERR,implode("\n",array_unique($fail))."\n");exit(1);}
echo "Phase 73 Section 5 Recurring Strategic Review contracts passed.\n";
