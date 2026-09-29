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

$must('database/migrations/20260928_100_cross_decision_pattern_memory.sql',[
 'research_intelligence_decision_pattern_runs','research_intelligence_decision_patterns','research_intelligence_decision_pattern_members',
 "'repeated_assumption'","'repeated_plan_risk'","'recurring_variance_type'","'recurring_outcome_assessment'","'expected_actual_variance'","'repeated_lesson'",
 'uq_p73_pattern_run_state','uq_p73_pattern_fingerprint','uq_p73_pattern_member'
],'Phase 73 Section 3 migration');
$must('app/research-intelligence-pattern-memory.php',[
 'research_intelligence_pattern_memory_ready','research_intelligence_portfolio_pattern_candidates',
 'research_intelligence_portfolio_pattern_refresh','research_intelligence_portfolio_pattern_memory',
 'if(count($decisions)<2)continue','mb_strtolower','state_hash',
 'research_action_plan_execution_variances','research_intelligence_portfolio_decision_rows'
],'Phase 73 Section 3 runtime');
$avoid('app/research-intelligence-pattern-memory.php',[
 'ai_run(','openai','anthropic','gemini','embedding','vector_similarity',
 'UPDATE research_decisions','UPDATE research_action_plans','INSERT INTO research_decisions','INSERT INTO research_action_plans',
 'research_decision_set_status','research_action_plan_set_status','research_action_plan_resolve_execution_variance',
 'research_decision_record_outcome('
],'Phase 73 Section 3 authority');
$must('app/research-intelligence-pattern-memory.php',[
 'decision_pattern_memory_refreshed'
],'Phase 73 Section 3 Pattern Memory event');
$must('app/research-intelligence-operations.php',[
 'decision_pattern_memory_refresh_failed',"'pattern_summary'=>","'learning_patterns'=>"
],'Phase 73 Section 3 Portfolio cycle and command center integration');
$must('api/research-intelligence-portfolios.php',[
 "'refresh_patterns'","research_intelligence_portfolio_pattern_refresh"
],'Phase 73 Section 3 API');
$must('research-intelligence-portfolios.php',[
 'PHASE 73 · CROSS-DECISION PATTERN MEMORY','Refresh Pattern Memory','Repeated assumptions','Repeated lessons'
],'Phase 73 Section 3 Portfolio UI');
$must('app/research-portfolios-ui.php',[
 'CROSS-DECISION LEARNING','Pattern Memory'
],'Phase 73 Section 3 canonical Portfolio attention UI');
$must('research-intelligence-portfolios.php',['Learning patterns'],'Phase 73 Section 3 canonical Portfolio Overview metric');
$must('docs/phase-73-portfolio-decision-intelligence-organizational-learning.md',[
 '## Section 3 — Cross-Decision Learning & Pattern Memory','two or more distinct native Portfolio Decisions',
 'no embedding similarity','Migration 100','No new worker or scheduler'
],'Phase 73 Section 3 architecture');
$must('tests/ci/run-static-contracts.sh',['phase73-section3-cross-decision-learning-pattern-memory-contract.php'],'Section 3 static gate');
$must('tests/ci/run-full-regression.sh',['phase73-section3-cross-decision-learning-pattern-memory-db.php'],'Section 3 DB gate');
$must('.github/workflows/full-regression.yml',['phase73-section3-upgrade-from-099.php'],'Section 3 upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260928_100_cross_decision_pattern_memory.sql','app/research-intelligence-pattern-memory.php',
 'phase73-section3-cross-decision-learning-pattern-memory-contract.php','phase73-section3-cross-decision-learning-pattern-memory-db.php','phase73-section3-upgrade-from-099.php'
],'Section 3 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 73 Section 3 Cross-Decision Learning & Pattern Memory contracts passed.\n";
