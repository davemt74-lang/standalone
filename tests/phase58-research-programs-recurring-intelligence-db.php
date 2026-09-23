<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);

foreach(['installer','storage','jobs','functions','access','notifications','rate-limit','conversations','research-automation','research-workspace','research-agents','research-agent-workspace','research-retrieval','workspace-context','object-handoff','agent-chat','research-autonomy','research-monitoring','research-tasks','research-programs'] as $lib)require_once $root.'/app/'.$lib.'.php';
require_once $root.'/app/cognitive-feed.php';

function p58(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p58throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p58(research_programs_ready($pdo),'Phase 58 schema is available.');
p58(job_table_meta('research_program_runs')['schedule']==='available_at','58.6 Program runs use the existing leased-job architecture.');

$run='p58'.substr(bin2hex(random_bytes(6)),0,10);
$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
    $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};

$owner=$makeUser('ProgramOwner','admin');$outsider=$makeUser('ProgramOutsider');
$agent=research_agent_create($pdo,$owner,['name'=>'Phase 58 Agent','description'=>'Run recurring governed intelligence.','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);
p58((bool)$project,'Research Agent project is available for Programs.');

$source=ensure_source($pdo,'https://8.8.8.8/'.$run.'/program-source','Phase 58 Program Source');
$text1='Initial Phase 58 evidence.';
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)")
  ->execute([(int)$source['id'],'https://8.8.8.8/'.$run.'/program-source','Phase 58 Program Source',$text1,hash('sha256',$text1)]);
$v1=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$v1,(int)$source['id']]);
$pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);

$program=research_program_create($pdo,$owner,[
  'agent_id'=>$agent['public_id'],
  'title'=>'Phase 58 Weekly Intelligence',
  'objective'=>'Track material evidence changes and create a recurring reviewed intelligence deliverable.',
  'cadence'=>'weekly','timezone_name'=>'UTC','run_time_local'=>'09:00','weekday'=>1,
  'priority'=>'high','deliverable_type'=>'weekly_report',
  'quiet_mode'=>'material_only','materiality_threshold'=>'important','catch_up_mode'=>'latest',
  'token_budget_per_run'=>50000,'monthly_run_limit'=>31,'max_concurrent_runs'=>1,'max_tasks_per_run'=>4,
  'scope'=>['source_ids'=>[$source['public_id']],'topics'=>['phase 58 evidence'],'include_annotations'=>true,'include_workspace'=>true],
  'tasks'=>[['title'=>'Review recurring evidence','description'=>'Review the material delta and summarize the implications.','task_type'=>'general','priority'=>'high']]
]);
p58(($program['status']??'')==='active'&&($program['cadence']??'')==='weekly','58.1 creates an Agent/project-scoped recurring Program.');
p58(!empty($program['next_run_at']),'58.1 scheduled Programs receive a durable next-run time.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_program_versions WHERE program_id=?');$q->execute([(int)$program['id']]);
p58((int)$q->fetchColumn()===1,'58.1 initial Program configuration is versioned.');

$run1Public=research_program_enqueue($pdo,$program,(int)$owner['id'],'manual');
p58(is_string($run1Public)&&$run1Public!=='','58.2 manual Program run is queued.');
p58(research_program_enqueue($pdo,$program,(int)$owner['id'],'manual')===null,'58.6 queued work counts against the Program concurrency ceiling.');

$programUpdated=research_program_update($pdo,$owner,(string)$program['public_id'],['title'=>'Phase 58 Updated Intelligence','reason'=>'Rename future cycles']);
p58((int)$programUpdated['current_revision']===2,'58.1 Program edits create a new configuration revision.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_program_versions WHERE program_id=?');$q->execute([(int)$program['id']]);
p58((int)$q->fetchColumn()===2,'58.1 Program revision history preserves the original configuration.');
$noop=research_program_update($pdo,$owner,(string)$program['public_id'],['reason'=>'No-op']);
p58((int)$noop['current_revision']===2,'58.1 no-op edits do not create fake Program revisions.');

$claimed1=research_program_claim($pdo);p58($claimed1&&(string)$claimed1['public_id']===$run1Public,'58.2 leased worker claims the queued Program run.');
$effective1=research_program_effective_for_run(research_program_by_id($pdo,(int)$claimed1['program_id']),$claimed1);
p58(($effective1['title']??'')==='Phase 58 Weekly Intelligence'&&(int)$effective1['run_program_revision']===1,'58.2 a queued run is pinned to the Program revision/config that created it.');

