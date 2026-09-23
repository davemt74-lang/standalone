<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);

foreach(['installer','storage','jobs','functions','access','notifications','rate-limit','conversations','research-automation','research-workspace','research-agents','research-agent-workspace','research-retrieval','workspace-context','object-handoff','agent-chat','research-autonomy','research-monitoring'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p56(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p56throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p56(research_monitor_ready($pdo),'Phase 56 schema is available.');
p56(job_table_meta('research_monitor_jobs')['schedule']==='available_at','Continuous Research Monitoring uses leased jobs.');

$run='p56'.substr(bin2hex(random_bytes(6)),0,10);
$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
    $username=substr(strtolower($name).'_'.$run,0,48);
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'cloaked')")
      ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
    $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};

$owner=$makeUser('MonitorOwner','admin');$outsider=$makeUser('MonitorOutsider');
$agent=research_agent_create($pdo,$owner,['name'=>'Phase 56 Agent','description'=>'Monitor external evidence.','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);
p56((bool)$project,'Research Agent project is available for monitoring.');

$url='https://8.8.8.8/'.$run.'/watched';
$urlWatch=research_monitor_create($pdo,$owner,['agent_id'=>$agent['public_id'],'watch_type'=>'url','target'=>$url,'cadence'=>'daily','alert_level'=>'important','auto_promote'=>false]);
p56(($urlWatch['watch_type']??'')==='url'&&($urlWatch['status']??'')==='active','56.1 creates an Agent-scoped URL watch.');
$q=$pdo->prepare("SELECT COUNT(*) FROM project_sources ps JOIN sources s ON s.id=ps.source_id WHERE ps.project_id=? AND s.canonical_url_hash=?");$q->execute([$project['id'],hash('sha256',canonicalize_url($url))]);
p56((int)$q->fetchColumn()===1,'56.1 an exact URL watch immediately enters the existing Source Monitor corpus.');
$urlWatchAgain=research_monitor_create($pdo,$owner,['agent_id'=>$agent['public_id'],'watch_type'=>'url','target'=>$url,'cadence'=>'weekly','alert_level'=>'all','auto_promote'=>false]);
p56((int)$urlWatchAgain['id']===(int)$urlWatch['id']&&$urlWatchAgain['cadence']==='weekly','56.1 deduplicates watch identity while allowing settings updates.');

$queryWatch=research_monitor_create($pdo,$owner,['agent_id'=>$agent['public_id'],'watch_type'=>'query','target'=>'phase 56 market intelligence','cadence'=>'manual','alert_level'=>'important','auto_promote'=>false]);
p56(($queryWatch['watch_type']??'')==='query','56.1 supports external discovery query watches.');
p56throws(fn()=>research_monitor_list($pdo,$outsider,(string)$agent['public_id'],20),'56.1 watchlists remain live permission checked.');

$candidate=research_monitor_candidate_ingest($pdo,$queryWatch,[
  'url'=>'https://1.1.1.1/'.$run.'/market-report',
  'title'=>'Phase 56 Market Intelligence Report',
  'excerpt'=>'A phase 56 market intelligence report with monitored research evidence.',
  'published_at'=>'2026-09-23T12:00:00Z'
]);
p56($candidate&&$candidate['status']==='candidate'&&(float)$candidate['relevance_score']>=65,'56.2 discovery candidates are normalized, scored, and retained for review.');
$candidateAgain=research_monitor_candidate_ingest($pdo,$queryWatch,[
  'url'=>'https://1.1.1.1/'.$run.'/market-report',
  'title'=>'Phase 56 Market Intelligence Report',
  'excerpt'=>'Updated excerpt for the same canonical candidate.',
]);
p56($candidateAgain&&(int)$candidateAgain['id']===(int)$candidate['id'],'56.2 candidate discovery deduplicates canonical URLs per watch.');

$promoted=research_monitor_candidate_promote_for_user($pdo,$owner,(string)$candidate['public_id']);
p56(($promoted['status']??'')==='promoted'&&(int)($promoted['source_id']??0)>0,'56.2 approved discovery promotes into the existing Sources corpus.');
$q=$pdo->prepare('SELECT COUNT(*) FROM project_sources WHERE project_id=? AND source_id=?');$q->execute([$project['id'],$promoted['source_id']]);
p56((int)$q->fetchColumn()===1,'56.2 promoted discovery is attached to the existing Research project, not a parallel knowledge store.');
$q=$pdo->prepare("SELECT COUNT(*) FROM source_monitor_jobs WHERE source_id=? AND status IN ('queued','processing')");$q->execute([$promoted['source_id']]);
p56((int)$q->fetchColumn()>=1,'56.2 promoted discovery hands off to the existing Source Monitor.');

$ignoredCandidate=research_monitor_candidate_ingest($pdo,$queryWatch,['url'=>'https://9.9.9.9/'.$run.'/secondary','title'=>'Secondary phase 56 intelligence','excerpt'=>'phase 56 market intelligence']);
p56($ignoredCandidate&&research_monitor_candidate_ignore($pdo,$owner,(string)$ignoredCandidate['public_id']),'56.2 discovery candidates can be explicitly ignored.');

$source=ensure_source($pdo,$url,'Watched Phase 56 Source');
$oldText='Original monitored evidence says the launch target is October 1.';
$newText='Updated monitored evidence says the launch target is October 15.';
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)")
  ->execute([$source['id'],$url,'Watched Phase 56 Source',$oldText,hash('sha256',$oldText)]);$oldVersion=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,2,?,?,?,?)")
  ->execute([$source['id'],$url,'Watched Phase 56 Source',$newText,hash('sha256',$newText)]);$newVersion=(int)$pdo->lastInsertId();
$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$newVersion,$source['id']]);
$pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([$project['id'],$source['id'],$owner['id']]);
$pdo->prepare("UPDATE research_monitor_watches SET last_checked_at=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE id=?")->execute([$urlWatch['id']]);
$pdo->prepare("INSERT INTO source_change_events(source_id,previous_version_id,new_version_id,change_type,target_changed,diff_summary,created_at) VALUES(?,?,?,'updated',1,'Launch target changed from October 1 to October 15.',NOW())")
  ->execute([$source['id'],$oldVersion,$newVersion]);$changeId=(int)$pdo->lastInsertId();

