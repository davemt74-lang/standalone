<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function rmv12(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}

rmv12(research_missions_ready($pdo)&&research_tasks_ready($pdo),'Mission and Task runtimes are ready.');
$run='rm2'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$username='mission2_'.$run;
$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','admin','pro','cloaked')")
  ->execute([$pub('u'),$username,'Mission Section 2',$username.'@example.test']);
$uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$owner=$q->fetch();
$agent=research_agent_create($pdo,$owner,['name'=>'Mission Planner Agent','description'=>'Section 2 fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$programsBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_programs')->fetchColumn();

$mission=research_mission_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'title'=>'Orchard Expansion Mission','research_question'=>'Should we expand into the orchard market?',
 'objective'=>'Reach a provenance-backed recommendation with explicit risks.','priority'=>'high','success_definition'=>'Answer the question and expose unresolved uncertainty.',
 'subquestions'=>[
   ['question'=>'What is buyer demand?','priority'=>'high','rationale'=>'Demand sets the opportunity size.'],
   ['question'=>'What are the operational risks?','priority'=>'high'],
   ['question'=>'Which evidence could reverse the conclusion?','priority'=>'medium']
 ]
]);
$plansBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_task_plans')->fetchColumn();
$planned=research_mission_create_plan($pdo,$owner,(string)$mission['public_id'],[]);
rmv12(!empty($planned['plan'])&&($planned['plan']['status']??'')==='paused','Mission creates an existing Research Plan in paused review state.');
rmv12((int)$pdo->query('SELECT COUNT(*) FROM research_task_plans')->fetchColumn()===$plansBefore+1,'Mission planning creates exactly one Research Plan.');
rmv12((int)$pdo->query('SELECT COUNT(*) FROM research_programs')->fetchColumn()===$programsBefore,'Mission planning does not create a Research Program.');
$plan=$planned['plan'];$planId=(int)$plan['id'];$tasks=(array)$plan['tasks'];
rmv12(count($tasks)===4,'Three Mission sub-questions compile to three evidence tasks plus one synthesis task.');
$linked=array_values(array_filter(array_map(fn($s)=>(string)($s['linked_task_public_id']??''),(array)$planned['subquestions'])));
rmv12(count($linked)===3&&count(array_unique($linked))===3,'Each Mission sub-question is durably linked to its own Research Task.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_task_dependencies d JOIN research_tasks t ON t.id=d.task_id WHERE t.plan_id=? AND t.task_type=\'synthesize\'');$q->execute([$planId]);
rmv12((int)$q->fetchColumn()===3,'Synthesis waits for every Mission sub-question task.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_task_jobs WHERE plan_id=?');$q->execute([$planId]);rmv12((int)$q->fetchColumn()===0,'Paused Mission plan does not queue autonomous work.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_task_deliverables WHERE plan_id=?');$q->execute([$planId]);rmv12((int)$q->fetchColumn()===0,'Planning does not create a deliverable before explicit start.');

$again=research_mission_create_plan($pdo,$owner,(string)$mission['public_id'],[]);
rmv12(($again['plan']['public_id']??'')===($plan['public_id']??''),'Mission plan creation is idempotent.');
rmv12((int)$pdo->query('SELECT COUNT(*) FROM research_task_plans')->fetchColumn()===$plansBefore+1,'Repeated plan creation cannot duplicate the Plan.');

$started=research_mission_start($pdo,$owner,(string)$mission['public_id']);
rmv12(($started['status']??'')==='active'&&($started['plan']['status']??'')==='active','Explicit Mission start activates Mission and existing Plan.');
$q=$pdo->prepare("SELECT COUNT(*) FROM research_task_jobs WHERE plan_id=? AND status='queued'");$q->execute([$planId]);rmv12((int)$q->fetchColumn()===3,'Starting queues only dependency-ready sub-question tasks.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_task_deliverables WHERE plan_id=?');$q->execute([$planId]);rmv12((int)$q->fetchColumn()===1,'Starting creates the existing living Research Plan deliverable.');

$paused=research_mission_pause($pdo,$owner,(string)$mission['public_id']);rmv12(($paused['plan']['status']??'')==='paused','Mission execution can pause through the existing Plan control.');
$q=$pdo->prepare("SELECT COUNT(*) FROM research_task_jobs WHERE plan_id=? AND status='queued'");$q->execute([$planId]);rmv12((int)$q->fetchColumn()===0,'Pausing clears queued work through existing Task queue semantics.');

$resumed=research_mission_start($pdo,$owner,(string)$mission['public_id']);rmv12(($resumed['plan']['status']??'')==='active','Mission execution can resume without creating another Plan.');
rmv12((int)$pdo->query('SELECT COUNT(*) FROM research_task_plans')->fetchColumn()===$plansBefore+1,'Resume preserves one Mission Plan.');
echo "Research Missions V1 Section 2 database journey passed.\n";
