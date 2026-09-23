<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);

foreach(['installer','storage','jobs','functions','access','notifications','rate-limit','conversations','research-automation','research-workspace','research-agents','research-agent-workspace','research-retrieval','workspace-context','object-handoff','agent-chat','research-autonomy','research-monitoring','research-tasks'] as $lib)require_once $root.'/app/'.$lib.'.php';
require_once $root.'/app/cognitive-feed.php';

function p57(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p57throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p57(research_tasks_ready($pdo),'Phase 57 schema is available.');
p57(job_table_meta('research_task_jobs')['schedule']==='available_at','Phase 57 uses the existing leased-job runtime.');

$run='p57'.substr(bin2hex(random_bytes(6)),0,10);
$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
    $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};

$owner=$makeUser('TaskOwner','admin');$outsider=$makeUser('TaskOutsider');
$agent=research_agent_create($pdo,$owner,['name'=>'Phase 57 Agent','description'=>'Plan and execute governed research work.','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);
p57((bool)$project,'Research Agent project is available for Phase 57.');

$source=ensure_source($pdo,'https://8.8.8.8/'.$run.'/evidence','Phase 57 Primary Source');
$text='Primary evidence for the Phase 57 deliverable.';
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)")
  ->execute([(int)$source['id'],'https://8.8.8.8/'.$run.'/evidence','Phase 57 Primary Source',$text,hash('sha256',$text)]);
$versionId=(int)$pdo->lastInsertId();
$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$versionId,(int)$source['id']]);
$pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);

$plan=research_task_plan_create($pdo,$owner,[
  'agent_id'=>$agent['public_id'],
  'title'=>'Phase 57 Competitive Research',
  'objective'=>'Verify the source and produce a reviewed competitive research brief.',
  'priority'=>'high',
  'deliverable_type'=>'research_brief',
  'deliverable_title'=>'Phase 57 Competitive Brief',
  'tasks'=>[
    ['title'=>'Collect evidence','description'=>'Review the available source.','task_type'=>'general','priority'=>'high'],
    ['title'=>'Draft reviewed brief','description'=>'Synthesize the verified source into the deliverable.','task_type'=>'draft_deliverable','priority'=>'high','depends_on'=>[0]],
  ],
]);
p57(($plan['status']??'')==='active','57.1 creates an active versioned Research plan.');

$detail=research_task_plan_detail($pdo,$owner,(string)$plan['public_id']);
p57($detail&&count($detail['tasks'])===2,'57.2 plan contains durable ordered tasks.');
p57((int)$detail['current_revision']===1,'57.2 initial plan revision is recorded.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_task_plan_versions WHERE plan_id=?');$q->execute([(int)$detail['id']]);
p57((int)$q->fetchColumn()===1,'57.2 initial plan snapshot is durable.');
p57(!empty($detail['deliverable']['document_public_id']),'57.4 plan creates a normal Research document deliverable.');

$task1=$detail['tasks'][0];$task2=$detail['tasks'][1];
$q=$pdo->prepare('SELECT COUNT(*) FROM research_task_dependencies WHERE task_id=? AND depends_on_task_id=?');$q->execute([(int)$task2['id'],(int)$task1['id']]);
p57((int)$q->fetchColumn()===1,'57.2 task dependencies are durable.');
p57($task2['status']==='queued','57.3 dependent task remains queued until its prerequisite completes.');

$ctx1=research_task_execution_context($pdo,(string)$task1['public_id']);
$run1=$pub('run');
$pdo->prepare("INSERT INTO research_task_runs(public_id,task_id,plan_id,research_agent_id,project_id,trigger_type,status,input_hash) VALUES(?,?,?,?,?,'manual','queued_ai',?)")
  ->execute([$run1,(int)$task1['id'],(int)$detail['id'],(int)$agent['id'],(int)$project['id'],$ctx1['input_hash']]);
$result1=research_task_apply_ai_output($pdo,(string)$task1['public_id'],json_encode([
  'summary'=>'The primary source is available and suitable for the next synthesis task.',
  'state'=>'review',
  'blocking_reason'=>'',
  'evidence_refs'=>[['type'=>'source','id'=>$source['public_id'],'relationship'=>'primary','locator'=>'current version']],
]),'ai-'.$run1);
p57(($result1['status']??'')==='complete','57.3 a task with satisfied gates can complete from a governed AI result.');