$freshUrlWatch=research_monitor_watch_access($pdo,$owner,(string)$urlWatch['public_id']);
$sourceEvents=research_monitor_sync_source_changes($pdo,$freshUrlWatch);
p56($sourceEvents===1,'56.3 meaningful Source Monitor changes become project-scoped monitoring events.');
$q=$pdo->prepare("SELECT event_type,importance,source_change_event_id FROM research_monitor_events WHERE watch_id=? AND source_change_event_id=?");$q->execute([$urlWatch['id'],$changeId]);$event=$q->fetch();
p56($event&&$event['event_type']==='source_changed'&&$event['importance']==='high','56.3 source-change provenance and importance are preserved.');

$pdo->prepare("INSERT INTO source_change_events(source_id,previous_version_id,new_version_id,change_type,target_changed,diff_summary,created_at) VALUES(?,?,?,'restored',1,'Source became reachable again.',NOW())")
  ->execute([$source['id'],$newVersion,$newVersion]);$restoredId=(int)$pdo->lastInsertId();
$pdo->prepare("UPDATE research_monitor_watches SET last_checked_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=?")->execute([$urlWatch['id']]);
$freshUrlWatch=research_monitor_watch_access($pdo,$owner,(string)$urlWatch['public_id']);
research_monitor_sync_source_changes($pdo,$freshUrlWatch);
$q=$pdo->prepare("SELECT event_type FROM research_monitor_events WHERE watch_id=? AND source_change_event_id=?");$q->execute([$urlWatch['id'],$restoredId]);
p56((string)$q->fetchColumn()==='source_restored','56.3 unavailable/restored source lifecycle is durable.');

$claimPublic=$pub('claim');
$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,'The launch target is October 15.','factual','unverified')")
  ->execute([$claimPublic,$project['id'],$owner['id']]);$claimId=(int)$pdo->lastInsertId();
$claimWatch=research_monitor_create($pdo,$owner,['agent_id'=>$agent['public_id'],'watch_type'=>'claim','target'=>$claimPublic,'cadence'=>'daily','alert_level'=>'important','auto_promote'=>false]);
p56(research_monitor_sync_claim($pdo,$claimWatch)===0,'56.4 unsupported monitored claims begin unresolved without inventing evidence.');

