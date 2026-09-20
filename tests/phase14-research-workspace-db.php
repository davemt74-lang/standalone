<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/installer.php';require_once $root.'/app/storage.php';require_once $root.'/app/functions.php';require_once $root.'/app/access.php';require_once $root.'/app/jobs.php';require_once $root.'/app/ai.php';require_once $root.'/app/source-integrity.php';require_once $root.'/app/annotation-intelligence.php';require_once $root.'/app/research-workspace.php';require_once $root.'/app/conversations.php';require_once $root.'/app/agent-chat.php';
function p14(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$run='p14'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('WorkspaceOwner');$viewer=$makeUser('WorkspaceViewer');$outsider=$makeUser('WorkspaceOutsider');
p14(research_workspace_ready($pdo),'Phase 14 Research Workspace schema is available');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Workspace Team']);$teamId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'viewer')")->execute([$teamId,$owner['id'],$teamId,$viewer['id']]);
$projectPublic=$pub('project');$pdo->prepare('INSERT INTO research_projects(public_id,owner_user_id,team_id,title,description) VALUES(?,?,?,?,?)')->execute([$projectPublic,$owner['id'],$teamId,'Stage 14 Project','Workspace intelligence CI project']);$projectId=(int)$pdo->lastInsertId();

$url='https://example.com/'.$run.'/evidence';$sourcePublic=$pub('source');$pdo->prepare("INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title,status) VALUES(?,'webpage',?,?,?,'Workspace Evidence','current')")->execute([$sourcePublic,$url,hash('sha256',$url),'example.com']);$sourceId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,'Workspace v1','Original evidence text',?)")->execute([$sourceId,$url,hash('sha256','Original evidence text')]);$v1=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,2,?,'Workspace v2','Changed evidence text',?)")->execute([$sourceId,$url,hash('sha256','Changed evidence text')]);$v2=(int)$pdo->lastInsertId();
$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$v2,$sourceId]);
$pdo->prepare('INSERT INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([$projectId,$sourceId,$owner['id']]);
$pdo->prepare("INSERT INTO source_change_events(source_id,previous_version_id,new_version_id,change_type,target_changed,diff_summary) VALUES(?,?,?,'edited',1,'Referenced evidence changed materially.')")->execute([$sourceId,$v1,$v2]);

$claimStmt=$pdo->prepare("INSERT INTO research_claims(public_id,project_id,created_by_user_id,statement,claim_type,status) VALUES(?,?,?,?,'factual',?)");
$supportedPublic=$pub('claim');$claimStmt->execute([$supportedPublic,$projectId,$owner['id'],'Supported claim with changed-source evidence','supported']);$supportedId=(int)$pdo->lastInsertId();
$gapPublic=$pub('claim');$claimStmt->execute([$gapPublic,$projectId,$owner['id'],'Unverified claim with no evidence','unverified']);$gapId=(int)$pdo->lastInsertId();
$conflictPublic=$pub('claim');$claimStmt->execute([$conflictPublic,$projectId,$owner['id'],'Claim with contradictory evidence','contradicted']);$conflictId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO claim_evidence(public_id,claim_id,added_by_user_id,evidence_type,source_version_id,relationship) VALUES(?,?,?,'source_version',?,'supports')")->execute([$pub('ev'),$supportedId,$owner['id'],$v1]);
$pdo->prepare("INSERT INTO claim_evidence(public_id,claim_id,added_by_user_id,evidence_type,source_version_id,relationship) VALUES(?,?,?,'source_version',?,'contradicts')")->execute([$pub('ev'),$conflictId,$owner['id'],$v2]);
$pdo->prepare("INSERT INTO claim_relations(public_id,project_id,source_claim_id,target_claim_id,added_by_user_id,relation_type,note) VALUES(?,?,?,?,?,'contradicts','CI contradiction')")->execute([$pub('rel'),$projectId,$supportedId,$conflictId,$owner['id']]);
$pdo->prepare("INSERT INTO research_tasks(public_id,project_id,created_by_user_id,title,task_type,status) VALUES(?,?,?,'Find independent source','find_source','open')")->execute([$pub('task'),$projectId,$owner['id']]);

$entityPublic=$pub('entity');$pdo->prepare("INSERT INTO research_entities(public_id,project_id,created_by_user_id,entity_type,canonical_name,normalized_name,status,source_type) VALUES(?,?,?,'topic','Workspace Topic','workspace topic','confirmed','manual')")->execute([$entityPublic,$projectId,$owner['id']]);

$snapshot=research_workspace_deterministic_snapshot($pdo,$projectId);
p14(($snapshot['counts']['claims']??0)===3,'deterministic workspace counts project claims');
p14(count(array_filter($snapshot['gaps'],fn($x)=>($x['claim_id']??'')===$gapPublic))===1,'deterministic workspace detects unsupported claim gap');
p14(count(array_filter($snapshot['conflicts'],fn($x)=>($x['claim_id']??'')===$conflictPublic||($x['target_claim_id']??'')===$conflictPublic))>=1,'deterministic workspace detects claim conflicts');
p14(count(array_filter($snapshot['source_risks'],fn($x)=>($x['source_public_id']??'')===$sourcePublic&&($x['target_changed']??false)))===1,'deterministic workspace surfaces changed source risk');
p14(count(array_filter($snapshot['next_actions'],fn($x)=>($x['type']??'')==='verify_claim'))>=1,'deterministic workspace proposes verification action');
p14(count(array_filter($snapshot['next_actions'],fn($x)=>($x['type']??'')==='review_source_change'))>=1,'deterministic workspace proposes source-change review');
p14(count(array_filter($snapshot['entities'],fn($x)=>($x['public_id']??'')===$entityPublic))===1,'deterministic workspace surfaces project entities');

p14(research_workspace_queue($pdo,$projectId,4),'workspace state queues background AI synthesis');
$q=$pdo->prepare("SELECT requested_by_user_id,input_json FROM ai_jobs WHERE task_type='research_workspace_intelligence' AND object_public_id=? ORDER BY id DESC LIMIT 1");$q->execute([$projectPublic]);$job=$q->fetch();
p14($job&&$job['requested_by_user_id']===null,'workspace synthesis is system background work rather than a user entitlement run');
$input=json_decode((string)$job['input_json'],true);$oldHash=(string)($input['input_hash']??'');p14(strlen($oldHash)===64,'workspace job carries deterministic input hash');
p14(!research_workspace_queue($pdo,$projectId,4),'same workspace hash does not enqueue duplicate work');

$output=json_encode([
 'summary'=>'The project has one unsupported claim, one conflict, and changed-source evidence requiring review.',
 'highlights'=>[['type'=>'finding','title'=>'Changed source affects a supported claim','detail'=>'Review the older captured source version before relying on the supported claim.','priority'=>'high','ref_type'=>'source','ref_id'=>$sourcePublic]],
 'gaps'=>[['type'=>'missing_evidence','title'=>'Unverified claim lacks evidence','detail'=>'Attach evidence from an independent source.','priority'=>'high','ref_type'=>'claim','ref_id'=>$gapPublic]],
 'conflicts'=>[['type'=>'claim_conflict','title'=>'Claims conflict','detail'=>'Compare the supported and contradicted claims.','priority'=>'high','ref_type'=>'claim','ref_id'=>$conflictPublic]],
 'source_risks'=>[['type'=>'changed_source','title'=>'Source changed','detail'=>'The project source changed after evidence capture.','priority'=>'high','ref_type'=>'source','ref_id'=>$sourcePublic]],
 'next_actions'=>[
   ['type'=>'verify_claim','title'=>'Verify the unverified claim','detail'=>'Find independent evidence.','priority'=>'high','ref_type'=>'claim','ref_id'=>$gapPublic],
   ['type'=>'invented','title'=>'Invalid invented reference','detail'=>'Must be discarded.','priority'=>'low','ref_type'=>'claim','ref_id'=>'invented-id']
 ],
 'confidence'=>0.84
],JSON_UNESCAPED_SLASHES);
$applied=research_workspace_apply_ai_output($pdo,$projectPublic,$output,$pub('run'),0,$oldHash);
p14(empty($applied['stale'])&&($applied['summary']??'')!=='','AI workspace output persists for the matching project hash');
$record=research_workspace_record($pdo,$projectId);p14(($record['status']??'')==='ready'&&abs((float)$record['confidence']-0.84)<0.001,'ready workspace retains analysis confidence');
p14(count($record['next_actions'])===1&&($record['next_actions'][0]['ref_id']??'')===$gapPublic,'AI workspace drops references to project objects that were not supplied');
$ctx=research_workspace_context($pdo,$projectId);p14(str_contains($ctx['text'],'AI-derived live synthesis')&&str_contains($ctx['text'],$gapPublic),'Agent-ready workspace context contains synthesis and provenance IDs');

$ownerProject=project_access($pdo,$owner['id'],$projectPublic);$viewerProject=project_access($pdo,$viewer['id'],$projectPublic);$outsiderProject=project_access($pdo,$outsider['id'],$projectPublic);
p14($ownerProject!==null&&project_can_write($ownerProject),'project owner retains workspace write access');
p14($viewerProject!==null&&!project_can_write($viewerProject),'project viewer remains read-only');
p14($outsiderProject===null,'workspace project remains inaccessible to outsider');

$pdo->prepare("UPDATE ai_jobs SET status='processing',claim_token='phase14-race',lease_expires_at=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE task_type='research_workspace_intelligence' AND object_public_id=?")->execute([$projectPublic]);
$pdo->prepare("UPDATE research_claims SET statement='Changed claim statement after AI started' WHERE id=?")->execute([$gapId]);
p14(research_workspace_queue($pdo,$projectId,2),'changed project state queues a new hash-specific workspace job');
$stale=research_workspace_apply_ai_output($pdo,$projectPublic,$output,$pub('stale-run'),0,$oldHash);
p14(!empty($stale['stale']),'in-flight workspace AI output is rejected after project state changes');
$record=research_workspace_record($pdo,$projectId);p14(($record['status']??'')==='pending','stale AI output cannot mark changed workspace ready');

echo "Phase 14 Research Workspace Intelligence MariaDB suite passed.\n";
