<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/installer.php';require_once $root.'/app/storage.php';require_once $root.'/app/functions.php';require_once $root.'/app/access.php';require_once $root.'/app/notifications.php';require_once $root.'/app/rate-limit.php';require_once $root.'/app/source-integrity.php';require_once $root.'/app/annotation-intelligence.php';require_once $root.'/app/conversations.php';require_once $root.'/app/agent-chat.php';
function p13(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p13throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}
$run='p13'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('IntelOwner','admin');$other=$makeUser('IntelOther');$member=$makeUser('IntelMember');$outsider=$makeUser('IntelOutsider');

p13(annotation_intelligence_ready($pdo),'Phase 13 intelligence schema is available');

$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Intelligence Team']);$teamId=(int)$pdo->lastInsertId();
foreach([[$owner,'owner'],[$member,'researcher']] as [$u,$role])$pdo->prepare('INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,?)')->execute([$teamId,$u['id'],$role]);

$source=ensure_source($pdo,'https://example.com/'.$run,'Phase 13 Evidence');
$pdo->prepare('INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash,target_content_hash) VALUES(?,1,?,?,?,?,?)')->execute([$source['id'],$source['canonical_url'],'Phase 13 Evidence','Evidence text for intelligence testing.',hash('sha256','page-'.$run),hash('sha256','target-'.$run)]);
$versionId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=? WHERE id=?')->execute([$versionId,$source['id']]);

$createAnnotation=function(array $user,string $visibility,string $selected,string $comment,?int $teamId=null,string $captureType='text')use($pdo,$pub,$source,$versionId): array{
    $pdo->prepare('INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?,?,?)')->execute([$pub('cap'),$source['id'],$versionId,$user['id'],$captureType,$selected!==''?$selected:null]);$captureId=(int)$pdo->lastInsertId();
    $public=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,team_id,status) VALUES(?,?,?,?,?,?,?,?, 'published')")->execute([$public,$user['id'],$source['id'],$versionId,$captureId,$comment!==''?$comment:null,$visibility,$teamId]);
    return ['id'=>(int)$pdo->lastInsertId(),'public_id'=>$public,'capture_id'=>$captureId];
};
$duplicateText='This captured statement contains enough repeated evidence words to identify a deterministic duplicate annotation without relying on a language model response.';
$a=$createAnnotation($owner,'public',$duplicateText,'Primary annotation about the evidence.');
$b=$createAnnotation($other,'public',$duplicateText,'Another researcher captured the same evidence.');
$team=$createAnnotation($owner,'team','Team-only evidence that should never leak to outsiders through relationship metadata.','Team private analysis.',$teamId);
$private=$createAnnotation($owner,'private','Private evidence only the owner should be able to inspect.','Private analysis.');
$imageOnly=$createAnnotation($owner,'public','','',null,'image_region');

p13(!annotation_intelligence_queue($pdo,$imageOnly['id'],null,5),'image-only capture without analyzable text is not given fabricated text intelligence');
p13(annotation_intelligence_queue($pdo,$a['id'],$owner['id'],4),'published text annotation queues intelligence');
$q=$pdo->prepare("SELECT COUNT(*) FROM ai_jobs WHERE task_type='annotation_intelligence' AND object_public_id=?");$q->execute([$a['public_id']]);p13((int)$q->fetchColumn()===1,'Annotation Intelligence queue creates one AI job');
annotation_intelligence_queue($pdo,$a['id'],$owner['id'],4);$q->execute([$a['public_id']]);p13((int)$q->fetchColumn()===1,'repeated queue requests do not create duplicate active AI jobs');
$q=$pdo->prepare("SELECT requested_by_user_id FROM ai_jobs WHERE task_type='annotation_intelligence' AND object_public_id=? ORDER BY id DESC LIMIT 1");$q->execute([$a['public_id']]);p13($q->fetchColumn()===null,'background Annotation Intelligence runs as system work rather than impersonating a user plan');