$pdo->prepare("INSERT INTO claim_evidence(public_id,claim_id,added_by_user_id,evidence_type,annotation_id,source_version_id,relationship,note) VALUES(?,?,?,'source_version',NULL,?,'supports','Phase 56 support')")
  ->execute([$pub('ev'),$claimId,$owner['id'],$newVersion]);
p56(research_monitor_sync_claim($pdo,$claimWatch)===1,'56.4 authoritative evidence changes produce a supported derived claim state.');
$q=$pdo->prepare('SELECT assessment FROM research_monitor_claim_states WHERE watch_id=? AND claim_id=?');$q->execute([$claimWatch['id'],$claimId]);
p56((string)$q->fetchColumn()==='supported','56.4 derived claim state records explicit support.');

$pdo->prepare("INSERT INTO claim_evidence(public_id,claim_id,added_by_user_id,evidence_type,annotation_id,source_version_id,relationship,note) VALUES(?,?,?,'source_version',NULL,?,'contradicts','Phase 56 contradiction')")
  ->execute([$pub('ev'),$claimId,$owner['id'],$oldVersion]);
p56(research_monitor_sync_claim($pdo,$claimWatch)===1,'56.4 conflicting authoritative evidence produces a weakened derived claim state.');
$q=$pdo->prepare('SELECT assessment FROM research_monitor_claim_states WHERE watch_id=? AND claim_id=?');$q->execute([$claimWatch['id'],$claimId]);
p56((string)$q->fetchColumn()==='weakened','56.4 explicit support and contradiction remain side-by-side.');

$assessmentPublic=$pub('assess');
$claimStatement='The launch target is October 15.';
$pdo->prepare("INSERT INTO research_monitor_claim_assessments(public_id,watch_id,project_id,claim_id,source_version_id,claim_statement,status) VALUES(?,?,?,?,?,?,'processing')")
  ->execute([$assessmentPublic,$claimWatch['id'],$project['id'],$claimId,$newVersion,$claimStatement]);
$claimStatusBefore=(string)$pdo->query("SELECT status FROM research_claims WHERE id=".$claimId)->fetchColumn();
$derived=research_monitor_claim_assessment_apply($pdo,$assessmentPublic,json_encode(['assessment'=>'supports','confidence'=>0.91,'rationale'=>'The monitored source directly states the October 15 launch target.']),'ai-'.$run);
$claimStatusAfter=(string)$pdo->query("SELECT status FROM research_claims WHERE id=".$claimId)->fetchColumn();
p56(($derived['assessment']??'')==='supports'&&$claimStatusBefore===$claimStatusAfter,'56.4 model-backed monitoring intelligence is derived and never silently rewrites the saved claim.');
$q=$pdo->prepare("SELECT COUNT(*) FROM research_monitor_events WHERE watch_id=? AND claim_id=? AND event_type='claim_supported'");$q->execute([$claimWatch['id'],$claimId]);
p56((int)$q->fetchColumn()>=1,'56.4 model-backed claim intelligence retains claim/source provenance as a monitoring event.');

$stalePublic=$pub('assess');
$pdo->prepare("INSERT INTO research_monitor_claim_assessments(public_id,watch_id,project_id,claim_id,source_version_id,claim_statement,status) VALUES(?,?,?,?,?,?,'processing')")
  ->execute([$stalePublic,$claimWatch['id'],$project['id'],$claimId,$oldVersion,$claimStatement]);
$pdo->prepare("UPDATE research_claims SET statement='The launch target is October 20.' WHERE id=?")->execute([$claimId]);
$stale=research_monitor_claim_assessment_apply($pdo,$stalePublic,json_encode(['assessment'=>'supports','confidence'=>0.95,'rationale'=>'Stale result fixture.']),'ai-stale-'.$run);
$q=$pdo->prepare("SELECT status,assessment,ai_run_public_id FROM research_monitor_claim_assessments WHERE public_id=?");$q->execute([$stalePublic]);$staleRow=$q->fetch();
p56(!empty($stale['stale'])&&$staleRow&&in_array((string)$staleRow['status'],['queued','failed'],true)&&$staleRow['assessment']===null&&$staleRow['ai_run_public_id']===null,'56.4 AI output for an edited claim is discarded and is either safely requeued or explicitly failed.');
$pdo->prepare("UPDATE research_claims SET statement=? WHERE id=?")->execute([$claimStatement,$claimId]);

