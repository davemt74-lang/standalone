<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-missions','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-intelligence-decision-rollups','research-intelligence-pattern-memory'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p73s3(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p73s3throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

p73s3(research_intelligence_pattern_memory_ready($pdo),'Migration 100 exposes deterministic Pattern Memory.');
$run='p73s3'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Phase73S3Owner');$collab=$makeUser('Phase73S3Collaborator');$outsider=$makeUser('Phase73S3Outsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 73 Section 3 Team']);$teamId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$collab['id']]);

$agent=research_agent_create($pdo,$owner,['name'=>'Phase 73 S3 Agent','description'=>'Cross-Decision pattern fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$program=research_program_create($pdo,$owner,['agent_id'=>$agent['public_id'],'title'=>'Phase 73 S3 Program','objective'=>'Exercise cross-Decision learning.','cadence'=>'manual','priority'=>'high','tasks'=>[['title'=>'Monitor learning','task_type'=>'general']]]);
$portfolio=research_intelligence_portfolio_create($pdo,$owner,['title'=>'Phase 73 S3 Portfolio','objective'=>'Learn across multiple governed Decisions.','team_id'=>$teamPublic,'briefing_cadence'=>'manual','timezone_name'=>'UTC']);
$portfolio=research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolio['public_id'],(string)$program['public_id'],'primary');

$decisionIds=[];$planIds=[];$varianceIds=[];$outcomeIds=[];
for($i=1;$i<=2;$i++){
  $native=research_intelligence_portfolio_create_native_decision($pdo,$owner,(string)$portfolio['public_id'],[
    'idempotency_key'=>'phase73-s3-decision-'.$i,'title'=>'Governed expansion decision '.$i,'statement'=>'Execute controlled expansion path '.$i.'.',
    'rationale'=>'Two independent Decisions intentionally share deterministic learning evidence.','decision_type'=>'decision','confidence'=>0.85,
    'assumptions'=>['Demand remains above the operating threshold.']
  ]);
  $decision=research_decision_set_status($pdo,$owner,(string)$native['decision']['public_id'],'accepted');$decisionIds[]=(string)$decision['public_id'];
  $plan=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],[
    'idempotency_key'=>'phase73-s3-plan-'.$i,'title'=>'Execution plan '.$i,'objective'=>'Execute governed expansion '.$i.'.','expected_result'=>'Expansion meets target without material delay.',
    'priority'=>'high','success_measures'=>[['label'=>'Expansion target','target'=>'Met']],
    'risks'=>[['title'=>'Supplier capacity','detail'=>'Capacity may constrain execution.','mitigation'=>'Stage the rollout.']]
  ]);
  $plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'proposed');$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'active');$planIds[]=(string)$plan['public_id'];
  $obs=research_action_plan_record_execution_observation($pdo,$owner,(string)$plan['public_id'],[
    'idempotency_key'=>'phase73-s3-variance-'.$i,'observation_type'=>'progress','assessment'=>'at_risk','summary'=>'Execution is behind the intended pace.',
    'actual'=>['progress'=>0.5],'material'=>true,'variance_type'=>'execution_deviation','severity'=>'high','impact'=>'Target timing is at risk.'
  ],false);
  $varianceIds[]=(string)$obs['variance']['public_id'];
  $plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'completed');
  $preview=research_action_plan_outcome_preview($pdo,$owner,(string)$plan['public_id']);
  $link=research_action_plan_record_outcome_handoff($pdo,$owner,(string)$plan['public_id'],[
    'assessment'=>'partial','actual_summary'=>'Expansion completed but later than expected.','variance_summary'=>'Execution timing missed the original expectation.',
    'lessons'=>'Use staged rollout checkpoints earlier.','follow_up_state'=>'follow_up','confidence'=>0.8,'handoff_state_hash'=>(string)$preview['state_hash']
  ],false);
  $outcomeIds[]=(string)$link['decision_outcome_public_id'];
}

$legacy=research_intelligence_portfolio_record_decision($pdo,$owner,(string)$portfolio['public_id'],['title'=>'Legacy historical record','summary'=>'Must never become Pattern Memory evidence.','decision_type'=>'recorded']);
p73s3(!empty($legacy['outcome']['public_id']),'Legacy Phase 61 record remains present as a negative-control fixture.');

$first=research_intelligence_portfolio_pattern_refresh($pdo,$owner,(string)$portfolio['public_id'],'test');
p73s3(!$first['reused_run'],'First Pattern Memory refresh creates a durable run.');
p73s3(($first['summary']['active_patterns']??0)===6,'Two Decisions produce exactly the six supported deterministic pattern types.');
foreach(['repeated_assumptions','repeated_plan_risks','recurring_variance_types','recurring_outcome_assessments','expected_actual_variance','repeated_lessons'] as $key)
  p73s3(($first['summary'][$key]??0)===1,'Pattern summary contains exactly one '.$key.' pattern.');

