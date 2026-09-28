<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-missions','research-intelligence-decision-rollups','research-intelligence-pattern-memory','research-intelligence-strategic-graph','research-intelligence-strategic-reviews','research-intelligence-strategic-briefings','research-intelligence-organizational-cognition'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p73s8(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p73s8throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}
function p73s8message(PDO $pdo,array $conversation,string $body): int {$pdo->prepare("INSERT INTO conversation_messages(public_id,conversation_id,user_id,sender_type,body) VALUES(?,?,NULL,'agent',?)")->execute([ulid_like(),(int)$conversation['id'],$body]);return (int)$pdo->lastInsertId();}
function p73s8proposal(PDO $pdo,array $viewer,array $conversation,array $project,array $agent,array $raw): array {
    $ctx=research_intelligence_organizational_agent_context($pdo,$viewer,(string)$agent['public_id'],6);
    $messageId=p73s8message($pdo,$conversation,'Phase 73 final governed proposal fixture');
    $rows=agent_action_create_proposals($pdo,$viewer,$conversation,$messageId,[['type'=>'research','public_id'=>(string)$project['public_id']]],[$raw],(array)($ctx['refs']??[]));
    if(count($rows)!==1)throw new RuntimeException('FAIL: expected one final Agent action proposal.');
    return $rows[0];
}

p73s8(research_intelligence_portfolio_native_decisions_ready($pdo),'Native Portfolio Decision handoff is ready.');
p73s8(research_intelligence_portfolio_execution_rollups_ready($pdo),'Portfolio Decision and execution rollups are ready.');
p73s8(research_intelligence_pattern_memory_ready($pdo),'Cross-Decision Pattern Memory is ready.');
p73s8(research_intelligence_strategic_graph_ready($pdo),'Strategic Dependency & Conflict Graph is ready.');
p73s8(research_intelligence_strategic_reviews_ready($pdo),'Recurring Strategic Review is ready.');
p73s8(research_intelligence_strategic_briefings_ready($pdo),'Executive Strategic Briefings are ready.');
p73s8(research_intelligence_organizational_cognition_ready($pdo),'Organizational Agent Cognition is ready.');
p73s8(!(glob($root.'/database/migrations/*_104_*.sql')?:[]),'Migration 103 remains the final Phase 73 schema boundary.');

$run='p73s8'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Phase73ReleaseOwner');$reviewer=$makeUser('Phase73ReleaseReviewer');$outsider=$makeUser('Phase73ReleaseOutsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 73 Release Team']);$teamId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$reviewer['id']]);

