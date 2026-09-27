<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-action-plans'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p72s1(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p72s1throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p72s1(research_decisions_ready($pdo)&&research_action_plans_ready($pdo),'Decision and Action Plan Ledger runtimes are ready.');
$run='p72s1'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('ActionPlanOwner');$teammate=$makeUser('ActionPlanTeammate');$outsider=$makeUser('ActionPlanOutsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,(int)$owner['id'],'Action Plan Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,(int)$owner['id'],$teamId,(int)$teammate['id']]);
$agent=research_agent_create($pdo,$owner,['name'=>'Action Plan Agent','description'=>'Phase 72 Section 1 fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);

$draftDecision=research_decision_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Unready execution Decision','statement'=>'Do not execute yet.','rationale'=>'Still under review.'
]);
p72s1throws(fn()=>research_action_plan_from_decision($pdo,$owner,(string)$draftDecision['public_id'],[
 'title'=>'Invalid early Action Plan','objective'=>'Should fail.','expected_result'=>'Should never exist.','success_measures'=>['No plan should exist.']
]),'Draft Decision cannot create an Action Plan.');

$decision=research_decision_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Controlled rollout','statement'=>'Proceed with a controlled rollout.',
 'rationale'=>'Current evidence supports bounded execution with explicit review gates.','confidence'=>0.86,'accountable_user_id'=>$owner['public_id']
]);
$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'proposed');
$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted');
$decisionBefore=research_decision_detail($pdo,$owner,(string)$decision['public_id']);$decisionRevision=(int)$decisionBefore['current_revision'];$decisionStatus=(string)$decisionBefore['status'];
$tasksBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_tasks')->fetchColumn();$programsBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_programs')->fetchColumn();

$plan=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'controlled-rollout-execution-v1','owner_user_id'=>$teammate['public_id'],'title'=>'Execute controlled rollout',
 'objective'=>'Launch the controlled cohort while preserving the approved governance gates.',
 'expected_result'=>'Validate demand without exceeding the agreed support-capacity ceiling.',
 'priority'=>'high',
 'success_measures'=>[
   ['label'=>'Cohort reaches target adoption','target'=>'At least 80% of planned cohort activation'],
   ['label'=>'Support burden stays within ceiling','target'=>'At or below the approved operating ceiling']
 ],
 'risks'=>[['title'=>'Support overload','detail'=>'Demand may outpace support capacity.','mitigation'=>'Gate expansion on capacity review.']],
 'assumptions'=>['The support ceiling remains the governing operational constraint.'],
 'start_on'=>'2026-09-28','due_on'=>'2026-10-31'
]);
p72s1(($plan['status']??'')==='draft','Decision-to-Action handoff always creates a Draft Action Plan.');
p72s1(($plan['owner_user_public_id']??'')===$teammate['public_id'],'Action Plan records an explicit Team-accessible owner.');
p72s1((int)$plan['source_decision_revision']===$decisionRevision&&($plan['source_decision_status']??'')==='accepted','Action Plan pins the exact accepted Decision revision and status.');
p72s1(($plan['source_decision_config_hash']??'')===$decisionBefore['config_hash']&&!empty($plan['source_decision_snapshot']['config']),'Action Plan stores immutable canonical Decision provenance.');
p72s1((int)$plan['current_revision']===1&&count((array)$plan['versions'])===1,'Initial Action Plan configuration is immutable revision 1.');
p72s1(empty($plan['source_stale']),'New Action Plan source provenance is current.');
p72s1((int)$pdo->query('SELECT COUNT(*) FROM research_tasks')->fetchColumn()===$tasksBefore&&(int)$pdo->query('SELECT COUNT(*) FROM research_programs')->fetchColumn()===$programsBefore,'Action Plan foundation creates no Research Tasks or Programs.');

$retry=research_action_plan_from_decision($pdo,$teammate,(string)$decision['public_id'],[
 'idempotency_key'=>'controlled-rollout-execution-v1','title'=>'Retry should reuse','objective'=>'Retry should reuse.','expected_result'=>'Retry should reuse.'
]);
p72s1(($retry['public_id']??'')===$plan['public_id'],'Decision-to-Action retry is idempotent across Team members.');

$updated=research_action_plan_update($pdo,$teammate,(string)$plan['public_id'],[
 'risks'=>[
   ['title'=>'Support overload','detail'=>'Demand may outpace support capacity.','mitigation'=>'Gate expansion on capacity review.'],
   ['title'=>'Training gap','detail'=>'Operators may not be ready for the controlled cohort.','mitigation'=>'Require readiness confirmation before launch.']
 ],
 'reason'=>'Added operational readiness risk'
]);
p72s1((int)$updated['current_revision']===2&&count((array)$updated['versions'])===2,'Action Plan configuration update creates immutable revision 2.');
p72s1((int)$updated['source_decision_revision']===$decisionRevision,'Action Plan edits cannot rebase the pinned Decision revision.');

