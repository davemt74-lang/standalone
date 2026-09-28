<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','cognitive-feed','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-reviews'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p72s7(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p72s7throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p72s7(research_action_plan_outcomes_ready($pdo),'Action Plan Outcome Handoff runtime is ready on existing Outcome Memory.');
$run='p72s7'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{
  $username=substr(strtolower($name).'_'.$run,0,48);
  $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
    ->execute([$pub('u'),$username,$name,$username.'@example.test']);
  $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('OutcomeOwner');$reviewer=$makeUser('OutcomeReviewer');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,(int)$owner['id'],'Outcome Learning Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,(int)$owner['id'],$teamId,(int)$reviewer['id']]);
$agent=research_agent_create($pdo,$owner,['name'=>'Outcome Learning Agent','description'=>'Phase 72 Section 7 fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$decision=research_decision_create($pdo,$owner,['agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Roll out final cohort','statement'=>'Proceed with final cohort rollout.','rationale'=>'Evidence supported a bounded rollout.','confidence'=>0.82]);
$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted');
$plan=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'section7-plan','title'=>'Final cohort rollout','objective'=>'Execute the accepted rollout and learn from the actual result.',
 'expected_result'=>'Achieve at least 80% activation without exceeding support capacity.','success_measures'=>[['label'=>'Activation','target'=>'80% activation']],
 'assumptions'=>['Support capacity remains available.'],'risks'=>[['title'=>'Support overload','detail'=>'Demand could exceed capacity.','mitigation'=>'Gate expansion.']],
 'start_on'=>gmdate('Y-m-d'),'due_on'=>gmdate('Y-m-d',strtotime('+30 days'))
]);
$milestone=research_action_plan_create_milestone($pdo,$owner,(string)$plan['public_id'],['title'=>'Final cohort complete','target_on'=>gmdate('Y-m-d',strtotime('+10 days')),'completion_criteria'=>['Final cohort result reviewed.']]);
$task=research_action_plan_add_task($pdo,$owner,(string)$plan['public_id'],['milestone_id'=>$milestone['public_id'],'title'=>'Run final cohort','task_type'=>'general','priority'=>'high','due_at'=>gmdate('Y-m-d',strtotime('+8 days')).'T17:00:00Z']);
$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'proposed');$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'active');
$milestone=research_action_plan_set_milestone_status($pdo,$owner,(string)$milestone['public_id'],'in_progress');
research_action_plan_record_execution_observation($pdo,$owner,(string)$plan['public_id'],[
 'idempotency_key'=>'activation-observation','observation_type'=>'success_measure','subject_type'=>'success_measure','success_measure_index'=>0,
 'summary'=>'Final activation reached 68%, below the 80% target.','actual'=>['value'=>'68% activation'],'assessment'=>'missed','material'=>true,'severity'=>'high'
]);
$pdo->prepare("UPDATE research_tasks SET execution_summary='Final cohort completed and activation measured at 68%.',status='review',blocking_reason=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$task['id']]);
$taskDone=research_task_review($pdo,$owner,(string)$task['public_id'],true);p72s7(($taskDone['status']??'')==='complete','Existing Research Task review remains the completion authority.');
$milestone=research_action_plan_set_milestone_status($pdo,$owner,(string)$milestone['public_id'],'completed');p72s7(($milestone['status']??'')==='completed','Human completes the final milestone through existing Action Plan execution.');
$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'completed');p72s7(($plan['status']??'')==='completed','Action Plan reaches Completed only through its existing lifecycle guard.');

p72s7(research_action_plan_outcome_link($pdo,$owner,(string)$plan['public_id'])===null,'Action Plan completion does not automatically fabricate Outcome Memory.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_decision_outcomes WHERE decision_id=?');$q->execute([(int)$decision['id']]);p72s7((int)$q->fetchColumn()===0,'Decision Outcome Memory remains empty until explicit handoff.');

