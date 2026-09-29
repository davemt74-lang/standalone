<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p)){$fail[]=$label.' missing '.$file;return;}$c=(string)file_get_contents($p);foreach($needles as $n)if(!str_contains($c,$n))$fail[]=$label.' missing '.$n.' in '.$file;};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p))return;$c=(string)file_get_contents($p);foreach($needles as $n)if(str_contains($c,$n))$fail[]=$label.' must not contain '.$n.' in '.$file;};

$m104=glob($root.'/database/migrations/*_104_*.sql')?:[];
if($m104)$fail[]='Section 7 must not add migration 104: '.implode(', ',array_map('basename',$m104));

$must('app/research-intelligence-organizational-cognition.php',[
 'research_intelligence_organizational_cognition_ready','research_intelligence_organizational_portfolio_cognition',
 'research_intelligence_organizational_cognition_center','research_intelligence_organizational_agent_context',
 'research_intelligence_organizational_cognitive_observations','research_intelligence_organizational_state_hash',
 "'decision_reconsideration'","'execution_follow_through'","'strategic_relationship'","'strategic_review_follow_through'","'strategic_briefing_follow_through'",
 'DECISION ANALOGUE','propose only'
],'Section 7 cognition runtime');
$avoid('app/research-intelligence-organizational-cognition.php',[
 'INSERT INTO','UPDATE research_decisions','UPDATE research_action_plans','research_decision_set_status(',
 'research_action_plan_set_status(','research_action_plan_resolve_execution_variance(','research_decision_apply_reconsideration(',
 'research_intelligence_strategic_edge_upsert(','research_publication_publish(','ai_run('
],'Section 7 cognition authority');

$must('app/agent-actions.php',[
 "'research.portfolio.create_decision_draft'=>","'research.decision.create_action_plan_draft'=>",
 "'research.decision.open_reconsideration'=>","'research.portfolio.create_strategic_review'=>",
 "'research.portfolio.create_strategic_briefing'=>",'portfolio_state_hash','decision_state_hash','source_packet_hash',
 'research_intelligence_organizational_state_hash','research_decision_review_state_hash','AgentActionStale',
 'research_intelligence_portfolio_create_native_decision','research_action_plan_from_decision',
 'research_decision_open_reconsideration','research_intelligence_strategic_review_create','research_intelligence_strategic_briefing_create'
],'Section 7 governed proposals');
$avoid('app/agent-actions.php',[
 "'research.decision.set_status'=>","'research.action_plan.activate'=>","'research.action_plan.complete'=>",
 "'research.action_plan.cancel'=>","'research.action_plan.resolve_variance'=>","'research.strategic_graph.edit'=>",
 "'research.review.complete'=>"
],'Section 7 forbidden capability registry');

$must('app/agent-chat.php',[
 'research_intelligence_organizational_agent_context','Organizational Strategic Cognition is read-only reasoning context',
 'portfolio_state_hash','decision_state_hash','draft Decisions only','draft plans only'
],'Section 7 Agent Chat cognition');
$must('app/research-reviews.php',['$ownsTransaction=!$pdo->inTransaction()'],'Section 7 composable Team Review transaction');
$must('app/research-intelligence-operations.php',[
 "'organizational_cognition'=>","'organizational_cognition_summary'=>","'organizational_cognition_signals'=>","'organizational_cognition_analogues'=>",
 'bool $byAgent=false'
],'Section 7 Portfolio integration');
$must('app/cognitive-feed.php',['organizational_cognition','research_intelligence_organizational_cognitive_observations'],'Section 7 Cognitive Feed');
$must('app/bootstrap.php',["research-intelligence-organizational-cognition.php"],'Section 7 bootstrap');
$must('research-intelligence-portfolios.php',[
 'ORGANIZATIONAL AGENT COGNITION &amp; GOVERNED FOLLOW-THROUGH','EXACT DECISION ANALOGUES','Strategic state hash'
],'Section 7 Portfolio UI');
$must('app/research-portfolios-ui.php',[
 'ORGANIZATIONAL AGENT COGNITION','Governed strategic reasoning','DECISION ANALOGUES','organizational_cognition_signals'
],'Section 7 canonical Portfolio attention UI');
$must('docs/phase-73-portfolio-decision-intelligence-organizational-learning.md',[
 '## Section 7 — Organizational Agent Cognition & Governed Follow-Through','No migration 104','agent_action_proposals',
 'stale proposal','draft Decision','draft Action Plan','exact Pattern Memory','authority boundary'
],'Section 7 architecture');
$must('tests/ci/run-static-contracts.sh',['phase73-section7-organizational-agent-cognition-contract.php'],'Section 7 static gate');
$must('tests/ci/run-full-regression.sh',['phase73-section7-organizational-agent-cognition-db.php'],'Section 7 DB gate');
$must('.github/workflows/full-regression.yml',['phase73-section7-no-migration-governance.php'],'Section 7 no-migration gate');
$must('.github/workflows/package-two-zips.yml',[
 'app/research-intelligence-organizational-cognition.php','phase73-section7-organizational-agent-cognition-contract.php',
 'phase73-section7-organizational-agent-cognition-db.php','phase73-section7-no-migration-governance.php'
],'Section 7 package gate');
if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 73 Section 7 Organizational Agent Cognition & Governed Follow-Through contracts passed.\n";
