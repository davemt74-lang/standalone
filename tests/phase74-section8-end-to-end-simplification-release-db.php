<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-agent-shell-ui','research-agent-knowledge-ui','research-agent-research-ui','research-agent-reports-ui','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-intelligence-decision-rollups','research-intelligence-pattern-memory','research-intelligence-strategic-graph','research-intelligence-strategic-reviews','research-intelligence-strategic-briefings','research-intelligence-organizational-cognition','research-portfolios-ui','research-legacy-compat'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p74s8(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
$run='p74s8'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Phase74ReleaseOwner');$member=$makeUser('Phase74ReleaseMember');$outsider=$makeUser('Phase74ReleaseOutsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 74 Release Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$member['id']]);

$agent=research_agent_create($pdo,$owner,['name'=>'Phase 74 Release Agent','description'=>'End-to-end simplification release fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$agent=research_agent_access($pdo,$owner,(string)$agent['public_id']);$agentId=(string)$agent['public_id'];$projectId=(string)$agent['project_public_id'];$conversationId=(string)$agent['conversation_public_id'];
p74s8($agentId!==''&&$projectId!==''&&$conversationId!=='','Final journey preserves one Agent, one Project boundary, and one conversation identity.');
p74s8((research_agent_access($pdo,$member,$agentId)['public_id']??'')===$agentId,'Authorized Team researcher resolves the same canonical Research Agent.');
p74s8(research_agent_access($pdo,$outsider,$agentId)===null,'Outsider cannot resolve the Team Research Agent.');

$tabs=research_agent_shell_tabs();p74s8(array_keys($tabs)===['chat','knowledge','research','reports'],'Research Agent exposes exactly Chat, Knowledge, Research, Reports.');
foreach(['chat','knowledge','research','reports'] as $tab){$href=research_agent_shell_href($agent,$tab);p74s8($href!==''&&($tab==='chat'?str_contains($href,rawurlencode($conversationId)):str_contains($href,rawurlencode($agentId))),'Shell '.$tab.' preserves authoritative Agent identity.');}
p74s8(array_keys(research_agent_knowledge_views())===['library','insights','changes'],'Knowledge is exactly Library, Insights, Changes.');
p74s8(array_keys(research_agent_research_views())===['missions','tasks','decisions','follow_through','recurring'],'Research is exactly Missions, Tasks, Decisions, Follow-through, Recurring.');
p74s8(array_keys(research_agent_reports_views())===['create','recent','scheduled','published'],'Reports is exactly Create, Recent, Scheduled, Published.');
p74s8(array_keys(research_portfolios_views())===['overview','portfolios'],'Global Portfolio UI is exactly Overview and Portfolios.');

$mission=research_mission_create($pdo,$owner,['agent_id'=>$agentId,'title'=>'Release Mission','research_question'=>'Does the simplified Research Agent preserve the mature workflow?','objective'=>'Validate the Phase 74 canonical journey.','success_criteria'=>[['label'=>'Canonical journey remains authoritative']]]);
$plan=research_task_plan_create($pdo,$owner,['agent_id'=>$agentId,'title'=>'Release Task Plan','objective'=>'Validate mature task execution remains underneath the simplified UI.','create_deliverable'=>false,'tasks'=>[]]);
$decision=research_decision_create($pdo,$owner,['agent_id'=>$agentId,'title'=>'Release Decision','statement'=>'Use the simplified canonical Research Agent UI.','rationale'=>'Mature engines remain authoritative underneath.','confidence'=>0.95]);
$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted',false);
$action=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],['title'=>'Release Follow-through','objective'=>'Validate governed follow-through remains intact.','expected_result'=>'Action Plan stays authoritative.']);
$program=research_program_create($pdo,$owner,['agent_id'=>$agentId,'title'=>'Release Recurring Program','objective'=>'Validate recurring research remains Program-backed.','cadence'=>'weekly','timezone_name'=>'UTC','run_time_local'=>'09:00']);
p74s8(count(research_mission_list($pdo,$owner,$agentId,20))===1,'Missions remain backed by the existing Mission engine.');
p74s8(count(research_task_plan_list($pdo,$owner,$agentId,20))===1,'Tasks remain backed by the existing Task Plan engine.');
p74s8(count(research_decision_list($pdo,$owner,$agentId,20))===1,'Decisions remain backed by the Phase 71 Decision Ledger.');
p74s8(count(research_action_plan_list($pdo,$owner,$agentId,null,20))===1,'Follow-through remains backed by the Phase 72 Action Plan ledger.');
p74s8(count(research_program_list($pdo,$owner,$agentId,20))===1,'Recurring remains backed by the existing Program engine.');

