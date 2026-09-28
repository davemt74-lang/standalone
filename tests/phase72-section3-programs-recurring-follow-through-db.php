<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-action-plans'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p72s3(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p72s3throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p72s3(research_action_plan_follow_through_ready($pdo)&&research_programs_ready($pdo),'Action Plan follow-through and existing Research Programs are ready.');
$run='p72s3'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('FollowThroughOwner');$teammate=$makeUser('FollowThroughTeammate');$outsider=$makeUser('FollowThroughOutsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,(int)$owner['id'],'Follow-through Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,(int)$owner['id'],$teamId,(int)$teammate['id']]);
$agent=research_agent_create($pdo,$owner,['name'=>'Follow-through Agent','description'=>'Phase 72 Section 3 fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);

$decision=research_decision_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Governed rollout follow-through','statement'=>'Proceed with the governed rollout.',
 'rationale'=>'Execution requires recurring review without a second scheduler.','confidence'=>0.9,'accountable_user_id'=>$owner['public_id']
]);
$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted');
$plan=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'section3-main-plan','owner_user_id'=>$owner['public_id'],'title'=>'Governed rollout execution',
 'objective'=>'Execute and review the rollout through existing recurring Research Programs.',
 'expected_result'=>'Execution progress is reviewed on cadence without duplicate scheduling infrastructure.',
 'priority'=>'high','success_measures'=>['Milestones complete under governed execution.','Source Decision remains valid during execution.'],
 'due_on'=>'2099-12-31'
]);
$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'proposed');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_action_plan_program_links WHERE action_plan_id=?');$q->execute([(int)$plan['id']]);
p72s3((int)$q->fetchColumn()===0,'Action Plan does not auto-create recurring Programs.');

$execLink=research_action_plan_create_program($pdo,$owner,(string)$plan['public_id'],[
 'program_role'=>'execution_review','cadence'=>'weekly','timezone_name'=>'UTC','run_time_local'=>'09:00'
]);
$execProgram=$execLink['program'];
p72s3(($execProgram['status']??'')==='paused'&&($execLink['program_role']??'')==='execution_review'&&!empty($execLink['sync_with_action_plan']),'Program created while Action Plan is Proposed is linked and Paused.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_program_runs WHERE program_id=?');$q->execute([(int)$execProgram['id']]);
p72s3((int)$q->fetchColumn()===0,'Creating/linking a follow-through Program fabricates no Program run.');

$retry=research_action_plan_create_program($pdo,$teammate,(string)$plan['public_id'],['program_role'=>'execution_review']);
p72s3(($retry['program']['public_id']??'')===$execProgram['public_id'],'Creating the same follow-through role is idempotent across Team members.');

$external=research_program_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'title'=>'Existing evidence follow-through','objective'=>'Existing Program linked into Action Plan follow-through.',
 'cadence'=>'weekly','timezone_name'=>'UTC','run_time_local'=>'10:00','quiet_mode'=>'material_only','materiality_threshold'=>'important'
]);
p72s3(($external['status']??'')==='active','Independent existing Research Program begins under normal Program rules.');
$evidenceLink=research_action_plan_link_program($pdo,$owner,(string)$plan['public_id'],(string)$external['public_id'],'evidence_refresh',true);
p72s3(($evidenceLink['program']['status']??'')==='paused','Linking a Program with sync enabled to a non-Active Action Plan pauses it through existing Program lifecycle.');

$otherPlan=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'section3-other-plan','title'=>'Separate Action Plan','objective'=>'Provide Program link isolation.','expected_result'=>'Program cannot belong to two Action Plans.','success_measures'=>['Isolation remains intact.']
]);
p72s3throws(fn()=>research_action_plan_link_program($pdo,$owner,(string)$otherPlan['public_id'],(string)$execProgram['public_id'],'execution_review',true),'One Research Program cannot be linked to multiple Action Plans.');

$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'active');
$execProgram=research_program_access($pdo,$owner,(string)$execProgram['public_id']);$external=research_program_access($pdo,$owner,(string)$external['public_id']);
p72s3(($execProgram['status']??'')==='active'&&($external['status']??'')==='active','Action Plan activation resumes sync-enabled Programs through existing Program lifecycle.');

