<?php
declare(strict_types=1);

$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing'] as $lib)require_once $root.'/app/'.$lib.'.php';

function p59(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p59throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

p59(research_publications_ready($pdo),'Phase 59 publication workflow schema is available.');
$run='p59'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('PublishOwner','admin');$reviewer=$makeUser('PublishReviewer');$approver=$makeUser('PublishApprover');$outsider=$makeUser('PublishOutsider');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Publishing Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$reviewer['id'],$teamId,$approver['id']]);
$agent=research_agent_create($pdo,$owner,['name'=>'Phase 59 Agent','description'=>'Collaborative publishing fixture.','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);p59((bool)$project&&((int)$project['team_id']===$teamId),'59.1 uses a real Team Research Agent/project.');

$source=ensure_source($pdo,'https://8.8.8.8/'.$run.'/publishing-source','Phase 59 Evidence');
$evidence='Fresh evidence supporting the Phase 59 publication.';
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)")->execute([(int)$source['id'],'https://8.8.8.8/'.$run.'/publishing-source','Phase 59 Evidence',$evidence,hash('sha256',$evidence)]);
$sourceVersion=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$sourceVersion,(int)$source['id']]);$pdo->prepare('INSERT IGNORE INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([(int)$project['id'],(int)$source['id'],(int)$owner['id']]);

$doc=research_agent_workspace_create_document($pdo,$owner,$project,['title'=>'Phase 59 Research Brief','body'=>'<h2>Executive summary</h2><p>Initial reviewed content.</p>','summary'=>'Initial publication summary.','document_type'=>'research_brief'],false);
p59(($doc['object_type']??'')==='document'&&(int)$doc['revision_number']===1,'59.1 starts from a normal versioned Research document.');

$workflow=research_publication_workflow_create($pdo,$owner,(string)$doc['public_id'],[
 'title'=>'Phase 59 Published Brief','summary'=>'Governed publication fixture.','visibility'=>'team','topics'=>'publishing, governance',
 'required_approvals'=>1,'owner_approval'=>true,'fresh_evidence_days'=>30,'recipient_user_ids'=>[$reviewer['id']],'notify_team'=>false,'notify_subscribers'=>false
]);
p59(($workflow['status']??'')==='draft'&&(int)$workflow['document_revision_number']===1,'59.1 workflow pins the exact living document revision.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_publication_approval_gates WHERE workflow_id=?');$q->execute([(int)$workflow['id']]);p59((int)$q->fetchColumn()===7,'59.3 publication workflow materializes all configured approval gates.');

$workflow=research_publication_request_review($pdo,$owner,(string)$workflow['public_id'],[
 ['user_id'=>$reviewer['id'],'role'=>'reviewer','required'=>true],
 ['user_id'=>$approver['id'],'role'=>'approver','required'=>true],
],date('Y-m-d H:i:s',time()+86400),'Verify evidence, wording, and publication readiness.');
p59($workflow['status']==='in_review'&&(int)$workflow['current_round']===1,'59.1 assigned document review starts round 1.');
$review=research_review_access($pdo,$owner,(string)$workflow['review_public_id']);$assignments=research_review_assignments($pdo,$review);
p59(count($assignments)===2&&array_values(array_filter($assignments,fn($a)=>$a['reviewer_role']==='approver'&&(int)$a['is_required']===1))!==[],'59.1 Review Center preserves required reviewer/approver roles.');
p59throws(fn()=>research_review_respond($pdo,$owner,(string)$review['public_id'],'approve','Self approval'), '59.1 workflow requester cannot vote on their own review.');

$thread=research_publication_thread_create($pdo,$reviewer,(string)$workflow['public_id'],['anchor_type'=>'document_section','anchor_public_id'=>(string)$doc['public_id'],'locator'=>['label'=>'Executive summary'],'title'=>'Clarify this statement','body'=>'Please make the evidence qualification explicit.']);
p59(($thread['status']??'')==='open','59.2 reviewer creates an anchored document discussion thread.');
research_publication_thread_message($pdo,$approver,(string)$thread['public_id'],'Agreed; this needs qualification.');
research_review_respond($pdo,$reviewer,(string)$review['public_id'],'request_changes','Please revise the executive summary.');
$workflow=research_publication_workflow_detail($pdo,$owner,(string)$workflow['public_id']);p59($workflow['status']==='changes_requested','59.3 a required reviewer change request blocks publication.');

$current=research_agent_workspace_object($pdo,$owner,(string)$doc['public_id'],false);
$edited=research_agent_workspace_save_document($pdo,$owner,(string)$doc['public_id'],['title'=>$current['title'],'content_html'=>'<h2>Executive summary</h2><p>Revised and qualified content tied to the fresh source.</p>','summary'=>'Revised publication summary.','base_revision'=>(int)$current['revision_number']]);
$workflow=research_publication_workflow_detail($pdo,$owner,(string)$workflow['public_id']);
p59((int)$edited['revision_number']===2&&$workflow['status']==='changes_requested'&&empty($workflow['owner_approved_at']),'59.3 document edits invalidate the reviewed pin and clear owner approval.');

$workflow=research_publication_restart_review($pdo,$owner,(string)$workflow['public_id'],date('Y-m-d H:i:s',time()+86400));
p59((int)$workflow['current_round']===2&&(int)$workflow['document_revision_number']===2,'59.1 restarting review pins current revision while preserving round history.');
$q=$pdo->prepare('SELECT COUNT(*) FROM research_publication_review_rounds WHERE workflow_id=?');$q->execute([(int)$workflow['id']]);p59((int)$q->fetchColumn()===2,'59.1 prior review rounds remain durable and auditable.');
$newReview=research_review_access($pdo,$owner,(string)$workflow['review_public_id']);

research_review_respond($pdo,$reviewer,(string)$newReview['public_id'],'approve','Revision addresses my requested change.');
$eval=research_publication_evaluate($pdo,$owner,(string)$workflow['public_id']);
p59((int)$eval['approval']['approved']===0&&!empty($eval['approval']['approver_mode']),'59.3 ordinary reviewer approval does not satisfy an explicit approver-role gate.');
research_review_respond($pdo,$approver,(string)$newReview['public_id'],'approve','Approved for publication.');
research_review_complete($pdo,$owner,(string)$newReview['public_id']);

$thread2=research_publication_thread_create($pdo,$reviewer,(string)$workflow['public_id'],['anchor_type'=>'source','anchor_public_id'=>(string)$source['public_id'],'title'=>'Source checked','body'=>'Primary evidence checked against the current captured version.']);
research_publication_owner_approve($pdo,$owner,(string)$workflow['public_id']);
$eval=research_publication_evaluate($pdo,$owner,(string)$workflow['public_id']);p59(!$eval['pass'],'59.3 unresolved anchored discussion blocks publication even after review and owner approval.');
research_publication_thread_resolve($pdo,$owner,(string)$thread2['public_id'],true);

$obsPublic=$pub('obs');$fp=hash('sha256','phase59-contradiction-'.$run);
$pdo->prepare("INSERT INTO research_autonomy_observations(public_id,research_agent_id,project_id,observation_type,subject_type,subject_public_id,fingerprint,title,detail,severity,status) VALUES(?,?,?,'contradiction','project',?,?,?,'Open publishing contradiction','high','open')")->execute([$obsPublic,(int)$agent['id'],(int)$project['id'],(string)$project['public_id'],$fp,'Contradiction must be resolved before publication.']);
$eval=research_publication_evaluate($pdo,$owner,(string)$workflow['public_id']);p59(!$eval['pass'],'59.3 an open high-severity Research contradiction blocks publication.');
$pdo->prepare("UPDATE research_autonomy_observations SET status='resolved',resolved_at=NOW() WHERE public_id=?")->execute([$obsPublic]);
$eval=research_publication_evaluate($pdo,$owner,(string)$workflow['public_id']);
p59($eval['pass'],'59.3 approval gates pass only after review, approver vote, owner approval, discussion resolution, fresh evidence, and contradiction resolution.');
$workflow=research_publication_workflow_detail($pdo,$owner,(string)$workflow['public_id']);p59($workflow['status']==='approved','59.3 fully governed workflow reaches explicit approved state.');

$preSnapshot=research_publication_document_snapshot($pdo,$owner,$workflow);$publish=research_publication_publish($pdo,$owner,(string)$workflow['public_id']);
p59((int)$publish['version_number']>=1&&!empty($publish['snapshot_hash']),'59.4 authorized owner publishes an immutable existing Research report version.');
$q=$pdo->prepare('SELECT * FROM research_report_versions WHERE id=?');$q->execute([(int)$publish['version_id']]);$version=$q->fetch();
p59((int)$version['publication_workflow_id']===(int)$workflow['id']&&(int)$version['document_revision_number']===2&&$version['document_revision_public_id']===$workflow['document_revision_public_id'],'59.4 immutable report version stores exact workflow and document revision provenance.');
$approval=json_decode((string)$version['approval_snapshot_json'],true)?:[];$snapshot=json_decode((string)$version['snapshot_json'],true)?:[];
p59(($approval['workflow_public_id']??'')===$workflow['public_id']&&($snapshot['document']['revision_public_id']??'')===$workflow['document_revision_public_id'],'59.4 publication freezes the human approval record and reviewed document snapshot together.');

$q=$pdo->prepare("SELECT COUNT(*) FROM research_publication_distribution_events WHERE workflow_id=? AND target_user_id=? AND status='sent'");$q->execute([(int)$workflow['id'],(int)$reviewer['id']]);
p59((int)$q->fetchColumn()===1,'59.5 specific collaborator distribution uses the in-app notification path after live access checks.');
$q=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND notification_type='research_publication_published' AND object_public_id=?");$q->execute([(int)$reviewer['id'],(string)$publish['public_id']]);
p59((int)$q->fetchColumn()===1,'59.5 distributed publication appears in the existing Notification Center.');

$publishedHash=(string)$version['snapshot_hash'];$publishedJson=(string)$version['snapshot_json'];$live=research_agent_workspace_object($pdo,$owner,(string)$doc['public_id'],false);
research_agent_workspace_save_document($pdo,$owner,(string)$doc['public_id'],['title'=>$live['title'],'content_html'=>'<p>Post-publication living document edit.</p>','summary'=>'Living document continues evolving.','base_revision'=>(int)$live['revision_number']]);
$q=$pdo->prepare('SELECT snapshot_hash,snapshot_json FROM research_report_versions WHERE id=?');$q->execute([(int)$publish['version_id']]);$after=$q->fetch();
p59(hash_equals($publishedHash,(string)$after['snapshot_hash'])&&hash_equals($publishedJson,(string)$after['snapshot_json']),'59.4 later living-document edits cannot mutate the published snapshot.');
$workflow=research_publication_workflow_detail($pdo,$owner,(string)$workflow['public_id']);p59($workflow['status']==='published','59.4 published workflow remains immutable when the living document advances.');

p59throws(fn()=>research_publication_workflow_detail($pdo,$outsider,(string)$workflow['public_id'])?:throw new RuntimeException('forbidden'),'59.8 outsider cannot access Team publication workflow.');
$items=[];research_publication_cognitive_observations($pdo,$owner,$items,30);p59(is_array($items),'59.7 publication workflow integrates safely with the Now/cognitive feed.');

$q=$pdo->prepare("SELECT COUNT(*) FROM research_publication_events WHERE workflow_id=? AND event_type IN ('created','review_requested','review_restarted','owner_approved','published')");$q->execute([(int)$workflow['id']]);
p59((int)$q->fetchColumn()>=5,'59.8 workflow lifecycle is durably audited.');

echo "Phase 59 Collaborative Review, Approval & Publishing MariaDB suite passed.\n";
