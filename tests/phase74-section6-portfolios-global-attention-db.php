<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-missions','research-intelligence-decision-rollups','research-intelligence-pattern-memory','research-intelligence-strategic-graph','research-intelligence-strategic-reviews','research-intelligence-strategic-briefings','research-intelligence-organizational-cognition','research-portfolios-ui'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p74s6(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
p74s6(research_intelligence_portfolios_ready($pdo)&&research_intelligence_portfolio_operations_ready($pdo),'Existing Portfolio and organization intelligence engines are ready.');
$run='p74s6'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','admin','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Phase74PortfolioOwner');$outsider=$makeUser('Phase74PortfolioOutsider');
$agent=research_agent_create($pdo,$owner,['name'=>'Phase 74 Portfolio Agent','description'=>'Portfolio simplification fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$program=research_program_create($pdo,$owner,['agent_id'=>$agent['public_id'],'title'=>'Section 6 Program','objective'=>'Exercise canonical Portfolio Overview.','cadence'=>'manual','tasks'=>[['title'=>'Observe portfolio','task_type'=>'general']]]);
$portfolio=research_intelligence_portfolio_create($pdo,$owner,['title'=>'Section 6 Portfolio','objective'=>'Unify organization intelligence and contextual attention.','briefing_cadence'=>'manual','timezone_name'=>'UTC']);
$detail=research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolio['public_id'],(string)$program['public_id'],'primary');
p74s6(count((array)$detail['programs'])===1,'Canonical Portfolio continues to group the existing Research Program.');

$center=research_intelligence_organization_command_center($pdo,$owner);
p74s6(($center['summary']['portfolios']??0)>=1,'Existing organization Command Center read model is still available to the canonical Overview.');
$sections=research_portfolios_attention_sections($center);
p74s6(count($sections)===13,'Canonical Overview packages every mature Command Center attention group.');
$scoped=research_portfolios_attention_sections($center,(string)$portfolio['public_id']);
foreach($scoped as $section)foreach((array)$section['items'] as $item)p74s6((string)($item['portfolio_id']??'')===(string)$portfolio['public_id'],'Contextual attention remains scoped to the selected Portfolio.');
p74s6(research_portfolios_href('portfolios',(string)$portfolio['public_id'])==='/research-intelligence-portfolios.php?view=portfolios&portfolio='.rawurlencode((string)$portfolio['public_id']),'Canonical Portfolio deep link preserves Portfolio identity.');

$before=[
 'portfolios'=>(int)$pdo->query('SELECT COUNT(*) FROM research_intelligence_portfolios')->fetchColumn(),
 'agents'=>(int)$pdo->query('SELECT COUNT(*) FROM research_agents')->fetchColumn(),
 'reviews'=>(int)$pdo->query('SELECT COUNT(*) FROM research_reviews')->fetchColumn(),
 'publications'=>(int)$pdo->query('SELECT COUNT(*) FROM research_publication_workflows')->fetchColumn(),
];
for($i=0;$i<3;$i++){research_portfolios_attention_sections($center);research_portfolios_attention_sections($center,(string)$portfolio['public_id']);research_portfolios_href('overview');}
$after=[
 'portfolios'=>(int)$pdo->query('SELECT COUNT(*) FROM research_intelligence_portfolios')->fetchColumn(),
 'agents'=>(int)$pdo->query('SELECT COUNT(*) FROM research_agents')->fetchColumn(),
 'reviews'=>(int)$pdo->query('SELECT COUNT(*) FROM research_reviews')->fetchColumn(),
 'publications'=>(int)$pdo->query('SELECT COUNT(*) FROM research_publication_workflows')->fetchColumn(),
];
p74s6($before===$after,'Unified Portfolio presentation creates no duplicate Portfolio, Agent, Review, or publication authority.');

$outside=research_intelligence_organization_command_center($pdo,$outsider);
p74s6((int)($outside['summary']['portfolios']??0)===0,'Global Portfolio Overview respects existing Portfolio access boundaries.');
p74s6(research_intelligence_portfolio_access($pdo,$outsider,(string)$portfolio['public_id'])===null,'Outsider cannot resolve selected Portfolio detail.');
p74s6(!(glob($root.'/database/migrations/*_104_*.sql')?:[]),'Unified Portfolio UI adds no migration 104.');

echo "Phase 74 Section 6 Portfolios & Global Attention database journey passed.\n";
