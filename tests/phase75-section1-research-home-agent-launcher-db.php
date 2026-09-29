<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-agent-shell-ui','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-intelligence-decision-rollups','research-intelligence-pattern-memory','research-intelligence-strategic-graph','research-intelligence-strategic-reviews','research-intelligence-strategic-briefings','research-intelligence-organizational-cognition','research-home-ui'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p75s1(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p75s1'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$username='home_'.$run;$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','admin','pro','cloaked')")->execute([$pub('u'),$username,'Phase75 Home',$username.'@example.test']);$uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$u=$q->fetch();

$personal=research_agent_create($pdo,$u,['name'=>'Personal Launch Agent','description'=>'Personal launch fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$uid,'Launch Team']);$teamId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner')")->execute([$teamId,$uid]);
$teamAgent=research_agent_create($pdo,$u,['name'=>'Team Launch Agent','description'=>'Team launch fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$program=research_program_create($pdo,$u,['agent_id'=>$teamAgent['public_id'],'title'=>'Launch Program','objective'=>'Exercise Portfolio launcher composition.','cadence'=>'manual','tasks'=>[['title'=>'Review launch state','task_type'=>'general']]]);
$portfolio=research_intelligence_portfolio_create($pdo,$u,['title'=>'Launch Portfolio','objective'=>'Keep Research home connected to canonical Portfolio intelligence.','team_id'=>$teamPublic,'briefing_cadence'=>'manual','timezone_name'=>'UTC']);
research_intelligence_portfolio_add_program($pdo,$u,(string)$portfolio['public_id'],(string)$program['public_id'],'primary');

$agents=research_agent_list($pdo,$u,50);$home=research_home_dashboard($pdo,$u,$agents);
p75s1(count($agents)>=2,'Research home composes canonical Research Agent list.');
p75s1(($home['summary']['teams']??0)>=1&&($home['summary']['team_agents']??0)>=1,'Team Research is grouped without a second Agent store.');
p75s1(($home['summary']['personal_agents']??0)>=1,'Personal Research remains visible.');
p75s1(($home['summary']['portfolios']??0)>=1&&count((array)$home['portfolios'])>=1,'Canonical Intelligence Portfolios appear on Research home.');
p75s1(count((array)$home['activity'])>=2,'Recent activity is derived from canonical Agent activity.');
p75s1((string)$home['teams'][0]['name']==='Launch Team','Team launcher preserves canonical Team identity.');
p75s1(research_home_agent_scope(research_agent_access($pdo,$u,(string)$teamAgent['public_id']))==='Launch Team','Agent scope label uses canonical Team name.');
p75s1(str_contains(research_agent_shell_href($teamAgent,'chat'),'/home.php?agent='),'Launcher opens the canonical Agent Chat surface.');
p75s1(!(glob($root.'/database/migrations/*_104_research_home_agent_launcher.sql')?:[]),'Section 1 adds no Research authority schema.');
echo "Phase 75 Section 1 Research Home & Agent Launcher database journey passed.\n";
