<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','cognitive-feed','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-reviews'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p71s8(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p71s8throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p71s8(research_decisions_ready($pdo)&&research_decision_handoffs_ready($pdo)&&research_decision_challenges_ready($pdo)&&research_decision_outcomes_ready($pdo)&&research_decision_reconsiderations_ready($pdo)&&research_reviews_ready($pdo),'Full Phase 71 runtime is ready.');
$run='p71s8'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('Phase71ReleaseOwner');$reviewer=$makeUser('Phase71ReleaseReviewer');$outsider=$makeUser('Phase71ReleaseOutsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,(int)$owner['id'],'Phase 71 Release Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,(int)$owner['id'],$teamId,(int)$reviewer['id']]);

$agent=research_agent_create($pdo,$owner,['name'=>'Phase 71 Release Agent','description'=>'Final Decision Memory acceptance fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);
p71s8((bool)$project&&(int)$project['team_id']===$teamId,'Final journey starts with a Team-scoped Research Agent and project.');

$sources=[];
foreach([1,2] as $n){$url='https://8.8.8.8/'.$run.'/phase71-source-'.$n;$source=ensure_source($pdo,$url,'Phase 71 Release Source '.$n);$body='Phase 71 release evidence '.$n.' '.$run;
  $pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,captured_at) VALUES(?,1,?,?,?,?,NOW())")->execute([(int)$source['id'],$url,'Phase 71 Release Source '.$n,$body,hash('sha256',$body)]);
  $sv=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$sv,(int)$source['id']]);
  $pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);$sources[]=$source;
}
p71s8(count($sources)===2,'Final journey has accessible two-sided evidence.');

$mission=research_mission_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'title'=>'Phase 71 Release Mission','research_question'=>'Should the controlled rollout proceed?',
 'objective'=>'Produce a cited synthesis that can be handed to durable Decision Memory.','success_definition'=>'A reviewable synthesis exists with explicit evidence lineage.','priority'=>'high',
 'success_criteria'=>[['label'=>'Primary rollout question answered']],
 'subquestions'=>[['question'=>'What does the current rollout evidence show?','priority'=>'high']]
]);
$mission=research_mission_create_plan($pdo,$owner,(string)$mission['public_id'],[]);
$mission=research_mission_start($pdo,$owner,(string)$mission['public_id']);
foreach((array)$mission['plan']['tasks'] as $idx=>$task){
    research_task_store_refs($pdo,(int)$task['id'],(int)$project['id'],[
      ['type'=>'source','id'=>(string)$sources[0]['public_id'],'locator'=>'Release evidence A','relationship'=>'primary'],
      ['type'=>'source','id'=>(string)$sources[1]['public_id'],'locator'=>'Release evidence B','relationship'=>'supports']
    ]);
    $summary=$idx===0?'Current evidence supports a bounded rollout with an explicit support ceiling.':'The synthesis supports proceeding only with a reversible controlled rollout.';
    $pdo->prepare("UPDATE research_tasks SET execution_summary=?,status='review',blocking_reason=NULL,updated_at=NOW() WHERE id=?")->execute([$summary,(int)$task['id']]);
    research_task_review($pdo,$owner,(string)$task['public_id'],true);
}
$mission=research_mission_detail($pdo,$owner,(string)$mission['public_id']);
p71s8(($mission['status']??'')==='review'&&($mission['plan']['status']??'')==='completed'&&!empty($mission['progress']['primary_answer']['summary']),'Mission reaches review with a durable synthesis before Decision handoff.');

$decision=research_decision_from_mission($pdo,$owner,(string)$mission['public_id'],[
 'idempotency_key'=>'phase71-release-primary','decision_type'=>'decision','title'=>'Controlled rollout decision',
 'statement'=>'Proceed with a controlled rollout and explicit support-capacity review gates.',
 'rationale'=>'Mission evidence supports bounded exposure while preserving a reversal path.','confidence'=>0.83,
 'alternatives'=>[['title'=>'Delay rollout','reason'=>'Reduces exposure but delays learning.']]
]);
p71s8(($decision['status']??'')==='proposed'&&($decision['source_mission_public_id']??'')===$mission['public_id'],'Mission handoff creates a Proposed Decision with immutable Mission lineage.');
$retry=research_decision_from_mission($pdo,$owner,(string)$mission['public_id'],[
 'idempotency_key'=>'phase71-release-primary','decision_type'=>'decision','title'=>'Controlled rollout decision',
 'statement'=>'Proceed with a controlled rollout and explicit support-capacity review gates.'
]);
p71s8(($retry['public_id']??'')===$decision['public_id'],'Mission-to-Decision handoff remains idempotent.');