$result1=research_program_prepare_run($pdo,$effective1,$owner,$claimed1);
p58(($result1['status']??'')==='active'&&count($result1['material']??[])===1,'58.3 the first run captures a material baseline.');
research_program_activate_run($pdo,$effective1,$claimed1,(string)$claimed1['claim_token'],$result1);
$q=$pdo->prepare("SELECT id,public_id,title,program_run_id FROM research_task_plans WHERE program_run_id=? LIMIT 1");$q->execute([(int)$claimed1['id']]);$plan1=$q->fetch();
p58($plan1&&(int)$plan1['program_run_id']===(int)$claimed1['id'],'58.2 each material Program run creates a fresh Phase 57 plan linked atomically before task queueing.');
p58(str_contains((string)$plan1['title'],'Phase 58 Weekly Intelligence'),'58.2 the generated plan uses the pinned run configuration, not a later Program edit.');
$detail1=research_task_plan_detail($pdo,$owner,(string)$plan1['public_id']);
p58($detail1&&!empty($detail1['deliverable']['document_public_id']),'58.4 Program run creates a normal versioned Phase 57 Research Doc deliverable.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_task_plans WHERE program_run_id=?');$q->execute([(int)$claimed1['id']]);
p58((int)$q->fetchColumn()===1,'58.2 one Program run owns exactly one generated plan.');

$pdo->prepare("UPDATE research_tasks SET status='complete',completed_at=NOW() WHERE plan_id=?")->execute([(int)$plan1['id']]);
$pdo->prepare("UPDATE research_task_jobs SET status='done',claim_token=NULL,lease_expires_at=NULL,completed_at=NOW() WHERE plan_id=? AND status IN ('queued','processing')")->execute([(int)$plan1['id']]);
$pdo->prepare("UPDATE research_task_plans SET status='completed',completed_at=NOW() WHERE id=?")->execute([(int)$plan1['id']]);
p58(research_program_reconcile_runs($pdo,50)>=1,'58.4 completed Phase 57 plan reconciles into a completed Program run.');
$run1=research_program_run_row($pdo,$owner,$run1Public);
p58($run1&&$run1['status']==='completed','58.4 historical Program run records completion without mutating the prior plan.');

$programCurrent=research_program_access($pdo,$owner,(string)$program['public_id']);
$run2Public=research_program_enqueue($pdo,$programCurrent,(int)$owner['id'],'manual');
$claimed2=research_program_claim($pdo);p58($claimed2&&(string)$claimed2['public_id']===$run2Public,'58.2 second recurring cycle can be claimed.');
$effective2=research_program_effective_for_run(research_program_by_id($pdo,(int)$claimed2['program_id']),$claimed2);
$result2=research_program_prepare_run($pdo,$effective2,$owner,$claimed2);
p58(($result2['status']??'')==='skipped'&&!empty($result2['quiet']),'58.4 unchanged Research state is suppressed by material-only quiet mode.');
research_program_complete_quiet_run($pdo,$effective2,$claimed2,(string)$claimed2['claim_token'],$result2);
$q=$pdo->prepare('SELECT COUNT(*) FROM research_task_plans WHERE program_run_id=?');$q->execute([(int)$claimed2['id']]);
p58((int)$q->fetchColumn()===0,'58.4 a quiet run does not generate a redundant plan or deliverable.');

$text2='Updated Phase 58 evidence with a material change.';
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,2,?,?,?,?)")
  ->execute([(int)$source['id'],'https://8.8.8.8/'.$run.'/program-source','Phase 58 Program Source',$text2,hash('sha256',$text2)]);
$v2=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$v2,(int)$source['id']]);

$programCurrent=research_program_access($pdo,$owner,(string)$program['public_id']);
$run3Public=research_program_enqueue($pdo,$programCurrent,(int)$owner['id'],'manual');
$claimed3=research_program_claim($pdo);p58($claimed3&&(string)$claimed3['public_id']===$run3Public,'58.3 changed cycle is queued and claimed.');
$effective3=research_program_effective_for_run(research_program_by_id($pdo,(int)$claimed3['program_id']),$claimed3);
$result3=research_program_prepare_run($pdo,$effective3,$owner,$claimed3);
$types=array_column($result3['deltas']??[],'type');
p58(($result3['status']??'')==='active'&&in_array('source_changed',$types,true),'58.3 source-version changes produce structured provenance-backed deltas.');
research_program_activate_run($pdo,$effective3,$claimed3,(string)$claimed3['claim_token'],$result3);
$q=$pdo->prepare('SELECT public_id,title FROM research_task_plans WHERE program_run_id=? LIMIT 1');$q->execute([(int)$claimed3['id']]);$plan3=$q->fetch();
p58($plan3&&$plan3['public_id']!==$plan1['public_id'],'58.2 each material cycle creates a new plan instead of rewriting the previous cycle.');
p58(str_contains((string)$plan3['title'],'Phase 58 Updated Intelligence'),'58.2 future cycles use the newer Program revision.');

