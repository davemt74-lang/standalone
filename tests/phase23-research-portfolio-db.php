<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=getenv('DB_DSN')?:'';$dbUser=getenv('DB_USER')?:'root';$dbPass=getenv('DB_PASS')?:'';if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p23(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p23'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('PortfolioOwner');$collab=$makeUser('PortfolioCollaborator');$outsider=$makeUser('PortfolioOutsider');p23(research_portfolio_ready($pdo),'Phase 23 schema is available');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Portfolio Team']);$teamId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$collab['id']]);
$pa=$pub('project-a');$pb=$pub('project-b');$pc=$pub('project-c');
$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,team_id,title,description,status) VALUES(?,?,?,?,?,'active'),(?,?,?,?,?,'active'),(?,?,?,?,?,'active')")->execute([
 $pa,$owner['id'],$teamId,'Needs Attention Project','High-priority explicit signals.',
 $pb,$owner['id'],$teamId,'Watch Project','Ordinary follow-up work only.',
 $pc,$owner['id'],$teamId,'Clear Project','No current attention signals.'
]);
$q=$pdo->prepare('SELECT id,public_id FROM research_projects WHERE public_id IN (?,?,?)');$q->execute([$pa,$pb,$pc]);$ids=[];foreach($q->fetchAll() as $r)$ids[$r['public_id']]=(int)$r['id'];$a=$ids[$pa];$b=$ids[$pb];$c=$ids[$pc];

$claim=$pub('claim');$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,'This unverified portfolio claim has no evidence.','factual','unverified')")->execute([$claim,$a,$owner['id']]);
$task=$pub('task');$pdo->prepare("INSERT INTO research_tasks(public_id,project_id,created_by_user_id,title,description,task_type,status) VALUES(?,?,?,'Continue ordinary follow-up','No high-priority condition.','general','open')")->execute([$task,$b,$owner['id']]);

$conversation=agent_chat_create($pdo,$owner,'Portfolio Pending Proposal');$um=conversation_message_create($pdo,$owner,(string)$conversation['public_id'],'Prepare one bounded proposal.',null,'p23-'.$run);$am=agent_chat_insert_agent_message($pdo,$conversation,'Proposal ready.',(int)$um['id']);$proposal=$pub('proposal');$args=json_encode(['title'=>'Verify portfolio claim']);$pdo->prepare("INSERT INTO agent_action_proposals(public_id,conversation_id,assistant_message_id,proposed_by_user_id,project_id,capability_key,status,arguments_json,provenance_json,project_state_hash,dedupe_key,expires_at) VALUES(?,?,?,?,?,'research.create_task','pending',?,'{}',?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))")->execute([$proposal,$conversation['id'],$am['id'],$owner['id'],$a,$args,hash('sha256','p23-state-'.$run),hash('sha256','p23-proposal-'.$run)]);

$auto=research_automation_create($pdo,$owner,['title'=>'Portfolio Failure Fixture','project_id'=>$pa,'workflow_type'=>'briefing','trigger_type'=>'schedule','cadence'=>'manual','timezone_name'=>'UTC']);$pdo->prepare('UPDATE research_automations SET failure_count=2 WHERE id=?')->execute([$auto['id']]);

