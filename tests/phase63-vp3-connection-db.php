<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','vp3-connector'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p63(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function p63throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}
p63(vp3_connector_ready($pdo),'63A migration 060 VP3 connection/import schema is ready.');

$cfg=['app'=>['base_url'=>'https://annotated.example.test','encryption_key'=>str_repeat('k',64)],'vp3'=>['base_url'=>'https://vp3.example.test','client_id'=>'annotated','client_secret'=>str_repeat('s',64),'redirect_uri'=>'https://annotated.example.test/vp3/callback.php']];
$run='p63'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$makeUser('VP3ImportOwner');$other=$makeUser('VP3ImportOther');

$tokens=['access_token'=>str_repeat('a',64),'refresh_token'=>str_repeat('b',80),'expires_in'=>3600,'refresh_expires_in'=>7776000,'scope'=>'account.identity.read meetings.transcripts.read meetings.intelligence.read'];
$identity=['id'=>'vp3-'.$run,'display_name'=>'VP3 Research User','email'=>'vp3-'.$run.'@example.test'];
$conn=vp3_connector_attach($pdo,$cfg,(int)$owner['id'],$identity,$tokens);
p63(($conn['status']??'')==='active'&&vp3_connector_decrypt($cfg,(string)$conn['access_token_cipher'])===$tokens['access_token'],'63A credentials are encrypted at rest and decrypt only through the Annotated application key.');
p63(!str_contains((string)$conn['access_token_cipher'],$tokens['access_token'])&&!str_contains((string)$conn['refresh_token_cipher'],$tokens['refresh_token']),'63A raw VP3 credentials are not stored in connection columns.');
p63throws(fn()=>vp3_connector_attach($pdo,$cfg,(int)$other['id'],$identity,$tokens),'63B one VP3 identity cannot be attached to two Annotated users.');

