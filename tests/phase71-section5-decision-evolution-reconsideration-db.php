<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p71s5(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p71s5throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p71s5(research_decisions_ready($pdo)&&research_decision_outcomes_ready($pdo)&&research_decision_reconsiderations_ready($pdo),'Decision, Outcome Memory, and Reconsideration runtimes are ready.');
$run='p71s5'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('ReconsiderOwner');$outsider=$makeUser('ReconsiderOutsider');
$agent=research_agent_create($pdo,$owner,['name'=>'Reconsideration Agent','description'=>'Phase 71 Section 5 fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);

$sources=[];
foreach([1,2] as $n){$url='https://8.8.8.8/'.$run.'/reconsider-source-'.$n;$source=ensure_source($pdo,$url,'Reconsider Source '.$n);$body='Reconsider evidence '.$n.' '.$run;
  $pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,captured_at) VALUES(?,1,?,?,?,?,NOW())")->execute([(int)$source['id'],$url,'Reconsider Source '.$n,$body,hash('sha256',$body)]);
  $sv=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$sv,(int)$source['id']]);
  $pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);$sources[]=$source;
}

$draft=research_decision_create($pdo,$owner,['agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Draft reconsideration guard','statement'=>'Draft decisions are not reconsidered.','rationale'=>'Still being formed.']);
p71s5throws(fn()=>research_decision_open_reconsideration($pdo,$owner,(string)$draft['public_id'],['reason'=>'Too early.']),'Draft Decision cannot enter reconsideration.');

$decision=research_decision_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Controlled expansion decision','statement'=>'Expand only through a controlled cohort.',
 'rationale'=>'The supporting evidence justifies bounded expansion while preserving a review gate.','confidence'=>0.80,
 'assumptions'=>['Support capacity remains within the operating ceiling.'],
 'uncertainty'=>['Long-tail incident frequency is not fully known.'],
 'refs'=>[
   ['type'=>'source','id'=>$sources[0]['public_id'],'role'=>'supports','strength'=>0.9,'note'=>'Primary supporting evidence'],
   ['type'=>'source','id'=>$sources[1]['public_id'],'role'=>'contradicts','strength'=>0.72,'note'=>'Evidence of elevated incident burden']
 ]
]);
$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'proposed');$decision=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted');
$reversal=research_decision_add_challenge($pdo,$owner,(string)$decision['public_id'],[
 'challenge_type'=>'reversal_condition','title'=>'Incident rate exceeds operating ceiling',
 'detail'=>'Reconsider the expansion if verified incidents remain above the operating ceiling for two review windows.','severity'=>'critical',
 'refs'=>[['type'=>'source','id'=>$sources[1]['public_id'],'role'=>'supports_challenge','strength'=>0.8]]
]);
$outcome=research_decision_record_outcome($pdo,$owner,(string)$decision['public_id'],[
 'idempotency_key'=>'reconsider-window-1','assessment'=>'partial',
 'expected_summary'=>'Demand should increase while support burden remains below the ceiling.',
 'actual_summary'=>'Demand increased, but support burden exceeded the ceiling in the first review window.',
 'variance_summary'=>'Demand matched plan; operational burden was worse than expected.',
 'lessons'=>'Expansion gates need direct support-capacity checks.','confidence'=>0.91,'follow_up_state'=>'follow_up',
 'refs'=>[['type'=>'source','id'=>$sources[1]['public_id'],'role'=>'result']]
]);
$decision=research_decision_detail($pdo,$owner,(string)$decision['public_id']);$revisionAtReview=(int)$decision['current_revision'];
p71s5(($decision['status']??'')==='accepted','Challenge and Outcome Memory do not alter the accepted Decision.');

$q=$pdo->prepare('SELECT COUNT(*) FROM research_decision_reconsiderations WHERE decision_id=?');$q->execute([(int)$decision['id']]);p71s5((int)$q->fetchColumn()===0,'Signals do not auto-open reconsideration cases.');
$signals=research_decision_reconsideration_signals($pdo,$owner,(string)$decision['public_id']);
$signalTypes=array_column((array)$signals['signals'],'trigger_type');
p71s5(in_array('evidence_change',$signalTypes,true)&&in_array('reversal_condition',$signalTypes,true)&&in_array('outcome',$signalTypes,true),'Signals surface contradicting evidence, reversal conditions, and observed outcome drift.');
p71s5(($signals['counts']['critical']??0)>=1,'Critical reversal condition is surfaced without hidden scoring.');
$afterSignals=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s5(($afterSignals['status']??'')==='accepted'&&(int)$afterSignals['current_revision']===$revisionAtReview,'Reading reconsideration signals is side-effect free.');
p71s5throws(fn()=>research_decision_open_reconsideration($pdo,$owner,(string)$decision['public_id'],[
 'trigger_type'=>'reversal_condition','trigger_public_id'=>'not-current','reason'=>'Invalid signal test.'
]),'Non-manual reconsideration rejects a stale or invented signal.');

