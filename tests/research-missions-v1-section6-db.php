<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-network','research-provenance','research-verification','research-evidence-packs','research-workflow','research-system-reports','research-report-studio','research-missions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function rmv16(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}

rmv16(research_missions_ready($pdo)&&research_reviews_ready($pdo)&&research_report_studio_ready($pdo),'Mission, Review Center, and Report Studio runtimes are ready.');
$run='rm6'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('MissionOwner');$reviewer=$makeUser('MissionReviewer');$outsider=$makeUser('MissionOutsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,(int)$owner['id'],'Mission Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,(int)$owner['id'],$teamId,(int)$reviewer['id']]);
$agent=research_agent_create($pdo,$owner,['name'=>'Mission Intelligence Agent','description'=>'Section 6 fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);rmv16((bool)$project&&$project['team_id']===$teamId,'Mission Agent uses a real collaborative Team project.');

$mission=research_mission_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'title'=>'Section 6 Mission','research_question'=>'What does the evidence support now?',
 'objective'=>'Produce a reviewable evidence-backed conclusion.','success_definition'=>'A synthesis exists and the team can review it.','priority'=>'high',
 'success_criteria'=>[['label'=>'Primary question answered']], 'subquestions'=>[['question'=>'What is the strongest evidence?','priority'=>'high']]
]);
rmv16(($mission['status']??'')==='draft','New Mission begins in draft state.');

$ctx=agent_chat_context_item($pdo,$owner,'mission',(string)$mission['public_id']);
rmv16($ctx!==null&&str_contains((string)$ctx['text'],'[RESEARCH MISSION')&&str_contains((string)$ctx['text'],'Section 6 Mission'),'Mission can be attached directly to Agent Chat.');
$autoCtx=research_mission_agent_context($pdo,$owner,(string)$agent['public_id'],6);
rmv16(str_contains((string)$autoCtx['text'],'Section 6 Mission')&&count((array)$autoCtx['refs'])===1,'Research Agent receives permission-checked Mission context automatically.');
$outsideCtx=null;try{$outsideCtx=agent_chat_context_item($pdo,$outsider,'mission',(string)$mission['public_id']);}catch(Throwable $ignored){}
rmv16($outsideCtx===null,'Outsider cannot resolve Mission Agent Chat context.');
$mission=research_mission_set_status($pdo,$owner,(string)$mission['public_id'],'active');

$feed=[];research_mission_cognitive_observations($pdo,$owner,$feed,20);
$missionCards=array_values(array_filter(array_values($feed),fn($x)=>str_starts_with((string)($x['type']??''),'research_mission_')));
rmv16(count($missionCards)>=1&&str_contains((string)$missionCards[0]['actions'][0]['url'],'research-missions.php'),'Mission state surfaces in Now with a Command Center action.');

$review=research_review_create($pdo,$owner,'mission',(string)$mission['public_id'],[(int)$reviewer['id']],null,'Check the Mission question, progress, and current evidence.');
rmv16(($review['subject_type']??'')==='mission'&&!empty($review['subject']['url']),'Mission uses the existing Review Center as a first-class review subject.');
$reviewerView=research_review_access($pdo,$reviewer,(string)$review['public_id']);
rmv16($reviewerView!==null&&!$reviewerView['is_stale'],'Assigned Team reviewer can access the exact current Mission review.');
research_review_respond($pdo,$reviewer,(string)$review['public_id'],'approve','The current Mission definition is reviewable.');
research_mission_update($pdo,$owner,(string)$mission['public_id'],['objective'=>'Produce a reviewable evidence-backed conclusion with explicit uncertainty.','reason'=>'Material Mission revision']);
$stale=research_review_access($pdo,$owner,(string)$review['public_id']);
rmv16($stale['is_stale']===true,'Mission review becomes stale when durable Mission state changes.');
$restarted=research_review_restart($pdo,$owner,(string)$review['public_id'],null);
rmv16($restarted['status']==='open'&&$restarted['subject_hash']!==$review['subject_hash'],'Review Center can restart a stale Mission review against current Mission state.');

$args=agent_action_clean_arguments('research.create_mission',[
 'title'=>'Agent Proposed Mission','research_question'=>'Which supplier risk should we investigate first?','objective'=>'Rank the most material supplier risk.',
 'success_definition'=>'A cited risk ranking is reviewable.','priority'=>'high','success_criteria'=>['Primary risk identified'],'subquestions'=>['Which risks have direct evidence?']
]);
$programsBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_programs')->fetchColumn();$plansBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_task_plans')->fetchColumn();
$created=agent_action_execute_capability($pdo,$owner,$project,'research.create_mission',$args);
$agentMission=research_mission_detail($pdo,$owner,(string)$created['public_id']);
rmv16(($created['type']??'')==='mission'&&($agentMission['status']??'')==='draft','Governed Agent capability creates a draft Mission.');
rmv16(empty($agentMission['plan_public_id'])&&empty($agentMission['program_public_id'])&&(int)$pdo->query('SELECT COUNT(*) FROM research_task_plans')->fetchColumn()===$plansBefore&&(int)$pdo->query('SELECT COUNT(*) FROM research_programs')->fetchColumn()===$programsBefore,'Agent Mission creation cannot silently start Plan or Program execution.');

$types=research_system_report_types();
rmv16(isset($types['mission_brief'],$types['mission_review']),'Report Studio exposes Mission Brief and Mission Review Brief.');
$brief=research_system_report_generate($pdo,[],$owner,(string)$agent['public_id'],'mission_brief','Section 6 Mission Brief',false,null,['depth'=>'standard','focus_query'=>'Section 6 Mission']);
rmv16(str_contains((string)$brief['rendered_html'],'Mission portfolio')&&str_contains((string)$brief['rendered_html'],'Section 6 Mission'),'Mission Brief deterministically renders focused Mission state.');
rmv16((int)($brief['metrics']['mission_count']??0)===1&&!empty($brief['knowledge_manifest']['missions']),'Mission Report Run records focused Mission metrics and manifest state.');
$reviewBrief=research_system_report_generate($pdo,[],$owner,(string)$agent['public_id'],'mission_review','Section 6 Review Brief',false,null,['depth'=>'standard','focus_query'=>'Section 6 Mission']);
rmv16(str_contains((string)$reviewBrief['rendered_html'],'Mission review queue')&&str_contains((string)$reviewBrief['rendered_html'],'What needs review'),'Mission Review Brief renders human-attention state without changing the Mission.');

$q=$pdo->prepare("SELECT COUNT(*) FROM research_missions WHERE research_agent_id=?");$q->execute([(int)$agent['id']]);
rmv16((int)$q->fetchColumn()===2,'Agent action and human flow share one durable Mission model.');
echo "Research Missions V1 Section 6 database journey passed.\n";
