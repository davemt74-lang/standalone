<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function rmv15(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}

$run='rm5'.substr(bin2hex(random_bytes(5)),0,10);$public=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$username='mission5_'.$run;
$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','admin','pro','cloaked')")
    ->execute([$public('u'),$username,'Mission Section 5',$username.'@example.test']);
$userId=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$userId]);
$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$userId]);$owner=$q->fetch();

$agent=research_agent_create($pdo,$owner,['name'=>'Mission Command Agent','description'=>'Section 5 fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$mission=research_mission_create($pdo,$owner,[
    'agent_id'=>$agent['public_id'],'title'=>'Command Center Mission','research_question'=>'What decision does the current evidence support?',
    'objective'=>'Produce a reviewable answer with visible evidence and history.','priority'=>'high','success_definition'=>'A cited synthesis is ready for review.',
    'success_criteria'=>[['type'=>'answer','label'=>'Primary question answered']],
    'subquestions'=>[['question'=>'What is the strongest supporting evidence?','priority'=>'high']]
]);
$detail=research_mission_detail($pdo,$owner,(string)$mission['public_id']);
rmv15(is_array($detail)&&count((array)$detail['versions'])===1,'Mission detail exposes immutable configuration history.');
rmv15($detail['plan']===null&&$detail['program']===null,'New Mission detail has no fabricated Plan or Program.');
rmv15(isset($detail['progress']['completion_readiness']),'Mission detail exposes authoritative progress for the Command Center.');

$detail=research_mission_create_plan($pdo,$owner,(string)$mission['public_id'],[]);
rmv15(!empty($detail['plan'])&&count((array)$detail['plan']['tasks'])===2,'Command Center detail exposes the existing Mission Task graph.');
$first=(array)$detail['plan']['tasks'][0];
rmv15(array_key_exists('evidence_refs',$first)&&$first['evidence_refs']===[],'Task graph exposes an evidence reference collection even before evidence is attached.');
$pdo->prepare("INSERT INTO research_task_evidence_refs(task_id,ref_type,ref_public_id,locator,relationship,added_by) VALUES(?,?,?,?,?,'user')")
    ->execute([(int)$first['id'],'source',$public('src'),'page 4','supports']);
$detail=research_mission_detail($pdo,$owner,(string)$mission['public_id']);
$first=(array)$detail['plan']['tasks'][0];
rmv15(count((array)$first['evidence_refs'])===1,'Mission detail exposes durable Task evidence references.');
rmv15(($first['evidence_refs'][0]['relationship']??'')==='supports'&&($first['evidence_refs'][0]['locator']??'')==='page 4','Evidence provenance fields are preserved in the Command Center payload.');

$detail=research_mission_bind_program($pdo,$owner,(string)$mission['public_id'],['cadence'=>'daily','timezone_name'=>'UTC','run_time_local'=>'09:00']);
rmv15(!empty($detail['program'])&&($detail['program']['status']??'')==='paused','Command Center detail exposes the bound paused Research Program.');
$detail=research_mission_update($pdo,$owner,(string)$mission['public_id'],['objective'=>'Produce a reviewable answer with visible evidence, history, and explicit uncertainty.','reason'=>'Section 5 revision visibility']);
rmv15(count((array)$detail['versions'])===2,'Mission detail exposes subsequent configuration revisions.');
rmv15(($detail['versions'][0]['change_reason']??'')==='Section 5 revision visibility','Latest revision reason is visible to the Command Center.');

$list=research_mission_list($pdo,$owner,(string)$agent['public_id'],60);
rmv15(count($list)>=1&&array_key_exists('criteria_count',$list[0])&&array_key_exists('subquestion_count',$list[0]),'Mission list remains lightweight while exposing card summary counts.');
echo "Research Missions V1 Section 5 database journey passed.\n";