$proposed=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'proposed');
$active=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'active');
p72s1(($active['status']??'')==='active'&&!empty($active['activated_at']),'Human explicitly activates a current Proposed Action Plan.');
p72s1((int)$active['current_revision']===2,'Lifecycle transitions do not rewrite Action Plan configuration revisions.');
p72s1throws(fn()=>research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'completed',true),'Agent-originated completion is blocked by foundation governance.');

$noMeasure=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'missing-measure-plan','title'=>'Plan without success measures','objective'=>'Demonstrate activation guard.','expected_result'=>'Activation should be blocked.','success_measures'=>[]
]);
$noMeasure=research_action_plan_set_status($pdo,$owner,(string)$noMeasure['public_id'],'proposed');
p72s1throws(fn()=>research_action_plan_set_status($pdo,$owner,(string)$noMeasure['public_id'],'active'),'Action Plan activation requires an explicit success measure.');

research_decision_add_challenge($pdo,$owner,(string)$decision['public_id'],[
 'challenge_type'=>'question','title'=>'New operational evidence requires review','detail'=>'New evidence arrived after Action Plan creation.','severity'=>'high'
]);
$stale=research_action_plan_detail($pdo,$owner,(string)$plan['public_id']);
p72s1(!empty($stale['source_stale'])&&($stale['status']??'')==='active','Decision change marks Action Plan provenance stale without silently pausing active execution.');
$paused=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'paused');
p72s1throws(fn()=>research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'active'),'Stale Decision provenance blocks Action Plan reactivation.');

$completed=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'completed');
p72s1(($completed['status']??'')==='completed'&&!empty($completed['completed_at']),'Explicit human completion records lifecycle timestamp.');
p72s1throws(fn()=>research_action_plan_update($pdo,$owner,(string)$plan['public_id'],['objective'=>'Illegal post-completion edit.']),'Completed Action Plan configuration is immutable.');

$decisionAfter=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p72s1(($decisionAfter['status']??'')===$decisionStatus,'Action Plan lifecycle never changes source Decision disposition.');
p72s1((int)$pdo->query('SELECT COUNT(*) FROM research_tasks')->fetchColumn()===$tasksBefore&&(int)$pdo->query('SELECT COUNT(*) FROM research_programs')->fetchColumn()===$programsBefore,'Action Plan lifecycle still creates no Tasks or Programs.');

$eventTypes=array_column((array)$completed['events'],'event_type');
p72s1(in_array('action_plan_created',$eventTypes,true)&&in_array('action_plan_updated',$eventTypes,true)&&in_array('action_plan_status_changed',$eventTypes,true),'Action Plan creation, edits, and lifecycle are append-only audit events.');
$decisionEventTypes=array_column((array)$decisionAfter['events'],'event_type');
p72s1(in_array('decision_action_plan_created',$decisionEventTypes,true),'Source Decision audit history records explicit Action Plan creation.');

$summary=research_action_plan_summary($pdo,$owner);$list=research_action_plan_list($pdo,$owner,(string)$agent['public_id'],null,100);
p72s1(count($list)===2&&($summary['total']??0)===2,'Action Plan list and summary expose both foundation plans.');
p72s1(($summary['completed']??0)===1&&($summary['proposed']??0)===1&&($summary['stale_source']??0)>=1,'Action Plan summary distinguishes lifecycle and stale Decision provenance.');
p72s1(research_action_plan_access($pdo,$outsider,(string)$plan['public_id'])===null,'Outsider cannot access Team Action Plan state.');

$reopened=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'reopened');
$prep=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'reopened-preparation-plan','title'=>'Prepare revised execution','objective'=>'Prepare a revised plan while the Decision is reopened.','expected_result'=>'A reviewable revised execution plan exists.','success_measures'=>['Revised plan is ready for review.']
]);
p72s1(($prep['status']??'')==='draft'&&($prep['source_decision_status']??'')==='reopened','Reopened Decision may create a Draft preparation plan.');
$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted');
$prep=research_action_plan_set_status($pdo,$owner,(string)$prep['public_id'],'proposed');
p72s1throws(fn()=>research_action_plan_set_status($pdo,$owner,(string)$prep['public_id'],'active'),'Plan drafted from Reopened Decision must be recreated/rebased explicitly after Decision acceptance.');

echo "Phase 72 Section 1 Action Plan Ledger database journey passed.\n";