$preview=research_action_plan_outcome_preview($pdo,$owner,(string)$plan['public_id']);
p72s7(!empty($preview['ready_for_handoff'])&&preg_match('/^[a-f0-9]{64}$/',(string)$preview['state_hash']),'Completed Action Plan exposes a deterministic outcome handoff preview and state hash.');
p72s7(str_contains((string)$preview['expected_summary'],'80%')&&($preview['variance_counts']['total']??0)>=1,'Preview carries activation baseline expectation and recorded execution variance.');
$staleHash=(string)$preview['state_hash'];

$review=research_review_create($pdo,$owner,'action_plan',(string)$plan['public_id'],[(int)$reviewer['id']],null,'Review completed execution before final outcome handoff.');
$review=research_review_respond($pdo,$reviewer,(string)$review['public_id'],'approve','Execution history is ready for explicit final outcome assessment.');
p72s7(!research_review_access($pdo,$owner,(string)$review['public_id'])['is_stale'],'Completed Action Plan review is current before final handoff.');

research_action_plan_record_execution_observation($pdo,$owner,(string)$plan['public_id'],[
 'idempotency_key'=>'late-support-evidence','observation_type'=>'new_evidence','summary'=>'Post-completion support analysis shows demand exceeded forecast by 35%.',
 'actual'=>['support_load'=>'135% of forecast'],'assessment'=>'at_risk','material'=>true,'severity'=>'high','source_type'=>'research_evidence','source_public_id'=>'section7-late-evidence'
]);
p72s7throws(fn()=>research_action_plan_record_outcome_handoff($pdo,$owner,(string)$plan['public_id'],[
 'handoff_state_hash'=>$staleHash,'assessment'=>'failure','actual_summary'=>'Activation missed target and support demand exceeded forecast.'
]),'Stale outcome preview cannot be applied after new execution evidence arrives.');

$fresh=research_action_plan_outcome_preview($pdo,$owner,(string)$plan['public_id']);
p72s7((string)$fresh['state_hash']!==$staleHash,'New execution evidence rotates the final handoff state hash.');
p72s7(!empty(research_review_access($pdo,$owner,(string)$review['public_id'])['is_stale']),'New execution evidence makes the earlier completed-state Team Review stale.');
$freshReview=research_review_create($pdo,$owner,'action_plan',(string)$plan['public_id'],[(int)$reviewer['id']],null,'Review final execution state immediately before Outcome Memory handoff.');
$freshReview=research_review_respond($pdo,$reviewer,(string)$freshReview['public_id'],'approve','Current completed execution state is ready for explicit human outcome assessment.');
p72s7(!research_review_access($pdo,$owner,(string)$freshReview['public_id'])['is_stale'],'Fresh pre-handoff Team Review is current after all execution evidence is present.');
p72s7throws(fn()=>research_action_plan_record_outcome_handoff($pdo,$owner,(string)$plan['public_id'],[
 'handoff_state_hash'=>(string)$fresh['state_hash'],'actual_summary'=>'Missing explicit assessment.'
]),'Final outcome requires explicit human assessment.');
p72s7throws(fn()=>research_action_plan_record_outcome_handoff($pdo,$owner,(string)$plan['public_id'],[
 'handoff_state_hash'=>(string)$fresh['state_hash'],'assessment'=>'failure'
]),'Final outcome requires explicit human actual result.');

$link=research_action_plan_record_outcome_handoff($pdo,$owner,(string)$plan['public_id'],[
 'handoff_state_hash'=>(string)$fresh['state_hash'],'assessment'=>'failure',
 'actual_summary'=>'Activation reached 68% and post-completion support demand reached 135% of forecast.',
 'variance_summary'=>'Activation missed the 80% target by 12 percentage points and support demand exceeded the baseline assumption.',
 'lessons'=>'Future expansion should validate support capacity before increasing cohort size.','confidence'=>'0.94','follow_up_state'=>'follow_up'
]);
p72s7(($link['outcome']['assessment']??'')==='failure'&&($link['outcome']['follow_up_state']??'')==='follow_up','Explicit human handoff records the existing Decision Outcome Memory assessment and follow-up state.');
p72s7(($link['execution_baseline_public_id']??'')===(string)$fresh['execution_baseline']['public_id']&&($link['handoff_state_hash']??'')===(string)$fresh['state_hash'],'Handoff preserves exact execution baseline and state-hash lineage.');
p72s7(!empty($link['handoff_snapshot']['human_outcome'])&&count((array)$link['handoff_snapshot']['observations'])>=2,'Frozen handoff snapshot preserves execution evidence plus explicit human outcome.');

