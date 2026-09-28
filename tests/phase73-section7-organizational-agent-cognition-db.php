<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-missions','research-intelligence-decision-rollups','research-intelligence-pattern-memory','research-intelligence-strategic-graph','research-intelligence-strategic-reviews','research-intelligence-strategic-briefings','research-intelligence-organizational-cognition'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p73s7(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p73s7throws(callable $fn,string $class,string $m): void {try{$fn();}catch(Throwable $e){if($e instanceof $class){echo "PASS: $m\n";return;}throw $e;}throw new RuntimeException('FAIL: '.$m);}
function p73s7_message(PDO $pdo,array $conversation,string $body): int {$pdo->prepare("INSERT INTO conversation_messages(public_id,conversation_id,user_id,sender_type,body) VALUES(?,?,NULL,'agent',?)")->execute([ulid_like(),(int)$conversation['id'],$body]);return (int)$pdo->lastInsertId();}
function p73s7_propose(PDO $pdo,array $viewer,array $conversation,array $project,array $agent,array $raw): array {
    $ctx=research_intelligence_organizational_agent_context($pdo,$viewer,(string)$agent['public_id'],6);$refs=(array)($ctx['refs']??[]);
    $messageId=p73s7_message($pdo,$conversation,'Phase 73 Section 7 proposal fixture');
    $rows=agent_action_create_proposals($pdo,$viewer,$conversation,$messageId,[['type'=>'research','public_id'=>(string)$project['public_id']]],[$raw],$refs);
    if(count($rows)!==1)throw new RuntimeException('FAIL: expected one Agent action proposal for '.(string)($raw['capability']??'unknown'));
    return $rows[0];
}

p73s7(research_intelligence_organizational_cognition_ready($pdo),'Organizational cognition is ready on the existing schema.');
p73s7(!(glob($root.'/database/migrations/*_104_*.sql')?:[]),'Section 7 adds no migration 104.');

$run='p73s7'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Phase73S7Owner');$collab=$makeUser('Phase73S7Collaborator');$outsider=$makeUser('Phase73S7Outsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 73 Section 7 Team']);$teamId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$collab['id']]);

