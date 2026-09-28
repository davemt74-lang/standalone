<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','cognitive-feed','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-reviews','agent-actions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p72s8(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p72s8throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p72s8(research_action_plans_ready($pdo)&&research_action_plan_execution_ready($pdo)&&research_action_plan_follow_through_ready($pdo)&&research_action_plan_variance_ready($pdo)&&research_action_plan_cognition_ready($pdo)&&research_action_plan_outcomes_ready($pdo)&&research_reviews_ready($pdo),'Full Phase 72 runtime is ready.');
$run='p72s8'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{
  $username=substr(strtolower($name).'_'.$run,0,48);
  $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
    ->execute([$pub('u'),$username,$name,$username.'@example.test']);
  $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('Phase72ReleaseOwner');$reviewer=$makeUser('Phase72ReleaseReviewer');$outsider=$makeUser('Phase72ReleaseOutsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,(int)$owner['id'],'Phase 72 Release Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,(int)$owner['id'],$teamId,(int)$reviewer['id']]);

$agent=research_agent_create($pdo,$owner,['name'=>'Phase 72 Release Agent','description'=>'Decision-to-action final acceptance fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);
p72s8((bool)$project&&(int)$project['team_id']===$teamId,'Final journey starts in a Team-scoped Research Agent and project.');

$decision=research_decision_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Phase 72 controlled rollout',
 'statement'=>'Proceed with a controlled rollout governed by explicit success and support-capacity gates.',
 'rationale'=>'Current Research supports bounded execution while preserving a reversal path.','confidence'=>0.86
]);
$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted');
p72s8(($decision['status']??'')==='accepted','Final journey begins from an explicitly Accepted Decision.');

$plan=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'phase72-release-plan','title'=>'Execute controlled rollout','objective'=>'Execute the accepted rollout with measurable activation and support-capacity gates.',
 'expected_result'=>'Achieve at least 80% activation while support load remains at or below forecast.',
 'success_measures'=>[['label'=>'Activation','target'=>'80% activation'],['label'=>'Support load','target'=>'At or below forecast']],
 'assumptions'=>['Support staffing remains available throughout rollout.'],
 'risks'=>[['title'=>'Support overload','detail'=>'Support demand may exceed forecast.','mitigation'=>'Gate expansion and review Decision.']],
 'start_on'=>gmdate('Y-m-d'),'due_on'=>gmdate('Y-m-d',strtotime('+30 days')),'priority'=>'high'
]);
$retry=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'phase72-release-plan','title'=>'Execute controlled rollout','objective'=>'Retry must reuse the existing Action Plan.','expected_result'=>'Retry must not duplicate state.',
 'success_measures'=>[['label'=>'Activation','target'=>'80% activation']]
]);
p72s8(($plan['status']??'')==='draft'&&($retry['public_id']??'')===$plan['public_id'],'Decision → Action Plan handoff starts Draft and remains idempotent.');

$milestone=research_action_plan_create_milestone($pdo,$owner,(string)$plan['public_id'],[
 'title'=>'Controlled rollout cohort complete','description'=>'Run and review the bounded cohort.','target_on'=>gmdate('Y-m-d',strtotime('+10 days')),
 'completion_criteria'=>['Cohort execution is complete.','Activation and support-load results are recorded.']
]);
$task=research_action_plan_add_task($pdo,$owner,(string)$plan['public_id'],[
 'milestone_id'=>$milestone['public_id'],'title'=>'Run controlled rollout cohort','description'=>'Execute the cohort and capture final activation/support evidence.',
 'task_type'=>'general','priority'=>'high','due_at'=>gmdate('Y-m-d',strtotime('+8 days')).'T17:00:00Z','link_role'=>'execution'
]);
$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'proposed');
$programLink=research_action_plan_create_program($pdo,$owner,(string)$plan['public_id'],[
 'program_role'=>'execution_review','cadence'=>'weekly','timezone_name'=>'UTC','run_time_local'=>'09:00','sync_with_action_plan'=>true
],false);
$program=(array)$programLink['program'];
p72s8(($program['status']??'')==='paused','Follow-through reuses the existing Research Program runtime and remains Paused before activation.');

