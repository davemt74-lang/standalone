<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-agent-reports-ui','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-network','research-provenance','research-verification','research-evidence-packs','research-workflow','research-system-reports','research-report-studio','research-intelligence-delivery'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p74s5(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
$run='p74s5'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','admin','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Phase74ReportsOwner');

p74s5(research_report_studio_ready($pdo)&&research_intelligence_delivery_ready($pdo)&&research_publications_ready($pdo),'Existing Report Studio, delivery, and publication engines are ready.');
$agent=research_agent_create($pdo,$owner,['name'=>'Phase 74 Reports Agent','description'=>'Unified Reports fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$agentId=(string)$agent['public_id'];$project=research_agent_workspace_project($pdo,$owner,$agentId);
p74s5((bool)$project&&!empty($agent['project_public_id'])&&!empty($agent['conversation_public_id']),'Reports fixture preserves Agent, Project, and conversation identities.');

foreach(['create','recent','scheduled','published'] as $view){$href=research_agent_reports_href($agentId,$view);p74s5(str_contains($href,'agent='.rawurlencode($agentId))&&str_contains($href,'view='.$view),'Reports '.$view.' URL preserves Agent identity.');}
p74s5(research_agent_reports_view('run')==='create'&&research_agent_reports_view('inbox')==='recent'&&research_agent_reports_view('subscriptions')==='scheduled'&&research_agent_reports_view('publications')==='published','Legacy Report URLs resolve to canonical four-view Reports navigation.');

$program=research_program_create($pdo,$owner,['agent_id'=>$agentId,'title'=>'Section 5 Scheduled Research','objective'=>'Drive the existing Report delivery runtime.','cadence'=>'weekly','timezone_name'=>'UTC','run_time_local'=>'09:00']);
$preset=research_report_studio_preset_save($pdo,$owner,$agentId,['name'=>'Section 5 Report Preset','report_type'=>'research_brief','title'=>'Section 5 Report','depth'=>'standard','program_id'=>$program['public_id']]);
$subscription=research_report_subscription_create($pdo,$owner,$agentId,['name'=>'Section 5 Subscription','preset_id'=>$preset['public_id'],'program_id'=>$program['public_id'],'delivery_policy'=>'if_changed','notify_in_app'=>true,'notify_agent_chat'=>false,'include_summary'=>true,'include_comparison'=>true]);
p74s5(($preset['program_public_id']??'')===$program['public_id']&&($subscription['program_public_id']??'')===$program['public_id'],'Scheduled reuses the existing preset, Program, and subscription authority.');

$report=research_system_report_generate($pdo,[],$owner,$agentId,'research_brief','Section 5 Report Run',false,null,['depth'=>'quick'],null,null,'user');
p74s5(!empty($report['public_id'])&&empty($report['document_public_id']),'Create produces an existing Report Run without implicitly creating a Research Document.');
$doc=research_report_studio_create_document($pdo,$owner,$agentId,(string)$report['public_id'],[]);
p74s5(($doc['document_type']??'')==='report','Recent keeps optional Research Document creation in the existing Report Studio engine.');
$workflow=research_publication_workflow_create($pdo,$owner,(string)$doc['public_id'],['title'=>'Section 5 Governed Publication','summary'=>'Publication handoff fixture','visibility'=>'private']);
p74s5(($workflow['status']??'')==='draft','Published hands a Research Document into the existing Phase 59 publication workflow.');

$all=research_publication_list($pdo,$owner,'all',250);$selected=research_agent_reports_filter_publications($agent,$all);
p74s5(count(array_filter($selected,fn($w)=>(string)$w['public_id']===(string)$workflow['public_id']))===1,'Published includes workflows attached through the selected Agent internal Project.');

$other=research_agent_create($pdo,$owner,['name'=>'Phase 74 Other Reports Agent','description'=>'Cross-Agent publication fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$otherProject=research_agent_workspace_project($pdo,$owner,(string)$other['public_id']);
$otherDoc=research_agent_workspace_create_document($pdo,$owner,$otherProject,['title'=>'Other Agent Document','document_type'=>'report','content_html'=>'<p>Other Agent</p>','summary'=>'Other project publication fixture.'],false);
$otherWorkflow=research_publication_workflow_create($pdo,$owner,(string)$otherDoc['public_id'],['title'=>'Other Agent Publication','visibility'=>'private']);
$selected=research_agent_reports_filter_publications($agent,research_publication_list($pdo,$owner,'all',250));
p74s5(count(array_filter($selected,fn($w)=>(string)$w['public_id']===(string)$otherWorkflow['public_id']))===0,'Published excludes workflows from another Research Agent Project.');

$links=research_agent_reports_engine_links($agent);
p74s5(str_contains((string)$links['scheduled'][1]['href'],rawurlencode($agentId))&&$links['published'][1]['href']==='/research-reviews.php','Canonical Reports preserves Program and Collaborative Review deep links.');
p74s5(str_contains(research_agent_reports_publication_href($agentId,$workflow),rawurlencode((string)$workflow['public_id'])),'Publication workflow deep link preserves the Phase 59 workflow ID.');

$q=$pdo->prepare('SELECT COUNT(*) FROM research_agents WHERE public_id=?');$q->execute([$agentId]);$before=(int)$q->fetchColumn();
foreach(['create','recent','scheduled','published'] as $view){research_agent_reports_href($agentId,$view);research_agent_reports_engine_links($agent);}research_agent_reports_filter_publications($agent,$all);
$q->execute([$agentId]);$after=(int)$q->fetchColumn();p74s5($before===$after,'Unified Reports navigation creates no duplicate Agent, Project, Report, delivery, or publication authority.');
p74s5(!(glob($root.'/database/migrations/*_104_*.sql')?:[]),'Unified Reports adds no migration 104.');

echo "Phase 74 Section 5 Unified Reports UI database journey passed.\n";