$deltas3=research_program_run_deltas($pdo,$owner,$run3Public,100);
p58(count(array_filter($deltas3,fn($d)=>$d['delta_type']==='source_changed'))===1,'58.3 structured delta history remains queryable per Program run.');
$memory=research_program_memory($pdo,research_program_by_id($pdo,(int)$program['id']),12);
p58(str_contains((string)$memory['text'],'Prior runs:'),'58.5 Program continuity is derived from prior runs rather than a parallel hidden memory store.');

$budgetProgram=research_program_create($pdo,$owner,[
  'agent_id'=>$agent['public_id'],'title'=>'Phase 58 Budget Guard','objective'=>'Verify Program execution budgets.',
  'cadence'=>'manual','priority'=>'medium','token_budget_per_run'=>5000,'monthly_run_limit'=>20,'max_concurrent_runs'=>3,
  'tasks'=>[['title'=>'Budget test','task_type'=>'general']]
]);
$budgetRunPublic=research_program_enqueue($pdo,$budgetProgram,(int)$owner['id'],'manual');
$q=$pdo->prepare('SELECT id FROM research_program_runs WHERE public_id=?');$q->execute([$budgetRunPublic]);$budgetRunId=(int)$q->fetchColumn();
$budget=research_program_task_budget($pdo,$budgetRunId,null);
p58(!$budget['allowed']&&(int)$budget['budget']===5000,'58.6 Program token ceilings block a new task when the next reserved task would exceed budget.');

$monthlyProgram=research_program_create($pdo,$owner,[
  'agent_id'=>$agent['public_id'],'title'=>'Phase 58 Monthly Limit','objective'=>'Verify run-count budget.',
  'cadence'=>'manual','monthly_run_limit'=>1,'max_concurrent_runs'=>3,'tasks'=>[['title'=>'Monthly limit test','task_type'=>'general']]
]);
$monthlyFirst=research_program_enqueue($pdo,$monthlyProgram,(int)$owner['id'],'manual');
p58($monthlyFirst!==null&&research_program_enqueue($pdo,$monthlyProgram,(int)$owner['id'],'manual')===null,'58.6 monthly run budget prevents additional cycles after its limit is consumed.');

$skipProgram=research_program_create($pdo,$owner,[
  'agent_id'=>$agent['public_id'],'title'=>'Phase 58 Catch-up Skip','objective'=>'Verify missed-run policy.',
  'cadence'=>'daily','timezone_name'=>'UTC','run_time_local'=>'08:00','catch_up_mode'=>'skip','quiet_mode'=>'material_only',
  'tasks'=>[['title'=>'Catch-up test','task_type'=>'general']]
]);
$pdo->prepare("UPDATE research_programs SET next_run_at=DATE_SUB(NOW(),INTERVAL 5 DAY) WHERE id=?")->execute([(int)$skipProgram['id']]);
$queuedBefore=(int)$pdo->query("SELECT COUNT(*) FROM research_program_runs WHERE program_id=".(int)$skipProgram['id'])->fetchColumn();
research_program_enqueue_due($pdo,100);
$queuedAfter=(int)$pdo->query("SELECT COUNT(*) FROM research_program_runs WHERE program_id=".(int)$skipProgram['id'])->fetchColumn();
$q=$pdo->prepare("SELECT COUNT(*) FROM research_program_events WHERE program_id=? AND event_type='missed_run_skipped'");$q->execute([(int)$skipProgram['id']]);
p58($queuedAfter===$queuedBefore&&(int)$q->fetchColumn()>=1,'58.6 stale scheduled cycles can be skipped without generating catch-up work.');

$pauseProgram=research_program_create($pdo,$owner,[
  'agent_id'=>$agent['public_id'],'title'=>'Phase 58 Pause Guard','objective'=>'Verify pause semantics.',
  'cadence'=>'manual','tasks'=>[['title'=>'Pause test','task_type'=>'general']]
]);
$pauseRun=research_program_enqueue($pdo,$pauseProgram,(int)$owner['id'],'manual');p58($pauseRun!==null,'58.6 pause fixture run is queued.');
research_program_set_status($pdo,$owner,(string)$pauseProgram['public_id'],'paused');
$q=$pdo->prepare("SELECT status FROM research_program_runs WHERE public_id=?");$q->execute([$pauseRun]);
p58((string)$q->fetchColumn()==='skipped','58.6 pausing a Program drains queued cycles while preserving history.');

$summary=research_program_summary($pdo,$owner,(string)$agent['public_id']);
p58(isset($summary['active'],$summary['paused'],$summary['review_tasks'],$summary['next_run_at']),'58.6 Program summary is available for Research UI surfaces.');
$items=[];research_program_cognitive_observations($pdo,$owner,$items,30);
p58(!empty($items),'58.6 recurring Program state surfaces into Now/cognitive feed.');
p58throws(fn()=>research_program_summary($pdo,$outsider,(string)$agent['public_id']),'58.6 Program state remains live permission checked.');

echo "Phase 58 Research Programs & Recurring Intelligence MariaDB suite passed.\n";