$types=[];foreach($first['patterns'] as $p){$types[(string)$p['pattern_type']]=$p;p73s3((int)$p['decision_count']===2,'Pattern '.$p['pattern_type'].' requires evidence from both native Decisions.');}
foreach(['repeated_assumption','repeated_plan_risk','recurring_variance_type','recurring_outcome_assessment','expected_actual_variance','repeated_lesson'] as $type)p73s3(isset($types[$type]),'Pattern type '.$type.' is durable and inspectable.');
p73s3((int)$types['repeated_plan_risk']['action_plan_count']===2,'Repeated risk links two authoritative Action Plans.');
p73s3((int)$types['recurring_outcome_assessment']['outcome_count']===2,'Recurring outcome assessment links two Decision Outcome Memory records.');
$legacyPublic=(string)$legacy['outcome']['public_id'];foreach($first['patterns'] as $p)foreach($p['members'] as $m)p73s3((string)$m['member_public_id']!==$legacyPublic,'Legacy Phase 61 outcome is excluded from Pattern membership.');

$runCountBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_intelligence_decision_pattern_runs')->fetchColumn();
$second=research_intelligence_portfolio_pattern_refresh($pdo,$owner,(string)$portfolio['public_id'],'test');
$runCountAfter=(int)$pdo->query('SELECT COUNT(*) FROM research_intelligence_decision_pattern_runs')->fetchColumn();
p73s3($second['reused_run']&&$second['state_hash']===$first['state_hash']&&$runCountAfter===$runCountBefore,'Unchanged Pattern snapshot reuses the same durable run.');

$memory=research_intelligence_portfolio_pattern_memory($pdo,$collab,(string)$portfolio['public_id']);
p73s3($memory['ready']&&($memory['summary']['active_patterns']??0)===6,'Current Team collaborator can inspect Pattern Memory.');
$center=research_intelligence_organization_command_center($pdo,$owner);
p73s3(($center['pattern_summary']['active_patterns']??0)>=6&&count((array)$center['learning_patterns'])>=6,'Organization Command Center aggregates accessible Pattern Memory.');

$sourceCounts=[
 'decisions'=>(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn(),
 'plans'=>(int)$pdo->query('SELECT COUNT(*) FROM research_action_plans')->fetchColumn(),
 'outcomes'=>(int)$pdo->query('SELECT COUNT(*) FROM research_decision_outcomes')->fetchColumn(),
 'variances'=>(int)$pdo->query('SELECT COUNT(*) FROM research_action_plan_variances')->fetchColumn()
];
research_intelligence_portfolio_pattern_refresh($pdo,$owner,(string)$portfolio['public_id'],'test');
p73s3($sourceCounts['decisions']===(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn()
 &&$sourceCounts['plans']===(int)$pdo->query('SELECT COUNT(*) FROM research_action_plans')->fetchColumn()
 &&$sourceCounts['outcomes']===(int)$pdo->query('SELECT COUNT(*) FROM research_decision_outcomes')->fetchColumn()
 &&$sourceCounts['variances']===(int)$pdo->query('SELECT COUNT(*) FROM research_action_plan_variances')->fetchColumn(),
 'Pattern refresh does not create or mutate authoritative Decision/execution record counts.');

p73s3throws(fn()=>research_intelligence_portfolio_pattern_refresh($pdo,$outsider,(string)$portfolio['public_id'],'test'),'Outsider cannot refresh Pattern Memory.');
p73s3(research_intelligence_portfolio_pattern_list($pdo,$outsider,(string)$portfolio['public_id'],true,100)===[],'Outsider cannot list Pattern Memory.');
$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$collab['id']]);
p73s3(research_intelligence_portfolio_pattern_list($pdo,$collab,(string)$portfolio['public_id'],true,100)===[],'Team revocation immediately removes Pattern Memory access.');

$runtime=(string)file_get_contents($root.'/app/research-intelligence-pattern-memory.php');
p73s3(!str_contains($runtime,'ai_run(')&&!str_contains($runtime,'embedding')&&!str_contains($runtime,'research_decision_set_status')&&!str_contains($runtime,'research_action_plan_set_status'),'Pattern Memory has no AI similarity or source-state mutation authority.');
echo "Phase 73 Section 3 Cross-Decision Learning & Pattern Memory database journey passed.\n";
