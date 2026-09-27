<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p71s1(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p71s1throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p71s1(research_decisions_ready($pdo)&&research_outcomes_ready($pdo),'Decision ledger and legacy Outcome Learning runtimes are ready.');
$run='p71s1'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('DecisionOwner');$outsider=$makeUser('DecisionOutsider');
$agent=research_agent_create($pdo,$owner,['name'=>'Decision Ledger Agent','description'=>'Phase 71 Section 1 fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);
$mission=research_mission_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'title'=>'Decision Source Mission','research_question'=>'Which path should we choose?','objective'=>'Build evidence for a durable decision.','priority'=>'high'
]);

$url='https://8.8.8.8/'.$run.'/decision-source';$source=ensure_source($pdo,$url,'Decision Ledger Source');$body='Decision ledger evidence '.$run;
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,captured_at) VALUES(?,1,?,?,?,?,NOW())")
  ->execute([(int)$source['id'],$url,'Decision Ledger Source',$body,hash('sha256',$body)]);
$sv=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$sv,(int)$source['id']]);
$pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);

$outcome=research_outcome_manual($pdo,$owner,['project_id'=>$project['public_id'],'decision_type'=>'recorded','title'=>'Legacy outcome remains separate','summary'=>'Pre-existing Phase 20 outcome event.']);
$outcomesBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_outcome_events')->fetchColumn();

$decision=research_decision_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'mission_id'=>$mission['public_id'],'decision_type'=>'decision','title'=>'Choose governed rollout',
 'statement'=>'Proceed with the governed rollout path.','rationale'=>'The current evidence supports a controlled rollout with explicit review gates.','confidence'=>0.72,
 'assumptions'=>['Demand remains within the observed range.'],'uncertainty'=>['Long-tail operational cost is still estimated.'],
 'alternatives'=>[['title'=>'Delay rollout','reason'=>'Would reduce execution risk but defer learning.']],
 'refs'=>[
   ['type'=>'source','id'=>$source['public_id'],'role'=>'supports','strength'=>0.8,'note'=>'Primary evidence'],
   ['type'=>'mission','id'=>$mission['public_id'],'role'=>'context','note'=>'Originating research Mission']
 ]
]);
p71s1(($decision['status']??'')==='draft'&&($decision['decision_type']??'')==='decision','Decision is created as a durable draft object.');
p71s1(($decision['current_revision']??0)===1&&count((array)$decision['versions'])===1,'Initial Decision configuration is captured as immutable revision 1.');
p71s1(count((array)$decision['refs'])===2,'Initial Decision stores project-scoped evidence lineage.');
p71s1(($decision['source_mission_public_id']??'')===$mission['public_id'],'Decision can retain an explicit source Mission without converting the Mission itself.');
p71s1(($decision['accountable_user_public_id']??'')===$owner['public_id'],'Decision records an accountable owner.');
p71s1((int)$pdo->query('SELECT COUNT(*) FROM research_outcome_events')->fetchColumn()===$outcomesBefore,'Creating a Decision does not fabricate or rewrite Outcome Learning events.');

$updated=research_decision_update($pdo,$owner,(string)$decision['public_id'],[
 'rationale'=>'The evidence supports a controlled rollout; monitoring and explicit review gates constrain remaining uncertainty.',
 'confidence'=>0.81,'alternatives'=>[['title'=>'Delay rollout','reason'=>'Rejected for now because the evidence supports bounded execution.']],
 'reason'=>'Clarified rationale and confidence'
]);
p71s1((int)$updated['current_revision']===2&&count((array)$updated['versions'])===2,'Decision configuration update creates immutable revision 2.');
p71s1(abs((float)$updated['confidence']-0.81)<0.0001,'Explicit Decision confidence is stored without being inferred.');

$added=research_decision_add_ref($pdo,$owner,(string)$decision['public_id'],[
 'type'=>'source','id'=>$source['public_id'],'role'=>'contradicts','strength'=>0.25,'note'=>'A narrow slice of the same source cuts against the preferred path.'
]);
$afterAdd=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s1((int)$afterAdd['current_revision']===3&&count((array)$afterAdd['refs'])===3,'Adding evidence lineage creates immutable revision 3.');
$afterRemove=research_decision_remove_ref($pdo,$owner,(string)$decision['public_id'],(string)$added['public_id']);
p71s1((int)$afterRemove['current_revision']===4&&count((array)$afterRemove['refs'])===2,'Removing evidence lineage creates immutable revision 4.');

$proposed=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'proposed');
$accepted=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'accepted');
p71s1(($accepted['status']??'')==='accepted'&&!empty($accepted['decided_at']),'Decision lifecycle records an explicit accepted disposition.');
p71s1((int)$accepted['current_revision']===4,'Status changes do not rewrite Decision configuration revisions.');
$eventTypes=array_column((array)$accepted['events'],'event_type');
p71s1(in_array('decision_created',$eventTypes,true)&&in_array('decision_updated',$eventTypes,true)&&in_array('decision_reference_added',$eventTypes,true)&&in_array('decision_status_changed',$eventTypes,true),'Decision lifecycle and lineage changes are append-only audit events.');

$reopened=research_decision_set_status($pdo,$owner,(string)$decision['public_id'],'reopened');
p71s1(($reopened['status']??'')==='reopened'&&empty($reopened['decided_at']),'Accepted Decision can be reopened without deleting prior history.');

$noRationale=research_decision_create($pdo,$owner,['agent_id'=>$agent['public_id'],'decision_type'=>'conclusion','title'=>'Unready conclusion','statement'=>'Evidence is not yet sufficient.']);
p71s1throws(fn()=>research_decision_set_status($pdo,$owner,(string)$noRationale['public_id'],'accepted'),'Accepted/rejected/deferred dispositions require a rationale.');
p71s1throws(fn()=>research_decision_create($pdo,$owner,['agent_id'=>$agent['public_id'],'title'=>'Bad confidence','statement'=>'Invalid confidence test.','confidence'=>1.5]),'Decision confidence outside 0–1 is rejected.');

$otherAgent=research_agent_create($pdo,$owner,['name'=>'Other Decision Agent','cadence'=>'manual','timezone_name'=>'UTC']);
$otherMission=research_mission_create($pdo,$owner,['agent_id'=>$otherAgent['public_id'],'title'=>'Other Mission','research_question'=>'Other project question?','objective'=>'Other project objective.']);
p71s1throws(fn()=>research_decision_add_ref($pdo,$owner,(string)$decision['public_id'],['type'=>'mission','id'=>$otherMission['public_id'],'role'=>'context']),'Decision rejects cross-project evidence lineage.');
p71s1(research_decision_access($pdo,$outsider,(string)$decision['public_id'])===null,'Outsider cannot access another user Research Decision.');

$list=research_decision_list($pdo,$owner,(string)$agent['public_id'],100);$summary=research_decision_summary($pdo,$owner,(string)$agent['public_id']);
p71s1(count($list)===2&&($summary['total']??0)===2,'Decision list and summary are scoped to the selected Research Agent.');
p71s1(($summary['types']['decision']??0)===1&&($summary['types']['conclusion']??0)===1,'Decision summary distinguishes Decisions from Conclusions.');
p71s1((int)$pdo->query('SELECT COUNT(*) FROM research_outcome_events')->fetchColumn()===$outcomesBefore,'Decision lifecycle remains separate from legacy Outcome Learning event history in Section 1.');

echo "Phase 71 Section 1 Decision & Conclusion Ledger database journey passed.\n";