foreach(['library','insights','changes'] as $view)p74s8(str_contains(research_agent_knowledge_href($agentId,$view),'agent='.rawurlencode($agentId)),'Knowledge '.$view.' preserves Agent identity.');
foreach(['missions','tasks','decisions','follow_through','recurring'] as $view)p74s8(str_contains(research_agent_research_href($agentId,$view),'agent='.rawurlencode($agentId)),'Research '.$view.' preserves Agent identity.');
foreach(['create','recent','scheduled','published'] as $view)p74s8(str_contains(research_agent_reports_href($agentId,$view),'agent='.rawurlencode($agentId)),'Reports '.$view.' preserves Agent identity.');

$portfolio=research_intelligence_portfolio_create($pdo,$owner,['title'=>'Phase 74 Release Portfolio','objective'=>'Validate canonical Portfolio presentation.','team_id'=>$teamPublic,'briefing_cadence'=>'manual','timezone_name'=>'UTC']);
$portfolio=research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolio['public_id'],(string)$program['public_id'],'primary');
p74s8(!empty($portfolio['public_id']),'Intelligence Portfolio remains the canonical Portfolio engine.');
p74s8(research_legacy_route_target($pdo,$owner,'research-intelligence-command-center.php',[])==='/research-intelligence-portfolios.php?view=overview','Legacy Command Center resolves to Portfolio Overview.');
p74s8(research_legacy_route_target($pdo,$owner,'research-portfolio.php',[])==='/research.php','Legacy Research Portfolio resolves to canonical Research home.');
p74s8(research_legacy_route_target($pdo,$owner,'research-project.php',['id'=>$projectId])==='/home.php?agent='.rawurlencode($conversationId),'Legacy Project resolves to canonical Agent Chat.');
p74s8(research_legacy_route_target($pdo,$owner,'research-knowledge.php',['id'=>$projectId])==='/research-agent-knowledge.php?agent='.rawurlencode($agentId).'&view=library','Legacy Knowledge resolves to canonical Agent Knowledge.');
p74s8(research_legacy_route_target($pdo,$owner,'research-brief.php',['id'=>$projectId])==='/research-reports.php?agent='.rawurlencode($agentId).'&view=create&type=research_brief','Legacy Research Brief resolves to Reports Create.');
p74s8(research_legacy_route_target($pdo,$owner,'research-automations.php',['agent'=>$agentId])==='/research-agent-research.php?agent='.rawurlencode($agentId).'&view=recurring','Legacy Automations resolves to Recurring.');
p74s8(research_legacy_route_target($pdo,$owner,'research-reviews.php',[])==='/research-intelligence-portfolios.php?view=overview&focus=review','Generic Review Center resolves to global Portfolio attention.');

$beforeAgents=(int)$pdo->query('SELECT COUNT(*) FROM research_agents')->fetchColumn();$beforeProjects=(int)$pdo->query('SELECT COUNT(*) FROM research_projects')->fetchColumn();
foreach(['chat','knowledge','research','reports'] as $tab)research_agent_shell_resolve($pdo,$owner,$agentId,[$agent]);
p74s8((int)$pdo->query('SELECT COUNT(*) FROM research_agents')->fetchColumn()===$beforeAgents&&(int)$pdo->query('SELECT COUNT(*) FROM research_projects')->fetchColumn()===$beforeProjects,'Canonical navigation creates no duplicate Agent or Project.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$member['id']]);
p74s8(research_agent_access($pdo,$member,$agentId)===null,'Revoking Team membership immediately removes canonical Agent access.');
p74s8(!(glob($root.'/database/migrations/*_104_*.sql')?:[]),'Migration 103 remains the final schema boundary through Phase 74.');
echo "Phase 74 Section 8 End-to-End Simplification Release database journey passed.\n";
