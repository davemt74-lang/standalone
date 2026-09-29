<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-agent-shell-ui','research-agent-knowledge-ui','research-agent-research-ui','research-agent-reports-ui','research-portfolios-ui','research-legacy-compat','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-intelligence-decision-rollups','research-intelligence-pattern-memory','research-intelligence-strategic-graph','research-intelligence-strategic-reviews','research-intelligence-strategic-briefings','research-intelligence-organizational-cognition','research-system-reports','research-report-studio','research-intelligence-delivery','research-longitudinal-intelligence'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p74s8(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p74s8'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$username='release_'.$run;$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','admin','pro','cloaked')")->execute([$pub('u'),$username,'Phase74 Release',$username.'@example.test']);$uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$u=$q->fetch();

$agent=research_agent_create($pdo,$u,['name'=>'Phase 74 Release Agent','description'=>'Final simplified journey fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$agent=research_agent_access($pdo,$u,(string)$agent['public_id']);
p74s8((bool)$agent&&!empty($agent['project_public_id'])&&!empty($agent['conversation_public_id']),'Final journey starts with one canonical Research Agent identity.');

p74s8(research_agent_shell_href($agent,'chat')==='/home.php?agent='.rawurlencode((string)$agent['conversation_public_id']),'Chat surface preserves canonical Agent conversation identity.');
p74s8(str_contains(research_agent_shell_href($agent,'knowledge'),'/research-agent-knowledge.php?agent='),'Knowledge surface preserves Agent identity.');
p74s8(str_contains(research_agent_shell_href($agent,'research'),'/research-agent-research.php?agent='),'Research surface preserves Agent identity.');
p74s8(str_contains(research_agent_shell_href($agent,'reports'),'/research-reports.php?agent='),'Reports surface preserves Agent identity.');

$mission=research_mission_create($pdo,$u,['agent_id'=>$agent['public_id'],'title'=>'Release Mission','research_question'=>'Does the simplified product preserve the mature Research engines?','objective'=>'Prove one canonical user journey.','success_criteria'=>[['label'=>'All canonical surfaces resolve']]]);
$program=research_program_create($pdo,$u,['agent_id'=>$agent['public_id'],'title'=>'Release Recurring Program','objective'=>'Keep the release fixture current.','cadence'=>'manual','tasks'=>[['title'=>'Review evidence','task_type'=>'general']]]);
p74s8(!empty($mission['public_id'])&&!empty($program['public_id']),'Research surface reuses existing Mission and Program engines.');

$report=research_system_report_generate($pdo,[], $u,(string)$agent['public_id'],'research_brief','Phase 74 Release Brief',false,null,[],null,null,'user');
p74s8(!empty($report['public_id']),'Reports surface reuses the existing System Report engine.');

$portfolio=research_intelligence_portfolio_create($pdo,$u,['title'=>'Phase 74 Release Portfolio','objective'=>'Validate canonical Portfolio entry after UI consolidation.','briefing_cadence'=>'manual','timezone_name'=>'UTC']);
$portfolio=research_intelligence_portfolio_add_program($pdo,$u,(string)$portfolio['public_id'],(string)$program['public_id'],'primary');
p74s8(!empty($portfolio['public_id']),'Portfolios surface reuses the existing Intelligence Portfolio engine.');

p74s8(research_legacy_route_target($pdo,$u,'research-project.php',['id'=>(string)$agent['project_public_id']])==='/home.php?agent='.rawurlencode((string)$agent['conversation_public_id']),'Legacy Project deep link resolves to canonical Chat.');
p74s8(str_contains((string)research_legacy_route_target($pdo,$u,'research-knowledge.php',['id'=>(string)$agent['project_public_id']]),'/research-agent-knowledge.php'),'Legacy Knowledge resolves to canonical Knowledge.');
p74s8(str_contains((string)research_legacy_route_target($pdo,$u,'research-brief.php',['id'=>(string)$agent['project_public_id']]),'/research-reports.php'),'Legacy Brief resolves to canonical Reports.');
p74s8(research_legacy_route_target($pdo,$u,'research-portfolio.php',[])==='/research.php','Legacy Research Portfolio resolves to canonical Research home.');
p74s8(research_legacy_route_target($pdo,$u,'research-intelligence-command-center.php',[])==='/research-intelligence-portfolios.php?view=overview','Legacy Command Center resolves to canonical Portfolio Overview.');

p74s8(research_legacy_route_target($pdo,$u,'research-project.php',['id'=>(string)$agent['project_public_id'],'legacy'=>'1'])===null,'Legacy escape hatch remains available for advanced compatibility.');
p74s8(!(glob($root.'/database/migrations/*_104_*.sql')?:[]),'Migration 103 remains the Phase 74 schema boundary.');
echo "Phase 74 Section 8 End-to-End Simplification Release & Hardening database journey passed.\n";
