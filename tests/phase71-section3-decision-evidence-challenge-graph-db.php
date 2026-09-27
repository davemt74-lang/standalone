<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
function p71s3(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p71s3throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p71s3(research_decisions_ready($pdo)&&research_decision_challenges_ready($pdo),'Decision Ledger and Challenge Graph runtimes are ready.');
$run='p71s3'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test']);
    $id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('DecisionGraphOwner');
$agent=research_agent_create($pdo,$owner,['name'=>'Decision Graph Agent','description'=>'Phase 71 Section 3 fixture','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);

$sources=[];
foreach([1,2] as $n){$url='https://8.8.8.8/'.$run.'/decision-graph-source-'.$n;$source=ensure_source($pdo,$url,'Decision Graph Source '.$n);$body='Decision challenge evidence '.$n.' '.$run;
  $pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,captured_at) VALUES(?,1,?,?,?,?,NOW())")->execute([(int)$source['id'],$url,'Decision Graph Source '.$n,$body,hash('sha256',$body)]);
  $sv=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$sv,(int)$source['id']]);
  $pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);$sources[]=$source;
}

$decision=research_decision_create($pdo,$owner,[
 'agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Choose launch sequence','statement'=>'Launch to the controlled cohort first.',
 'rationale'=>'The current evidence supports staged exposure with explicit review before expansion.','confidence'=>0.74,
 'assumptions'=>['Observed cohort behavior will generalize sufficiently for the first stage.'],
 'uncertainty'=>['Long-tail support demand remains uncertain.'],
 'alternatives'=>[['title'=>'Full launch','reason'=>'Faster learning but materially higher exposure.']],
 'refs'=>[
   ['type'=>'source','id'=>$sources[0]['public_id'],'role'=>'supports','strength'=>0.85,'note'=>'Primary supporting evidence'],
   ['type'=>'source','id'=>$sources[1]['public_id'],'role'=>'contradicts','strength'=>0.35,'note'=>'Evidence of higher support burden']
 ]
]);
p71s3((int)$decision['current_revision']===1,'Decision begins at immutable revision 1 with two-sided evidence lineage.');

$challenge=research_decision_add_challenge($pdo,$owner,(string)$decision['public_id'],[
 'challenge_type'=>'contradiction','title'=>'Support burden may erase rollout benefit','detail'=>'The second source indicates a materially higher support burden than the preferred path assumes.','severity'=>'high',
 'refs'=>[
   ['type'=>'source','id'=>$sources[1]['public_id'],'role'=>'supports_challenge','strength'=>0.8,'note'=>'Direct contradictory evidence'],
   ['type'=>'source','id'=>$sources[0]['public_id'],'role'=>'counters_challenge','strength'=>0.45,'note'=>'Evidence that the burden may be bounded']
 ]
]);
$afterChallenge=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s3((int)$afterChallenge['current_revision']===2&&count((array)$afterChallenge['challenges'])===1,'Adding a challenge creates immutable Decision revision 2.');
p71s3(count((array)$challenge['refs'])===2,'Challenge keeps both supporting and counter evidence pointers.');

$reversal=research_decision_add_challenge($pdo,$owner,(string)$decision['public_id'],[
 'challenge_type'=>'reversal_condition','title'=>'Support incident rate exceeds rollout ceiling',
 'detail'=>'Reconsider the staged launch if verified support incidents exceed the agreed ceiling for two consecutive review windows.','severity'=>'critical'
]);
$afterReversal=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s3((int)$afterReversal['current_revision']===3,'Adding a reversal condition creates immutable Decision revision 3.');
p71s3(($afterReversal['status']??'')==='draft','Adding a reversal condition does not mutate Decision status.');

$updated=research_decision_update_challenge($pdo,$owner,(string)$challenge['public_id'],['severity'=>'critical','detail'=>'The contradictory evidence indicates support burden could erase expected value unless mitigated.']);
$afterUpdate=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s3((int)$afterUpdate['current_revision']===4&&($updated['severity']??'')==='critical','Editing challenge severity/detail creates immutable Decision revision 4.');