$rels=annotation_intelligence_visible_relationships($pdo,$a['id'],$outsider,10);$dup=array_values(array_filter($rels,fn($r)=>($r['public_id']??'')===$b['public_id']&&($r['relation_type']??'')==='duplicate'));
p13(count($dup)===1&&($dup[0]['generated_by']??'')==='deterministic','identical captured evidence creates a deterministic duplicate relationship');

$output=json_encode([
 'summary'=>'The annotation captures a statement about the Phase 13 evidence and comments on its significance.',
 'topics'=>['Phase 13','Evidence','Annotation intelligence'],
 'entities'=>[['name'=>'Example Evidence','type'=>'document']],
 'claims'=>[['statement'=>'The captured passage discusses intelligence testing.','certainty'=>'explicit'],['statement'=>'The author considers the evidence significant.','certainty'=>'inferred']],
 'confidence'=>0.82,
 'relationships'=>[
   ['annotation_id'=>$b['public_id'],'type'=>'corroborates','confidence'=>0.77,'rationale'=>'Both annotations capture materially consistent statements.'],
   ['annotation_id'=>'invented-id','type'=>'conflicts','confidence'=>0.99,'rationale'=>'Must be rejected because it was not a supplied candidate.']
 ]
],JSON_UNESCAPED_SLASHES);
$applied=annotation_intelligence_apply_ai_output($pdo,$a['public_id'],$output,$pub('run'),0);
p13(($applied['summary']??'')!==''&&count($applied['claims']??[])===2,'AI output is normalized into summary and explicit/inferred claims');
$record=annotation_intelligence_record($pdo,$a['id']);p13(($record['status']??'')==='ready'&&abs((float)$record['confidence']-0.82)<0.001,'ready intelligence retains bounded analysis confidence and provenance state');
$batch=annotation_intelligence_attach_many($pdo,[['internal_id'=>$a['id'],'public_id'=>$a['public_id']],['internal_id'=>$b['id'],'public_id'=>$b['public_id']]],$outsider,8);
p13(($batch[0]['intelligence']['status']??'')==='ready','feed/profile batch hydration returns ready intelligence without per-card record lookup');
p13(($record['claims'][0]['certainty']??'')==='explicit'&&($record['claims'][1]['certainty']??'')==='inferred','claim certainty remains explicit versus inferred');

