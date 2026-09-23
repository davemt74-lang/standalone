<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p60(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p60throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

p60(research_intelligence_portfolios_ready($pdo),'60A/60B Phase 60 schema and reused dependencies are available.');
$run='p60'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('PortfolioExecutive','admin');$collab=$makeUser('PortfolioAnalyst');$outsider=$makeUser('PortfolioOutsider');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 60 Intelligence Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$collab['id']]);

$agentA=research_agent_create($pdo,$owner,['name'=>'Phase 60 Market Agent','description'=>'Track market intelligence.','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$agentB=research_agent_create($pdo,$owner,['name'=>'Phase 60 Product Agent','description'=>'Track product intelligence.','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$projectA=research_agent_workspace_project($pdo,$owner,(string)$agentA['public_id']);$projectB=research_agent_workspace_project($pdo,$owner,(string)$agentB['public_id']);
p60($projectA&&$projectB&&(int)$projectA['team_id']===$teamId&&(int)$projectB['team_id']===$teamId,'60B uses existing Team Research Agent/project boundaries.');

$programA=research_program_create($pdo,$owner,['agent_id'=>$agentA['public_id'],'title'=>'Market Signals','objective'=>'Track market changes.','cadence'=>'manual','priority'=>'high','tasks'=>[['title'=>'Review market signals','task_type'=>'general']]]);
$programB=research_program_create($pdo,$owner,['agent_id'=>$agentB['public_id'],'title'=>'Product Signals','objective'=>'Track product changes.','cadence'=>'manual','priority'=>'high','tasks'=>[['title'=>'Review product signals','task_type'=>'general']]]);
$portfolio=research_intelligence_portfolio_create($pdo,$owner,['title'=>'Executive Market Portfolio','objective'=>'Understand combined market and product movement.','team_id'=>$teamPublic,'briefing_cadence'=>'weekly','timezone_name'=>'UTC','briefing_time_local'=>'09:00','briefing_weekday'=>1]);
p60(($portfolio['access_role']??'')==='owner'&&(int)$portfolio['team_id']===$teamId,'60B creates a durable governed Team Portfolio.');

research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolio['public_id'],(string)$programA['public_id'],'primary');
$detail=research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolio['public_id'],(string)$programB['public_id'],'supporting');
p60(count($detail['programs'])===2&&(int)$detail['anchor_program_id']===(int)$programA['id'],'60B Portfolio groups existing Programs without copying them.');

$personalAgent=research_agent_create($pdo,$owner,['name'=>'Phase 60 Personal Agent','description'=>'Personal boundary fixture.','cadence'=>'manual','timezone_name'=>'UTC']);
$personalProgram=research_program_create($pdo,$owner,['agent_id'=>$personalAgent['public_id'],'title'=>'Personal Program','objective'=>'Must not cross Team Portfolio boundary.','cadence'=>'manual','tasks'=>[['title'=>'Boundary test','task_type'=>'general']]]);
p60throws(fn()=>research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolio['public_id'],(string)$personalProgram['public_id']),'60A/60B cross-boundary Program membership is rejected.');

