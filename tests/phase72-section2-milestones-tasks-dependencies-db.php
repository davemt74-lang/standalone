<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-action-plans'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p72s2(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p72s2throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p72s2(research_action_plans_ready($pdo)&&research_action_plan_execution_ready($pdo)&&research_tasks_ready($pdo),'Action Plan execution and existing Research Task runtimes are ready.');
$run='p72s2'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('ExecutionOwner');$teammate=$makeUser('ExecutionTeammate');$outsider=$makeUser('ExecutionOutsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,(int)$owner['id'],'Execution Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,(int)$owner['id'],$teamId,(int)$teammate['id']]);
$agent=research_agent_create($pdo,$owner,['name'=>'Execution Agent','description'=>'Phase 72 Section 2 fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);

$decision=research_decision_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Execute controlled expansion','statement'=>'Proceed with controlled expansion.',
 'rationale'=>'The approved Decision authorizes bounded execution.','confidence'=>0.88,'accountable_user_id'=>$owner['public_id']
]);
$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'proposed');
$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted');

$plan=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'section2-main-plan','owner_user_id'=>$owner['public_id'],'title'=>'Controlled expansion execution',
 'objective'=>'Execute the approved rollout through gated milestones.','expected_result'=>'A controlled rollout completes without bypassing operational gates.',
 'priority'=>'high','success_measures'=>['All gated milestones complete.','Execution tasks retain auditable evidence.']
]);
$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'proposed');

$m1=research_action_plan_create_milestone($pdo,$owner,(string)$plan['public_id'],[
 'title'=>'Prepare rollout','description'=>'Prepare operators and launch controls.','owner_user_id'=>$owner['public_id'],
 'completion_criteria'=>['Readiness checklist is complete.'],'target_on'=>'2026-10-05'
]);
$m2=research_action_plan_create_milestone($pdo,$owner,(string)$plan['public_id'],[
 'title'=>'Launch controlled cohort','description'=>'Launch only after preparation is complete.','owner_user_id'=>$teammate['public_id'],
 'completion_criteria'=>['Controlled cohort launch tasks are complete.'],'target_on'=>'2026-10-12'
]);
$m2=research_action_plan_add_milestone_dependency($pdo,$owner,(string)$m2['public_id'],(string)$m1['public_id']);
p72s2(count((array)$m2['dependencies'])===1&&($m2['dependencies'][0]['public_id']??'')===$m1['public_id'],'Milestone dependency is recorded inside the Action Plan.');
p72s2throws(fn()=>research_action_plan_add_milestone_dependency($pdo,$owner,(string)$m1['public_id'],(string)$m2['public_id']),'Milestone dependency cycles are rejected.');

$other=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'section2-other-plan','owner_user_id'=>$owner['public_id'],'title'=>'Separate execution plan',
 'objective'=>'Provide a separate dependency isolation fixture.','expected_result'=>'Cross-plan dependency attempts are rejected.','success_measures'=>['Isolation remains intact.']
]);
$otherMilestone=research_action_plan_create_milestone($pdo,$owner,(string)$other['public_id'],[
 'title'=>'Separate milestone','completion_criteria'=>['Separate work remains independent.']
]);
p72s2throws(fn()=>research_action_plan_add_milestone_dependency($pdo,$owner,(string)$m1['public_id'],(string)$otherMilestone['public_id']),'Cross-Action-Plan milestone dependencies are rejected.');

$t1=research_action_plan_add_task($pdo,$owner,(string)$plan['public_id'],[
 'milestone_id'=>$m1['public_id'],'title'=>'Prepare operator checklist','description'=>'Prepare the readiness checklist.','task_type'=>'general','priority'=>'high'
]);
$t2=research_action_plan_add_task($pdo,$owner,(string)$plan['public_id'],[
 'milestone_id'=>$m2['public_id'],'title'=>'Launch controlled cohort','description'=>'Launch only after preparation task completes.','task_type'=>'general','priority'=>'high','depends_on'=>[$t1['public_id']]
]);
$execution=research_action_plan_execution_detail($pdo,$owner,(string)$plan['public_id']);
p72s2(!empty($execution['task_plan'])&&count((array)$execution['milestones'])===2,'Action Plan lazily owns one existing Research Task Plan plus two milestones.');
p72s2((int)$execution['task_plan']['id']===(int)$plan['execution_task_plan_id']||!empty($execution['task_plan']['public_id']),'Execution uses the existing Research Task Plan runtime.');
p72s2(count((array)$execution['milestones'][0]['tasks'])+count((array)$execution['milestones'][1]['tasks'])===2,'Execution tasks are ordinary linked Research Tasks.');

$q=$pdo->prepare("SELECT COUNT(*) FROM research_task_jobs WHERE task_id IN (?,?) AND status='queued'");$q->execute([(int)$t1['id'],(int)$t2['id']]);
p72s2((int)$q->fetchColumn()===0,'Proposed Action Plan does not queue execution work.');

$otherTask=research_action_plan_add_task($pdo,$owner,(string)$other['public_id'],[
 'milestone_id'=>$otherMilestone['public_id'],'title'=>'Separate task','task_type'=>'general'
]);
p72s2throws(fn()=>research_action_plan_set_task_dependencies($pdo,$owner,(string)$plan['public_id'],(string)$t1['public_id'],[(string)$otherTask['public_id']]),'Cross-Action-Plan task dependencies are rejected.');
p72s2throws(fn()=>research_action_plan_set_task_dependencies($pdo,$owner,(string)$plan['public_id'],(string)$t1['public_id'],[(string)$t2['public_id']]),'Task dependency cycles are rejected.');