$agentLink=research_action_plan_create_program($pdo,$owner,(string)$plan['public_id'],[
 'program_role'=>'decision_follow_up','cadence'=>'monthly'
],true);
$agentProgram=$agentLink['program'];
p72s3(($agentProgram['status']??'')==='paused','Agent-created follow-through Program is forced Paused even while Action Plan is Active.');
$q=$pdo->prepare("SELECT actor_type,payload_json FROM research_program_events WHERE program_id=? AND event_type='paused' ORDER BY id DESC LIMIT 1");$q->execute([(int)$agentProgram['id']]);$agentPause=$q->fetch();
p72s3(($agentPause['actor_type']??'')==='agent','Agent-created Program pause is audit-attributed to the Agent.');
$agentLink=research_action_plan_set_program_sync($pdo,$owner,(string)$plan['public_id'],(string)$agentProgram['public_id'],false);

$successLink=research_action_plan_create_program($pdo,$owner,(string)$plan['public_id'],[
 'program_role'=>'success_measure_check','cadence'=>'weekly','run_time_local'=>'11:00'
]);
$successProgram=$successLink['program'];
p72s3(($successProgram['status']??'')==='active','Human-created follow-through Program can be Active when its Action Plan is Active.');

$evidenceLink=research_action_plan_set_program_sync($pdo,$owner,(string)$plan['public_id'],(string)$external['public_id'],false);
$external=research_program_set_status($pdo,$owner,(string)$external['public_id'],'paused');
$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'paused');
$execProgram=research_program_access($pdo,$owner,(string)$execProgram['public_id']);$successProgram=research_program_access($pdo,$owner,(string)$successProgram['public_id']);$external=research_program_access($pdo,$owner,(string)$external['public_id']);
p72s3(($execProgram['status']??'')==='paused'&&($successProgram['status']??'')==='paused'&&($external['status']??'')==='paused','Action Plan pause synchronizes only enabled Programs and preserves independent paused Program state.');
p72s3(research_program_enqueue($pdo,$execProgram,(int)$owner['id'],'manual',null)===null,'Lifecycle-synced Program cannot enqueue even a manual run while its Action Plan is Paused.');
$independentRun=research_program_enqueue($pdo,$external,(int)$owner['id'],'manual',null);
p72s3(is_string($independentRun)&&$independentRun!=='','Sync-disabled Program remains independently runnable under existing Program manual-run rules while Action Plan is Paused.');
$pdo->prepare("UPDATE research_program_runs SET status='skipped',completed_at=NOW(),summary='Section 3 independent lifecycle fixture.' WHERE public_id=?")->execute([$independentRun]);
$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'active');
$execProgram=research_program_access($pdo,$owner,(string)$execProgram['public_id']);$successProgram=research_program_access($pdo,$owner,(string)$successProgram['public_id']);$external=research_program_access($pdo,$owner,(string)$external['public_id']);
p72s3(($execProgram['status']??'')==='active'&&($successProgram['status']??'')==='active'&&($external['status']??'')==='paused','Action Plan resume does not override Program lifecycle when sync is disabled.');

$milestone=research_action_plan_create_milestone($pdo,$owner,(string)$plan['public_id'],[
 'title'=>'Launch readiness','completion_criteria'=>['Launch readiness task is complete.'],'target_on'=>'2099-12-15'
]);
$task=research_action_plan_add_task($pdo,$owner,(string)$plan['public_id'],[
 'milestone_id'=>$milestone['public_id'],'title'=>'Confirm launch readiness','description'=>'Confirm operators and launch controls are ready.','task_type'=>'general','priority'=>'high'
]);
$execProgram=research_program_access($pdo,$owner,(string)$execProgram['public_id']);
$snapshot0=research_program_snapshot($pdo,$execProgram);
p72s3(($snapshot0['action_plan']['public_id']??'')===$plan['public_id']&&($snapshot0['action_plan']['milestones'][$milestone['public_id']]['status']??'')==='planned','Existing Program snapshot includes linked Action Plan and Planned milestone state.');
$planStateBefore=research_action_plan_detail($pdo,$owner,(string)$plan['public_id']);$taskStateBefore=research_task_access($pdo,$owner,(string)$task['public_id']);

