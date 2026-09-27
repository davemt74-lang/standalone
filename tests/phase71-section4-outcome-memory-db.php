<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p71s4(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p71s4throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p71s4(research_decisions_ready($pdo)&&research_decision_outcomes_ready($pdo)&&research_outcomes_ready($pdo),'Decision, Outcome Memory, and Phase 20 Outcome Learning runtimes are ready.');
$run='p71s4'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('OutcomeOwner');$teammate=$makeUser('OutcomeTeammate');$outsider=$makeUser('OutcomeOutsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,(int)$owner['id'],'Outcome Memory Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,(int)$owner['id'],$teamId,(int)$teammate['id']]);
$agent=research_agent_create($pdo,$owner,['name'=>'Outcome Memory Agent','description'=>'Phase 71 Section 4 fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);
p71s4((bool)$project&&(int)$project['team_id']===$teamId,'Outcome Memory fixture uses a shared Team Research project.');

$url='https://8.8.8.8/'.$run.'/outcome-source';$source=ensure_source($pdo,$url,'Outcome Memory Source');$body='Outcome Memory evidence '.$run;
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,captured_at) VALUES(?,1,?,?,?,?,NOW())")
 ->execute([(int)$source['id'],$url,'Outcome Memory Source',$body,hash('sha256',$body)]);
$sv=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$sv,(int)$source['id']]);
$pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);

$decision=research_decision_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Controlled rollout decision','statement'=>'Proceed with a controlled rollout.',
 'rationale'=>'The evidence supports bounded exposure with explicit review checkpoints.','confidence'=>0.82,
 'refs'=>[['type'=>'source','id'=>$source['public_id'],'role'=>'supports','strength'=>0.9]]
]);
p71s4throws(fn()=>research_decision_record_outcome($pdo,$owner,(string)$decision['public_id'],['actual_summary'=>'Premature outcome.']),'Draft Decision cannot record Outcome Memory.');
$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'proposed');$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted');
$decisionRevision=(int)$decision['current_revision'];$decisionEventCount=count((array)$decision['events']);
p71s4(($decision['status']??'')==='accepted'&&!empty($decision['decided_at']),'Outcome Memory starts from an explicitly accepted Decision.');

$eventsBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_outcome_events')->fetchColumn();
$outcome=research_decision_record_outcome($pdo,$teammate,(string)$decision['public_id'],[
 'idempotency_key'=>'controlled-rollout-window-1','assessment'=>'partial',
 'expected_summary'=>'Controlled rollout should validate demand while keeping support burden within the planned ceiling.',
 'actual_summary'=>'Demand met the target, but support burden exceeded the planned ceiling during the first review window.',
 'variance_summary'=>'Demand matched expectations; support burden was materially worse than expected.',
 'lessons'=>'Keep the controlled rollout, but add support-capacity gating before expansion.',
 'confidence'=>0.88,'follow_up_state'=>'follow_up','observed_at'=>'2026-09-27 16:00:00',
 'refs'=>[['type'=>'source','id'=>$source['public_id'],'role'=>'result']]
]);
p71s4(($outcome['assessment']??'')==='partial'&&($outcome['follow_up_state']??'')==='follow_up','Teammate records explicit partial Outcome Memory with follow-up state.');
p71s4(abs((float)$outcome['confidence']-0.88)<0.0001,'Outcome confidence remains explicit and bounded.');
p71s4(count((array)$outcome['versions'])===1&&($outcome['versions'][0]['revision_number']??0)===1,'Initial Outcome Memory is captured as immutable revision 1.');
p71s4((int)$pdo->query('SELECT COUNT(*) FROM research_outcome_events')->fetchColumn()===$eventsBefore+1,'Decision Outcome Memory creates exactly one Phase 20 Outcome Learning event.');
$q=$pdo->prepare("SELECT source_type,source_public_id,object_type,object_public_id,decision_type,user_id FROM research_outcome_events WHERE id=?");$q->execute([(int)$outcome['outcome_event_id']]);$phase20=$q->fetch();
p71s4(($phase20['source_type']??'')==='research_decision'&&($phase20['object_type']??'')==='decision'&&($phase20['object_public_id']??'')===$decision['public_id'],'Phase 20 event is explicitly linked to the Decision.');
p71s4((int)$phase20['user_id']===(int)$teammate['id'],'Underlying Phase 20 event retains the actual recording user.');