$proposedReview=research_review_create($pdo,$owner,'action_plan',(string)$plan['public_id'],[(int)$reviewer['id']],null,'Review execution readiness before explicit activation.');
research_review_respond($pdo,$reviewer,(string)$proposedReview['public_id'],'approve','Success measures, ownership, milestone, and execution task are clear.');
$proposedReview=research_review_complete($pdo,$owner,(string)$proposedReview['public_id']);
p72s8(($proposedReview['status']??'')==='completed'&&(research_action_plan_detail($pdo,$owner,(string)$plan['public_id'])['status']??'')==='proposed','Team Review remains advisory and never auto-activates the Action Plan.');

$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'active');
$baseline=research_action_plan_execution_baseline($pdo,$owner,(string)$plan['public_id']);
$execution=research_action_plan_execution_detail($pdo,$owner,(string)$plan['public_id']);
$programAfter=research_program_access($pdo,$owner,(string)$program['public_id']);
p72s8($baseline!==null&&($execution['task_plan']['status']??'')==='active'&&($programAfter['status']??'')==='active','Explicit activation atomically captures baseline and synchronizes existing Task Plan/Program runtimes.');
p72s8(!empty(research_review_access($pdo,$owner,(string)$proposedReview['public_id'])['is_stale']),'Activation invalidates the pre-activation Team Review.');

$milestone=research_action_plan_set_milestone_status($pdo,$owner,(string)$milestone['public_id'],'in_progress');
$beforeEvidence=research_action_plan_cognition_snapshot($pdo,$owner,(string)$plan['public_id']);
p72s8(($beforeEvidence['strategic_state']??'')==='continue_execution'&&preg_match('/^[a-f0-9]{64}$/',(string)$beforeEvidence['state_hash']),'Agent cognition starts from deterministic current execution state.');

research_action_plan_record_execution_observation($pdo,$owner,(string)$plan['public_id'],[
 'idempotency_key'=>'phase72-release-activation','observation_type'=>'success_measure','subject_type'=>'success_measure','success_measure_index'=>0,
 'summary'=>'Activation reached 68%, below the 80% target.','actual'=>['value'=>'68% activation'],'assessment'=>'missed','material'=>true,'severity'=>'high','impact'=>'Expected adoption target was not met.'
]);
research_action_plan_record_execution_observation($pdo,$owner,(string)$plan['public_id'],[
 'idempotency_key'=>'phase72-release-support','observation_type'=>'new_evidence','summary'=>'Verified support demand reached 140% of forecast.',
 'actual'=>['support_load'=>'140% of forecast'],'assessment'=>'at_risk','material'=>true,'severity'=>'critical','source_type'=>'research_evidence','source_public_id'=>'phase72-release-support-evidence',
 'impact'=>'The accepted support-capacity assumption is materially invalid.'
]);
$afterEvidence=research_action_plan_cognition_snapshot($pdo,$owner,(string)$plan['public_id']);
p72s8(($afterEvidence['strategic_state']??'')==='decision_review'&&count((array)$afterEvidence['open_variances'])>=2,'Execution evidence produces durable variance and deterministic MAY AFFECT UNDERLYING DECISION cognition.');
p72s8((string)$afterEvidence['state_hash']!==(string)$beforeEvidence['state_hash'],'Execution evidence rotates the strategic state hash.');

$agentContext=research_action_plan_agent_context($pdo,$owner,(string)$agent['public_id'],6);
p72s8(str_contains((string)$agentContext['text'],'[ACTION PLAN STRATEGIC MEMORY]')&&str_contains((string)$agentContext['text'],'MAY AFFECT UNDERLYING DECISION'),'Research Agent receives read-only strategic Action Plan context.');
$feed=[];research_action_plan_cognitive_observations($pdo,$owner,$feed,20);
p72s8(count(array_filter($feed,fn($item)=>(string)($item['type']??'')==='action_plan_cognition'))>=1,'Now/cognitive feed surfaces material execution attention without mutating execution.');