$agent=research_agent_create($pdo,$owner,['name'=>'Phase 73 Release Agent','description'=>'Final organizational-learning acceptance fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$agent=research_agent_access($pdo,$owner,(string)$agent['public_id']);$project=project_access($pdo,(int)$owner['id'],(string)$agent['project_public_id']);p73s8((bool)$agent&&(bool)$project&&project_can_write($project),'Final journey starts in a writable Team-scoped Research Agent.');
$q=$pdo->prepare('SELECT * FROM conversations WHERE id=?');$q->execute([(int)$agent['conversation_id']]);$conversation=$q->fetch();p73s8((bool)$conversation,'Final journey has the Research Agent conversation used by governed proposals.');

$program=research_program_create($pdo,$owner,['agent_id'=>$agent['public_id'],'title'=>'Phase 73 Release Program','objective'=>'Continuously review portfolio decisions, execution, outcomes, and strategic relationships.','cadence'=>'manual','tasks'=>[['title'=>'Review strategic evidence','task_type'=>'general']]]);
$portfolio=research_intelligence_portfolio_create($pdo,$owner,['title'=>'Phase 73 Release Portfolio','objective'=>'Validate organizational learning from Portfolio Decision through governed follow-through.','team_id'=>$teamPublic,'briefing_cadence'=>'manual','timezone_name'=>'UTC']);
$portfolio=research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolio['public_id'],(string)$program['public_id'],'primary');

$legacy=research_intelligence_portfolio_record_decision($pdo,$owner,(string)$portfolio['public_id'],['title'=>'Historical Portfolio record','summary'=>'Legacy Phase 61 decision history remains visible beside native Decision Memory.','decision_type'=>'recorded','create_follow_up'=>false]);
p73s8(!empty($legacy['outcome']['public_id']),'Historical Portfolio decision path remains available for legacy continuity.');

$makeDecision=function(string $key,string $title,string $statement)use($pdo,$owner,$portfolio): array{
    $native=research_intelligence_portfolio_create_native_decision($pdo,$owner,(string)$portfolio['public_id'],['idempotency_key'=>$key,'title'=>$title,'statement'=>$statement,'rationale'=>'Final release governed rationale.','confidence'=>0.88,'assumptions'=>['Demand remains above the shared operating threshold.']]);
    return research_decision_set_status($pdo,$owner,(string)$native['decision']['public_id'],'accepted');
};
$a=$makeDecision('p73-release-a','Portfolio Decision Alpha','Proceed with Alpha under bounded operating gates.');
$b=$makeDecision('p73-release-b','Portfolio Decision Beta','Proceed with Beta under bounded operating gates.');
p73s8((string)$a['status']==='accepted'&&(string)$b['status']==='accepted','Portfolio handoff creates native Decisions that retain explicit human disposition authority.');

$plan=research_action_plan_from_decision($pdo,$owner,(string)$a['public_id'],['idempotency_key'=>'p73-release-plan','title'=>'Alpha Action Plan','objective'=>'Execute Alpha with a measurable support gate.','expected_result'=>'Alpha reaches the target without breaching support capacity.','priority'=>'high','due_on'=>date('Y-m-d',time()-86400),'success_measures'=>[['label'=>'Alpha target','target'=>'Reached']], 'risks'=>[['title'=>'Support overload','detail'=>'Support demand may exceed the operating ceiling.']]]);
$outcome=research_decision_record_outcome($pdo,$owner,(string)$a['public_id'],['idempotency_key'=>'p73-release-outcome','assessment'=>'failure','expected_summary'=>'Alpha reaches target within support capacity.','actual_summary'=>'Alpha missed target and exceeded the support ceiling.','variance_summary'=>'Observed execution materially diverged from the accepted assumption.','lessons'=>'Revalidate support capacity before repeating this execution pattern.','follow_up_state'=>'follow_up']);
p73s8((string)$plan['status']==='draft'&&(string)$outcome['assessment']==='failure','Phase 72 execution and Phase 71 Outcome Memory remain authoritative inputs to Portfolio learning.');

$rollup=research_intelligence_portfolio_decision_execution_rollup($pdo,$owner,(string)$portfolio['public_id'],100);
p73s8(($rollup['summary']['native_decisions']??0)>=2&&($rollup['summary']['legacy_records']??0)>=1&&($rollup['summary']['action_plans']??0)>=1,'Portfolio rollup composes native Decisions, legacy history, and Action Plan execution without duplicating authority.');

$memory=research_intelligence_portfolio_pattern_refresh($pdo,$owner,(string)$portfolio['public_id'],'test');$patterns=research_intelligence_portfolio_pattern_memory($pdo,$owner,(string)$portfolio['public_id']);
p73s8(($memory['summary']['repeated_assumptions']??0)>=1&&($patterns['summary']['active_patterns']??0)>=1,'Exact Pattern Memory records cross-Decision organizational learning.');

$conflict=research_intelligence_strategic_edge_upsert($pdo,$owner,(string)$portfolio['public_id'],['source_type'=>'decision','source_public_id'=>$a['public_id'],'target_type'=>'decision','target_public_id'=>$b['public_id'],'relation_type'=>'conflicts_with','rationale'=>'Alpha and Beta compete for the same constrained operating capacity.','materiality'=>'critical','confidence'=>0.94]);
$graph=research_intelligence_portfolio_strategic_graph($pdo,$owner,(string)$portfolio['public_id'],false,200);
p73s8(!empty($conflict['active'])&&($graph['summary']['high_or_critical_conflicts']??0)>=1,'Strategic Graph preserves an explicit critical conflict with provenance.');

$settings=research_intelligence_strategic_review_configure($pdo,$owner,(string)$portfolio['public_id'],['status'=>'active','cadence'=>'monthly','due_offset_hours'=>72,'reviewers'=>[['user_id'=>$reviewer['public_id'],'role'=>'reviewer','required'=>true]]]);
p73s8(count((array)$settings['reviewers'])===1,'Recurring Strategic Review uses explicit current Team reviewer configuration.');
$strategic=research_intelligence_strategic_review_create($pdo,$owner,(string)$portfolio['public_id'],['trigger_type'=>'manual','idempotency_key'=>'p73-release-strategic-review']);
$strategicReview=research_review_access($pdo,$owner,(string)$strategic['collaborative_review_public_id']);research_review_respond($pdo,$reviewer,(string)$strategicReview['public_id'],'approve','Frozen Portfolio packet accurately captures current Decision, execution, Pattern Memory, and graph state.');$strategicDone=research_review_complete($pdo,$owner,(string)$strategicReview['public_id']);$strategicAgg=research_review_aggregate($pdo,$strategicDone);
p73s8(($strategicAgg['consensus']??'')==='unanimous_approval','Frozen Strategic Review completes with explicit unanimous human approval.');

$brief=research_intelligence_strategic_briefing_create($pdo,$owner,(string)$portfolio['public_id'],['idempotency_key'=>'p73-release-brief','strategic_review_id'=>(string)$strategic['public_id'],'title'=>'Phase 73 Final Executive Strategic Briefing']);
p73s8((string)$brief['packet_hash']===(string)$strategic['packet_hash']&&!empty($brief['team_review_public_id']),'Executive Strategic Briefing freezes the approved Strategic Review packet and enters Team Review.');
$teamReview=research_review_access($pdo,$owner,(string)$brief['team_review_public_id']);p73s8throws(fn()=>research_intelligence_strategic_briefing_assert_publication_ready($pdo,$owner,(string)$brief['executive_briefing_public_id']),'Publication remains blocked before Strategic Briefing Team Review approval.');
research_review_respond($pdo,$reviewer,(string)$teamReview['public_id'],'approve','Executive Strategic Briefing is accurate and ready for governed publication.');research_review_complete($pdo,$owner,(string)$teamReview['public_id']);
research_intelligence_strategic_briefing_assert_publication_ready($pdo,$owner,(string)$brief['executive_briefing_public_id']);
$publication=research_intelligence_portfolio_prepare_publication($pdo,$owner,(string)$brief['executive_briefing_public_id'],['required_approvals'=>1]);
p73s8(!empty($publication['public_id']),'Approved Strategic Briefing enters the existing Phase 59 publication workflow rather than a parallel publisher.');

$cognition=research_intelligence_organizational_portfolio_cognition($pdo,$owner,(string)$portfolio['public_id'],120);
$kinds=array_values(array_unique(array_map(fn($s)=>(string)($s['kind']??''),(array)$cognition['signals'])));
p73s8($cognition['ready']&&($cognition['summary']['analogues']??0)>=1,'Organizational Cognition composes exact Decision analogues from durable Pattern Memory.');
p73s8(in_array('decision_reconsideration',$kinds,true)&&in_array('execution_follow_through',$kinds,true)&&in_array('strategic_relationship',$kinds,true),'Organizational Cognition explains Outcome, execution, and strategic-relationship attention without mutating them.');
$agentContext=research_intelligence_organizational_agent_context($pdo,$owner,(string)$agent['public_id'],6);
p73s8(str_contains((string)$agentContext['text'],'[ORGANIZATIONAL STRATEGIC COGNITION]')&&str_contains((string)$agentContext['text'],'DECISION ANALOGUE'),'Research Agent receives bounded explainable organizational memory.');

$beforeDecisionCount=(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn();
$proposal=p73s8proposal($pdo,$owner,$conversation,$project,$agent,['capability'=>'research.portfolio.create_decision_draft','project_id'=>$project['public_id'],'arguments'=>[
  'portfolio_id'=>$portfolio['public_id'],'portfolio_state_hash'=>$cognition['state_hash'],'title'=>'Governed follow-through Decision draft','statement'=>'Evaluate a bounded corrective strategic option.','rationale'=>'Derived from the current organizational cognition packet.','decision_type'=>'recommendation','confidence'=>0.71
]]);
p73s8((string)$proposal['status']==='pending'&&(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn()===$beforeDecisionCount,'Agent follow-through proposal remains non-mutating before explicit confirmation.');
$confirmed=agent_action_confirm_execute($pdo,$owner,(string)$proposal['public_id']);$draft=research_decision_detail($pdo,$owner,(string)$confirmed['result']['public_id']);
p73s8($draft&&(string)$draft['status']==='draft','Confirmed Agent follow-through creates only a draft Decision.');
p73s8((string)(research_decision_detail($pdo,$owner,(string)$a['public_id'])['status']??'')==='accepted'&&(string)(research_decision_detail($pdo,$owner,(string)$b['public_id'])['status']??'')==='accepted','Organizational cognition and governed follow-through never silently change existing Decision dispositions.');

$center=research_intelligence_organization_command_center($pdo,$owner);
p73s8(($center['organizational_cognition_summary']['signals']??0)>=1&&($center['strategic_briefing_summary']['total']??0)>=1&&($center['pattern_summary']['active_patterns']??0)>=1,'Organization Command Center composes the full Phase 73 learning and briefing state.');
$feed=[];research_intelligence_organizational_cognitive_observations($pdo,$owner,$feed,30);
p73s8(count(array_filter($feed,fn($x)=>(string)($x['type']??'')==='organizational_cognition'))>=1,'Cognitive Feed surfaces organizational attention without state mutation.');

p73s8(!research_intelligence_organizational_portfolio_cognition($pdo,$outsider,(string)$portfolio['public_id'])['ready'],'Outsider cannot access organizational cognition.');
$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$reviewer['id']]);
p73s8(!research_intelligence_organizational_portfolio_cognition($pdo,$reviewer,(string)$portfolio['public_id'])['ready'],'Team revocation immediately removes Portfolio cognition access.');
p73s8(research_intelligence_strategic_briefing_access($pdo,$reviewer,(string)$brief['public_id'])===null,'Team revocation immediately removes Strategic Briefing access.');
p73s8(research_review_access($pdo,$reviewer,(string)$teamReview['public_id'])===null,'Team revocation immediately removes Strategic Briefing Team Review access.');

echo "Phase 73 Section 8 integrated release journey passed: Portfolio → Decision → Action Plan → Outcome Memory → Pattern Memory → Strategic Graph → Strategic Review → Executive Strategic Briefing → Organizational Cognition → confirmed governed follow-through.\n";