$task2=research_task_access($pdo,$owner,(string)$task2['public_id']);
p57($task2&&$task2['status']==='ready','57.3 completing a dependency makes the next task ready.');
$q=$pdo->prepare("SELECT status FROM research_task_jobs WHERE task_id=?");$q->execute([(int)$task2['id']);
p57((string)$q->fetchColumn()==='queued','57.3 ready dependent work is handed to the leased worker queue.');

$ctx2=research_task_execution_context($pdo,(string)$task2['public_id']);
$run2=$pub('run');
$pdo->prepare("INSERT INTO research_task_runs(public_id,task_id,plan_id,research_agent_id,project_id,trigger_type,status,input_hash) VALUES(?,?,?,?,?,'dependency_ready','queued_ai',?)")
  ->execute([$run2,(int)$task2['id'],(int)$detail['id'],(int)$agent['id'],(int)$project['id'],$ctx2['input_hash']]);
$result2=research_task_apply_ai_output($pdo,(string)$task2['public_id'],json_encode([
  'summary'=>'Draft brief prepared from the primary source and ready for human review.',
  'state'=>'review',
  'blocking_reason'=>'',
  'evidence_refs'=>[['type'=>'source','id'=>$source['public_id'],'relationship'=>'primary','locator'=>'current version']],
]),'ai-'.$run2);
p57(($result2['status']??'')==='review','57.5 human-review gate prevents the Agent from self-approving a deliverable task.');
$task2=research_task_access($pdo,$owner,(string)$task2['public_id']);
$reviewed=research_task_review($pdo,$owner,(string)$task2['public_id'],true);
p57(($reviewed['status']??'')==='complete'&&!empty($reviewed['human_reviewed_at']),'57.5 explicit human review satisfies the final completion gate.');

$detail=research_task_plan_detail($pdo,$owner,(string)$plan['public_id']);
p57(($detail['status']??'')==='completed','57.3 the plan completes only after all planned tasks complete.');
p57(($detail['deliverable']['status']??'')==='active','57.4 living deliverable remains managed after task completion.');
$docPublic=(string)$detail['deliverable']['document_public_id'];
$doc=research_agent_workspace_object($pdo,$owner,$docPublic,false);
p57($doc&&str_contains((string)($doc['document_plain_text']??''),'Draft brief prepared'),'57.4 living deliverable includes task output.');

$userEdited=research_agent_workspace_save_document($pdo,$owner,$docPublic,[
  'title'=>(string)$doc['title'],
  'content_html'=>(string)$doc['content_html'].'<p>User-authored final note.</p>',
  'summary'=>(string)($doc['document_summary']??''),
  'base_revision'=>(int)$doc['revision_number'],
]);
research_task_refresh_deliverable($pdo,$owner,(string)$plan['public_id']);
$detail=research_task_plan_detail($pdo,$owner,(string)$plan['public_id']);
p57(($detail['deliverable']['status']??'')==='needs_review','57.4 Agent stops overwriting a living deliverable after a user edits it.');
$resumed=research_task_deliverable_resume($pdo,$owner,(string)$plan['public_id']);
p57(($resumed['deliverable']['status']??'')==='active','57.4 user can explicitly resume Agent-managed deliverable updates.');
$finalized=research_task_deliverable_finalize($pdo,$owner,(string)$plan['public_id']);
p57(($finalized['deliverable']['status']??'')==='finalized','57.4 user can explicitly finalize the deliverable.');

$task1=research_task_access($pdo,$owner,(string)$task1['public_id']);
$pdo->prepare("UPDATE research_tasks SET status='ready',completed_at=NULL WHERE id=?")->execute([(int)$task1['id']]);
$ctxStale=research_task_execution_context($pdo,(string)$task1['public_id']);
$staleRun=$pub('run');
$pdo->prepare("INSERT INTO research_task_runs(public_id,task_id,plan_id,research_agent_id,project_id,trigger_type,status,input_hash) VALUES(?,?,?,?,?,'recovery','queued_ai',?)")
  ->execute([$staleRun,(int)$task1['id'],(int)$detail['id'],(int)$agent['id'],(int)$project['id'],$ctxStale['input_hash']]);
$pdo->prepare("UPDATE research_tasks SET title=CONCAT(title,' revised') WHERE id=?")->execute([(int)$task1['id']]);
$stale=research_task_apply_ai_output($pdo,(string)$task1['public_id'],json_encode(['summary'=>'Stale result','state'=>'review','blocking_reason'=>'','evidence_refs'=>[]]),'ai-'.$staleRun);
$task1=research_task_access($pdo,$owner,(string)$task1['public_id']);
p57(!empty($stale['stale'])&&$task1['status']==='queued','57.3 stale AI output is discarded and the task is safely requeued.');

$signalPublic=$pub('obs');$fingerprint=hash('sha256','p57-signal-'.$run);
$pdo->prepare("INSERT INTO research_autonomy_observations(public_id,research_agent_id,project_id,observation_type,subject_type,subject_public_id,fingerprint,title,detail,severity,status) VALUES(?,?,?,'evidence_gap','project',?,?,?,'Need another independent source','high','open')")
  ->execute([$signalPublic,(int)$agent['id'],(int)$project['id'],(string)$project['public_id'],$fingerprint,'Find a second independent source']);
$createdFirst=research_task_sync_agent_signals($pdo,(int)$agent['id']);
$createdSecond=research_task_sync_agent_signals($pdo,(int)$agent['id']);
p57($createdFirst>=1&&$createdSecond===0,'57.1 evidence-gap signals create deduplicated Agent tasks.');

$summary=research_task_summary($pdo,$owner,(string)$agent['public_id']);
p57(isset($summary['active'],$summary['waiting'],$summary['review'],$summary['complete']),'57.6 Agent task summary is available for Research UI surfaces.');
$items=[];research_task_cognitive_observations($pdo,$owner,$items,24);
p57(!empty($items),'57.6 task state surfaces into the Now/cognitive feed.');
p57throws(fn()=>research_task_summary($pdo,$outsider,(string)$agent['public_id']),'57.6 task and plan state remains live permission checked.');

$pausePlan=research_task_signal_plan($pdo,$owner,$agent,$project);
research_task_plan_set_status($pdo,$owner,(string)$pausePlan['public_id'],'paused');
$q=$pdo->prepare("SELECT COUNT(*) FROM research_task_jobs WHERE plan_id=? AND status='queued'");$q->execute([(int)$pausePlan['id']]);
p57((int)$q->fetchColumn()===0,'57.6 pausing a plan drains queued task work.');
research_task_plan_set_status($pdo,$owner,(string)$pausePlan['public_id'],'active');

echo "Phase 57 Research Tasks, Plans & Deliverables MariaDB suite passed.\n";
