<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','agent-actions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p72s5(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p72s5throws(callable $fn,string $class,string $message): void {try{$fn();}catch(Throwable $e){if($e instanceof $class){echo "PASS: $message\n";return;}throw $e;}throw new RuntimeException('FAIL: '.$message);}

p72s5(research_action_plan_cognition_ready($pdo),'Action Plan cognition runtime is ready from existing durable ledgers.');
$run='p72s5'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$username='owner_'.$run;$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
 ->execute([$pub('u'),$username,'Section 5 Owner',$username.'@example.test']);$uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$viewer=$q->fetch();

$agent=research_agent_create($pdo,$viewer,['name'=>'Section 5 Agent','description'=>'Strategic cognition fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$project=project_access($pdo,(int)$viewer['id'],(string)$agent['project_public_id']);p72s5((bool)$project,'Research Agent project is available.');
$decision=research_decision_create($pdo,$viewer,['agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Expand controlled rollout','statement'=>'Proceed with the controlled rollout.','rationale'=>'Evidence supports a measured expansion.']);
$decision=research_decision_set_status($pdo,$viewer,(string)$decision['public_id'],'accepted');
$plan=research_action_plan_from_decision($pdo,$viewer,(string)$decision['public_id'],[
 'idempotency_key'=>'section5-plan','title'=>'Controlled rollout follow-through','objective'=>'Execute the accepted rollout safely.','expected_result'=>'Reach adoption target without exceeding support capacity.',
 'success_measures'=>[['label'=>'Adoption','target'=>'80% activation']],'assumptions'=>['Support staffing remains available.'],
 'risks'=>[['title'=>'Support overload','detail'=>'Demand could exceed capacity.','mitigation'=>'Gate expansion.']],
 'start_on'=>gmdate('Y-m-d'),'due_on'=>gmdate('Y-m-d',strtotime('+30 days'))
]);
$milestone=research_action_plan_create_milestone($pdo,$viewer,(string)$plan['public_id'],['title'=>'Pilot complete','target_on'=>gmdate('Y-m-d',strtotime('+10 days')),'completion_criteria'=>['Pilot cohort reviewed.']]);
$task=research_action_plan_add_task($pdo,$viewer,(string)$plan['public_id'],['milestone_id'=>$milestone['public_id'],'title'=>'Validate pilot cohort','task_type'=>'general','priority'=>'high','due_at'=>gmdate('Y-m-d',strtotime('+8 days')).'T17:00:00Z']);
$plan=research_action_plan_set_status($pdo,$viewer,(string)$plan['public_id'],'proposed');$plan=research_action_plan_set_status($pdo,$viewer,(string)$plan['public_id'],'active');

$clean=research_action_plan_cognition_snapshot($pdo,$viewer,(string)$plan['public_id']);
p72s5(($clean['strategic_state']??'')==='continue_execution','Clean active execution is classified CONTINUE EXECUTION.');
p72s5((bool)preg_match('/^[a-f0-9]{64}$/',(string)$clean['state_hash']),'Cognition exposes an exact Action Plan state hash.');
$context=research_action_plan_agent_context($pdo,$viewer,(string)$agent['public_id'],6);
p72s5(str_contains((string)$context['text'],'[ACTION PLAN STRATEGIC MEMORY]')&&str_contains((string)$context['text'],(string)$clean['state_hash']),'Agent context carries durable strategic state and exact hash.');

$oldHash=(string)$clean['state_hash'];
$obsArgs=agent_action_clean_arguments('research.action_plan.record_observation',[
 'action_plan_id'=>$plan['public_id'],'action_plan_state_hash'=>$oldHash,'observation_type'=>'new_evidence',
 'summary'=>'Support demand is materially above the accepted rollout assumption.','actual'=>['support_load'=>'145% of forecast'],
 'assessment'=>'at_risk','material'=>true,'severity'=>'critical','source_type'=>'research_evidence','source_public_id'=>'evidence-section5'
]);
$obsResult=agent_action_execute_capability($pdo,$viewer,$project,'research.action_plan.record_observation',$obsArgs);
p72s5(($obsResult['type']??'')==='action_plan_observation'&&!empty($obsResult['variance_id']),'Confirmed observation proposal appends execution evidence and a variance.');

$review=research_action_plan_cognition_snapshot($pdo,$viewer,(string)$plan['public_id']);
p72s5(($review['strategic_state']??'')==='decision_review','Material critical new evidence escalates cognition to MAY AFFECT UNDERLYING DECISION.');
p72s5((string)$review['state_hash']!==$oldHash,'Durable execution change rotates the Action Plan state hash.');
p72s5throws(fn()=>agent_action_execute_capability($pdo,$viewer,$project,'research.action_plan.create_milestone',agent_action_clean_arguments('research.action_plan.create_milestone',[
 'action_plan_id'=>$plan['public_id'],'action_plan_state_hash'=>$oldHash,'title'=>'Stale proposal must not land'
])),AgentActionStale::class,'A stale Action Plan proposal is rejected at confirmation-time execution.');

$currentHash=(string)$review['state_hash'];
$taskArgs=agent_action_clean_arguments('research.action_plan.add_task',[
 'action_plan_id'=>$plan['public_id'],'action_plan_state_hash'=>$currentHash,'title'=>'Investigate support-load variance','description'=>'Determine why support demand exceeded the rollout assumption.','task_type'=>'follow_up','priority'=>'high','link_role'=>'validation'
]);
$taskResult=agent_action_execute_capability($pdo,$viewer,$project,'research.action_plan.add_task',$taskArgs);
p72s5(($taskResult['type']??'')==='action_plan_task','Confirmed corrective task proposal reuses the existing Research Task runtime.');

$afterTask=research_action_plan_cognition_snapshot($pdo,$viewer,(string)$plan['public_id']);$currentHash=(string)$afterTask['state_hash'];
$programArgs=agent_action_clean_arguments('research.action_plan.create_follow_through_program',[
 'action_plan_id'=>$plan['public_id'],'action_plan_state_hash'=>$currentHash,'program_role'=>'evidence_refresh','cadence'=>'weekly','quiet_mode'=>'material_only','materiality_threshold'=>'important'
]);
$programResult=agent_action_execute_capability($pdo,$viewer,$project,'research.action_plan.create_follow_through_program',$programArgs);
p72s5(($programResult['type']??'')==='action_plan_program'&&($programResult['status']??'')==='paused','Agent-created follow-through Program is explicitly forced Paused.');

$afterProgram=research_action_plan_cognition_snapshot($pdo,$viewer,(string)$plan['public_id']);$currentHash=(string)$afterProgram['state_hash'];
$reconsiderArgs=agent_action_clean_arguments('research.action_plan.open_decision_reconsideration',[
 'action_plan_id'=>$plan['public_id'],'action_plan_state_hash'=>$currentHash,'reason'=>'Critical new execution evidence may invalidate the accepted support-capacity assumption.','materiality'=>'critical'
]);
$caseResult=agent_action_execute_capability($pdo,$viewer,$project,'research.action_plan.open_decision_reconsideration',$reconsiderArgs);
p72s5(($caseResult['type']??'')==='decision_reconsideration'&&($caseResult['status']??'')==='open','Confirmed proposal opens a human-governed Decision reconsideration case.');
$decisionNow=research_decision_detail($pdo,$viewer,(string)$decision['public_id']);
p72s5(($decisionNow['status']??'')==='accepted','Opening reconsideration does not mutate the source Decision status.');

$proposalCaps=agent_action_capabilities();
foreach(['research.action_plan.add_task','research.action_plan.create_milestone','research.action_plan.create_follow_through_program','research.action_plan.record_observation','research.action_plan.open_decision_reconsideration'] as $cap)p72s5(isset($proposalCaps[$cap]),'Governed capability registered: '.$cap);

echo "Phase 72 Section 5 Agent Cognition & Strategic Follow-Through database journey passed.\n";
