<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','functions','access','notifications','rate-limit','conversations','research-automation','research-workspace','research-agents','research-agent-workspace','research-retrieval','workspace-context','object-handoff','agent-chat','research-autonomy'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p55(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p55throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p55(research_autonomy_ready($pdo),'Phase 55 schema is available.');
p55(job_table_meta('research_autonomy_jobs')['schedule']==='available_at','Autonomous Research uses leased jobs.');

$run='p55'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('AutonomyOwner','admin');$outsider=$makeUser('AutonomyOutsider');

$agent=research_agent_create($pdo,$owner,['name'=>'Phase 55 Agent','description'=>'Maintain a living research workspace.','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);p55((bool)$project,'Research Agent project is available.');
p55(research_agent_access($pdo,$outsider,(string)$agent['public_id'])===null,'Outsider cannot access autonomous Research Agent.');

$userDoc=research_agent_workspace_create_document($pdo,$owner,$project,['title'=>'User Notes','document_type'=>'note','content_html'=>'<p>User authored content must remain unchanged.</p>'],false);
$userDocBefore=research_agent_workspace_object($pdo,$owner,(string)$userDoc['public_id'],false);

$claimGap=$pub('claim');$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,'The launch date is October 1.','factual','unverified')")->execute([$claimGap,$project['id'],$owner['id']]);$gapId=(int)$pdo->lastInsertId();
$claimConflict=$pub('claim');$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,'The pilot price is 4200.','factual','disputed')")->execute([$claimConflict,$project['id'],$owner['id']]);$conflictId=(int)$pdo->lastInsertId();

