<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','cognitive-feed','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-reviews'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p71s7(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p71s7throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p71s7(research_decisions_ready($pdo)&&research_reviews_ready($pdo)&&research_decision_reconsiderations_ready($pdo),'Decision Memory and Collaborative Review runtimes are ready.');
$run='p71s7'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('DecisionReviewOwner');$reviewer=$makeUser('DecisionReviewer');$outsider=$makeUser('DecisionReviewOutsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,(int)$owner['id'],'Decision Review Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,(int)$owner['id'],$teamId,(int)$reviewer['id']]);
$agent=research_agent_create($pdo,$owner,['name'=>'Decision Review Agent','description'=>'Phase 71 Section 7 fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);

$url='https://8.8.8.8/'.$run.'/review-source';$source=ensure_source($pdo,$url,'Decision Review Source');$body='Decision review evidence '.$run;
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,captured_at) VALUES(?,1,?,?,?,?,NOW())")
 ->execute([(int)$source['id'],$url,'Decision Review Source',$body,hash('sha256',$body)]);
$sv=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$sv,(int)$source['id']]);
$pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);

$decision=research_decision_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Team-reviewed rollout','statement'=>'Proceed with a controlled rollout.',
 'rationale'=>'The evidence supports a bounded rollout with an explicit review gate.','confidence'=>0.81,
 'refs'=>[['type'=>'source','id'=>$source['public_id'],'role'=>'supports','strength'=>0.9]]
]);
$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'proposed');
$hashBefore=research_decision_review_state_hash($pdo,$owner,(string)$decision['public_id']);
$subject=research_review_subject($pdo,$owner,'decision',(string)$decision['public_id']);
p71s7($subject!==null&&($subject['hash']??'')===$hashBefore&&str_contains((string)$subject['version_label'],'Decision revision'),'Decision is a native stale-aware Review Center subject.');

$review=research_review_create($pdo,$owner,'decision',(string)$decision['public_id'],[(int)$reviewer['id']],null,'Review the evidence and rationale before disposition.');
$reviewForReviewer=research_review_access($pdo,$reviewer,(string)$review['public_id']);
p71s7($reviewForReviewer!==null&&!$reviewForReviewer['is_stale'],'Assigned teammate can access the exact pinned Decision review.');
$review=research_review_respond($pdo,$reviewer,(string)$review['public_id'],'approve','Evidence and rationale support the proposed bounded rollout.');
$agg=research_review_aggregate($pdo,$review);
p71s7(($agg['consensus']??'')==='unanimous_approval'&&($agg['counts']['approve']??0)===1,'Team reviewer response produces explicit unanimous approval consensus.');
$review=research_review_complete($pdo,$owner,(string)$review['public_id']);
$decisionAfterReview=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s7(($review['status']??'')==='completed'&&($decisionAfterReview['status']??'')==='proposed','Completing Team Review never auto-accepts the proposed Decision.');

$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted');
$completedAfterChange=research_review_access($pdo,$owner,(string)$review['public_id']);
p71s7(!empty($completedAfterChange['is_stale']),'Changing Decision disposition makes the prior pinned Team Review stale.');

$currentReview=research_review_create($pdo,$owner,'decision',(string)$decision['public_id'],[(int)$reviewer['id']],null,'Review the accepted Decision against observed outcomes.');
p71s7(!research_review_access($pdo,$owner,(string)$currentReview['public_id'])['is_stale'],'Fresh Decision review begins against current accepted state.');
$outcome=research_decision_record_outcome($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'section7-observed-outcome','assessment'=>'partial','expected_summary'=>'Support burden remains within ceiling.',
 'actual_summary'=>'Support burden exceeded the initial ceiling while demand met target.','variance_summary'=>'Operational burden was worse than expected.',
 'lessons'=>'Support capacity must gate expansion.','confidence'=>0.9,'follow_up_state'=>'follow_up',
 'refs'=>[['type'=>'source','id'=>$source['public_id'],'role'=>'result']]
]);
$staleByOutcome=research_review_access($pdo,$owner,(string)$currentReview['public_id']);
p71s7(!empty($staleByOutcome['is_stale']),'Outcome Memory revision makes an in-flight Decision review stale.');

$challenge=research_decision_add_challenge($pdo,$owner,(string)$decision['public_id'],[
 'challenge_type'=>'reversal_condition','title'=>'Support burden remains above ceiling','detail'=>'Reconsider if burden remains above ceiling for two review windows.','severity'=>'critical',
 'refs'=>[['type'=>'source','id'=>$source['public_id'],'role'=>'supports_challenge','strength'=>0.8]]
]);
$case=research_decision_open_reconsideration($pdo,$owner,(string)$decision['public_id'],[
 'trigger_type'=>'reversal_condition','trigger_public_id'=>$challenge['public_id'],'title'=>'Review rollout after ceiling breach',
 'reason'=>'The explicit reversal condition is now material.','materiality'=>'critical'
]);
$caseSubject=research_review_subject($pdo,$owner,'decision_reconsideration',(string)$case['public_id']);
p71s7($caseSubject!==null&&($caseSubject['hash']??'')===research_decision_reconsideration_review_state_hash($pdo,$owner,(string)$case['public_id']),'Decision reconsideration is a native stale-aware Team Review subject.');
$caseReview=research_review_create($pdo,$owner,'decision_reconsideration',(string)$case['public_id'],[(int)$reviewer['id']],null,'Review whether this reconsideration should proceed.');
$caseReview=research_review_respond($pdo,$reviewer,(string)$caseReview['public_id'],'request_changes','Clarify the support ceiling before resolving this reconsideration.');
$caseReview=research_review_complete($pdo,$owner,(string)$caseReview['public_id']);
$caseAfterReview=research_decision_reconsideration_access($pdo,$owner,(string)$case['public_id']);
p71s7(($caseReview['status']??'')==='completed'&&($caseAfterReview['status']??'')==='open','Completing reconsideration Team Review never resolves or applies the reconsideration.');
$caseAfterReview=research_decision_set_reconsideration_status($pdo,$owner,(string)$case['public_id'],'reviewing',['recommended_action'=>'reopen']);
$caseReviewAfterChange=research_review_access($pdo,$owner,(string)$caseReview['public_id']);
p71s7(!empty($caseReviewAfterChange['is_stale']),'Changing reconsideration state makes its prior pinned Team Review stale.');

$beforeStatus=(string)$decision['status'];$beforeRevision=(int)$decision['current_revision'];
$center=research_decision_command_center($pdo,$owner,'attention',200);
$ids=array_column((array)$center['decisions'],'public_id');
p71s7(in_array($decision['public_id'],$ids,true),'Decision Command Center attention view includes the reviewed Decision with active critical review state.');
p71s7(($center['stats']['open_reviews']??0)>=1&&($center['stats']['attention']??0)>=1,'Command Center aggregates Team Review and attention metrics.');
$entry=null;foreach($center['decisions'] as $row)if($row['public_id']===$decision['public_id']){$entry=$row;break;}
p71s7($entry!==null&&!empty($entry['command']['review']['latest'])&&!empty($entry['command']['review_url']),'Command Center exposes latest Team Review state and direct review action.');
$afterCenter=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s7((string)$afterCenter['status']===$beforeStatus&&(int)$afterCenter['current_revision']===$beforeRevision,'Command Center generation is side-effect free.');
p71s7(research_review_subject($pdo,$outsider,'decision',(string)$decision['public_id'])===null,'Outsider cannot create review context for an inaccessible Decision.');

echo "Phase 71 Section 7 Decision Command Center + Team Review database journey passed.\n";