$decisionReview=research_review_create($pdo,$owner,'decision',(string)$decision['public_id'],[(int)$reviewer['id']],null,'Review the proposed Decision before disposition.');
research_review_respond($pdo,$reviewer,(string)$decisionReview['public_id'],'approve','The evidence supports the bounded rollout and explicit review gate.');
$decisionReview=research_review_complete($pdo,$owner,(string)$decisionReview['public_id']);
$decisionAfterReview=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s8(($decisionReview['status']??'')==='completed'&&($decisionAfterReview['status']??'')==='proposed','Team approval remains advisory; review completion does not accept the Decision.');

$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted');
p71s8(($decision['status']??'')==='accepted'&&!empty($decision['decided_at']),'Human explicitly records the accepted Decision disposition.');
$oldReview=research_review_access($pdo,$owner,(string)$decisionReview['public_id']);
p71s8(!empty($oldReview['is_stale']),'Disposition change makes the earlier pinned Team Review stale.');

$reversal=research_decision_add_challenge($pdo,$owner,(string)$decision['public_id'],[
 'challenge_type'=>'reversal_condition','title'=>'Support burden exceeds operating ceiling',
 'detail'=>'Reconsider if verified support incidents remain above the operating ceiling for two review windows.','severity'=>'critical',
 'refs'=>[['type'=>'source','id'=>$sources[1]['public_id'],'role'=>'supports_challenge','strength'=>0.85]]
]);
$outcome=research_decision_record_outcome($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'phase71-release-window-1','assessment'=>'partial',
 'expected_summary'=>'Demand increases while support burden remains within the operating ceiling.',
 'actual_summary'=>'Demand met target, but support burden exceeded the operating ceiling in the first review window.',
 'variance_summary'=>'Commercial performance matched plan while operational burden was materially worse.',
 'lessons'=>'Support capacity must be an explicit expansion gate.','confidence'=>0.94,'follow_up_state'=>'follow_up',
 'refs'=>[['type'=>'source','id'=>$sources[1]['public_id'],'role'=>'result']]
]);
p71s8(($outcome['assessment']??'')==='partial'&&($outcome['follow_up_state']??'')==='follow_up','Outcome Memory records expected-vs-actual drift without mutating the accepted Decision.');

$case=research_decision_open_reconsideration($pdo,$owner,(string)$decision['public_id'],[
 'trigger_type'=>'reversal_condition','trigger_public_id'=>$reversal['public_id'],'title'=>'Reconsider rollout after support ceiling breach',
 'reason'=>'The explicit reversal condition is now material to the rollout Decision.','materiality'=>'critical'
]);
$decisionBeforeProjection=research_decision_detail($pdo,$owner,(string)$decision['public_id']);$projectionStatus=(string)$decisionBeforeProjection['status'];$projectionRevision=(int)$decisionBeforeProjection['current_revision'];

$agentContext=research_decision_agent_context($pdo,$owner,(string)$agent['public_id'],8);
p71s8(str_contains((string)$agentContext['text'],'Controlled rollout decision')&&str_contains((string)$agentContext['text'],'Latest observed outcome: PARTIAL'),'Agent context explains durable Decision and Outcome Memory state.');
$feed=[];research_decision_cognitive_observations($pdo,$owner,$feed,20);
$decisionCards=array_values(array_filter($feed,fn($item)=>(string)($item['type']??'')==='decision_reconsideration'));
p71s8(count($decisionCards)>=1&&($decisionCards[0]['priority']??'')==='critical','Now surfaces the critical Decision reconsideration as read-only attention.');
$private=research_report_build_snapshot($pdo,$project,'private','Phase 71 Private Release Report','Final private Decision snapshot',$owner);
$public=research_report_build_snapshot($pdo,$project,'public','Phase 71 Public Release Report','Final public Decision snapshot',$owner);
$privateIds=array_column((array)$private['decisions'],'id');$publicIds=array_column((array)$public['decisions'],'id');
p71s8(in_array($decision['public_id'],$privateIds,true)&&in_array($decision['public_id'],$publicIds,true),'Private and public report projections include the currently Accepted Decision.');
$privateDecision=null;$publicDecision=null;foreach($private['decisions'] as $row)if($row['id']===$decision['public_id'])$privateDecision=$row;foreach($public['decisions'] as $row)if($row['id']===$decision['public_id'])$publicDecision=$row;
p71s8($privateDecision!==null&&!empty($privateDecision['reconsiderations'])&&!empty($privateDecision['challenges']),'Private report retains internal challenge and reconsideration detail.');
p71s8($publicDecision!==null&&!array_key_exists('reconsiderations',$publicDecision)&&!array_key_exists('challenges',$publicDecision),'Public report excludes internal reconsideration/challenge state.');

