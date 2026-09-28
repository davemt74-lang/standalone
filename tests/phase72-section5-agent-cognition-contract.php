<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{
  $path=$root.'/'.$file;if(!is_file($path)){$fail[]=$label.' missing '.$file;return;}
  $c=(string)file_get_contents($path);foreach($needles as $needle)if(!str_contains($c,$needle))$fail[]=$label.' missing '.$needle.' in '.$file;
};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{
  $path=$root.'/'.$file;if(!is_file($path))return;$c=(string)file_get_contents($path);foreach($needles as $needle)if(str_contains($c,$needle))$fail[]=$label.' must not contain '.$needle.' in '.$file;
};

$must('app/research-action-plan-cognition.php',[
 'research_action_plan_cognition_ready','research_action_plan_cognition_state_hash','research_action_plan_cognition_assert_state',
 'research_action_plan_cognition_snapshot','research_action_plan_agent_context','research_action_plan_cognitive_observations',
 'decision_review','needs_attention','continue_execution','[ACTION PLAN STRATEGIC MEMORY]','action_plan_state_hash'
],'Section 5 cognition runtime');

$must('app/agent-chat.php',[
 'research_action_plan_agent_context','Action Plan Strategic Memory','research.action_plan.*','action_plan_state_hash'
],'Section 5 Agent Chat integration');

$must('app/agent-actions.php',[
 "'research.action_plan.add_task'","'research.action_plan.create_milestone'","'research.action_plan.create_follow_through_program'",
 "'research.action_plan.record_observation'","'research.action_plan.open_decision_reconsideration'",
 'agent_action_action_plan_for_project','research_action_plan_cognition_assert_state',
 'research_action_plan_add_task','research_action_plan_create_milestone','research_action_plan_create_program',
 'research_action_plan_record_execution_observation','research_decision_open_reconsideration'
],'Section 5 governed proposals');

$must('app/cognitive-feed.php',['research_action_plan_cognitive_observations'],'Section 5 cognitive feed');
$must('app/bootstrap.php',["require_once __DIR__ . '/research-action-plan-cognition.php';"],'Section 5 bootstrap');
$must('docs/phase-72-decision-to-action-execution-strategic-follow-through.md',[
 '## Section 5 — Agent Cognition & Strategic Follow-Through','no new persistence table','state hash','human confirmation'
],'Section 5 architecture');

$avoid('app/research-action-plan-cognition.php',[
 'CREATE TABLE','INSERT INTO research_action_plan_cognition','research_action_plan_cognition_jobs','research_action_plan_cognition_worker',
 'research_task_queue(','research_program_enqueue(','research_action_plan_set_status(','research_action_plan_resolve_execution_variance('
],'Section 5 read-only cognition isolation');

$must('tests/ci/run-static-contracts.sh',['phase72-section5-agent-cognition-contract.php'],'Section 5 static gate');
$must('tests/ci/run-full-regression.sh',['phase72-section5-agent-cognition-db.php'],'Section 5 regression gate');
$must('.github/workflows/full-regression.yml',['phase72-section5-agent-cognition-db.php'],'Section 5 MySQL gate');
$must('.github/workflows/package-two-zips.yml',[
 'app/research-action-plan-cognition.php','phase72-section5-agent-cognition-contract.php','phase72-section5-agent-cognition-db.php'
],'Section 5 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 72 Section 5 Agent Cognition & Strategic Follow-Through contracts passed.\n";
