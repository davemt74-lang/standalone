<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-tasks','research-programs','research-missions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}

function rmv1(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function rmv1throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

rmv1(research_missions_ready($pdo),'Research Missions schema is ready.');
$run='rm'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('MissionOwner','admin');$outsider=$makeUser('MissionOutsider');
$agent=research_agent_create($pdo,$owner,['name'=>'Mission Research Agent','description'=>'Research Missions fixture.','cadence'=>'manual','timezone_name'=>'UTC']);
$plansBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_task_plans')->fetchColumn();$programsBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_programs')->fetchColumn();

$mission=research_mission_create($pdo,$owner,[
  'agent_id'=>$agent['public_id'],'title'=>'Evaluate Mercury Orchard','research_question'=>'Should the team expand into the Mercury orchard market?',
  'objective'=>'Produce an evidence-backed answer with the key risks and unresolved uncertainties.','success_definition'=>'Reach a reviewable conclusion supported by independent evidence.',
  'priority'=>'high','scope'=>['topics'=>['Mercury orchard','buyer demand']],'constraints'=>['deadline'=>'2026-10-15'],
  'success_criteria'=>[
    ['type'=>'answer','label'=>'Answer the primary research question'],
    ['type'=>'evidence','label'=>'Use independent supporting evidence','target'=>['min_sources'=>3]]
  ],
  'subquestions'=>[
    ['question'=>'How fast is buyer demand changing?','priority'=>'high'],
    ['question'=>'What are the largest execution risks?','priority'=>'medium']
  ]
]);
rmv1(($mission['status']??'')==='draft'&&count((array)$mission['criteria'])===2&&count((array)$mission['subquestions'])===2,'Mission creation stores lifecycle, criteria, and sub-questions.');
rmv1((int)$pdo->query('SELECT COUNT(*) FROM research_task_plans')->fetchColumn()===$plansBefore&&(int)$pdo->query('SELECT COUNT(*) FROM research_programs')->fetchColumn()===$programsBefore,'Mission creation does not create a Plan or Program.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_mission_versions WHERE mission_id=?');$q->execute([(int)$mission['id']]);rmv1((int)$q->fetchColumn()===1,'Initial Mission configuration is snapshotted.');
$q=$pdo->prepare("SELECT COUNT(*) FROM research_mission_events WHERE mission_id=? AND event_type='mission_created'");$q->execute([(int)$mission['id']]);rmv1((int)$q->fetchColumn()===1,'Mission creation appends an audit event.');

rmv1(research_mission_access($pdo,$outsider,(string)$mission['public_id'])===null,'Mission access is isolated from unrelated users.');
rmv1(count(research_mission_list($pdo,$owner,(string)$agent['public_id']))===1,'Mission list resolves through the owning Research Agent.');
$summary=research_mission_summary($pdo,$owner,(string)$agent['public_id']);rmv1(($summary['missions']??0)===1&&($summary['criteria']['total']??0)===2&&($summary['subquestions']['total']??0)===2,'Mission summary derives criteria and sub-question counts.');

$rev=(int)$mission['current_revision'];$mission=research_mission_update($pdo,$owner,(string)$mission['public_id'],['objective'=>'Produce an evidence-backed recommendation with explicit risks and unresolved uncertainties.','reason'=>'Tighten mission outcome.']);
rmv1((int)$mission['current_revision']===$rev+1,'Mission configuration changes create a new immutable revision.');

$criterion=(array)$mission['criteria'][0];$criterion=research_mission_update_criterion($pdo,$owner,(string)$criterion['public_id'],['status'=>'satisfied','evaluation'=>['reason'=>'Primary answer drafted.']]);
rmv1(($criterion['status']??'')==='satisfied','Success criteria can be evaluated durably.');
$sub=(array)$mission['subquestions'][0];$sub=research_mission_update_subquestion($pdo,$owner,(string)$sub['public_id'],['status'=>'answered','answer_summary'=>'Demand is accelerating based on the current evidence set.','confidence'=>0.82]);
rmv1(($sub['status']??'')==='answered'&&abs((float)$sub['confidence']-0.82)<0.001,'Sub-questions retain answers and bounded confidence.');

$added=research_mission_add_criterion($pdo,$owner,(string)$mission['public_id'],['type'=>'quality','label'=>'Resolve material contradictions']);
rmv1(!empty($added['public_id']),'Mission criteria can be expanded without replacing existing history.');
$newSub=research_mission_add_subquestion($pdo,$owner,(string)$mission['public_id'],['question'=>'Which assumption is most likely to change the conclusion?','priority'=>'high']);
rmv1(!empty($newSub['public_id']),'Mission sub-questions can be expanded durably.');

$mission=research_mission_set_status($pdo,$owner,(string)$mission['public_id'],'active');rmv1(($mission['status']??'')==='active'&&!empty($mission['started_at']),'Mission can be explicitly activated.');
$mission=research_mission_set_status($pdo,$owner,(string)$mission['public_id'],'review');$mission=research_mission_set_status($pdo,$owner,(string)$mission['public_id'],'completed');
rmv1(($mission['status']??'')==='completed'&&!empty($mission['completed_at']),'Mission lifecycle reaches review and completion with timestamps.');
rmv1throws(fn()=>research_mission_set_status($pdo,$owner,(string)$mission['public_id'],'blocked'),'Invalid completed-to-blocked transition is rejected.');
$mission=research_mission_set_status($pdo,$owner,(string)$mission['public_id'],'active');rmv1(($mission['status']??'')==='active'&&empty($mission['completed_at']),'Completed Missions can be deliberately reactivated.');

$q=$pdo->prepare("SELECT COUNT(*) FROM research_mission_events WHERE mission_id=? AND event_type='mission_status_changed'");$q->execute([(int)$mission['id']]);rmv1((int)$q->fetchColumn()===4,'Mission lifecycle changes remain append-only in the event ledger.');
echo "Research Missions V1 Section 1 database journey passed.\n";