$source=ensure_source($pdo,'https://example.com/'.$run.'/phase55','Phase 55 Evidence');$sourceText='Evidence about pricing and launch timing.';
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)")->execute([$source['id'],'https://example.com/'.$run.'/phase55','Phase 55 Evidence',$sourceText,hash('sha256',$sourceText)]);$versionId=(int)$pdo->lastInsertId();
$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$versionId,$source['id']]);$pdo->prepare('INSERT INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([$project['id'],$source['id'],$owner['id']]);
foreach(['supports','contradicts'] as $rel)$pdo->prepare("INSERT INTO claim_evidence(public_id,claim_id,added_by_user_id,evidence_type,annotation_id,source_version_id,relationship,note) VALUES(?,?,?,'source_version',NULL,?,?,?)")->execute([$pub('ev'),$conflictId,$owner['id'],$versionId,$rel,$rel.' fixture']);

research_retrieval_rebuild_project($pdo,[],(int)$project['id']);
$agentRow=research_autonomy_agent_by_id($pdo,(int)$agent['id']);p55((bool)$agentRow,'Autonomous runtime resolves active Research Agent.');

$first=research_autonomy_run($pdo,[],$agentRow,(int)$owner['id'],'manual','Phase 55 fixture.');
p55(($first['status']??'')==='completed','Autonomous Research run completes.');
p55((int)($first['metrics']['evidence_gaps']??0)===1,'Evidence-gap detector finds unsupported claim.');
p55((int)($first['metrics']['contradictions']??0)===1,'Contradiction detector finds conflicting claim evidence.');

$q=$pdo->prepare("SELECT raa.management_key,rwo.public_id,rwo.object_type FROM research_autonomy_artifacts raa JOIN research_workspace_objects rwo ON rwo.id=raa.object_id WHERE raa.research_agent_id=? AND raa.status='active' ORDER BY raa.management_key");$q->execute([$agent['id']]);$managed=$q->fetchAll();$keys=array_column($managed,'management_key');
p55(in_array('agent-research-root',$keys,true)&&in_array('living-report',$keys,true)&&in_array('attention-sticky',$keys,true),'Agent creates only registered managed workspace artifacts.');

$userDocAfter=research_agent_workspace_object($pdo,$owner,(string)$userDoc['public_id'],false);
p55((int)$userDocAfter['revision_number']===(int)$userDocBefore['revision_number']&&(string)$userDocAfter['document_plain_text']===(string)$userDocBefore['document_plain_text'],'Autonomous run does not mutate user-authored document.');

$q=$pdo->prepare("SELECT rwo.public_id,rwd.revision_number,rwd.created_by_agent FROM research_autonomy_reports rar JOIN research_workspace_objects rwo ON rwo.id=rar.object_id JOIN research_workspace_documents rwd ON rwd.object_id=rwo.id WHERE rar.research_agent_id=?");$q->execute([$agent['id']]);$report=$q->fetch();
p55($report&&(int)$report['revision_number']===1&&(int)$report['created_by_agent']===1,'Living report is an agent-created versioned Research Doc.');

$q=$pdo->prepare("SELECT COUNT(*) FROM conversation_messages WHERE conversation_id=? AND sender_type='agent' AND body LIKE 'Research workspace updated:%'");$q->execute([$agent['conversation_id']]);$messages1=(int)$q->fetchColumn();
p55($messages1===1,'Meaningful autonomous update posts once to main Agent Chat.');

$second=research_autonomy_run($pdo,[],$agentRow,null,'schedule','No authoritative changes.');
p55(($second['status']??'')==='skipped'&&empty($second['changed']),'Unchanged authoritative state skips autonomous churn.');
$q->execute([$agent['conversation_id']]);p55((int)$q->fetchColumn()===$messages1,'Unchanged run does not spam Agent Chat.');

$pdo->prepare("INSERT INTO claim_evidence(public_id,claim_id,added_by_user_id,evidence_type,annotation_id,source_version_id,relationship,note) VALUES(?,?,?,'source_version',NULL,?,'supports','gap resolved')")->execute([$pub('ev'),$gapId,$owner['id'],$versionId]);
$pdo->prepare("UPDATE research_claims SET status='supported',updated_at=DATE_ADD(NOW(),INTERVAL 1 SECOND) WHERE id=?")->execute([$gapId]);
$third=research_autonomy_run($pdo,[],$agentRow,null,'research_change','Evidence added.');
p55(($third['status']??'')==='completed'&&(int)($third['metrics']['evidence_gaps']??-1)===0,'New evidence re-runs autonomy and resolves the evidence gap.');
$q=$pdo->prepare("SELECT status FROM research_autonomy_observations WHERE research_agent_id=? AND subject_public_id=? AND observation_type='evidence_gap'");$q->execute([$agent['id'],$claimGap]);p55((string)$q->fetchColumn()==='resolved','Resolved evidence gap remains in auditable lifecycle history.');
$q=$pdo->prepare("SELECT rwd.revision_number FROM research_autonomy_reports rar JOIN research_workspace_documents rwd ON rwd.object_id=rar.object_id WHERE rar.research_agent_id=?");$q->execute([$agent['id']]);p55((int)$q->fetchColumn()>=2,'Living report updates incrementally through document revision history.');

research_autonomy_queue($pdo,(int)$agent['id'],(int)$owner['id'],'workspace_change','Queue test.');
$pdo->prepare("UPDATE research_autonomy_jobs SET status='processing',claim_token='fixtureclaim',lease_expires_at=DATE_ADD(NOW(),INTERVAL 10 MINUTE),rerun_requested=0 WHERE research_agent_id=?")->execute([$agent['id']]);
research_autonomy_queue($pdo,(int)$agent['id'],(int)$owner['id'],'workspace_change','Newer change.');
$q=$pdo->prepare("SELECT status,rerun_requested,claim_token FROM research_autonomy_jobs WHERE research_agent_id=?");$q->execute([$agent['id']]);$queued=$q->fetch();
p55($queued&&$queued['status']==='processing'&&(int)$queued['rerun_requested']===1&&$queued['claim_token']==='fixtureclaim','Mutation during active autonomous work preserves lease and requests rerun.');

$status=research_autonomy_status($pdo,$owner,(string)$agent['public_id']);p55($status&&$status['report']&&!empty($status['observations']),'Autonomy status exposes report and observation state to authorized user.');
p55(research_autonomy_status($pdo,$outsider,(string)$agent['public_id'])===null,'Autonomy status is live permission checked.');

echo "Phase 55 Autonomous Research Workspace MariaDB suite passed.\n";
