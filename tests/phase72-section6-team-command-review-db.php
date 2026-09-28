<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','cognitive-feed','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-reviews'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p72s6(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p72s6throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p72s6(research_action_plans_ready($pdo)&&research_action_plan_cognition_ready($pdo)&&research_reviews_ready($pdo),'Action Plan, cognition, and Collaborative Review runtimes are ready.');
$run='p72s6'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{
  $username=substr(strtolower($name).'_'.$run,0,48);
  $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
    ->execute([$pub('u'),$username,$name,$username.'@example.test']);
  $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('ActionPlanOwner');$reviewer=$makeUser('ActionPlanReviewer');$outsider=$makeUser('ActionPlanOutsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,(int)$owner['id'],'Action Plan Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,(int)$owner['id'],$teamId,(int)$reviewer['id']]);
$agent=research_agent_create($pdo,$owner,['name'=>'Action Plan Team Agent','description'=>'Phase 72 Section 6 fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);

$decision=research_decision_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Team-governed rollout','statement'=>'Proceed with a controlled rollout.',
 'rationale'=>'The evidence supports bounded execution with human review.','confidence'=>0.84
]);
$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted');
$plan=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'section6-plan','title'=>'Team-governed rollout plan','objective'=>'Execute the accepted rollout with team-visible governance.',
 'expected_result'=>'Reach 80% activation without exceeding support capacity.','success_measures'=>[['label'=>'Activation','target'=>'80%']],
 'assumptions'=>['Support capacity remains available.'],'risks'=>[['title'=>'Support overload','detail'=>'Demand may exceed capacity.','mitigation'=>'Gate expansion.']],
 'start_on'=>gmdate('Y-m-d'),'due_on'=>gmdate('Y-m-d',strtotime('+30 days'))
]);
$milestone=research_action_plan_create_milestone($pdo,$owner,(string)$plan['public_id'],['title'=>'Pilot review','target_on'=>gmdate('Y-m-d',strtotime('+10 days')),'completion_criteria'=>['Pilot cohort reviewed.']]);
$task=research_action_plan_add_task($pdo,$owner,(string)$plan['public_id'],['milestone_id'=>$milestone['public_id'],'title'=>'Review pilot cohort','task_type'=>'general','priority'=>'high','due_at'=>gmdate('Y-m-d',strtotime('+8 days')).'T17:00:00Z']);
$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'proposed');

$subject=research_review_subject($pdo,$owner,'action_plan',(string)$plan['public_id']);
p72s6($subject!==null&&($subject['hash']??'')===research_action_plan_review_state_hash($pdo,$owner,(string)$plan['public_id']),'Action Plan is a native stale-aware Review Center subject.');
$review=research_review_create($pdo,$owner,'action_plan',(string)$plan['public_id'],[(int)$reviewer['id']],null,'Review readiness, risks, success measures, and ownership before activation.');
$forReviewer=research_review_access($pdo,$reviewer,(string)$review['public_id']);
p72s6($forReviewer!==null&&!$forReviewer['is_stale'],'Assigned teammate can access the exact pinned Action Plan review.');
$review=research_review_respond($pdo,$reviewer,(string)$review['public_id'],'approve','Execution structure and success measures are clear.');
$agg=research_review_aggregate($pdo,$review);
p72s6(($agg['consensus']??'')==='unanimous_approval','Team response produces explicit unanimous approval consensus.');
$review=research_review_complete($pdo,$owner,(string)$review['public_id']);
$planAfterReview=research_action_plan_detail($pdo,$owner,(string)$plan['public_id']);
p72s6(($review['status']??'')==='completed'&&($planAfterReview['status']??'')==='proposed','Completing Team Review never activates the Action Plan.');

$plan=research_action_plan_update($pdo,$owner,(string)$plan['public_id'],['objective'=>'Execute the accepted rollout with a revised support-capacity checkpoint.','reason'=>'Section 6 stale review test']);
$staleAfterEdit=research_review_access($pdo,$owner,(string)$review['public_id']);
p72s6(!empty($staleAfterEdit['is_stale']),'Changing Action Plan configuration makes the prior pinned Team Review stale.');

$preActivation=research_review_create($pdo,$owner,'action_plan',(string)$plan['public_id'],[(int)$reviewer['id']],null,'Final review before activation.');
$preActivation=research_review_respond($pdo,$reviewer,(string)$preActivation['public_id'],'approve','Ready for explicit owner activation.');
$preActivation=research_review_complete($pdo,$owner,(string)$preActivation['public_id']);
$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'active');
p72s6(!empty(research_review_access($pdo,$owner,(string)$preActivation['public_id'])['is_stale']),'Activation changes strategic state and makes the pre-activation Team Review stale.');

$activeReview=research_review_create($pdo,$owner,'action_plan',(string)$plan['public_id'],[(int)$reviewer['id']],null,'Review active execution against current evidence.');
p72s6(!research_review_access($pdo,$owner,(string)$activeReview['public_id'])['is_stale'],'Fresh active-execution review begins against current strategic state.');
$obs=research_action_plan_record_execution_observation($pdo,$owner,(string)$plan['public_id'],[
 'idempotency_key'=>'section6-critical-evidence','observation_type'=>'new_evidence','summary'=>'Support demand is materially above the accepted capacity assumption.',
 'actual'=>['support_load'=>'145% of forecast'],'assessment'=>'at_risk','material'=>true,'severity'=>'critical',
 'source_type'=>'research_evidence','source_public_id'=>'section6-evidence'
]);
p72s6(!empty($obs['variance']['public_id']),'Critical execution evidence creates an explicit variance.');
p72s6(!empty(research_review_access($pdo,$owner,(string)$activeReview['public_id'])['is_stale']),'Material execution evidence invalidates an in-flight Action Plan Team Review.');

$beforeStatus=(string)research_action_plan_detail($pdo,$owner,(string)$plan['public_id'])['status'];
$center=research_action_plan_command_center($pdo,$owner,'attention',200);$ids=array_column((array)$center['action_plans'],'public_id');
p72s6(in_array($plan['public_id'],$ids,true),'Action Plan Command Center attention view includes the plan after critical execution evidence.');
p72s6(($center['stats']['decision_review']??0)>=1&&($center['stats']['open_reviews']??0)>=1&&($center['stats']['open_variances']??0)>=1,'Command Center aggregates strategic, review, and variance metrics.');
$entry=null;foreach($center['action_plans'] as $row)if($row['public_id']===$plan['public_id']){$entry=$row;break;}
p72s6($entry!==null&&($entry['command']['strategic_state']??'')==='decision_review'&&!empty($entry['command']['review']['latest'])&&!empty($entry['command']['review_url']),'Command Center exposes Decision-review strategic state and direct Team Review context.');
$afterStatus=(string)research_action_plan_detail($pdo,$owner,(string)$plan['public_id'])['status'];
p72s6($afterStatus===$beforeStatus,'Command Center generation is side-effect free.');
p72s6(research_review_subject($pdo,$outsider,'action_plan',(string)$plan['public_id'])===null,'Outsider cannot create review context for an inaccessible Action Plan.');

echo "Phase 72 Section 6 Team Command & Review database journey passed.\n";