$messagesBeforeQ=$pdo->prepare("SELECT COUNT(*) FROM conversation_messages WHERE conversation_id=? AND sender_type='agent' AND body LIKE 'Research monitoring update for %'");$messagesBeforeQ->execute([$agent['conversation_id']]);$messagesBefore=(int)$messagesBeforeQ->fetchColumn();
$notified=research_monitor_chat_updates($pdo,$claimWatch,999001);
$messagesBeforeQ->execute([$agent['conversation_id']]);$messagesAfter=(int)$messagesBeforeQ->fetchColumn();
p56($notified>0&&$messagesAfter===$messagesBefore+1,'56.5 meaningful monitoring changes post one concise Research Agent Chat update.');
p56(research_monitor_chat_updates($pdo,$claimWatch,999002)===0,'56.5 already-notified monitoring events do not spam Agent Chat.');

$q=$pdo->prepare("UPDATE research_monitor_jobs SET status='processing',claim_token='p56fixtureclaim',lease_expires_at=DATE_ADD(NOW(),INTERVAL 10 MINUTE),rerun_requested=0 WHERE watch_id=?");$q->execute([$urlWatch['id']]);
research_monitor_queue($pdo,(int)$urlWatch['id'],(int)$owner['id'],'source_change');
$q=$pdo->prepare('SELECT status,claim_token,rerun_requested FROM research_monitor_jobs WHERE watch_id=?');$q->execute([$urlWatch['id']]);$job=$q->fetch();
p56($job&&$job['status']==='processing'&&$job['claim_token']==='p56fixtureclaim'&&(int)$job['rerun_requested']===1,'56.6 newer monitoring work requests a rerun without stealing an active lease.');

p56(research_monitor_set_status($pdo,$owner,(string)$urlWatch['public_id'],'paused'),'56.6 watches can be paused.');
$paused=research_monitor_watch_access($pdo,$owner,(string)$urlWatch['public_id']);p56($paused&&$paused['status']==='paused'&&$paused['next_check_at']===null,'56.6 paused watches stop scheduled checks.');
p56(research_monitor_set_status($pdo,$owner,(string)$urlWatch['public_id'],'active'),'56.6 watches can be resumed.');
$resumed=research_monitor_watch_access($pdo,$owner,(string)$urlWatch['public_id']);p56($resumed&&$resumed['status']==='active'&&$resumed['next_check_at']!==null,'56.6 resumed watches receive a next-check time.');

$summary=research_monitor_summary($pdo,$owner,(string)$agent['public_id']);$events=research_monitor_events($pdo,$owner,(string)$agent['public_id'],100);$candidates=research_monitor_candidates($pdo,$owner,(string)$queryWatch['public_id'],100);
p56(isset($summary['watches']['active'])&&!empty($events)&&!empty($candidates),'56.6 authorized Monitoring control data includes watch, event, and candidate state.');
p56throws(fn()=>research_monitor_summary($pdo,$outsider,(string)$agent['public_id']),'56.6 Monitoring status remains live permission checked.');

$archiveWatch=research_monitor_create($pdo,$owner,['agent_id'=>$agent['public_id'],'watch_type'=>'topic','target'=>'temporary archive target','cadence'=>'manual']);
p56(research_monitor_set_status($pdo,$owner,(string)$archiveWatch['public_id'],'archived'),'56.6 watches can be archived without deleting monitoring history.');
p56(research_monitor_watch_access($pdo,$owner,(string)$archiveWatch['public_id'])===null,'56.6 archived watches leave the active control surface.');
$q=$pdo->prepare("SELECT status FROM research_monitor_jobs WHERE watch_id=?");$q->execute([$archiveWatch['id']]);
p56((string)$q->fetchColumn()==='done','56.6 archiving drains queued monitoring work instead of leaving release-health backlog.');

echo "Phase 56 Continuous Research Monitoring MariaDB suite passed.\n";