$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'active');
$execution=research_action_plan_execution_detail($pdo,$owner,(string)$plan['public_id']);
p72s2(($execution['task_plan']['status']??'')==='active','Action Plan activation synchronizes the existing Research Task Plan to Active.');
$t1Now=research_task_access($pdo,$owner,(string)$t1['public_id']);$t2Now=research_task_access($pdo,$owner,(string)$t2['public_id']);
p72s2(($t1Now['status']??'')==='queued'&&str_contains((string)($t1Now['blocking_reason']??''),'Action Plan or milestone'),'Task stays queued while its milestone is only Planned.');
p72s2(($t2Now['status']??'')==='queued','Dependent task remains queued before its milestone can start.');
p72s2throws(fn()=>research_action_plan_set_milestone_status($pdo,$owner,(string)$m2['public_id'],'in_progress'),'Child milestone cannot start before parent milestone completes.');

$m1=research_action_plan_set_milestone_status($pdo,$owner,(string)$m1['public_id'],'in_progress');
$t1Now=research_task_access($pdo,$owner,(string)$t1['public_id']);
p72s2(($t1Now['status']??'')==='ready','Starting the first milestone requeues its eligible existing Research Task.');
$q=$pdo->prepare("SELECT COUNT(*) FROM research_task_jobs WHERE task_id=? AND status='queued'");$q->execute([(int)$t1['id']]);
p72s2((int)$q->fetchColumn()===1,'Eligible Action Plan task is queued through the existing Research Task job table.');
p72s2throws(fn()=>research_action_plan_add_milestone_dependency($pdo,$owner,(string)$m1['public_id'],(string)$otherMilestone['public_id']),'Milestone dependency graph becomes immutable once milestone work starts.');
p72s2throws(fn()=>research_action_plan_set_milestone_status($pdo,$owner,(string)$m1['public_id'],'completed',true),'Agent cannot complete an Action Plan milestone.');

$pdo->prepare("UPDATE research_tasks SET execution_summary='Readiness checklist prepared and verified.',status='review',blocking_reason=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$t1['id']]);
$t1Done=research_task_review($pdo,$owner,(string)$t1['public_id'],true);
p72s2(($t1Done['status']??'')==='complete','Existing Research Task review/gates remain authoritative for execution-task completion.');
$m1=research_action_plan_set_milestone_status($pdo,$owner,(string)$m1['public_id'],'completed');
p72s2(($m1['status']??'')==='completed'&&!empty($m1['completed_at']),'Human completes milestone only after its linked task and criteria are complete.');

p72s2throws(fn()=>research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'completed'),'Action Plan cannot complete while a downstream milestone/task remains incomplete.');
$m2=research_action_plan_set_milestone_status($pdo,$teammate,(string)$m2['public_id'],'in_progress');
$t2Now=research_task_access($pdo,$owner,(string)$t2['public_id']);
p72s2(($t2Now['status']??'')==='ready','Completing parent milestone and starting child milestone releases the dependent existing Research Task.');
$deps=$pdo->prepare("SELECT COUNT(*) FROM research_task_dependencies WHERE task_id=? AND depends_on_task_id=?");$deps->execute([(int)$t2['id'],(int)$t1['id']]);
p72s2((int)$deps->fetchColumn()===1,'Task dependency is stored in the existing Research Task dependency table.');

$pdo->prepare("UPDATE research_tasks SET execution_summary='Controlled cohort launched under the approved gates.',status='review',blocking_reason=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$t2['id']]);
$t2Done=research_task_review($pdo,$owner,(string)$t2['public_id'],true);
p72s2(($t2Done['status']??'')==='complete','Second execution task completes through the existing Research Task review path.');
$m2=research_action_plan_set_milestone_status($pdo,$owner,(string)$m2['public_id'],'completed');
p72s2(($m2['status']??'')==='completed','Dependent milestone completes after parent and task completion.');

$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'completed');
p72s2(($plan['status']??'')==='completed'&&!empty($plan['completed_at']),'Action Plan completes only after milestone and task execution is complete.');
$execution=research_action_plan_execution_detail($pdo,$owner,(string)$plan['public_id']);
p72s2(($execution['task_plan']['status']??'')==='completed','Existing Research Task Plan retains its own completed execution history.');

$q=$pdo->prepare('SELECT COUNT(*) FROM research_action_plan_task_links WHERE action_plan_id=?');$q->execute([(int)$plan['id']]);
p72s2((int)$q->fetchColumn()===2,'Action Plan owns links to existing Research Tasks without duplicating task records.');
p72s2(research_action_plan_milestone_access($pdo,$outsider,(string)$m1['public_id'])===null,'Outsider cannot access Team milestone execution state.');
p72s2(research_action_plan_linked_task($pdo,$outsider,(string)$plan['public_id'],(string)$t1['public_id'])===null,'Outsider cannot access Action Plan execution-task links.');

echo "Phase 72 Section 2 Milestones, Tasks & Dependencies database journey passed.\n";