$ownerView=research_decision_outcome_access($pdo,$owner,(string)$outcome['public_id']);
p71s4($ownerView!==null&&($ownerView['actual_summary']??'')===$outcome['actual_summary'],'Decision owner can inspect Team-recorded Outcome Memory through Decision access.');
p71s4(research_outcome_access($pdo,$owner,(string)$outcome['outcome_event_public_id'])===null,'Legacy Phase 20 personal event visibility remains user-scoped.');
p71s4(research_outcome_access($pdo,$teammate,(string)$outcome['outcome_event_public_id'])!==null,'Recording teammate retains normal Phase 20 Outcome Learning access.');

$retry=research_decision_record_outcome($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'controlled-rollout-window-1','assessment'=>'partial',
 'actual_summary'=>'This retry must not create a second team outcome.','observed_at'=>'2026-09-27 16:00:00'
]);
p71s4(($retry['public_id']??'')===$outcome['public_id'],'Decision-scoped idempotency prevents duplicate outcome recording across teammates.');
p71s4((int)$pdo->query('SELECT COUNT(*) FROM research_outcome_events')->fetchColumn()===$eventsBefore+1,'Cross-team retry does not create an orphan Phase 20 outcome event.');

$updated=research_decision_update_outcome($pdo,$owner,(string)$outcome['public_id'],[
 'assessment'=>'mixed','actual_summary'=>'Demand remained strong and support burden improved after mitigation, but expansion risk remains.',
 'variance_summary'=>'Commercial result exceeded the support-adjusted plan; operational risk remains above the original target.',
 'lessons'=>'Support capacity must be treated as a first-class rollout gate.','confidence'=>0.93,'follow_up_state'=>'resolved',
 'reason'=>'Second review window incorporated',
 'refs'=>[['type'=>'source','id'=>$source['public_id'],'role'=>'result']]
]);
p71s4(($updated['assessment']??'')==='mixed'&&($updated['follow_up_state']??'')==='resolved','Outcome Memory can evolve from partial to mixed with an explicit resolved follow-up.');
p71s4(count((array)$updated['versions'])===2&&($updated['versions'][0]['revision_number']??0)===2,'Outcome assessment update creates immutable revision 2.');
p71s4((int)$pdo->query('SELECT COUNT(*) FROM research_outcome_events')->fetchColumn()===$eventsBefore+1,'Outcome assessment revision updates the linked Phase 20 projection instead of creating a duplicate event.');

$decisionAfter=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s4(($decisionAfter['status']??'')==='accepted'&&(int)$decisionAfter['current_revision']===$decisionRevision,'Outcome recording and revision do not mutate accepted Decision status or configuration revision.');
p71s4(count((array)$decisionAfter['events'])>=$decisionEventCount+2,'Decision audit history records Outcome Memory creation and update.');
p71s4(count((array)$decisionAfter['outcomes'])===1,'Decision detail exposes Team-visible Outcome Memory.');
$eventTypes=array_column((array)$decisionAfter['events'],'event_type');
p71s4(in_array('decision_outcome_recorded',$eventTypes,true)&&in_array('decision_outcome_updated',$eventTypes,true),'Decision audit ledger preserves outcome recorded/updated events.');

$summary=research_decision_outcome_summary($pdo,$owner,(string)$decision['public_id']);
p71s4(($summary['total']??0)===1&&($summary['assessments']['mixed']??0)===1,'Outcome summary reflects current Decision outcome assessment.');
p71s4(research_decision_outcome_access($pdo,$outsider,(string)$outcome['public_id'])===null,'Outsider cannot access Decision Outcome Memory.');

$legacy=research_outcome_record($pdo,$owner,[
 'event_type'=>'manual_observation','decision_type'=>'recorded','source_type'=>'manual','source_public_id'=>$pub('legacy'),
 'project_public_id'=>$project['public_id'],'title'=>'Existing operational observation','summary'=>'An existing Phase 20 observation can be attached after the fact.',
 'refs'=>[['type'=>'source','public_id'=>$source['public_id'],'role'=>'source']],'is_manual'=>true,'dedupe_key'=>'legacy-outcome-link-'.$run
]);
$linked=research_decision_record_outcome($pdo,$owner,(string)$decision['public_id'],[
 'outcome_event_id'=>$legacy['public_id'],'idempotency_key'=>'legacy-link','assessment'=>'unresolved',
 'expected_summary'=>'Operational observation may affect the rollout decision.','variance_summary'=>'Still under review.'
]);
p71s4(($linked['outcome_event_public_id']??'')===$legacy['public_id'],'Existing Phase 20 Outcome Learning event can be explicitly attached to a Decision without copying it.');
p71s4((research_decision_outcome_summary($pdo,$owner,(string)$decision['public_id'])['total']??0)===2,'One Decision may accumulate multiple observed outcomes over time.');

echo "Phase 71 Section 4 Outcome Memory database journey passed.\n";
