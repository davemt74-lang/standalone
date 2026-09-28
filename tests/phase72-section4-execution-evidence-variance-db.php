<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p72s4(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p72s4throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p72s4(research_action_plan_variance_ready($pdo),'Execution Evidence & Variance runtime is ready.');
$run='p72s4'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$username='owner_'.$run;$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
 ->execute([$pub('u'),$username,'Section 4 Owner',$username.'@example.test']);$uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$viewer=$q->fetch();

$agent=research_agent_create($pdo,$viewer,['name'=>'Section 4 Agent','description'=>'Execution evidence fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$decision=research_decision_create($pdo,$viewer,['agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Ship controlled rollout','statement'=>'Proceed with controlled rollout.','rationale'=>'Approved execution test.']);
$decision=research_decision_set_status($pdo,$viewer,(string)$decision['public_id'],'accepted');
$plan=research_action_plan_from_decision($pdo,$viewer,(string)$decision['public_id'],[
 'idempotency_key'=>'section4-plan','title'=>'Controlled rollout execution','objective'=>'Execute the approved rollout.','expected_result'=>'Reach target adoption without exceeding support capacity.',
 'success_measures'=>[['label'=>'Adoption','target'=>'80% activation']], 'assumptions'=>['Support staffing remains available.'],
 'risks'=>[['title'=>'Support overload','detail'=>'Demand could exceed capacity.','mitigation'=>'Gate expansion.']],
 'start_on'=>'2026-09-28','due_on'=>'2026-10-10'
]);
$milestone=research_action_plan_create_milestone($pdo,$viewer,(string)$plan['public_id'],['title'=>'Pilot complete','target_on'=>'2026-10-03','completion_criteria'=>['Pilot cohort reviewed.']]);
$task=research_action_plan_add_task($pdo,$viewer,(string)$plan['public_id'],['milestone_id'=>$milestone['public_id'],'title'=>'Validate pilot cohort','task_type'=>'general','due_at'=>'2026-10-02T17:00:00Z']);
p72s4(research_action_plan_execution_baseline($pdo,$viewer,(string)$plan['public_id'])===null,'Draft plan has no fabricated execution baseline.');

$plan=research_action_plan_set_status($pdo,$viewer,(string)$plan['public_id'],'proposed');
$plan=research_action_plan_set_status($pdo,$viewer,(string)$plan['public_id'],'active');
$baseline=research_action_plan_execution_baseline($pdo,$viewer,(string)$plan['public_id']);
p72s4((bool)$baseline&&count((array)$baseline['milestones'])===1&&count((array)$baseline['tasks'])===1,'Activation captures one immutable execution baseline with milestone/task state.');
$again=research_action_plan_capture_execution_baseline($pdo,$viewer,(string)$plan['public_id']);
p72s4((int)$again['id']===(int)$baseline['id'],'Baseline capture is idempotent for the same Action Plan revision/hash.');

$miss=research_action_plan_record_execution_observation($pdo,$viewer,(string)$plan['public_id'],[
 'idempotency_key'=>'adoption-miss','observation_type'=>'success_measure','subject_type'=>'success_measure','success_measure_index'=>0,
 'summary'=>'Pilot adoption reached 62%.','actual'=>['value'=>'62% activation'],'assessment'=>'missed','material'=>true,'severity'=>'high','impact'=>'Expansion criteria are not satisfied.'
]);
p72s4(($miss['variance']['variance_type']??'')==='target_miss'&&($miss['variance']['status']??'')==='open','Missed success measure creates durable target-miss variance.');
$retry=research_action_plan_record_execution_observation($pdo,$viewer,(string)$plan['public_id'],[
 'idempotency_key'=>'adoption-miss','observation_type'=>'success_measure','subject_type'=>'success_measure','success_measure_index'=>0,
 'summary'=>'Duplicate retry','actual'=>['value'=>'different'],'assessment'=>'missed','material'=>true
]);
p72s4(!empty($retry['reused']),'Execution observation retries are idempotent.');

$assumption=research_action_plan_record_execution_observation($pdo,$viewer,(string)$plan['public_id'],[
 'idempotency_key'=>'staffing-change','observation_type'=>'assumption','subject_type'=>'assumption','assumption_index'=>0,
 'summary'=>'Support staffing assumption changed after activation.','actual'=>'Two planned support shifts became unavailable.','assessment'=>'changed','material'=>true,'severity'=>'high'
]);
p72s4(($assumption['variance']['variance_type']??'')==='assumption_changed','Changed assumption creates durable assumption variance.');

$evidence=research_action_plan_record_execution_observation($pdo,$viewer,(string)$plan['public_id'],[
 'idempotency_key'=>'new-evidence','observation_type'=>'new_evidence','summary'=>'New customer evidence materially changes rollout risk.','actual'=>['finding'=>'Support demand is higher than forecast.'],
 'assessment'=>'at_risk','material'=>true,'severity'=>'critical','source_type'=>'research_evidence','source_public_id'=>'evidence-fixture-1','source_snapshot'=>['claim'=>'Support demand is higher than forecast.']
]);
p72s4(($evidence['variance']['variance_type']??'')==='new_evidence'&&($evidence['variance']['severity']??'')==='critical','Material new evidence creates a critical new-evidence variance with source snapshot.');

$auto=research_action_plan_refresh_execution_variances($pdo,$viewer,(string)$plan['public_id'],['as_of'=>'2026-10-12']);
$types=array_column((array)$auto['created'],'variance_type');
p72s4(in_array('schedule_delay',$types,true),'Variance refresh detects baseline schedule delay without a new scheduler.');

$detail=research_action_plan_execution_variance_detail($pdo,$viewer,(string)$plan['public_id']);
p72s4(($detail['summary']['material_open']??0)>=3&&count((array)$detail['observations'])===3,'Execution history reports observations and material open variances.');

$resolved=research_action_plan_resolve_execution_variance($pdo,$viewer,(string)$miss['variance']['public_id'],'Rollout expansion held pending remediation.');
p72s4(($resolved['status']??'')==='resolved'&&!empty($resolved['resolved_at']),'Human resolution closes variance without rewriting the observation.');
p72s4throws(fn()=>research_action_plan_resolve_execution_variance($pdo,$viewer,(string)$assumption['variance']['public_id'],'',true),'Agent-originated variance resolution is blocked.');

$observationCount=(int)$pdo->query('SELECT COUNT(*) FROM research_action_plan_execution_observations')->fetchColumn();
$varianceCount=(int)$pdo->query('SELECT COUNT(*) FROM research_action_plan_variances')->fetchColumn();
p72s4($observationCount===3&&$varianceCount>=4,'Execution observations remain append-only while variances accumulate independently.');

$events=research_action_plan_detail($pdo,$viewer,(string)$plan['public_id'])['events'];$eventTypes=array_column((array)$events,'event_type');
p72s4(in_array('execution_baseline_captured',$eventTypes,true)&&in_array('execution_observation_recorded',$eventTypes,true)&&in_array('execution_variance_resolved',$eventTypes,true),'Section 4 activity is represented in Action Plan audit history.');

echo "Phase 72 Section 4 Execution Evidence & Variance database journey passed.\n";