p71s3throws(fn()=>research_decision_set_challenge_status($pdo,$owner,(string)$challenge['public_id'],'resolved',''),'Closing a challenge requires an explicit resolution note.');
$resolved=research_decision_set_challenge_status($pdo,$owner,(string)$challenge['public_id'],'resolved','Mitigation capacity was verified and the support ceiling was added to rollout controls.');
$afterResolved=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s3((int)$afterResolved['current_revision']===5&&($resolved['status']??'')==='resolved','Resolving a challenge creates immutable Decision revision 5.');
p71s3(($afterResolved['status']??'')==='draft','Resolving a challenge does not automatically accept or reject the Decision.');

$reopened=research_decision_set_challenge_status($pdo,$owner,(string)$challenge['public_id'],'open','');
$afterReopen=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s3((int)$afterReopen['current_revision']===6&&($reopened['status']??'')==='open','Reopening a challenge preserves history and creates immutable Decision revision 6.');

$extraRef=research_decision_add_challenge_ref($pdo,$owner,(string)$reversal['public_id'],[
 'type'=>'source','id'=>$sources[1]['public_id'],'role'=>'source','strength'=>0.7,'note'=>'Source used to evaluate the reversal condition.'
]);
$afterRef=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s3((int)$afterRef['current_revision']===7,'Adding challenge evidence creates immutable Decision revision 7.');
research_decision_remove_challenge_ref($pdo,$owner,(string)$reversal['public_id'],(string)$extraRef['public_id']);
$afterRemove=research_decision_detail($pdo,$owner,(string)$decision['public_id']);
p71s3((int)$afterRemove['current_revision']===8,'Removing challenge evidence creates immutable Decision revision 8.');

$graph=research_decision_evidence_graph($pdo,$owner,(string)$decision['public_id']);
p71s3(($graph['revision']??0)===8,'Evidence graph identifies the exact Decision revision.');
p71s3(($graph['counts']['supports']??0)===1&&($graph['counts']['contradicts']??0)===1,'Evidence graph counts supporting and contradicting Decision references.');
p71s3(($graph['counts']['open_challenges']??0)===2&&($graph['counts']['high_open_challenges']??0)===2,'Evidence graph exposes open high-severity challenge load without scoring the Decision.');
p71s3(($graph['counts']['reversal_conditions']??0)===1,'Evidence graph exposes explicit what-would-change-this-decision conditions.');
$kinds=array_count_values(array_map(fn($n)=>(string)($n['kind']??''),(array)$graph['nodes']));
p71s3(($kinds['decision']??0)===1&&($kinds['challenge']??0)===2&&($kinds['assumption']??0)===1&&($kinds['uncertainty']??0)===1&&($kinds['alternative']??0)===1,'Graph contains Decision, challenge, assumption, uncertainty, and alternative nodes.');

$otherAgent=research_agent_create($pdo,$owner,['name'=>'Other Graph Agent','cadence'=>'manual','timezone_name'=>'UTC']);$otherProject=research_agent_workspace_project($pdo,$owner,(string)$otherAgent['public_id']);
$otherUrl='https://1.1.1.1/'.$run.'/other-source';$otherSource=ensure_source($pdo,$otherUrl,'Other Project Source');$pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$otherProject['id'],(int)$otherSource['id'],(int)$owner['id']]);
p71s3throws(fn()=>research_decision_add_challenge_ref($pdo,$owner,(string)$challenge['public_id'],['type'=>'source','id'=>$otherSource['public_id'],'role'=>'supports_challenge']),'Challenge graph rejects cross-project evidence pointers.');

$eventTypes=array_column((array)$afterRemove['events'],'event_type');
foreach(['decision_challenge_added','decision_challenge_updated','decision_challenge_status_changed','decision_challenge_reference_added','decision_challenge_reference_removed'] as $type)
  p71s3(in_array($type,$eventTypes,true),'Decision audit history preserves '.$type.'.');

echo "Phase 71 Section 3 Decision Evidence & Challenge Graph database journey passed.\n";