$case=research_decision_open_reconsideration($pdo,$owner,(string)$decision['public_id'],[
 'trigger_type'=>'reversal_condition','trigger_public_id'=>$reversal['public_id'],'title'=>'Review controlled expansion after incident threshold',
 'reason'=>'The explicit reversal condition is now material to the Decision review.','materiality'=>'critical'
]);
p71s5(($case['status']??'')==='open'&&($case['decision_status_opened']??'')==='accepted','Explicit reconsideration opens without changing the Decision.');
p71s5((int)$case['decision_revision_opened']===$revisionAtReview&&!empty($case['opening_context_hash']),'Reconsideration freezes exact Decision revision and canonical context hash.');
$snap=(array)$case['opening_snapshot'];
p71s5(($snap['decision_status']??'')==='accepted'&&($snap['graph_counts']['reversal_conditions']??0)>=1&&($snap['outcome_summary']['total']??0)>=1,'Opening snapshot freezes Decision, challenge graph, and Outcome Memory state.');

$duplicate=research_decision_open_reconsideration($pdo,$owner,(string)$decision['public_id'],[
 'trigger_type'=>'reversal_condition','trigger_public_id'=>$reversal['public_id'],'title'=>'Review controlled expansion after incident threshold',
 'reason'=>'Duplicate active signal click.','materiality'=>'critical'
]);
p71s5(($duplicate['public_id']??'')===$case['public_id'],'Duplicate active signal/context reuses the existing reconsideration case.');

$case=research_decision_set_reconsideration_status($pdo,$owner,(string)$case['public_id'],'reviewing',['recommended_action'=>'reopen']);
p71s5(($case['status']??'')==='reviewing'&&empty($case['resolved_at']),'Case can enter explicit Reviewing state.');
p71s5throws(fn()=>research_decision_set_reconsideration_status($pdo,$owner,(string)$case['public_id'],'resolved',['recommended_action'=>'reopen','resolution'=>'']),'Resolved reconsideration requires an explicit resolution.');
$case=research_decision_set_reconsideration_status($pdo,$owner,(string)$case['public_id'],'resolved',[
 'recommended_action'=>'reopen','resolution'=>'The threshold breach warrants reopening the Decision for a new evidence cycle.'
]);
$beforeApply=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s5(($beforeApply['status']??'')==='accepted','Resolving a reconsideration does not change Decision disposition.');

$applied=research_decision_apply_reconsideration($pdo,$owner,(string)$case['public_id']);
p71s5(($applied['decision']['status']??'')==='reopened'&&!empty($applied['reconsideration']['applied_at']),'Only explicit Apply enacts the resolved reopen recommendation.');
$eventCount=count((array)$applied['decision']['events']);
$retry=research_decision_apply_reconsideration($pdo,$owner,(string)$case['public_id']);
p71s5(($retry['decision']['status']??'')==='reopened'&&count((array)$retry['decision']['events'])===$eventCount,'Reconsideration Apply is retry-safe and does not duplicate Decision status events.');
p71s5throws(fn()=>research_decision_set_reconsideration_status($pdo,$owner,(string)$case['public_id'],'open',[]),'Applied reconsideration becomes immutable.');

$timeline=research_decision_evolution_timeline($pdo,$owner,(string)$decision['public_id'],300);$kinds=array_count_values(array_column((array)$timeline['items'],'kind'));
p71s5(($timeline['current_status']??'')==='reopened'&&($kinds['decision_revision']??0)>=1&&($kinds['decision_event']??0)>=1&&($kinds['decision_outcome']??0)>=1&&($kinds['reconsideration']??0)>=1,'Evolution timeline composes Decision revisions, events, outcomes, and reconsiderations.');
p71s5(research_decision_reconsideration_access($pdo,$outsider,(string)$case['public_id'])===null,'Outsider cannot access another Research Decision reconsideration.');

$stale=research_decision_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Stale review guard','statement'=>'Maintain current launch policy.',
 'rationale'=>'Evidence currently supports the policy.','refs'=>[['type'=>'source','id'=>$sources[0]['public_id'],'role'=>'supports','strength'=>0.8]]
]);
$stale=research_decision_set_status($pdo,$owner,(string)$stale['public_id'],'proposed');$stale=research_decision_set_status($pdo,$owner,(string)$stale['public_id'],'accepted');
$staleCase=research_decision_open_reconsideration($pdo,$owner,(string)$stale['public_id'],['trigger_type'=>'manual','title'=>'Manual stale review','reason'=>'Human requested a review before new evidence arrived.','materiality'=>'medium']);
$staleCase=research_decision_set_reconsideration_status($pdo,$owner,(string)$staleCase['public_id'],'resolved',['recommended_action'=>'reopen','resolution'=>'Review indicates reopening if current context remains unchanged.']);
research_decision_add_challenge($pdo,$owner,(string)$stale['public_id'],['challenge_type'=>'question','title'=>'New evidence arrived after review','detail'=>'This changes the Decision context after the reconsideration snapshot.','severity'=>'high']);
p71s5throws(fn()=>research_decision_apply_reconsideration($pdo,$owner,(string)$staleCase['public_id']),'Stale reconsideration cannot apply after Decision revision changes.');
$staleAfter=research_decision_detail($pdo,$owner,(string)$stale['public_id']);
p71s5(($staleAfter['status']??'')==='accepted','Blocked stale Apply leaves Decision status untouched.');

echo "Phase 71 Section 5 Decision Evolution & Reconsideration database journey passed.\n";