$agent=research_agent_create($pdo,$owner,['name'=>'Phase 73 S7 Agent','description'=>'Organizational cognition fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$agent=research_agent_access($pdo,$owner,(string)$agent['public_id']);p73s7((bool)$agent,'Research Agent fixture is available.');
$project=project_access($pdo,(int)$owner['id'],(string)$agent['project_public_id']);p73s7((bool)$project&&project_can_write($project),'Owner has writable anchor Research project.');
$q=$pdo->prepare('SELECT * FROM conversations WHERE id=?');$q->execute([(int)$agent['conversation_id']]);$conversation=$q->fetch();p73s7((bool)$conversation,'Agent conversation fixture is available.');

$program=research_program_create($pdo,$owner,['agent_id'=>$agent['public_id'],'title'=>'Phase 73 S7 Program','objective'=>'Exercise organizational reasoning and governed follow-through.','cadence'=>'manual','tasks'=>[['title'=>'Monitor strategy','task_type'=>'general']]]);
$portfolio=research_intelligence_portfolio_create($pdo,$owner,['title'=>'Phase 73 S7 Portfolio','objective'=>'Reason across Decisions, execution, learning, reviews, and briefings without bypassing human authority.','team_id'=>$teamPublic,'briefing_cadence'=>'manual','timezone_name'=>'UTC']);
$portfolio=research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolio['public_id'],(string)$program['public_id'],'primary');
research_intelligence_strategic_review_configure($pdo,$owner,(string)$portfolio['public_id'],['status'=>'active','cadence'=>'monthly','due_offset_hours'=>72,'reviewers'=>[['user_id'=>$collab['public_id'],'role'=>'reviewer','required'=>true]]]);

$nativeA=research_intelligence_portfolio_create_native_decision($pdo,$owner,(string)$portfolio['public_id'],['idempotency_key'=>'s7-a','title'=>'Decision Alpha','statement'=>'Proceed with Alpha.','rationale'=>'Alpha rationale.','confidence'=>0.85,'assumptions'=>['Shared demand remains above threshold.']]);
$a=research_decision_set_status($pdo,$owner,(string)$nativeA['decision']['public_id'],'accepted');
$nativeB=research_intelligence_portfolio_create_native_decision($pdo,$owner,(string)$portfolio['public_id'],['idempotency_key'=>'s7-b','title'=>'Decision Beta','statement'=>'Proceed with Beta.','rationale'=>'Beta rationale.','confidence'=>0.8,'assumptions'=>['Shared demand remains above threshold.']]);
$b=research_decision_set_status($pdo,$owner,(string)$nativeB['decision']['public_id'],'accepted');
$outcome=research_decision_record_outcome($pdo,$owner,(string)$a['public_id'],['idempotency_key'=>'s7-failure','assessment'=>'failure','expected_summary'=>'Alpha should meet target.','actual_summary'=>'Alpha missed the target materially.','variance_summary'=>'Demand fell below the assumption.','lessons'=>'Validate demand before repeating this approach.','follow_up_state'=>'follow_up']);
$yesterday=date('Y-m-d',time()-86400);
$existingPlan=research_action_plan_from_decision($pdo,$owner,(string)$a['public_id'],['idempotency_key'=>'s7-existing-plan','title'=>'Alpha Execution','objective'=>'Execute Alpha.','expected_result'=>'Alpha reaches target.','priority'=>'high','due_on'=>$yesterday,'success_measures'=>[['label'=>'Target','target'=>'Reached']]]);
research_intelligence_strategic_edge_upsert($pdo,$owner,(string)$portfolio['public_id'],['source_type'=>'decision','source_public_id'=>$a['public_id'],'target_type'=>'decision','target_public_id'=>$b['public_id'],'relation_type'=>'conflicts_with','rationale'=>'Alpha and Beta compete for the same constrained resource.','materiality'=>'critical','confidence'=>0.95]);
$memory=research_intelligence_portfolio_pattern_refresh($pdo,$owner,(string)$portfolio['public_id'],'test');
p73s7(($memory['summary']['repeated_assumptions']??0)>=1,'Exact Pattern Memory detects the repeated Decision assumption.');

$cognition=research_intelligence_organizational_portfolio_cognition($pdo,$owner,(string)$portfolio['public_id'],100);
p73s7($cognition['ready']&&preg_match('/^[a-f0-9]{64}$/',(string)$cognition['state_hash'])===1,'Portfolio cognition exposes an exact strategic state hash.');
$kinds=array_values(array_unique(array_map(fn($s)=>(string)$s['kind'],(array)$cognition['signals'])));
p73s7(in_array('decision_reconsideration',$kinds,true),'Failure Outcome Memory becomes a Decision reconsideration signal.');
p73s7(in_array('execution_follow_through',$kinds,true),'Overdue Action Plan becomes an execution follow-through signal.');
p73s7(in_array('strategic_relationship',$kinds,true),'Critical Strategic Graph conflict becomes an organizational cognition signal.');
$analogue=$cognition['analogues'][0]??null;p73s7($analogue&&($analogue['decision_count']??0)>=2,'Exact Pattern Memory becomes a multi-Decision organizational analogue.');
$analogueIds=array_map(fn($d)=>(string)$d['public_id'],(array)$analogue['decisions']);p73s7(in_array((string)$a['public_id'],$analogueIds,true)&&in_array((string)$b['public_id'],$analogueIds,true),'Decision analogue preserves explicit Alpha/Beta provenance.');

$agentCtx=research_intelligence_organizational_agent_context($pdo,$owner,(string)$agent['public_id'],6);
p73s7(str_contains((string)$agentCtx['text'],'[ORGANIZATIONAL STRATEGIC COGNITION]')&&str_contains((string)$agentCtx['text'],'Strategic state hash:')&&str_contains((string)$agentCtx['text'],'DECISION ANALOGUE'),'Agent Chat receives governed organizational reasoning, hashes, and analogue provenance.');
$feed=[];research_intelligence_organizational_cognitive_observations($pdo,$owner,$feed,24);p73s7((bool)array_filter($feed,fn($x)=>(string)($x['type']??'')==='organizational_cognition'),'Organizational cognition surfaces through the existing Cognitive Feed.');

$capabilities=agent_action_capabilities();
foreach(['research.portfolio.create_decision_draft','research.decision.create_action_plan_draft','research.decision.open_reconsideration','research.portfolio.create_strategic_review','research.portfolio.create_strategic_briefing'] as $cap)p73s7(isset($capabilities[$cap]),'Governed capability registered: '.$cap);
foreach(['research.decision.set_status','research.action_plan.activate','research.action_plan.complete','research.action_plan.cancel','research.action_plan.resolve_variance','research.strategic_graph.edit','research.review.complete'] as $forbidden)p73s7(!isset($capabilities[$forbidden]),'Direct authority capability is absent: '.$forbidden);

$decisionCountBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn();
$cognition=research_intelligence_organizational_portfolio_cognition($pdo,$owner,(string)$portfolio['public_id'],100);
$proposal=p73s7_propose($pdo,$owner,$conversation,$project,$agent,['capability'=>'research.portfolio.create_decision_draft','project_id'=>$project['public_id'],'arguments'=>[
  'portfolio_id'=>$portfolio['public_id'],'portfolio_state_hash'=>$cognition['state_hash'],'title'=>'Agent Proposed Decision','statement'=>'Evaluate a bounded strategic option.','rationale'=>'Derived from current organizational context.','decision_type'=>'recommendation','confidence'=>0.7
]]);
p73s7((string)$proposal['status']==='pending'&&(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn()===$decisionCountBefore,'Decision proposal remains pending with zero Decision mutation before confirmation.');
$confirmed=agent_action_confirm_execute($pdo,$owner,(string)$proposal['public_id']);$draftDecision=research_decision_detail($pdo,$owner,(string)$confirmed['result']['public_id']);
p73s7($draftDecision&&(string)$draftDecision['status']==='draft','Confirmed organizational proposal creates only a draft Decision.');
p73s7((string)(research_decision_detail($pdo,$owner,(string)$a['public_id'])['status']??'')==='accepted'&&(string)(research_decision_detail($pdo,$owner,(string)$b['public_id'])['status']??'')==='accepted','Creating a draft Decision does not change existing Decision dispositions.');

$bCurrent=research_decision_detail($pdo,$owner,(string)$b['public_id']);$bHash=research_decision_review_state_hash($pdo,$owner,(string)$b['public_id']);
$planCountBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_action_plans')->fetchColumn();
$proposal=p73s7_propose($pdo,$owner,$conversation,$project,$agent,['capability'=>'research.decision.create_action_plan_draft','project_id'=>$project['public_id'],'arguments'=>[
  'decision_id'=>$b['public_id'],'decision_state_hash'=>$bHash,'title'=>'Beta Governed Plan','objective'=>'Prepare Beta execution.','expected_result'=>'Beta execution is ready for human activation.','priority'=>'high','success_measures'=>[['label'=>'Readiness','target'=>'Approved for activation']]
]]);
p73s7((string)$proposal['status']==='pending'&&(int)$pdo->query('SELECT COUNT(*) FROM research_action_plans')->fetchColumn()===$planCountBefore,'Action Plan proposal remains pending with zero plan mutation before confirmation.');
$confirmed=agent_action_confirm_execute($pdo,$owner,(string)$proposal['public_id']);$draftPlan=research_action_plan_detail($pdo,$owner,(string)$confirmed['result']['public_id']);
p73s7($draftPlan&&(string)$draftPlan['status']==='draft','Confirmed organizational proposal creates only a draft Action Plan.');
p73s7((string)(research_decision_detail($pdo,$owner,(string)$b['public_id'])['status']??'')==='accepted','Draft Action Plan creation cannot alter its source Decision.');

$aHash=research_decision_review_state_hash($pdo,$owner,(string)$a['public_id']);$casesBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_decision_reconsiderations')->fetchColumn();
$proposal=p73s7_propose($pdo,$owner,$conversation,$project,$agent,['capability'=>'research.decision.open_reconsideration','project_id'=>$project['public_id'],'arguments'=>[
  'decision_id'=>$a['public_id'],'decision_state_hash'=>$aHash,'title'=>'Review Alpha after failed outcome','reason'=>'Observed failure and variance materially challenge the recorded Decision.','materiality'=>'critical'
]]);
p73s7((int)$pdo->query('SELECT COUNT(*) FROM research_decision_reconsiderations')->fetchColumn()===$casesBefore,'Reconsideration proposal creates no case before confirmation.');
$confirmed=agent_action_confirm_execute($pdo,$owner,(string)$proposal['public_id']);$case=research_decision_reconsideration_access($pdo,$owner,(string)$confirmed['result']['public_id']);
p73s7($case&&(string)$case['status']==='open'&&!$case['applied_at'],'Confirmed proposal only opens a human-governed reconsideration case.');
p73s7((string)(research_decision_detail($pdo,$owner,(string)$a['public_id'])['status']??'')==='accepted','Opening reconsideration does not change Decision status.');

$bHash=research_decision_review_state_hash($pdo,$owner,(string)$b['public_id']);
$staleProposal=p73s7_propose($pdo,$owner,$conversation,$project,$agent,['capability'=>'research.decision.open_reconsideration','project_id'=>$project['public_id'],'arguments'=>[
  'decision_id'=>$b['public_id'],'decision_state_hash'=>$bHash,'title'=>'Potential Beta review','reason'=>'Fixture for exact-state stale rejection.','materiality'=>'high'
]]);
research_decision_update($pdo,$owner,(string)$b['public_id'],['rationale'=>'Beta rationale changed after Agent proposal.','reason'=>'Make Section 7 proposal stale.']);
p73s7throws(fn()=>agent_action_confirm_execute($pdo,$owner,(string)$staleProposal['public_id']),AgentActionStale::class,'Decision state change invalidates the pending governed proposal.');
$q=$pdo->prepare('SELECT status,error_text FROM agent_action_proposals WHERE public_id=?');$q->execute([$staleProposal['public_id']]);$staleRow=$q->fetch();
p73s7($staleRow&&(string)$staleRow['status']==='stale'&&str_contains((string)$staleRow['error_text'],'Governed source state changed'),'Stale exact-state proposal is durably marked stale.');
p73s7((string)(research_decision_detail($pdo,$owner,(string)$b['public_id'])['status']??'')==='accepted','Stale proposal cannot mutate the Decision disposition.');

$cognition=research_intelligence_organizational_portfolio_cognition($pdo,$owner,(string)$portfolio['public_id'],100);
$reviewsBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_intelligence_strategic_reviews')->fetchColumn();
$proposal=p73s7_propose($pdo,$owner,$conversation,$project,$agent,['capability'=>'research.portfolio.create_strategic_review','project_id'=>$project['public_id'],'arguments'=>[
  'portfolio_id'=>$portfolio['public_id'],'portfolio_state_hash'=>$cognition['state_hash'],'instructions'=>'Human reviewers should assess current strategic risk and follow-through.'
]]);
p73s7((int)$pdo->query('SELECT COUNT(*) FROM research_intelligence_strategic_reviews')->fetchColumn()===$reviewsBefore,'Strategic Review proposal freezes nothing before confirmation.');
$confirmed=agent_action_confirm_execute($pdo,$owner,(string)$proposal['public_id']);$strategicReview=research_intelligence_strategic_review_access($pdo,$owner,(string)$confirmed['result']['public_id'],false);
p73s7($strategicReview&&!empty($strategicReview['collaborative_review_public_id']),'Confirmed proposal enters the existing frozen Strategic Review + Collaborative Review path.');

$cognition=research_intelligence_organizational_portfolio_cognition($pdo,$owner,(string)$portfolio['public_id'],100);
$briefsBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_intelligence_strategic_briefings')->fetchColumn();$pubBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_publication_workflows')->fetchColumn();
$proposal=p73s7_propose($pdo,$owner,$conversation,$project,$agent,['capability'=>'research.portfolio.create_strategic_briefing','project_id'=>$project['public_id'],'arguments'=>[
  'portfolio_id'=>$portfolio['public_id'],'portfolio_state_hash'=>$cognition['state_hash'],'title'=>'Agent Proposed Executive Strategic Briefing','window_days'=>30
]]);
p73s7((int)$pdo->query('SELECT COUNT(*) FROM research_intelligence_strategic_briefings')->fetchColumn()===$briefsBefore,'Strategic Briefing proposal creates no briefing before confirmation.');
$confirmed=agent_action_confirm_execute($pdo,$owner,(string)$proposal['public_id']);$brief=research_intelligence_strategic_briefing_access($pdo,$owner,(string)$confirmed['result']['public_id'],false);
p73s7($brief&&!empty($brief['team_review_public_id'])&&(string)($brief['team_review']['status']??'')==='open','Confirmed proposal creates a frozen Strategic Briefing in the existing Team Review path.');
p73s7((int)$pdo->query('SELECT COUNT(*) FROM research_publication_workflows')->fetchColumn()===$pubBefore,'Strategic Briefing proposal cannot publish or create the Phase 59 publication workflow.');

$aFinal=research_decision_detail($pdo,$owner,(string)$a['public_id']);$bFinal=research_decision_detail($pdo,$owner,(string)$b['public_id']);$existingPlanFinal=research_action_plan_detail($pdo,$owner,(string)$existingPlan['public_id']);$draftPlanFinal=research_action_plan_detail($pdo,$owner,(string)$draftPlan['public_id']);
p73s7((string)$aFinal['status']==='accepted'&&(string)$bFinal['status']==='accepted','Section 7 proposal journey never changes recorded Decision dispositions.');
p73s7((string)$existingPlanFinal['status']==='draft'&&(string)$draftPlanFinal['status']==='draft','Section 7 proposal journey never activates, completes, or cancels Action Plans.');

$center=research_intelligence_organizational_cognition_center($pdo,$owner,120);p73s7($center['ready']&&($center['summary']['portfolios']??0)>=1&&($center['summary']['analogues']??0)>=1,'Organization Command Center receives explainable cognition and exact Decision analogues.');
$ops=research_intelligence_portfolio_operations_detail($pdo,$owner,research_intelligence_portfolio_access($pdo,$owner,(string)$portfolio['public_id']));p73s7(!empty($ops['organizational_cognition']['ready']),'Portfolio operations expose Section 7 organizational cognition.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$collab['id']]);
p73s7(!research_intelligence_organizational_portfolio_cognition($pdo,$collab,(string)$portfolio['public_id'])['ready'],'Team revocation immediately removes Portfolio cognition access.');
p73s7(!research_intelligence_organizational_portfolio_cognition($pdo,$outsider,(string)$portfolio['public_id'])['ready'],'Outsider cannot access Portfolio cognition.');
$runtime=(string)file_get_contents($root.'/app/research-intelligence-organizational-cognition.php');
p73s7(!str_contains($runtime,'research_decision_set_status(')&&!str_contains($runtime,'research_action_plan_set_status(')&&!str_contains($runtime,'research_publication_publish(')&&!str_contains($runtime,'ai_run('),'Cognition runtime remains read-only and deterministic.');
echo "Phase 73 Section 7 Organizational Agent Cognition & Governed Follow-Through database journey passed.\n";