$retry=research_action_plan_record_outcome_handoff($pdo,$owner,(string)$plan['public_id'],[
 'handoff_state_hash'=>(string)$fresh['state_hash'],'assessment'=>'success','actual_summary'=>'A retry must not create or overwrite another final outcome.'
]);
p72s7((string)$retry['public_id']===(string)$link['public_id']&&($retry['outcome']['assessment']??'')==='failure','Outcome handoff retries are idempotent and cannot overwrite the recorded final outcome.');

$q=$pdo->prepare("SELECT COUNT(*) FROM research_outcome_refs r JOIN research_decision_outcomes d ON d.outcome_event_id=r.outcome_id WHERE d.public_id=? AND r.ref_type='action_plan' AND r.ref_public_id=? AND r.ref_role='source'");
$q->execute([(string)$link['decision_outcome_public_id'],(string)$plan['public_id']]);p72s7((int)$q->fetchColumn()===1,'Phase 20 Outcome Learning preserves a permission-checked Action Plan source reference.');
p72s7(!empty(research_review_access($pdo,$owner,(string)$freshReview['public_id'])['is_stale']),'Recording final Outcome Memory itself makes the fresh pre-handoff Team Review stale.');

$decisionAfter=research_decision_detail($pdo,$owner,(string)$decision['public_id']);p72s7(($decisionAfter['status']??'')==='accepted','Recording an Action Plan outcome never changes the source Decision status.');
$signals=research_decision_reconsideration_signals($pdo,$owner,(string)$decision['public_id']);$outcomeSignal=false;foreach($signals['signals'] as $s)if(($s['trigger_type']??'')==='outcome'&&($s['trigger_public_id']??'')===(string)$link['decision_outcome_public_id']){$outcomeSignal=true;p72s7(($s['materiality']??'')==='critical','Failure outcome becomes the existing critical Decision reconsideration signal.');}
p72s7($outcomeSignal,'Existing Decision reconsideration runtime observes the Action Plan-derived outcome without auto-opening a case.');

$cognition=research_action_plan_cognition_snapshot($pdo,$owner,(string)$plan['public_id']);
p72s7(($cognition['outcome_handoff']['outcome']['assessment']??'')==='failure','Action Plan strategic memory includes the linked final Outcome Memory.');
$agentContext=research_action_plan_agent_context($pdo,$owner,(string)$agent['public_id'],6);
p72s7(str_contains((string)$agentContext['text'],'Final Outcome Memory: FAILURE')&&str_contains((string)$agentContext['text'],'Lessons learned:'),'Research Agent context can explain what happened and what was learned.');

$center=research_action_plan_command_center($pdo,$owner,'outcomes',100);$found=null;foreach($center['action_plans'] as $row)if($row['public_id']===$plan['public_id']){$found=$row;break;}
p72s7($found!==null&&!empty($found['command']['outcome_handoff'])&&($center['stats']['outcomes_recorded']??0)>=1,'Command Center Outcome Learning view shows the recorded final handoff.');
p72s7(($center['stats']['completed_without_outcome']??0)===0,'Recorded final outcome closes the completed-without-outcome learning gap.');

p72s7throws(fn()=>research_action_plan_record_outcome_handoff($pdo,$owner,(string)$plan['public_id'],['handoff_state_hash'=>(string)$fresh['state_hash'],'assessment'=>'failure','actual_summary'=>'Agent attempt'],true),'Agent cannot record final Outcome Memory through the Action Plan bridge.');

echo "Phase 72 Section 7 Completion, Outcome Handoff & Decision Learning database journey passed.\n";