$aiBefore=(int)$pdo->query('SELECT COUNT(*) FROM ai_jobs')->fetchColumn();$portfolio=research_portfolio_compose($pdo,$owner,20);$aiAfter=(int)$pdo->query('SELECT COUNT(*) FROM ai_jobs')->fetchColumn();p23($aiBefore===$aiAfter,'Portfolio composition creates no AI ranking job');
p23(($portfolio['summary']['total']??0)===3,'Portfolio includes all three currently accessible active Research projects');
$by=[];foreach($portfolio['projects'] as $item)$by[$item['project']['public_id']]=$item;
p23(($by[$pa]['attention_state']??'')==='needs_attention','high-priority explicit signals produce Needs Attention state');
$reasonTypes=array_column($by[$pa]['reasons'],'type');p23(in_array('evidence_gaps',$reasonTypes,true)&&in_array('pending_agent_action',$reasonTypes,true)&&in_array('automation_failure',$reasonTypes,true),'Needs Attention exposes named evidence-gap, Agent-confirmation, and automation reasons');
p23(($by[$pb]['attention_state']??'')==='watch'&&in_array('open_tasks',array_column($by[$pb]['reasons'],'type'),true),'ordinary open task produces Watch rather than high-priority state');
p23(($by[$pc]['attention_state']??'')==='clear'&&count($by[$pc]['reasons'])===0,'project with no current deterministic signal is Clear without claiming Research correctness');
p23(($portfolio['summary']['needs_attention']??0)===1&&($portfolio['summary']['watch']??0)===1&&($portfolio['summary']['clear']??0)===1,'portfolio summary reports explainable attention-state counts');

research_portfolio_set_pin($pdo,$owner,$pc,true);$pinned=research_portfolio_compose($pdo,$owner,20);p23(($pinned['projects'][0]['project']['public_id']??'')===$pc&&$pinned['projects'][0]['project']['pinned']===true,'explicit pin moves chosen project to top without changing attention state');
$collabPortfolio=research_portfolio_compose($pdo,$collab,20);$collabC=array_values(array_filter($collabPortfolio['projects'],fn($x)=>$x['project']['public_id']===$pc))[0]??null;p23($collabC!==null&&$collabC['project']['pinned']===false,'portfolio pins are user-scoped');
$filtered=research_portfolio_filter($pinned,['attention'=>'needs_attention','access'=>'','q'=>'','pinned'=>false]);p23(count($filtered['projects'])===1&&$filtered['projects'][0]['project']['public_id']===$pa,'attention filter returns only matching project without changing source state');
$filteredPinned=research_portfolio_filter($pinned,['attention'=>'','access'=>'','q'=>'','pinned'=>true]);p23(count($filteredPinned['projects'])===1&&$filteredPinned['projects'][0]['project']['public_id']===$pc,'pinned-only filter is explicit UI filtering');

$ctx=research_portfolio_context($pdo,$owner,10);p23(str_contains($ctx['text'],'[RESEARCH PORTFOLIO]')&&str_contains($ctx['text'],'Needs Attention Project'),'portfolio context names concrete accessible projects and states');
$handoff=research_portfolio_agent_handoff($pdo,$owner,null);p23($handoff!==null&&count($handoff['context'])===3&&str_contains($handoff['prompt'],'Do not invent a health score'),'Portfolio Agent handoff uses bounded permission-checked Research contexts and forbids hidden scoring');
$focused=research_portfolio_agent_handoff($pdo,$owner,$pb);p23($focused!==null&&count($focused['context'])===1&&$focused['context'][0]['public_id']===$pb,'project-specific Portfolio Agent handoff is bounded to the chosen project');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$collab['id']]);$revoked=research_portfolio_compose($pdo,$collab,20);p23(($revoked['summary']['total']??-1)===0&&count($revoked['projects'])===0,'Team revocation immediately removes all project portfolio visibility');
$outside=research_portfolio_compose($pdo,$outsider,20);$json=json_encode($outside,JSON_UNESCAPED_SLASHES);p23(($outside['summary']['total']??-1)===0&&!str_contains($json,$pa)&&!str_contains($json,$pb)&&!str_contains($json,$pc),'outsider receives no portfolio project identity leakage');

$runtime=file_get_contents($root.'/app/research-portfolio.php');p23(!str_contains($runtime,'ai_run(')&&!str_contains($runtime,'health_score')&&!str_contains($runtime,'agent_action_confirm_execute('),'Portfolio runtime has no AI ranker, hidden health score, or direct Agent execution path');
echo "Phase 23 Research Portfolio Intelligence MariaDB suite passed.\n";
