<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-missions','research-intelligence-decision-rollups','research-intelligence-pattern-memory','research-intelligence-strategic-graph','research-intelligence-strategic-reviews'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p73s5(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p73s5throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

p73s5(research_intelligence_strategic_reviews_ready($pdo),'Migration 102 exposes Recurring Strategic Review.');
$run='p73s5'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('Phase73S5Owner');$collab=$makeUser('Phase73S5Collaborator');$outsider=$makeUser('Phase73S5Outsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Phase 73 Section 5 Team']);$teamId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$collab['id']]);

$agent=research_agent_create($pdo,$owner,['name'=>'Phase 73 S5 Agent','description'=>'Recurring strategic review fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$program=research_program_create($pdo,$owner,['agent_id'=>$agent['public_id'],'title'=>'Phase 73 S5 Program','objective'=>'Recurring strategic review scope.','cadence'=>'manual','tasks'=>[['title'=>'Monitor','task_type'=>'general']]]);
$portfolio=research_intelligence_portfolio_create($pdo,$owner,['title'=>'Phase 73 S5 Portfolio','objective'=>'Exercise recurring human strategic review.','team_id'=>$teamPublic,'briefing_cadence'=>'weekly','briefing_time_local'=>'09:00','briefing_weekday'=>1,'timezone_name'=>'UTC']);
$portfolio=research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolio['public_id'],(string)$program['public_id'],'primary');

$nativeA=research_intelligence_portfolio_create_native_decision($pdo,$owner,(string)$portfolio['public_id'],['idempotency_key'=>'s5-da','title'=>'Strategic Decision A','statement'=>'Proceed with strategic initiative A.','rationale'=>'Explicit test rationale.','confidence'=>0.9,'assumptions'=>['Shared market condition remains true.']]);
$a=research_decision_set_status($pdo,$owner,(string)$nativeA['decision']['public_id'],'accepted');
$nativeB=research_intelligence_portfolio_create_native_decision($pdo,$owner,(string)$portfolio['public_id'],['idempotency_key'=>'s5-db','title'=>'Strategic Decision B','statement'=>'Proceed with strategic initiative B.','rationale'=>'Explicit test rationale.','confidence'=>0.8,'assumptions'=>['Shared market condition remains true.']]);
$b=research_decision_set_status($pdo,$owner,(string)$nativeB['decision']['public_id'],'accepted');
$plan=research_action_plan_from_decision($pdo,$owner,(string)$a['public_id'],['idempotency_key'=>'s5-plan','title'=>'Strategic Plan A','objective'=>'Execute strategic initiative A.','expected_result'=>'Initiative A completes.','priority'=>'high','success_measures'=>[['label'=>'Completion','target'=>'Complete']]]);
$conflict=research_intelligence_strategic_edge_upsert($pdo,$owner,(string)$portfolio['public_id'],['source_type'=>'decision','source_public_id'=>$a['public_id'],'target_type'=>'decision','target_public_id'=>$b['public_id'],'relation_type'=>'conflicts_with','rationale'=>'Competing strategic resource allocation.','materiality'=>'critical','confidence'=>0.9]);
research_intelligence_portfolio_pattern_refresh($pdo,$owner,(string)$portfolio['public_id'],'test');

$settings=research_intelligence_strategic_review_configure($pdo,$owner,(string)$portfolio['public_id'],['status'=>'active','cadence'=>'every_cycle','due_offset_hours'=>48,'reviewers'=>[['user_id'=>$collab['public_id'],'role'=>'reviewer','required'=>true]]]);
p73s5($settings['status']==='active'&&$settings['cadence']==='every_cycle'&&count($settings['reviewers'])===1,'Portfolio stores explicit recurring review cadence and reviewer configuration.');

$packet1=research_intelligence_strategic_review_packet($pdo,$owner,(string)$portfolio['public_id']);usleep(1000);$packet2=research_intelligence_strategic_review_packet($pdo,$owner,(string)$portfolio['public_id']);
p73s5($packet1['state_hash']===$packet2['state_hash'],'Frozen packet state hash ignores capture time when strategic state is unchanged.');
p73s5(isset($packet1['state']['execution'],$packet1['state']['pattern_memory'],$packet1['state']['strategic_graph'],$packet1['state']['review_focus']),'Packet freezes execution, Pattern Memory, Strategic Graph, and deterministic review focus.');
p73s5(count((array)$packet1['state']['review_focus'])>=2,'Material conflict and organizational learning produce deterministic Strategic Review focus.');

$before=['a'=>(string)$a['status'],'b'=>(string)$b['status'],'plan'=>(string)$plan['status'],'decision_count'=>(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn(),'plan_count'=>(int)$pdo->query('SELECT COUNT(*) FROM research_action_plans')->fetchColumn()];
$manual=research_intelligence_strategic_review_create($pdo,$owner,(string)$portfolio['public_id'],['trigger_type'=>'manual','idempotency_key'=>'manual-once']);
p73s5((string)$manual['trigger_type']==='manual'&&!empty($manual['collaborative_review_public_id']),'Manual Strategic Review creates a frozen packet and an existing Collaborative Review.');
$review=research_review_access($pdo,$owner,(string)$manual['collaborative_review_public_id']);
p73s5($review&&$review['subject_type']==='strategic_review'&&!$review['is_stale'],'Collaborative Review recognizes the frozen strategic_review subject and does not treat later source state as the subject itself.');
$assignments=research_review_assignments($pdo,$review);p73s5(count($assignments)===1&&(int)$assignments[0]['reviewer_user_id']===(int)$collab['id'],'Only explicitly configured current collaborator is assigned.');
$again=research_intelligence_strategic_review_create($pdo,$owner,(string)$portfolio['public_id'],['trigger_type'=>'manual','idempotency_key'=>'manual-once']);
p73s5((string)$again['public_id']===(string)$manual['public_id'],'Manual idempotency reuses the same Strategic Review packet.');

$aNow=research_decision_detail($pdo,$owner,(string)$a['public_id']);$bNow=research_decision_detail($pdo,$owner,(string)$b['public_id']);$planNow=research_action_plan_detail($pdo,$owner,(string)$plan['public_id']);
$after=['a'=>(string)$aNow['status'],'b'=>(string)$bNow['status'],'plan'=>(string)$planNow['status'],'decision_count'=>(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn(),'plan_count'=>(int)$pdo->query('SELECT COUNT(*) FROM research_action_plans')->fetchColumn()];
p73s5($before===$after,'Creating Strategic Review never mutates or duplicates Decision/Action Plan source state.');

$responded=research_review_respond($pdo,$collab,(string)$review['public_id'],'request_changes','Resolve the critical strategic conflict before relying on this packet.');
p73s5(($responded['aggregate']['consensus']??'')==='changes_requested','Strategic Review uses existing Collaborative Review response and consensus behavior.');
$center=research_intelligence_organization_strategic_review_center($pdo,$owner);
p73s5(($center['summary']['changes_requested']??0)>=1&&count((array)$center['attention'])>=1,'Organization Strategic Review center surfaces changes-requested human attention.');

$a=research_decision_set_status($pdo,$owner,(string)$a['public_id'],'reopened');
$drift=research_intelligence_strategic_review_access($pdo,$owner,(string)$manual['public_id'],true);
$reviewStill=research_review_access($pdo,$owner,(string)$review['public_id']);
p73s5(!empty($drift['current_drift']),'Authoritative strategic state change is detected as current drift from the frozen packet.');
p73s5($reviewStill&&!$reviewStill['is_stale']&&(string)$reviewStill['subject_hash']===(string)$manual['packet_hash'],'Current drift does not rewrite or invalidate the frozen Collaborative Review subject.');

$cyclePublic=$pub('cycle');$scheduled=gmdate('Y-m-d H:i:s');$pdo->prepare("INSERT INTO research_intelligence_portfolio_cycles(public_id,portfolio_id,scheduled_for,trigger_type,status,dedupe_key,completed_at) VALUES(?,?,?,'schedule','completed',?,NOW())")->execute([$cyclePublic,(int)$portfolio['id'],$scheduled,hash('sha256','p73s5-cycle-'.$run)]);
$cycle=['public_id'=>$cyclePublic,'scheduled_for'=>$scheduled];
$recurring=research_intelligence_strategic_review_maybe_create_for_cycle($pdo,$owner,$portfolio,$cycle);
p73s5($recurring&&$recurring['trigger_type']==='cycle','Existing Portfolio cycle can create a due recurring Strategic Review.');
$recurringAgain=research_intelligence_strategic_review_maybe_create_for_cycle($pdo,$owner,$portfolio,$cycle);
p73s5((string)$recurringAgain['public_id']===(string)$recurring['public_id'],'Recurring Strategic Review de-duplicates by Portfolio cycle.');

$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$collab['id']]);
p73s5(research_intelligence_strategic_review_access($pdo,$collab,(string)$manual['public_id'])===null,'Team revocation immediately removes Strategic Review packet access.');
p73s5(research_review_access($pdo,$collab,(string)$review['public_id'])===null,'Team revocation immediately removes Collaborative Review access.');
$cyclePublic2=$pub('cycle');$scheduled2=gmdate('Y-m-d H:i:s',time()+60);$pdo->prepare("INSERT INTO research_intelligence_portfolio_cycles(public_id,portfolio_id,scheduled_for,trigger_type,status,dedupe_key,completed_at) VALUES(?,?,?,'schedule','completed',?,NOW())")->execute([$cyclePublic2,(int)$portfolio['id'],$scheduled2,hash('sha256','p73s5-cycle2-'.$run)]);
p73s5throws(fn()=>research_intelligence_strategic_review_maybe_create_for_cycle($pdo,$owner,$portfolio,['public_id'=>$cyclePublic2,'scheduled_for'=>$scheduled2]),'Revoked configured reviewer is never silently reassigned on a later cycle.');
p73s5throws(fn()=>research_intelligence_strategic_review_configure($pdo,$outsider,(string)$portfolio['public_id'],['status'=>'paused','reviewers'=>[]]),'Outsider cannot configure Strategic Review.');

$runtime=(string)file_get_contents($root.'/app/research-intelligence-strategic-reviews.php');
p73s5(!str_contains($runtime,'research_decision_set_status(')&&!str_contains($runtime,'research_action_plan_set_status(')&&!str_contains($runtime,'ai_run('),'Strategic Review runtime has no Decision/Action Plan lifecycle or AI decision authority.');
echo "Phase 73 Section 5 Recurring Strategic Review database journey passed.\n";