$activeReview=research_review_create($pdo,$owner,'action_plan',(string)$plan['public_id'],[(int)$reviewer['id']],null,'Review the material execution variance before completion.');
research_review_respond($pdo,$reviewer,(string)$activeReview['public_id'],'request_changes','Record the final cohort result and lessons before closing execution.');
$activeReview=research_review_complete($pdo,$owner,(string)$activeReview['public_id']);
p72s8(($activeReview['status']??'')==='completed'&&(research_action_plan_detail($pdo,$owner,(string)$plan['public_id'])['status']??'')==='active','Execution Team Review remains advisory and does not pause or complete the Action Plan.');

$pdo->prepare("UPDATE research_tasks SET execution_summary='Controlled cohort completed: activation 68%; support demand 140% of forecast.',status='review',blocking_reason=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$task['id']]);
$task=research_task_review($pdo,$owner,(string)$task['public_id'],true);
$milestone=research_action_plan_set_milestone_status($pdo,$owner,(string)$milestone['public_id'],'completed');
$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'completed');
$programAfterCompletion=research_program_access($pdo,$owner,(string)$program['public_id']);
p72s8(($task['status']??'')==='complete'&&($milestone['status']??'')==='completed'&&($plan['status']??'')==='completed','Existing Research Task review, milestone gates, and explicit Action Plan completion close execution in order.');
p72s8(($programAfterCompletion['status']??'')==='paused','Completed Action Plan pauses lifecycle-synchronized follow-through Program.');
p72s8(research_action_plan_outcome_link($pdo,$owner,(string)$plan['public_id'])===null,'Completion never auto-creates final Outcome Memory.');

$centerBefore=research_action_plan_command_center($pdo,$owner,'outcomes',100);
p72s8(($centerBefore['stats']['completed_without_outcome']??0)>=1,'Command Center explicitly surfaces completed execution awaiting Outcome Memory.');

$completionReview=research_review_create($pdo,$owner,'action_plan',(string)$plan['public_id'],[(int)$reviewer['id']],null,'Review completed execution before final learning handoff.');
research_review_respond($pdo,$reviewer,(string)$completionReview['public_id'],'approve','Execution evidence is complete enough for explicit human outcome assessment.');
$preview=research_action_plan_outcome_preview($pdo,$owner,(string)$plan['public_id']);
$link=research_action_plan_record_outcome_handoff($pdo,$owner,(string)$plan['public_id'],[
 'handoff_state_hash'=>(string)$preview['state_hash'],'assessment'=>'failure',
 'actual_summary'=>'Activation reached 68% and support demand reached 140% of forecast.',
 'variance_summary'=>'Activation missed the target and support load materially exceeded the accepted operating assumption.',
 'lessons'=>'Validate support capacity before expanding cohort size; keep support load as a reversal gate.',
 'confidence'=>'0.96','follow_up_state'=>'follow_up'
],false);
p72s8(($link['outcome']['assessment']??'')==='failure'&&($link['execution_baseline_public_id']??'')===(string)$baseline['public_id'],'Explicit human Outcome Memory handoff preserves immutable execution-baseline lineage.');
p72s8(!empty(research_review_access($pdo,$owner,(string)$completionReview['public_id'])['is_stale']),'Final Outcome Memory makes the pre-handoff completion review stale.');
$decisionAfterOutcome=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p72s8(($decisionAfterOutcome['status']??'')==='accepted','Observed failure never auto-reopens or changes the source Decision.');

$signals=research_decision_reconsideration_signals($pdo,$owner,(string)$decision['public_id']);$outcomeSignal=null;
foreach((array)$signals['signals'] as $signal)if(($signal['trigger_type']??'')==='outcome'&&($signal['trigger_public_id']??'')===(string)$link['decision_outcome_public_id']){$outcomeSignal=$signal;break;}
p72s8($outcomeSignal!==null&&($outcomeSignal['materiality']??'')==='critical','Existing Decision reconsideration runtime receives the failure outcome as a critical signal.');