$rels=annotation_intelligence_visible_relationships($pdo,$a['id'],$outsider,20);
p13(count(array_filter($rels,fn($r)=>($r['public_id']??'')==='invented-id'))===0,'model cannot invent relationship targets outside supplied candidate IDs');
p13(count(array_filter($rels,fn($r)=>($r['public_id']??'')===$b['public_id']&&($r['relation_type']??'')==='corroborates'))===1,'AI corroboration relationship is stored for a supplied accessible candidate');
$pdo->prepare('INSERT INTO blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([$outsider['id'],$other['id']]);
p13(count(array_filter(annotation_intelligence_visible_relationships($pdo,$a['id'],$outsider,20),fn($r)=>($r['public_id']??'')===$b['public_id']))===0,'blocked users are removed from related-evidence delivery');
$pdo->prepare('DELETE FROM blocks WHERE blocker_user_id=? AND blocked_user_id=?')->execute([$outsider['id'],$other['id']]);
$pdo->prepare('INSERT INTO blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([$owner['id'],$other['id']]);
$candidateIds=array_column(annotation_intelligence_candidate_rows($pdo,annotation_intelligence_source_row($pdo,$a['id']),40),'public_id');
p13(!in_array($b['public_id'],$candidateIds,true),'blocked users are excluded before Annotation Intelligence candidate prompting');
$pdo->prepare('DELETE FROM blocks WHERE blocker_user_id=? AND blocked_user_id=?')->execute([$owner['id'],$other['id']]);


annotation_intelligence_relation_upsert($pdo,$a['id'],$team['id'],'conflicts',0.74,'Team evidence differs from the public annotation.','ai',$pub('rel'));
$outsiderRels=annotation_intelligence_visible_relationships($pdo,$a['id'],$outsider,20);
$memberRels=annotation_intelligence_visible_relationships($pdo,$a['id'],$member,20);
p13(count(array_filter($outsiderRels,fn($r)=>($r['public_id']??'')===$team['public_id']))===0,'related-intelligence graph never reveals Team annotations to outsiders');
p13(count(array_filter($memberRels,fn($r)=>($r['public_id']??'')===$team['public_id']))===1,'current Team member may see an authorized Team relationship');
annotation_intelligence_relation_upsert($pdo,$a['id'],$private['id'],'related',0.8,'Private owner context.','ai',$pub('private-rel'));
p13(count(array_filter(annotation_intelligence_visible_relationships($pdo,$a['id'],$outsider,20),fn($r)=>($r['public_id']??'')===$private['public_id']))===0,'private relationship targets never leak to other users');

$ownerCtx=agent_chat_context_item($pdo,$owner,'annotation',$a['public_id']);p13(str_contains((string)$ownerCtx['text'],'Derived annotation intelligence'),'Agent Chat receives derived Annotation Intelligence alongside captured evidence');
$outsiderCtx=agent_chat_context_item($pdo,$outsider,'annotation',$a['public_id']);p13(!str_contains((string)$outsiderCtx['text'],$team['public_id'])&&!str_contains((string)$outsiderCtx['text'],$private['public_id']),'Agent context filters intelligence relationships by the current viewer permissions');

$fenced=annotation_intelligence_parse_json("```json\n{\"summary\":\"ok\"}\n```");p13(($fenced['summary']??'')==='ok','fenced provider JSON is normalized safely');

$q=$pdo->prepare("SELECT id,input_json FROM ai_jobs WHERE task_type='annotation_intelligence' AND object_public_id=? ORDER BY id ASC LIMIT 1");$q->execute([$a['public_id']]);$oldJob=$q->fetch();$oldInput=json_decode((string)$oldJob['input_json'],true);$oldHash=(string)($oldInput['input_hash']??'');
$pdo->prepare("UPDATE ai_jobs SET status='processing',claim_token='phase13-race',lease_expires_at=DATE_ADD(NOW(),INTERVAL 10 MINUTE) WHERE id=?")->execute([$oldJob['id']]);
$pdo->prepare('UPDATE captures SET selected_text=? WHERE id=?')->execute(['The captured evidence changed substantially after a transcript or capture correction and should invalidate stale derived relationships.',$a['capture_id']]);
p13(annotation_intelligence_queue($pdo,$a['id'],$owner['id'],3),'changed annotation input queues a new hash-specific job even while the old job is processing');
$record=annotation_intelligence_record($pdo,$a['id']);p13(($record['status']??'')==='pending','changed evidence hides stale ready intelligence while reprocessing');
$relsAfter=annotation_intelligence_visible_relationships($pdo,$a['id'],$owner,20);p13(count(array_filter($relsAfter,fn($r)=>($r['generated_by']??'')==='ai'))===0,'changed evidence removes stale AI relationships before re-analysis');
$stale=annotation_intelligence_apply_ai_output($pdo,$a['public_id'],$output,$pub('stale-run'),0,$oldHash);
p13(!empty($stale['stale']),'in-flight AI output is rejected when its input hash no longer matches current evidence');
$record=annotation_intelligence_record($pdo,$a['id']);p13(($record['status']??'')==='pending','rejected stale output cannot mark changed evidence ready');
$q=$pdo->prepare("SELECT COUNT(*) FROM ai_jobs WHERE task_type='annotation_intelligence' AND object_public_id=?");$q->execute([$a['public_id']]);p13((int)$q->fetchColumn()===2,'evidence change preserves the in-flight job and creates one fresh follow-up job');
$pdo->prepare("UPDATE ai_jobs SET status='done',claim_token=NULL,lease_expires_at=NULL,completed_at=NOW() WHERE id=?")->execute([$oldJob['id']]);

annotation_intelligence_mark_failed($pdo,$a['public_id'],'Synthetic provider failure');$record=annotation_intelligence_record($pdo,$a['id']);p13(($record['status']??'')==='failed'&&str_contains((string)$record['last_error'],'Synthetic'),'final worker failure is represented explicitly without overwriting captured evidence');

echo "Phase 13 Annotation Intelligence MariaDB suite passed.\n";