$agent=research_agent_create($pdo,$owner,['name'=>'VP3 Evidence Agent','description'=>'Validate VP3 meeting material.','cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);p63($project!==null,'63D import target is a normal Research Agent workspace.');
$remoteId=bin2hex(random_bytes(16));$hash1=hash('sha256','vp3-version-one-'.$run);
$payload1=['artifact'=>['id'=>$remoteId,'type'=>'meeting','title'=>'VP3 Strategy Meeting','status'=>'processed','start_at_utc'=>'2026-09-23 10:00:00','updated_at'=>'2026-09-23 11:00:00','original_url'=>'/meeting.php?meeting='.$remoteId.'&review=1','version_hash'=>$hash1],
 'transcript'=>['text'=>"Dave: Initial market signal.\nAlex: Validate it against customer evidence.",'segment_count'=>2,'source_hash'=>hash('sha256','transcript-one')],
 'ai_summary'=>['summary'=>'Initial VP3 meeting summary.','key_points'=>[['text'=>'Validate the market signal.']],'decisions'=>[['decision'=>'Research before acting.']],'actions'=>[['action'=>'Check evidence.']],'questions'=>[],'risks'=>[],'topics'=>[['text'=>'Market validation']],'source_hash'=>hash('sha256','summary-one'),'generated_at'=>'2026-09-23 11:01:00']];
$imp1=vp3_connector_import_payload($pdo,$cfg,$owner,(string)$agent['public_id'],$remoteId,['transcript','summary'],$payload1);
p63(($imp1['status']??'')==='current'&&(int)$imp1['source_version_id']>0&&(int)$imp1['transcript_document_object_id']>0&&(int)$imp1['summary_document_object_id']>0,'63D transcript + AI summary import creates Source provenance and both Research Agent documents.');

$q=$pdo->prepare('SELECT * FROM source_versions WHERE id=?');$q->execute([(int)$imp1['source_version_id']]);$sv1=$q->fetch();$expectedExtracted=$payload1['transcript']['text']."\n\nVP3 AI Summary\n".$payload1['ai_summary']['summary'];
p63(hash_equals(hash('sha256',$expectedExtracted),(string)$sv1['content_hash'])&&hash_equals($hash1,(string)$sv1['target_content_hash']),'63D Annotated evidence hash and VP3 remote version hash are stored separately.');
$meta=json_decode((string)$sv1['metadata_json'],true)?:[];p63(($meta['origin']??'')==='vp3'&&($meta['remote_artifact_id']??'')===$remoteId,'63D Source Version keeps VP3 origin/artifact provenance.');
$q=$pdo->prepare('SELECT COUNT(*) FROM project_sources WHERE project_id=? AND source_id=?');$q->execute([(int)$project['id'],(int)$imp1['source_id']]);p63((int)$q->fetchColumn()===1,'63D imported VP3 evidence enters the normal project_sources graph.');

$revisionCount=function(int $objectId)use($pdo): int{$q=$pdo->prepare('SELECT COUNT(*) FROM research_workspace_document_revisions WHERE document_object_id=?');$q->execute([$objectId]);return (int)$q->fetchColumn();};
p63($revisionCount((int)$imp1['transcript_document_object_id'])===1&&$revisionCount((int)$imp1['summary_document_object_id'])===1,'63D initial import creates exactly one revision per Research document.');
vp3_connector_import_payload($pdo,$cfg,$owner,(string)$agent['public_id'],$remoteId,['transcript','summary'],$payload1);
$q=$pdo->prepare('SELECT COUNT(*) FROM source_versions WHERE source_id=?');$q->execute([(int)$imp1['source_id']]);
p63((int)$q->fetchColumn()===1&&$revisionCount((int)$imp1['transcript_document_object_id'])===1&&$revisionCount((int)$imp1['summary_document_object_id'])===1,'63E re-importing the identical VP3 version is a complete no-op.');

$hash2=hash('sha256','vp3-version-two-'.$run);$payload2=$payload1;$payload2['artifact']['version_hash']=$hash2;$payload2['artifact']['updated_at']='2026-09-23 12:00:00';$payload2['transcript']['text'].="\nDave: New customer evidence changed the conclusion.";$payload2['ai_summary']['summary']='Updated VP3 meeting summary with new evidence.';$payload2['ai_summary']['source_hash']=hash('sha256','summary-two');
$imp2=vp3_connector_import_payload($pdo,$cfg,$owner,(string)$agent['public_id'],$remoteId,['transcript','summary'],$payload2);
$q=$pdo->prepare('SELECT COUNT(*) FROM source_versions WHERE source_id=?');$q->execute([(int)$imp2['source_id']]);
p63((int)$q->fetchColumn()===2&&$revisionCount((int)$imp2['transcript_document_object_id'])===2&&$revisionCount((int)$imp2['summary_document_object_id'])===2,'63E changed VP3 material creates a new Source Version and normal document revisions.');
$q=$pdo->prepare('SELECT target_content_hash FROM source_versions WHERE id=?');$q->execute([(int)$imp2['source_version_id']]);p63(hash_equals($hash2,(string)$q->fetchColumn()),'63E import points at the exact updated VP3 version.');

$hash3=hash('sha256','vp3-version-three-'.$run);vp3_connector_reconcile_imports($pdo,$owner,[['id'=>$remoteId,'version_hash'=>$hash3]]);$row=vp3_connector_import_row($pdo,(int)$project['id'],$remoteId);p63(($row['status']??'')==='update_available','63E library reconciliation persists newer VP3 version availability.');
vp3_connector_reconcile_imports($pdo,$owner,[]);$row=vp3_connector_import_row($pdo,(int)$project['id'],$remoteId);p63(($row['status']??'')==='source_unavailable','63E revoked/removed VP3 artifact is marked unavailable rather than deleting evidence.');

vp3_connector_mark_disconnected($pdo,(int)$owner['id']);$disconnected=vp3_connector_connection($pdo,(int)$owner['id'],true);p63(($disconnected['status']??'')==='disconnected'&&empty($disconnected['access_token_cipher'])&&empty($disconnected['refresh_token_cipher']),'63E disconnect wipes future VP3 access credentials.');
$q=$pdo->prepare('SELECT COUNT(*) FROM vp3_imports WHERE id=?');$q->execute([(int)$imp2['id']]);$importStill=(int)$q->fetchColumn();$q=$pdo->prepare('SELECT COUNT(*) FROM source_versions WHERE source_id=?');$q->execute([(int)$imp2['source_id']]);$versionsStill=(int)$q->fetchColumn();
p63($importStill===1&&$versionsStill===2&&$revisionCount((int)$imp2['transcript_document_object_id'])===2,'63E disconnect preserves explicitly imported Research evidence and history.');

echo "Phase 63 VP3 Account Connection & Research Ingestion MariaDB suite passed.\n";