$runA=research_program_enqueue($pdo,$programA,(int)$owner['id'],'manual');$runB=research_program_enqueue($pdo,$programB,(int)$owner['id'],'manual');
$q=$pdo->prepare('SELECT id FROM research_program_runs WHERE public_id=?');$q->execute([$runA]);$runAId=(int)$q->fetchColumn();$q->execute([$runB]);$runBId=(int)$q->fetchColumn();
$shared=$pub('shared-claim');
$insertDelta=function(int $runId,array $program,string $type,string $importance,string $summary,string $ref)use($pdo): void {$pdo->prepare("INSERT INTO research_program_deltas(public_id,run_id,program_id,project_id,delta_type,importance,ref_type,ref_public_id,fingerprint,summary) VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([ulid_like(),$runId,(int)$program['id'],(int)$program['project_id'],$type,$importance,'claim',$ref,hash('sha256',$runId.'|'.$type.'|'.$ref),$summary]);};
$insertDelta($runAId,$programA,'claim_strengthened','important','Market evidence strengthened the shared claim.',$shared);
$insertDelta($runBId,$programB,'claim_contradicted','high','Product evidence contradicted the shared claim.',$shared);
$insertDelta($runAId,$programA,'source_restored','important','A key market source returned.',$pub('source-restored'));
$aggregate=research_intelligence_portfolio_aggregate($pdo,$owner,$portfolio,30);
$tensions=array_values(array_filter($aggregate['cross_program'],fn($x)=>$x['kind']==='cross_program_tension'));
p60((int)$aggregate['summary']['programs']===2&&count($tensions)===1,'60B deterministic aggregation detects opposite cross-Program evidence on a shared reference.');
p60(count($aggregate['risks'])>=1&&count($aggregate['opportunities'])>=1,'60B risk and opportunity views are derived from explicit Program deltas.');

$snapshot=research_intelligence_portfolio_snapshot($pdo,$owner,(string)$portfolio['public_id'],30,'manual');
p60(strlen((string)$snapshot['snapshot_hash'])===64&&count($snapshot['provenance']['programs'])===2,'60B immutable snapshot freezes aggregate data with per-Program provenance.');
$beforeHash=(string)$snapshot['snapshot_hash'];$pdo->prepare("UPDATE research_programs SET title=CONCAT(title,' Updated') WHERE id=?")->execute([(int)$programA['id']]);
$q=$pdo->prepare('SELECT snapshot_hash FROM research_intelligence_portfolio_snapshots WHERE id=?');$q->execute([(int)$snapshot['id']]);
p60(hash_equals($beforeHash,(string)$q->fetchColumn()),'60B later Program edits cannot mutate a frozen Portfolio snapshot.');

p60throws(fn()=>research_intelligence_portfolio_add_inference($pdo,$owner,(string)$portfolio['public_id'],['title'=>'Opaque inference','body'=>'No provenance.','category'=>'risk']),'60B Agent inference without explicit provenance is rejected.');
$inference=research_intelligence_portfolio_add_inference($pdo,$owner,(string)$portfolio['public_id'],['title'=>'Emerging commercial tension','body'=>'The opposing shared-claim signals may indicate a market/product divergence.','category'=>'contradiction','severity'=>'watch','confidence'=>0.72,'snapshot_id'=>$snapshot['public_id'],'provenance_refs'=>[['type'=>'claim','id'=>$shared,'label'=>'Shared claim']]],true);
p60($inference['insight_kind']==='inference'&&(int)$inference['created_by_agent']===1&&abs((float)$inference['confidence']-.72)<.001,'60B inference is explicitly labeled, confidence-bearing, and provenance-backed.');

$brief=research_intelligence_portfolio_create_briefing($pdo,$owner,(string)$portfolio['public_id'],['title'=>'Phase 60 Executive Briefing','window_days'=>30]);
p60(($brief['document']['object_type']??'')==='document'&&($brief['document']['document_type']??'')==='research_brief'&&(int)$brief['document']['revision_number']===1,'60C Executive Briefing is a normal versioned Research Doc.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_workspace_document_revisions WHERE document_object_id=?');$q->execute([(int)$brief['document']['id']]);p60((int)$q->fetchColumn()===1,'60C briefing uses the existing Research Doc revision store.');

$workflow=research_intelligence_portfolio_prepare_publication($pdo,$owner,(string)$brief['public_id'],['required_approvals'=>1,'visibility'=>'team']);
p60(($workflow['status']??'')==='draft'&&!empty($workflow['public_id']),'60C briefing enters the existing Phase 59 publication workflow instead of a parallel review system.');
$q=$pdo->prepare('SELECT publication_workflow_id FROM research_executive_briefings WHERE public_id=?');$q->execute([(string)$brief['public_id']]);p60((int)$q->fetchColumn()===(int)$workflow['id'],'60C briefing stores the authoritative Phase 59 workflow link.');

$collabView=research_intelligence_portfolio_detail($pdo,$collab,(string)$portfolio['public_id']);p60($collabView&&count($collabView['programs'])===2,'60A Team collaborator receives live permission-checked Portfolio intelligence.');
p60(research_intelligence_portfolio_access($pdo,$outsider,(string)$portfolio['public_id'])===null,'60A outsider receives no Portfolio access.');
$items=[];research_intelligence_portfolio_cognitive_observations($pdo,$owner,$items,20);p60(!empty($items),'60C Portfolio risks/tensions/briefing state integrate with Now/cognitive feed.');
$dashboard=research_intelligence_portfolio_dashboard($pdo,$owner);p60(($dashboard['summary']['portfolios']??0)>=1&&($dashboard['summary']['programs']??0)>=2,'60C organization dashboard rolls accessible Portfolios and Programs upward.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$collab['id']]);
p60(research_intelligence_portfolio_access($pdo,$collab,(string)$portfolio['public_id'])===null,'60A Team revocation immediately removes Portfolio access.');

$runtime=(string)file_get_contents($root.'/app/research-intelligence-portfolios.php');
p60(!str_contains($runtime,'ai_run(')&&!str_contains($runtime,'agent_action_confirm_execute('),'60A Portfolio aggregation has no hidden AI ranker or direct Agent-action execution path.');
echo "Phase 60 Research Intelligence Portfolios & Executive Briefing MariaDB suite passed.\n";
