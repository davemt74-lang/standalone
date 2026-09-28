<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-missions','research-intelligence-decision-rollups','research-intelligence-pattern-memory','research-intelligence-strategic-graph','research-intelligence-strategic-reviews','research-intelligence-strategic-briefings'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p73s6(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p73s6throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

p73s6(research_intelligence_strategic_briefings_ready($pdo),'Migration 103 exposes Executive Strategic Briefings.');
$run='p73s6'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Phase73S6Owner');$collab=$makeUser('Phase73S6Collaborator');$outsider=$makeUser('Phase73S6Outsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 73 Section 6 Team']);$teamId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$collab['id']]);

$agent=research_agent_create($pdo,$owner,['name'=>'Phase 73 S6 Agent','description'=>'Strategic briefing fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$program=research_program_create($pdo,$owner,['agent_id'=>$agent['public_id'],'title'=>'Phase 73 S6 Program','objective'=>'Executive strategic briefing scope.','cadence'=>'manual','tasks'=>[['title'=>'Monitor','task_type'=>'general']]]);
$portfolio=research_intelligence_portfolio_create($pdo,$owner,['title'=>'Phase 73 S6 Portfolio','objective'=>'Exercise frozen strategic briefing and Team Review governance.','team_id'=>$teamPublic,'briefing_cadence'=>'manual','timezone_name'=>'UTC']);
$portfolio=research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolio['public_id'],(string)$program['public_id'],'primary');

$nativeA=research_intelligence_portfolio_create_native_decision($pdo,$owner,(string)$portfolio['public_id'],['idempotency_key'=>'s6-da','title'=>'Executive Decision A','statement'=>'Proceed with initiative A.','rationale'=>'Explicit rationale A.','confidence'=>0.9,'assumptions'=>['Shared demand remains above threshold.']]);
$a=research_decision_set_status($pdo,$owner,(string)$nativeA['decision']['public_id'],'accepted');
$nativeB=research_intelligence_portfolio_create_native_decision($pdo,$owner,(string)$portfolio['public_id'],['idempotency_key'=>'s6-db','title'=>'Executive Decision B','statement'=>'Proceed with initiative B.','rationale'=>'Explicit rationale B.','confidence'=>0.8,'assumptions'=>['Shared demand remains above threshold.']]);
$b=research_decision_set_status($pdo,$owner,(string)$nativeB['decision']['public_id'],'accepted');
$plan=research_action_plan_from_decision($pdo,$owner,(string)$a['public_id'],['idempotency_key'=>'s6-plan','title'=>'Executive Plan A','objective'=>'Execute initiative A.','expected_result'=>'Initiative A completes.','priority'=>'high','success_measures'=>[['label'=>'Completion','target'=>'Complete']]]);
research_intelligence_strategic_edge_upsert($pdo,$owner,(string)$portfolio['public_id'],['source_type'=>'decision','source_public_id'=>$a['public_id'],'target_type'=>'decision','target_public_id'=>$b['public_id'],'relation_type'=>'conflicts_with','rationale'=>'Competing executive resource allocation.','materiality'=>'critical','confidence'=>0.9]);
research_intelligence_portfolio_pattern_refresh($pdo,$owner,(string)$portfolio['public_id'],'test');
$settings=research_intelligence_strategic_review_configure($pdo,$owner,(string)$portfolio['public_id'],['status'=>'active','cadence'=>'monthly','due_offset_hours'=>72,'reviewers'=>[['user_id'=>$collab['public_id'],'role'=>'reviewer','required'=>true]]]);
p73s6(count((array)$settings['reviewers'])===1,'Strategic Briefing reuses configured current Strategic Review collaborators.');

$source=research_intelligence_strategic_review_create($pdo,$owner,(string)$portfolio['public_id'],['trigger_type'=>'manual','idempotency_key'=>'s6-source']);
$sourceReview=research_review_access($pdo,$owner,(string)$source['collaborative_review_public_id']);p73s6((bool)$sourceReview,'Source Strategic Review is available.');
research_review_respond($pdo,$collab,(string)$sourceReview['public_id'],'approve','Frozen strategic packet is suitable for executive briefing.');
$sourceDone=research_review_complete($pdo,$owner,(string)$sourceReview['public_id']);$sourceAgg=research_review_aggregate($pdo,$sourceDone);
p73s6(($sourceAgg['consensus']??'')==='unanimous_approval','Source Strategic Review completed with unanimous approval.');

$before=['a'=>(string)$a['status'],'b'=>(string)$b['status'],'plan'=>(string)$plan['status'],'decision_count'=>(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn(),'plan_count'=>(int)$pdo->query('SELECT COUNT(*) FROM research_action_plans')->fetchColumn()];
$brief=research_intelligence_strategic_briefing_create($pdo,$owner,(string)$portfolio['public_id'],['idempotency_key'=>'s6-brief-1','strategic_review_id'=>(string)$source['public_id'],'title'=>'Executive Strategic Briefing One']);
p73s6(!empty($brief['executive_briefing_public_id'])&&!empty($brief['document_public_id'])&&!empty($brief['team_review_public_id']),'Strategic Briefing reuses Executive Briefing, Research Doc, and Collaborative Review stores.');
p73s6((string)$brief['packet_hash']===(string)$source['packet_hash'],'Strategic Briefing freezes the exact selected Strategic Review packet.');
p73s6((string)$brief['source_review_consensus']==='unanimous_approval','Strategic Briefing preserves source Strategic Review consensus provenance.');
$doc=research_agent_workspace_object($pdo,$owner,(string)$brief['document_public_id'],false);
p73s6($doc&&str_contains((string)$doc['content_html'],'Frozen strategic packet')&&str_contains((string)$doc['content_html'],(string)$brief['packet_hash']),'Strategic Briefing document is rendered from the frozen strategic packet and includes its state hash.');
$teamReview=research_review_access($pdo,$owner,(string)$brief['team_review_public_id']);
p73s6($teamReview&&$teamReview['subject_type']==='document'&&!$teamReview['is_stale'],'Strategic Briefing Team Review is the existing document Collaborative Review subject.');
$assignments=research_review_assignments($pdo,$teamReview);p73s6(count($assignments)===1&&(int)$assignments[0]['reviewer_user_id']===(int)$collab['id'],'Team Review uses only explicitly configured current collaborator.');
$again=research_intelligence_strategic_briefing_create($pdo,$owner,(string)$portfolio['public_id'],['idempotency_key'=>'s6-brief-1','strategic_review_id'=>(string)$source['public_id']]);
p73s6((string)$again['public_id']===(string)$brief['public_id'],'Strategic Briefing creation is idempotent.');
p73s6throws(fn()=>research_intelligence_strategic_briefing_assert_publication_ready($pdo,$owner,(string)$brief['executive_briefing_public_id']),'Publication is blocked while Strategic Briefing Team Review is open.');

$aNow=research_decision_detail($pdo,$owner,(string)$a['public_id']);$bNow=research_decision_detail($pdo,$owner,(string)$b['public_id']);$planNow=research_action_plan_detail($pdo,$owner,(string)$plan['public_id']);
$after=['a'=>(string)$aNow['status'],'b'=>(string)$bNow['status'],'plan'=>(string)$planNow['status'],'decision_count'=>(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn(),'plan_count'=>(int)$pdo->query('SELECT COUNT(*) FROM research_action_plans')->fetchColumn()];
p73s6($before===$after,'Creating Strategic Briefing and Team Review never mutates Decision or Action Plan authority.');

research_review_respond($pdo,$collab,(string)$teamReview['public_id'],'approve','Executive Strategic Briefing accurately reflects the frozen packet.');
$teamDone=research_review_complete($pdo,$owner,(string)$teamReview['public_id']);
$approved=research_intelligence_strategic_briefing_access($pdo,$owner,(string)$brief['public_id'],true);
p73s6(!empty($approved['publication_ready'])&&($approved['team_review_aggregate']['consensus']??'')==='unanimous_approval','Completed current unanimous Team Review unlocks the Strategic Briefing publication gate.');
research_intelligence_strategic_briefing_assert_publication_ready($pdo,$owner,(string)$brief['executive_briefing_public_id']);
$workflow=research_intelligence_portfolio_prepare_publication($pdo,$owner,(string)$brief['executive_briefing_public_id'],['required_approvals'=>1]);
p73s6(!empty($workflow['public_id']),'Approved Strategic Briefing enters the existing Phase 59 publication workflow.');

$brief2=research_intelligence_strategic_briefing_create($pdo,$owner,(string)$portfolio['public_id'],['idempotency_key'=>'s6-brief-2','title'=>'Executive Strategic Briefing Stale Review']);
$teamReview2=research_review_access($pdo,$owner,(string)$brief2['team_review_public_id']);
research_review_respond($pdo,$collab,(string)$teamReview2['public_id'],'approve','Approve before edit.');
research_review_complete($pdo,$owner,(string)$teamReview2['public_id']);
$doc2=research_agent_workspace_object($pdo,$owner,(string)$brief2['document_public_id'],false);
research_agent_workspace_save_document($pdo,$owner,(string)$brief2['document_public_id'],['base_revision'=>(int)$doc2['revision_number'],'title'=>(string)$doc2['title'],'content_html'=>(string)$doc2['content_html'].'<p>Human editorial change after Team Review.</p>','summary'=>(string)($doc2['document_summary']??'')]);
$staleReview=research_review_access($pdo,$owner,(string)$teamReview2['public_id']);
p73s6($staleReview&&!empty($staleReview['is_stale']),'A document edit makes the Team Review stale through the existing review hash contract.');
p73s6throws(fn()=>research_intelligence_strategic_briefing_assert_publication_ready($pdo,$owner,(string)$brief2['executive_briefing_public_id']),'Stale Team Review blocks publication even after prior approval.');

$a=research_decision_set_status($pdo,$owner,(string)$a['public_id'],'reopened');
$drift=research_intelligence_strategic_briefing_access($pdo,$owner,(string)$brief['public_id'],true);
$approvedReviewStill=research_review_access($pdo,$owner,(string)$teamReview['public_id']);
p73s6(!empty($drift['current_drift']),'New authoritative strategic state is shown as current drift from the frozen Strategic Briefing packet.');
p73s6($approvedReviewStill&&!$approvedReviewStill['is_stale'],'Strategic state drift does not rewrite or stale the frozen briefing document Team Review.');

$center=research_intelligence_organization_strategic_briefing_center($pdo,$owner);
p73s6(($center['summary']['total']??0)>=2&&count((array)$center['attention'])>=1,'Organization Command Center receives Strategic Briefing Team Review and drift attention.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$collab['id']]);
p73s6(research_intelligence_strategic_briefing_access($pdo,$collab,(string)$brief['public_id'])===null,'Team revocation immediately removes Strategic Briefing access.');
p73s6(research_review_access($pdo,$collab,(string)$teamReview['public_id'])===null,'Team revocation immediately removes Strategic Briefing Team Review access.');
p73s6throws(fn()=>research_intelligence_strategic_briefing_create($pdo,$outsider,(string)$portfolio['public_id'],['idempotency_key'=>'outsider']),'Outsider cannot create an Executive Strategic Briefing.');

$runtime=(string)file_get_contents($root.'/app/research-intelligence-strategic-briefings.php');
p73s6(!str_contains($runtime,'research_decision_set_status(')&&!str_contains($runtime,'research_action_plan_set_status(')&&!str_contains($runtime,'ai_run('),'Strategic Briefing runtime has no Decision/Action Plan lifecycle or autonomous AI authority.');
echo "Phase 73 Section 6 Executive Strategic Briefings & Team Review database journey passed.\n";
