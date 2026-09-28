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

$must('database/migrations/20260928_101_strategic_dependency_conflict_graph.sql',[
 'research_intelligence_strategic_edges','research_intelligence_strategic_edge_events',
 "'depends_on'","'supports'","'conflicts_with'","'duplicates'","'supersedes'","'blocks'","'materially_affects'",
 'source_state_hash','target_state_hash','materiality','removal_reason','uq_p73_strategic_edge','chk_p73_strategic_edge_not_self'
],'Phase 73 Section 4 migration');
$must('app/research-intelligence-strategic-graph.php',[
 'research_intelligence_strategic_graph_ready','research_intelligence_strategic_edge_upsert',
 'research_intelligence_strategic_cycle_exists','research_intelligence_strategic_edge_refresh',
 'research_intelligence_strategic_edge_remove','research_intelligence_portfolio_strategic_graph',
 'research_intelligence_organization_strategic_graph',
 "research_intelligence_strategic_symmetric_relations","research_intelligence_strategic_cycle_relations",
 "'cross_portfolio'","'stale'","'high_materiality'","'blocking_relationship'","research_intelligence_strategic_attention_reasons"
],'Phase 73 Section 4 graph runtime');
$avoid('app/research-intelligence-strategic-graph.php',[
 'ai_run(','embedding','vector_similarity','research_decision_set_status','research_action_plan_set_status',
 'UPDATE research_decisions','UPDATE research_action_plans','INSERT INTO research_decisions','INSERT INTO research_action_plans',
 'research_decision_record_outcome(','agent_action_confirm_execute('
],'Phase 73 Section 4 source authority');
$must('app/research-intelligence-operations.php',[
 "'strategic_graph'=>","'strategic_graph_summary'=>","'strategic_graph_attention'=>",
 "'portfolio_strategic_relationship'","research_intelligence_portfolio_operations_cognitive_observations"
],'Section 4 Portfolio/Command Center/Now integration');
$must('api/research-intelligence-portfolios.php',[
 "'strategic_edge_create'","'strategic_edge_refresh'","'strategic_edge_remove'"
],'Section 4 API');
$must('research-intelligence-portfolios.php',[
 'PHASE 73 · STRATEGIC DEPENDENCY & CONFLICT GRAPH','Record relationship','Acknowledge current state','Remove relationship',
 'Materiality','Removal reason','Cross-Portfolio','Stale'
],'Section 4 Portfolio UI');
$must('research-intelligence-command-center.php',[
 'STRATEGIC DEPENDENCY & CONFLICT GRAPH','Relationship attention','Strategic conflicts','Stale relationships'
],'Section 4 Command Center UI');
$must('docs/phase-73-portfolio-decision-intelligence-organizational-learning.md',[
 '## Section 4 — Strategic Dependency & Conflict Graph','conflicts_with','depends_on','Supersedes','state hashes',
 'materiality','removal reason','no worker, scheduler, queue'
],'Section 4 architecture');
$must('tests/ci/run-static-contracts.sh',['phase73-section4-strategic-dependency-conflict-graph-contract.php'],'Section 4 static gate');
$must('tests/ci/run-full-regression.sh',['phase73-section4-strategic-dependency-conflict-graph-db.php'],'Section 4 DB gate');
$must('.github/workflows/full-regression.yml',['phase73-section4-upgrade-from-100.php'],'Section 4 upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260928_101_strategic_dependency_conflict_graph.sql','app/research-intelligence-strategic-graph.php',
 'phase73-section4-strategic-dependency-conflict-graph-contract.php','phase73-section4-strategic-dependency-conflict-graph-db.php','phase73-section4-upgrade-from-100.php'
],'Section 4 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 73 Section 4 Strategic Dependency & Conflict Graph contracts passed.\n";
