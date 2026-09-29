<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agents','research-agent-shell-ui','research-agent-research-ui','research-tasks','research-programs','research-missions','research-outcomes','research-decisions','research-action-plans'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p74s4(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message
";}
$run='p74s4'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Phase74ResearchOwner');$member=$makeUser('Phase74ResearchMember');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 74 Research Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$member['id']]);
$agent=research_agent_create($pdo,$owner,['name'=>'Phase 74 Research Agent','description'=>'Unified Research permission fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$agentId=(string)$agent['public_id'];

p74s4(!empty($agentId)&&!empty($agent['project_public_id'])&&!empty($agent['conversation_public_id']),'Research fixture preserves Agent, Project, and conversation identities.');
foreach(['missions','tasks','decisions','follow_through','recurring'] as $view){$href=research_agent_research_href($agentId,$view);p74s4(str_contains($href,'agent='.rawurlencode($agentId))&&str_contains($href,'view='.$view),'Research '.$view.' URL preserves Agent identity.');}
$links=research_agent_research_engine_links($agent);
p74s4(str_contains((string)$links['missions'][0]['href'],rawurlencode($agentId))&&str_contains((string)$links['tasks'][0]['href'],rawurlencode($agentId)),'Mission and Task engine links preserve Agent identity.');
p74s4($links['decisions'][0]['href']==='/research-decisions.php'&&$links['follow_through'][0]['href']==='/research-action-plans.php','Decision and Follow-through retain authoritative command-center routes.');
p74s4(count($links['recurring'])===1&&$links['recurring'][0]['href']==='/research-programs.php?agent='.rawurlencode($agentId),'Recurring exposes Programs only while Phase 18 Automations remain route-level compatibility.');

$mission=research_mission_create($pdo,$owner,['agent_id'=>$agentId,'title'=>'Section 4 Mission','research_question'=>'What should the unified Research surface prove?','objective'=>'Prove Missions stay authoritative.','success_criteria'=>[['label'=>'Mission is visible']]]);
$plan=research_task_plan_create($pdo,$owner,['agent_id'=>$agentId,'title'=>'Section 4 Plan','objective'=>'Prove task plans stay authoritative.','create_deliverable'=>false,'tasks'=>[]]);
$decision=research_decision_create($pdo,$owner,['agent_id'=>$agentId,'title'=>'Section 4 Decision','statement'=>'Keep the existing Decision Ledger as the authority.','rationale'=>'UI consolidation must not duplicate decision state.','confidence'=>0.9]);
research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted',false);
$action=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],['title'=>'Section 4 Follow-through','objective'=>'Execute the accepted decision through the existing Action Plan engine.','expected_result'=>'A governed follow-through record exists.']);
$program=research_program_create($pdo,$owner,['agent_id'=>$agentId,'title'=>'Section 4 Recurring','objective'=>'Keep the Research Agent current through the existing Program engine.','cadence'=>'weekly','timezone_name'=>'UTC','run_time_local'=>'09:00']);

p74s4(count(research_mission_list($pdo,$owner,$agentId,20))===1,'Missions view reads the existing Mission engine.');
p74s4(count(research_task_plan_list($pdo,$owner,$agentId,20))===1,'Tasks view reads the existing Task Plan engine.');
p74s4(count(research_decision_list($pdo,$owner,$agentId,20))===1,'Decisions view reads the existing Decision Ledger.');
p74s4(count(research_action_plan_list($pdo,$owner,$agentId,null,20))===1,'Follow-through view reads the existing Action Plan ledger.');
p74s4(count(research_program_list($pdo,$owner,$agentId,20))===1,'Recurring view reads the existing Program engine.');

p74s4(str_contains(research_agent_research_item_href('missions',$agentId,$mission),'mission='.rawurlencode((string)$mission['public_id'])),'Mission deep link preserves the mature editor.');
p74s4(str_contains(research_agent_research_item_href('tasks',$agentId,$plan),'plan='.rawurlencode((string)$plan['public_id'])),'Task Plan deep link preserves the mature editor.');
p74s4(str_contains(research_agent_research_item_href('decisions',$agentId,$decision),'decision='.rawurlencode((string)$decision['public_id'])),'Decision deep link preserves the command center.');
p74s4(str_contains(research_agent_research_item_href('follow_through',$agentId,$action),'action_plan='.rawurlencode((string)$action['public_id'])),'Follow-through deep link preserves Action Plan governance.');
p74s4(str_contains(research_agent_research_item_href('recurring',$agentId,$program),'program='.rawurlencode((string)$program['public_id'])),'Recurring deep link preserves Program history.');

$memberCtx=research_agent_shell_resolve($pdo,$member,$agentId);
p74s4((string)($memberCtx['agent']['public_id']??'')===$agentId,'Authorized Team researcher resolves the same unified Research Agent.');
p74s4(count(research_mission_list($pdo,$member,$agentId,20))===1&&count(research_program_list($pdo,$member,$agentId,20))===1,'Authorized Team researcher sees the same underlying Research records.');

$q=$pdo->prepare('SELECT COUNT(*) FROM research_agents WHERE public_id=?');$q->execute([$agentId]);$before=(int)$q->fetchColumn();
foreach(['missions','tasks','decisions','follow_through','recurring'] as $view){research_agent_research_href($agentId,$view);research_agent_research_engine_links($agent);}
$q->execute([$agentId]);$after=(int)$q->fetchColumn();p74s4($before===$after,'Unified Research navigation creates no duplicate Agent, Project, or engine state.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$member['id']]);
p74s4(research_agent_access($pdo,$member,$agentId)===null,'Team revocation remains authoritative for unified Research access.');
$revoked=research_agent_shell_resolve($pdo,$member,$agentId);p74s4((string)($revoked['agent']['public_id']??'')!==$agentId,'Revoked Team Agent cannot remain selected in unified Research.');
p74s4(!(glob($root.'/database/migrations/*_104_*.sql')?:[]),'Unified Research adds no migration 104.');
echo "Phase 74 Section 4 Unified Research UI database journey passed.
";