$center=research_decision_command_center($pdo,$owner,'attention',200);$centerIds=array_column((array)$center['decisions'],'public_id');
p71s8(in_array($decision['public_id'],$centerIds,true)&&($center['stats']['attention']??0)>=1,'Decision Command Center surfaces the active critical reconsideration.');
$afterProjection=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s8((string)$afterProjection['status']===$projectionStatus&&(int)$afterProjection['current_revision']===$projectionRevision,'Agent, Now, Report Studio, and Command Center projections are side-effect free.');

$caseReview=research_review_create($pdo,$owner,'decision_reconsideration',(string)$case['public_id'],[(int)$reviewer['id']],null,'Review the critical reconsideration before any Decision change.');
research_review_respond($pdo,$reviewer,(string)$caseReview['public_id'],'request_changes','Clarify the support-capacity gate before reopening the Decision.');
$caseReview=research_review_complete($pdo,$owner,(string)$caseReview['public_id']);
$caseAfterReview=research_decision_reconsideration_access($pdo,$owner,(string)$case['public_id']);
p71s8(($caseReview['status']??'')==='completed'&&($caseAfterReview['status']??'')==='open','Reconsideration Team Review remains advisory and does not resolve/apply the case.');

$case=research_decision_set_reconsideration_status($pdo,$owner,(string)$case['public_id'],'reviewing',['recommended_action'=>'reopen']);
$case=research_decision_set_reconsideration_status($pdo,$owner,(string)$case['public_id'],'resolved',[
 'recommended_action'=>'reopen','resolution'=>'The support ceiling breach warrants reopening the Decision for a new evidence cycle.'
]);
$staleCaseReview=research_review_access($pdo,$owner,(string)$caseReview['public_id']);
p71s8(!empty($staleCaseReview['is_stale']),'Changing reconsideration state makes its pinned Team Review stale.');

$applied=research_decision_apply_reconsideration($pdo,$owner,(string)$case['public_id']);
p71s8(($applied['decision']['status']??'')==='reopened'&&!empty($applied['reconsideration']['applied_at']),'Explicit Apply reopens the Decision and records applied reconsideration state.');
$eventCount=count((array)$applied['decision']['events']);$again=research_decision_apply_reconsideration($pdo,$owner,(string)$case['public_id']);
p71s8(($again['decision']['status']??'')==='reopened'&&count((array)$again['decision']['events'])===$eventCount,'Reconsideration Apply remains retry-safe.');

$publicAfter=research_report_build_snapshot($pdo,$project,'public','Phase 71 Public After Reopen','Reopened Decision public projection',$owner);
p71s8(!in_array($decision['public_id'],array_column((array)$publicAfter['decisions'],'id'),true),'Public report conservatively excludes a reopened Decision.');
$timeline=research_decision_evolution_timeline($pdo,$owner,(string)$decision['public_id'],400);$kinds=array_count_values(array_column((array)$timeline['items'],'kind'));
p71s8(($timeline['current_status']??'')==='reopened'&&($kinds['decision_revision']??0)>=1&&($kinds['decision_outcome']??0)>=1&&($kinds['reconsideration']??0)>=1,'Evolution timeline composes revision, outcome, and reconsideration history after reopen.');
$centerAfter=research_decision_command_center($pdo,$owner,'reopened',200);
p71s8(in_array($decision['public_id'],array_column((array)$centerAfter['decisions'],'public_id'),true),'Decision Command Center Reopened view reflects the explicit reconsideration Apply.');

$q=$pdo->prepare('SELECT COUNT(*) FROM research_decision_handoffs WHERE decision_id=?');$q->execute([(int)$decision['id']]);p71s8((int)$q->fetchColumn()===1,'Final lifecycle retains one authoritative Mission handoff.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_decision_outcomes WHERE decision_id=?');$q->execute([(int)$decision['id']]);p71s8((int)$q->fetchColumn()===1,'Final lifecycle retains one authoritative observed Decision outcome.');

p71s8(research_decision_access($pdo,$outsider,(string)$decision['public_id'])===null,'Outsider cannot access Team Decision Memory.');
$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,(int)$reviewer['id']]);
p71s8(research_decision_access($pdo,$reviewer,(string)$decision['public_id'])===null,'Team revocation immediately removes Decision access.');
p71s8(research_review_access($pdo,$reviewer,(string)$caseReview['public_id'])===null,'Team revocation also removes access to Decision reconsideration review history.');

echo "Phase 71 Section 8 integrated release journey passed: Mission → Decision → Team Review → explicit disposition → Challenge/Outcome → Reconsideration → Agent/Now/Report/Command Center → explicit reopen.\n";