$case=research_decision_open_reconsideration($pdo,$owner,(string)$decision['public_id'],[
 'trigger_type'=>'outcome','trigger_public_id'=>(string)$link['decision_outcome_public_id'],
 'title'=>'Reconsider controlled rollout after failed execution outcome',
 'reason'=>'The final execution outcome missed activation and support-capacity gates.','materiality'=>'critical'
]);
$caseReview=research_review_create($pdo,$owner,'decision_reconsideration',(string)$case['public_id'],[(int)$reviewer['id']],null,'Review the outcome-triggered reconsideration before changing Decision state.');
research_review_respond($pdo,$reviewer,(string)$caseReview['public_id'],'approve','The failed execution outcome supports reopening the Decision for a new evidence cycle.');
$caseReview=research_review_complete($pdo,$owner,(string)$caseReview['public_id']);
p72s8((research_decision_reconsideration_access($pdo,$owner,(string)$case['public_id'])['status']??'')==='open','Reconsideration review remains advisory.');

$case=research_decision_set_reconsideration_status($pdo,$owner,(string)$case['public_id'],'reviewing',['recommended_action'=>'reopen']);
$case=research_decision_set_reconsideration_status($pdo,$owner,(string)$case['public_id'],'resolved',[
 'recommended_action'=>'reopen','resolution'=>'Execution outcome requires a new Decision evidence cycle before any further rollout.'
]);
$applied=research_decision_apply_reconsideration($pdo,$owner,(string)$case['public_id']);
p72s8(($applied['decision']['status']??'')==='reopened'&&!empty($applied['reconsideration']['applied_at']),'Only explicit reconsideration Apply reopens the source Decision.');
$planAfterReopen=research_action_plan_detail($pdo,$owner,(string)$plan['public_id']);
p72s8(!empty($planAfterReopen['source_stale'])&&($planAfterReopen['status']??'')==='completed','Historical completed Action Plan stays immutable and reports stale Decision provenance after reopen.');

$center=research_action_plan_command_center($pdo,$owner,'outcomes',100);$found=null;foreach((array)$center['action_plans'] as $row)if((string)$row['public_id']===(string)$plan['public_id']){$found=$row;break;}
p72s8($found!==null&&!empty($found['command']['outcome_handoff'])&&($center['stats']['outcomes_recorded']??0)>=1,'Final Command Center projection exposes recorded Outcome Memory without side effects.');
$finalContext=research_action_plan_agent_context($pdo,$owner,(string)$agent['public_id'],6);
p72s8(str_contains((string)$finalContext['text'],'Final Outcome Memory: FAILURE')&&str_contains((string)$finalContext['text'],'Lessons learned:'),'Agent can explain final execution result and learning after explicit handoff.');

$q=$pdo->prepare('SELECT COUNT(*) FROM research_action_plans WHERE decision_id=?');$q->execute([(int)$decision['id']]);p72s8((int)$q->fetchColumn()===1,'Final lifecycle retains one authoritative Action Plan for the idempotent handoff.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_action_plan_outcome_links WHERE action_plan_id=?');$q->execute([(int)$plan['id']]);p72s8((int)$q->fetchColumn()===1,'Final lifecycle retains one authoritative Action Plan outcome handoff.');
p72s8(research_action_plan_access($pdo,$outsider,(string)$plan['public_id'])===null,'Outsider cannot access Team Action Plan execution state.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,(int)$reviewer['id']]);
p72s8(research_action_plan_access($pdo,$reviewer,(string)$plan['public_id'])===null,'Team revocation immediately removes Action Plan access.');
p72s8(research_review_access($pdo,$reviewer,(string)$completionReview['public_id'])===null,'Team revocation also removes access to Action Plan review history.');
p72s8(research_action_plan_outcome_link($pdo,$reviewer,(string)$plan['public_id'])===null,'Team revocation removes access to linked final Outcome Memory provenance.');

echo "Phase 72 Section 8 integrated release journey passed: Accepted Decision → Action Plan → milestones/tasks/program → activation baseline → evidence/variance → Agent cognition/Team Review → completion → explicit Outcome Memory → reconsideration signal → explicit Decision reopen.\n";