$run1Public=research_program_enqueue($pdo,$execProgram,(int)$owner['id'],'manual',null);p72s3($run1Public!==null,'Existing Research Program enqueue creates the explicit baseline run.');
$run1=research_program_run_row($pdo,$owner,(string)$run1Public);$pdo->prepare("UPDATE research_program_runs SET status='completed',input_snapshot_json=?,completed_at=NOW() WHERE id=?")->execute([json_encode($snapshot0,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$run1['id']]);

$milestone=research_action_plan_set_milestone_status($pdo,$owner,(string)$milestone['public_id'],'in_progress');
$snapshot1=research_program_snapshot($pdo,$execProgram);
$run2Public=research_program_enqueue($pdo,$execProgram,(int)$owner['id'],'manual',null);$run2=research_program_run_row($pdo,$owner,(string)$run2Public);
$deltas1=research_program_compare_snapshots($pdo,$execProgram,$snapshot1,['snapshot'=>$snapshot0],(int)$run2['id']);$types1=array_column($deltas1,'type');
p72s3(in_array('milestone_started',$types1,true),'Existing Program delta engine records Action Plan milestone start.');
$pdo->prepare("UPDATE research_program_runs SET status='completed',input_snapshot_json=?,completed_at=NOW() WHERE id=?")->execute([json_encode($snapshot1,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$run2['id']]);

$pdo->prepare("UPDATE research_tasks SET execution_summary='Launch readiness verified.',status='review',blocking_reason=NULL,updated_at=NOW() WHERE id=?")->execute([(int)$task['id']]);
$task=research_task_review($pdo,$owner,(string)$task['public_id'],true);$milestone=research_action_plan_set_milestone_status($pdo,$owner,(string)$milestone['public_id'],'completed');
$snapshot2=research_program_snapshot($pdo,$execProgram);
$run3Public=research_program_enqueue($pdo,$execProgram,(int)$owner['id'],'manual',null);$run3=research_program_run_row($pdo,$owner,(string)$run3Public);
$deltas2=research_program_compare_snapshots($pdo,$execProgram,$snapshot2,['snapshot'=>$snapshot1],(int)$run3['id']);$types2=array_column($deltas2,'type');
p72s3(in_array('milestone_completed',$types2,true)&&in_array('execution_progress_changed',$types2,true),'Existing Program delta engine records milestone completion and execution-task progress.');
$pdo->prepare("UPDATE research_program_runs SET status='completed',input_snapshot_json=?,completed_at=NOW() WHERE id=?")->execute([json_encode($snapshot2,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),(int)$run3['id']]);

research_decision_add_challenge($pdo,$owner,(string)$decision['public_id'],[
 'challenge_type'=>'question','title'=>'New evidence after execution began','detail'=>'This changes source Decision state after Action Plan pinning.','severity'=>'high'
]);
$snapshot3=research_program_snapshot($pdo,$execProgram);
$run4Public=research_program_enqueue($pdo,$execProgram,(int)$owner['id'],'manual',null);$run4=research_program_run_row($pdo,$owner,(string)$run4Public);
$deltas3=research_program_compare_snapshots($pdo,$execProgram,$snapshot3,['snapshot'=>$snapshot2],(int)$run4['id']);
$staleDeltas=array_values(array_filter($deltas3,fn($d)=>(string)$d['type']==='action_plan_source_stale'));
p72s3(count($staleDeltas)===1&&($staleDeltas[0]['importance']??'')==='high','Existing Program delta ledger raises high-importance Decision provenance staleness.');
$afterRead=research_action_plan_detail($pdo,$owner,(string)$plan['public_id']);$taskAfterRead=research_task_access($pdo,$owner,(string)$task['public_id']);
p72s3(($afterRead['status']??'')===($planStateBefore['status']??'')&&($taskAfterRead['status']??'')==='complete','Program snapshots/deltas observe execution state without mutating Action Plan or Task state.');

$programs=research_action_plan_programs($pdo,$teammate,(string)$plan['public_id']);
p72s3(count($programs)===4,'Team member can read all four explicitly linked follow-through Program roles.');
p72s3(research_action_plan_programs($pdo,$outsider,(string)$plan['public_id'])===[],'Outsider cannot access Action Plan follow-through Programs.');

$unlink=research_action_plan_unlink_program($pdo,$owner,(string)$plan['public_id'],(string)$external['public_id']);
p72s3(!empty($unlink['unlinked'])&&research_program_access($pdo,$owner,(string)$external['public_id'])!==null,'Unlinking preserves the existing Research Program as an independent record.');

echo "Phase 72 Section 3 Programs & Recurring Follow-Through database journey passed.\n";
