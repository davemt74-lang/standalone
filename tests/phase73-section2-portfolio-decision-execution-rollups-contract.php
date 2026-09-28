<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{
  $path=$root.'/'.$file;if(!is_file($path)){$fail[]=$label.' missing '.$file;return;}$c=(string)file_get_contents($path);
  foreach($needles as $needle)if(!str_contains($c,$needle))$fail[]=$label.' missing '.$needle.' in '.$file;
};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{
  $path=$root.'/'.$file;if(!is_file($path))return;$c=(string)file_get_contents($path);
  foreach($needles as $needle)if(str_contains($c,$needle))$fail[]=$label.' must not contain '.$needle.' in '.$file;
};

$must('app/research-intelligence-decision-rollups.php',[
 'research_intelligence_portfolio_execution_rollups_ready',
 'research_intelligence_portfolio_subject_reviews',
 'research_intelligence_portfolio_action_plan_rollup',
 'research_intelligence_portfolio_decision_execution_rollup',
 'research_action_plan_execution_variance_summary',
 'research_action_plan_outcome_link',
 "subject_type=? AND subject_public_id=?",
 "'legacy_records'=>0",
 "'completed_without_outcome'=>0"
],'Phase 73 Section 2 rollup service');
$avoid('app/research-intelligence-decision-rollups.php',[
 'INSERT INTO research_decisions','INSERT INTO research_action_plans','UPDATE research_action_plans','DELETE FROM research_action_plans',
 'ai_run(','agent_action_confirm_execute('
],'Phase 73 Section 2 read-only authority');
$must('app/bootstrap.php',["research-intelligence-decision-rollups.php"],'Section 2 bootstrap');
$must('app/research-intelligence-operations.php',[
 "'decision_execution'=>","'execution_summary'=>","'decision_execution_attention'=>"
],'Section 2 command center integration');
$must('research-intelligence-portfolios.php',[
 'PHASE 73 · DECISION & EXECUTION ROLLUP','Strategic execution state','Material variances','Awaiting outcome'
],'Section 2 Portfolio UI');
$must('research-intelligence-command-center.php',[
 'PHASE 73 · PORTFOLIO DECISION & EXECUTION','DECISION & EXECUTION ATTENTION','Native decisions','Material variances'
],'Section 2 Command Center UI');
$must('docs/phase-73-portfolio-decision-intelligence-organizational-learning.md',[
 '## Section 2 — Portfolio Decision & Execution Rollups','schema-free','completed-without-outcome'
],'Section 2 architecture');
if(is_file($root.'/database/migrations/20260928_100_portfolio_decision_execution_rollups.sql'))$fail[]='Section 2 must remain schema-free; migration 100 must not exist.';
$must('tests/ci/run-static-contracts.sh',['phase73-section2-portfolio-decision-execution-rollups-contract.php'],'Section 2 static gate');
$must('tests/ci/run-full-regression.sh',['phase73-section2-portfolio-decision-execution-rollups-db.php'],'Section 2 DB gate');
$must('.github/workflows/package-two-zips.yml',[
 'app/research-intelligence-decision-rollups.php','phase73-section2-portfolio-decision-execution-rollups-contract.php','phase73-section2-portfolio-decision-execution-rollups-db.php'
],'Section 2 package gate');
if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 73 Section 2 Portfolio Decision & Execution Rollups contracts passed.\n";
