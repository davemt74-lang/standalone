<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-missions','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-intelligence-decision-rollups'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p73s2(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p73s2throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

p73s2(research_intelligence_portfolio_execution_rollups_ready($pdo),'Section 2 reuses authoritative Decision, Action Plan, variance, review, and outcome stores.');
$run='p73s2'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Phase73S2Owner');$reviewer=$makeUser('Phase73S2Reviewer');$outsider=$makeUser('Phase73S2Outsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 73 Section 2 Team']);$teamId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$reviewer['id']]);

$agent=research_agent_create($pdo,$owner,['name'=>'Phase 73 S2 Agent','description'=>'Portfolio execution rollup fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$program=research_program_create($pdo,$owner,['agent_id'=>$agent['public_id'],'title'=>'Phase 73 S2 Program','objective'=>'Exercise native Decision execution rollups.','cadence'=>'manual','priority'=>'high','tasks'=>[['title'=>'Monitor execution','task_type'=>'general']]]);
$portfolio=research_intelligence_portfolio_create($pdo,$owner,['title'=>'Phase 73 S2 Portfolio','objective'=>'Roll native Decisions into execution state.','team_id'=>$teamPublic,'briefing_cadence'=>'manual','timezone_name'=>'UTC']);
$portfolio=research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolio['public_id'],(string)$program['public_id'],'primary');

$native=research_intelligence_portfolio_create_native_decision($pdo,$owner,(string)$portfolio['public_id'],[
 'idempotency_key'=>'phase73-s2-native','title'=>'Proceed with governed execution','statement'=>'Proceed only through a governed Action Plan.','rationale'=>'Portfolio evidence supports controlled execution.','decision_type'=>'decision','confidence'=>0.9
]);
$decision=research_decision_set_status($pdo,$owner,(string)$native['decision']['public_id'],'accepted');
$plan=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'phase73-s2-plan','title'=>'Portfolio execution plan','objective'=>'Execute the accepted Portfolio Decision.','expected_result'=>'Execution completes with documented variance and outcome.',
 'priority'=>'high','success_measures'=>[['label'=>'Controlled execution','target'=>'Complete with evidence']]
]);
$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'proposed');
$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'active');

$observation=research_action_plan_record_execution_observation($pdo,$owner,(string)$plan['public_id'],[
 'idempotency_key'=>'phase73-s2-risk','observation_type'=>'progress','assessment'=>'at_risk','summary'=>'A material execution deviation requires leadership attention.',
 'actual'=>['progress'=>0.35],'material'=>true,'variance_type'=>'execution_deviation','severity'=>'high','impact'=>'Expected result may be delayed.'
],false);
p73s2(!empty($observation['variance']['public_id']),'Authoritative Phase 72 execution evidence creates a durable material variance.');

$review=research_review_create($pdo,$owner,'action_plan',(string)$plan['public_id'],[(int)$reviewer['id']],gmdate('Y-m-d H:i:s',time()+86400),'Review the material execution variance.');
p73s2(($review['status']??'')==='open','Existing Collaborative Review opens against the Action Plan.');

$legacy=research_intelligence_portfolio_record_decision($pdo,$owner,(string)$portfolio['public_id'],['title'=>'Historical compatibility record','summary'=>'Legacy Phase 61 fixture.','decision_type'=>'recorded']);
p73s2(!empty($legacy['outcome']['public_id']),'Legacy Phase 61 Portfolio decision remains independently recordable for compatibility.');

$roll=research_intelligence_portfolio_decision_execution_rollup($pdo,$owner,(string)$portfolio['public_id'],100);$s=$roll['summary'];
p73s2($roll['ready']&&$s['native_decisions']===1&&$s['legacy_records']===1,'Rollup separates one native Decision from one legacy Phase 61 record.');
p73s2($s['action_plans']===1&&$s['active_action_plans']===1,'Rollup derives Action Plan counts/status from the Phase 72 ledger.');
p73s2($s['open_variances']===1&&$s['material_open_variances']===1&&$s['high_or_critical_open_variances']===1,'Rollup derives material/high execution variance state.');
p73s2($s['open_reviews']>=1,'Rollup derives open Collaborative Review state without a parallel review store.');
$decisionRow=$roll['decisions'][0]??[];$planRow=$decisionRow['action_plans'][0]??[];
p73s2(($planRow['status']??'')==='active'&&($planRow['variance']['material_open']??0)===1&&($planRow['reviews']['open']??0)>=1,'Per-Decision rollup exposes live Action Plan, variance, and review state.');

$center=research_intelligence_organization_command_center($pdo,$owner);
p73s2(($center['execution_summary']['native_decisions']??0)>=1&&($center['execution_summary']['material_open_variances']??0)>=1,'Organization Command Center aggregates native Portfolio execution totals.');
$attention=array_values(array_filter((array)$center['decision_execution_attention'],fn($x)=>($x['action_plan_public_id']??'')===(string)$plan['public_id']));
p73s2(count($attention)===1&&in_array('material_variance',(array)$attention[0]['reasons'],true)&&in_array('open_review',(array)$attention[0]['reasons'],true),'Command Center prioritizes material variance and review attention from authoritative state.');

$plan=research_action_plan_set_status($pdo,$owner,(string)$plan['public_id'],'completed');
$roll=research_intelligence_portfolio_decision_execution_rollup($pdo,$owner,(string)$portfolio['public_id'],100);
p73s2($roll['summary']['completed_action_plans']===1&&$roll['summary']['completed_without_outcome']===1,'Completed Action Plan without Outcome Memory is explicitly visible as a learning gap.');

$preview=research_action_plan_outcome_preview($pdo,$owner,(string)$plan['public_id']);
$link=research_action_plan_record_outcome_handoff($pdo,$owner,(string)$plan['public_id'],[
 'assessment'=>'partial','actual_summary'=>'Execution completed with a documented material deviation.','lessons'=>'Portfolio execution needs earlier variance review.',
 'follow_up_state'=>'follow_up','confidence'=>0.8,'handoff_state_hash'=>(string)$preview['state_hash']
],false);
p73s2(!empty($link['decision_outcome_public_id']),'Explicit human handoff records existing Decision Outcome Memory.');

$roll=research_intelligence_portfolio_decision_execution_rollup($pdo,$owner,(string)$portfolio['public_id'],100);
p73s2($roll['summary']['outcomes_recorded']===1&&$roll['summary']['completed_without_outcome']===0,'Rollup closes the learning gap only after explicit Outcome Memory exists.');
p73s2throws(fn()=>research_intelligence_portfolio_decision_execution_rollup($pdo,$outsider,(string)$portfolio['public_id'],100),'Outsider cannot read Portfolio Decision execution rollups.');
$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$reviewer['id']]);
p73s2throws(fn()=>research_intelligence_portfolio_decision_execution_rollup($pdo,$reviewer,(string)$portfolio['public_id'],100),'Team revocation immediately removes Portfolio execution rollup access.');

$runtime=(string)file_get_contents($root.'/app/research-intelligence-decision-rollups.php');
p73s2(!str_contains($runtime,'INSERT INTO research_decisions')&&!str_contains($runtime,'INSERT INTO research_action_plans')&&!str_contains($runtime,'ai_run('),'Section 2 remains a read-only projection with no duplicate execution or AI authority.');
echo "Phase 73 Section 2 Portfolio Decision & Execution Rollups database journey passed.\n";
