<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function rmv14(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function rmv14throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}
$run='rm4'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$make=function(string $name)use($pdo,$run,$pub){$u=strtolower($name).'_'.$run;$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','admin','pro','cloaked')")->execute([$pub('u'),$u,$name,$u.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$make('MissionWatch');$agent=research_agent_create($pdo,$owner,['name'=>'Mission Watch Agent','cadence'=>'manual','timezone_name'=>'UTC']);
$mission=research_mission_create($pdo,$owner,['agent_id'=>$agent['public_id'],'title'=>'Watch Mission','research_question'=>'Has the market changed enough to revisit the conclusion?','objective'=>'Maintain a reviewed conclusion and reopen it on material evidence.','priority'=>'high','scope'=>['topics'=>['market demand']]]);
$programs=(int)$pdo->query('SELECT COUNT(*) FROM research_programs')->fetchColumn();
$bound=research_mission_bind_program($pdo,$owner,(string)$mission['public_id'],['cadence'=>'daily','timezone_name'=>'UTC','run_time_local'=>'09:00','materiality_threshold'=>'important']);
rmv14(!empty($bound['program_public_id']),'Mission creates and binds an existing Research Program.');
$program=research_program_access($pdo,$owner,(string)$bound['program_public_id']);rmv14(($program['status']??'')==='paused'&&empty($program['next_run_at']),'New Mission Watch Program starts paused with no scheduled next run.');
rmv14((int)$pdo->query('SELECT COUNT(*) FROM research_programs')->fetchColumn()===$programs+1,'Binding creates exactly one Program.');
$again=research_mission_bind_program($pdo,$owner,(string)$mission['public_id'],[]);rmv14(($again['program_public_id']??'')===$bound['program_public_id']&&(int)$pdo->query('SELECT COUNT(*) FROM research_programs')->fetchColumn()===$programs+1,'Program binding is idempotent.');
$bound=research_mission_set_program_status($pdo,$owner,(string)$mission['public_id'],'active');$program=research_program_access($pdo,$owner,(string)$bound['program_public_id']);rmv14(($program['status']??'')==='active'&&!empty($program['next_run_at']),'Mission activates the existing Program scheduler explicitly.');
$mission=research_mission_set_status($pdo,$owner,(string)$mission['public_id'],'active');$mission=research_mission_set_status($pdo,$owner,(string)$mission['public_id'],'completed');
$runPublic=research_program_enqueue($pdo,$program,(int)$owner['id'],'manual');rmv14($runPublic!==null,'Bound Program can use the existing Program queue.');
$runRow=research_program_run_row($pdo,$owner,(string)$runPublic);$pdo->prepare("UPDATE research_program_runs SET status='completed',material_change_count=2,summary='Two material changes affect the Mission.',completed_at=NOW() WHERE id=?")->execute([(int)$runRow['id']]);
research_mission_program_observe_run($pdo,$program,(int)$runRow['id'],'program_completed');
$mission=research_mission_detail($pdo,$owner,(string)$mission['public_id']);rmv14(($mission['status']??'')==='active'&&empty($mission['completed_at']),'Recorded material Program changes reactivate a completed Mission.');
$q=$pdo->prepare("SELECT COUNT(*) FROM research_mission_events WHERE mission_id=? AND event_type='mission_material_change_reactivated'");$q->execute([(int)$mission['id']]);rmv14((int)$q->fetchColumn()===1,'Material reactivation is append-only in Mission history.');
$mission=research_mission_set_status($pdo,$owner,(string)$mission['public_id'],'completed');
$run2=research_program_enqueue($pdo,$program,(int)$owner['id'],'manual');$rr2=research_program_run_row($pdo,$owner,(string)$run2);$pdo->prepare("UPDATE research_program_runs SET status='skipped',material_change_count=0,quiet_suppressed=1,summary='No material changes.',completed_at=NOW() WHERE id=?")->execute([(int)$rr2['id']]);
research_mission_program_observe_run($pdo,$program,(int)$rr2['id'],'program_quiet');
$mission=research_mission_detail($pdo,$owner,(string)$mission['public_id']);rmv14(($mission['status']??'')==='completed','Quiet/no-material Program run does not reactivate a completed Mission.');
$other=research_agent_create($pdo,$owner,['name'=>'Other Agent','cadence'=>'manual','timezone_name'=>'UTC']);$otherProgram=research_program_create($pdo,$owner,['agent_id'=>$other['public_id'],'title'=>'Other Program','objective'=>'Unrelated work','cadence'=>'manual','timezone_name'=>'UTC']);
$unbound=research_mission_unbind_program($pdo,$owner,(string)$mission['public_id']);rmv14(empty($unbound['program_public_id']),'Mission can unbind without deleting Program history.');
rmv14throws(fn()=>research_mission_bind_program($pdo,$owner,(string)$mission['public_id'],['program_id'=>$otherProgram['public_id']]),'Mission rejects Program binding from another Research Agent.');
rmv14((bool)research_program_access($pdo,$owner,(string)$program['public_id']),'Unbinding preserves the existing Research Program.');
echo "Research Missions V1 Section 4 database journey passed.\n";
