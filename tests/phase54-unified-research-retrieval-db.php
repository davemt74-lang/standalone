<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','functions','access','notifications','rate-limit','conversations','research-automation','research-workspace','research-agents','research-agent-workspace','research-retrieval','workspace-context','object-handoff','agent-chat'] as $lib)require_once $root.'/app/'.$lib.'.php';
function p54(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
function p54throws(callable $fn,string $message): void {try{$fn();}catch(Throwable $e){echo "PASS: $message\n";return;}throw new RuntimeException('FAIL: '.$message);}

p54(research_retrieval_ready($pdo),'Phase 54 retrieval schema is available.');
p54(job_table_meta('research_retrieval_jobs')['schedule']==='available_at','Retrieval queue participates in generic worker leases.');
p54(abs(research_retrieval_cosine([1,0],[1,0])-1.0)<0.0001,'Semantic reranker cosine math is deterministic.');

$run='p54'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{
  $username=substr(strtolower($name).'_'.$run,0,48);
  $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,live_presence_mode) VALUES(?,?,?,?,NOW(),'active',?,'cloaked')")
    ->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);
  $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$owner=$makeUser('RetrievalOwner','admin');$researcher=$makeUser('RetrievalResearcher');$outsider=$makeUser('RetrievalOutsider');
$teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'Retrieval Team']);$teamId=(int)$pdo->lastInsertId();
foreach([[$owner,'owner'],[$researcher,'researcher']] as [$member,$role])$pdo->prepare('INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,?)')->execute([$teamId,$member['id'],$role]);

$agent=research_agent_create($pdo,$owner,['name'=>'Unified Retrieval Agent','description'=>'Phase 54 retrieval test.','team_id'=>$teamPublic,'cadence'=>'manual','timezone_name'=>'UTC']);
$project=research_agent_workspace_project($pdo,$owner,(string)$agent['public_id']);$projectResearcher=research_agent_workspace_project($pdo,$researcher,(string)$agent['public_id']);
p54($project&&$projectResearcher,'Owner and Team researcher can access the Research Agent project.');

$folder=research_agent_workspace_create_folder($pdo,$owner,$project,'Evidence Folder');
$doc=research_agent_workspace_create_document($pdo,$owner,$project,[
  'title'=>'Pilot Pricing Memo','document_type'=>'memo','parent_id'=>$folder['public_id'],
  'content_html'=>'<h1>Pilot Plan</h1><p>orangesignal orangesignal orangesignal oldrevisiontheta folderdelta. The pilot price is 4200.</p><h2>Risks</h2><p>Implementation timing remains open.</p>'
],false);
$sticky=research_agent_workspace_create_sticky($pdo,$owner,$project,['title'=>'Root Sticky','body'=>'orangesignal folderdelta appears in a root sticky.','color'=>'yellow']);

$source=ensure_source($pdo,'https://example.com/'.$run.'/source','Phase 54 Primary Source');
$sourceText='The primary source describes launchwindowalpha and supporting public evidence.';
$pdo->prepare("INSERT INTO source_versions(source_id,version_number,final_url,title,extracted_text,content_hash) VALUES(?,1,?,?,?,?)")
  ->execute([$source['id'],'https://example.com/'.$run.'/source','Phase 54 Primary Source',$sourceText,hash('sha256',$sourceText)]);
$sourceVersion=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE sources SET current_version_id=?,last_checked_at=NOW() WHERE id=?')->execute([$sourceVersion,$source['id']]);
$pdo->prepare('INSERT INTO project_sources(project_id,source_id,added_by_user_id) VALUES(?,?,?)')->execute([$project['id'],$source['id'],$owner['id']]);

$capturePublic=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text,start_seconds,end_seconds) VALUES(?,?,?,?, 'video_clip',?,?,?)")
  ->execute([$capturePublic,$source['id'],$sourceVersion,$owner['id'],'publicannotationbeta evidence excerpt',12.0,18.0]);$captureId=(int)$pdo->lastInsertId();
$annotationPublic=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status) VALUES(?,?,?,?,?,?,'public','published')")
  ->execute([$annotationPublic,$owner['id'],$source['id'],$sourceVersion,$captureId,'publicannotationbeta commentary']);$annotationId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO project_annotations(project_id,annotation_id,added_by_user_id) VALUES(?,?,?)')->execute([$project['id'],$annotationId,$owner['id']]);
research_agent_workspace_desktop_move($pdo,$owner,$project,'annotation',$annotationPublic,(string)$folder['public_id']);

$privateCapture=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?, 'text',?)")
  ->execute([$privateCapture,$source['id'],$sourceVersion,$owner['id'],'secretprivategamma owner only']);$privateCaptureId=(int)$pdo->lastInsertId();
$privateAnn=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status) VALUES(?,?,?,?,?,?,'private','published')")
  ->execute([$privateAnn,$owner['id'],$source['id'],$sourceVersion,$privateCaptureId,'secretprivategamma confidential']);$privateAnnId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO project_annotations(project_id,annotation_id,added_by_user_id) VALUES(?,?,?)')->execute([$project['id'],$privateAnnId,$owner['id']]);

$bookmark=research_agent_workspace_create_bookmark($pdo,$owner,$project,['url'=>'https://example.net/'.$run.'/bookmark','title'=>'Deployment Bookmark','description'=>'bookmarkepsilon deployment checklist']);
$upload=research_agent_workspace_register_upload($pdo,$owner,$project,[
  'storage_uri'=>'private://research-upload/'.$run.'/proposal.pdf','original_name'=>'proposal.pdf','mime_type'=>'application/pdf','file_size'=>2048,'checksum'=>hash('sha256','p54pdf'),'title'=>'Proposal PDF','parent_id'=>$folder['public_id']
]);
$pdfText="Page one intro and context.\fPage two contains pricingdelta and commercial terms.";
$pdo->prepare("UPDATE research_workspace_uploads SET processing_status='ready',extracted_text=?,page_count=2,updated_at=NOW() WHERE object_id=?")->execute([$pdfText,(int)$upload['id']]);

$imageUpload=research_agent_workspace_register_upload($pdo,$owner,$project,[
  'storage_uri'=>'private://research-upload/'.$run.'/visual.png','original_name'=>'visual.png','mime_type'=>'image/png','file_size'=>512,'checksum'=>hash('sha256','p54image'),'title'=>'Visual Evidence Omega'
]);
$pdo->prepare("UPDATE research_workspace_uploads SET processing_status='ready',extracted_text=NULL,updated_at=NOW() WHERE object_id=?")->execute([(int)$imageUpload['id']]);

$semanticDoc=research_agent_workspace_create_document($pdo,$owner,$project,[
  'title'=>'Semantic Concept Note','document_type'=>'note',
  'content_html'=>'<p>semanticalpha describes the latent launch concept without using the user query words.</p>'
],false);


$recording=research_agent_workspace_register_recording($pdo,$owner,$project,[
  'storage_uri'=>'private://research-recording/'.$run.'/interview.webm','original_name'=>'interview.webm','mime_type'=>'audio/webm','file_size'=>4096,'checksum'=>hash('sha256','p54audio'),'title'=>'Customer Interview','parent_id'=>$folder['public_id'],'duration_seconds'=>90,'recording_source'=>'browser'
]);
$segments=[
 ['start'=>5.0,'end'=>11.0,'text'=>'Initial context for the interview.'],
 ['start'=>42.0,'end'=>49.0,'text'=>'decisionomega customer approved the pilot direction.']
];
$pdo->prepare("UPDATE research_workspace_recording_transcripts SET status='ready',raw_text=?,segments_json=?,provider='fixture',model='fixture',updated_at=NOW() WHERE object_id=?")
  ->execute(['Initial context for the interview. decisionomega customer approved the pilot direction.',json_encode($segments), (int)$recording['id']]);

$records=research_retrieval_collect_records($pdo,(int)$project['id']);
$types=array_count_values(array_map(fn($x)=>(string)$x['object_type'],$records));
foreach(['source','annotation','document','bookmark','sticky','upload','recording'] as $type)p54(($types[$type]??0)>=1,'Unified corpus includes '.$type.' evidence.');
$rebuilt=research_retrieval_rebuild_project($pdo,[],(int)$project['id'],$records,false);
p54(($rebuilt['documents']??0)>=7,'Project retrieval index rebuilds the complete corpus.');
$q=$pdo->prepare("SELECT status,document_count,chunk_count,state_hash FROM research_retrieval_projects WHERE project_id=?");$q->execute([$project['id']]);$state=$q->fetch();
p54($state&&$state['status']==='ready'&&(int)$state['document_count']>=7&&(int)$state['chunk_count']>=7,'Index state records ready document/chunk counts.');

$titleOnlySearch=research_retrieval_search($pdo,[],$researcher,(string)$project['public_id'],'Visual Evidence Omega',['type'=>'upload'],10,true);
p54(count(array_filter($titleOnlySearch['results'],fn($x)=>($x['public_id']??'')===$imageUpload['public_id']))===1,'Title-only Research objects remain searchable even when they have no extracted chunk text.');

$embedScript=tempnam(sys_get_temp_dir(),'p54-embed-');
if($embedScript===false)throw new RuntimeException('Unable to create semantic retrieval fixture.');
file_put_contents($embedScript,<<<'PHP'
<?php
$text=(string)file_get_contents($argv[1]??'');
$match=str_contains($text,'semanticalpha')||str_contains($text,'meaningbeta');
file_put_contents($argv[2]??'',json_encode($match?[1.0,0.0]:[0.0,1.0]));
PHP);
$semanticConfig=['research_retrieval'=>[
  'embedding_command'=>escapeshellarg(PHP_BINARY).' '.escapeshellarg($embedScript).' {input} {output}',
  'embedding_provider'=>'fixture','embedding_model'=>'fixture-v1'
]];
research_retrieval_rebuild_project($pdo,$semanticConfig,(int)$project['id'],null,true);
$semanticSearch=research_retrieval_search($pdo,$semanticConfig,$researcher,(string)$project['public_id'],'meaningbeta',[],10,true);
p54(($semanticSearch['mode']??'')==='hybrid','Configured embeddings activate hybrid Research retrieval.');
p54(count(array_filter($semanticSearch['results'],fn($x)=>($x['public_id']??'')===$semanticDoc['public_id']))===1,'Hybrid retrieval returns a semantic-only match with no lexical query overlap.');

$q=$pdo->prepare("SELECT c.embedding_status,c.embedding_json FROM research_retrieval_documents d JOIN research_retrieval_chunks c ON c.document_id=d.id WHERE d.project_id=? AND d.object_type='document' AND d.object_public_id=? LIMIT 1");
$q->execute([$project['id'],$semanticDoc['public_id']]);$semanticBeforeMove=$q->fetch();
p54($semanticBeforeMove&&$semanticBeforeMove['embedding_status']==='ready'&&!empty($semanticBeforeMove['embedding_json']),'Semantic fixture document has a ready embedding before a folder move.');
research_agent_workspace_move($pdo,$owner,(string)$semanticDoc['public_id'],(string)$folder['public_id']);
$semanticAfterMove=research_retrieval_search($pdo,$semanticConfig,$researcher,(string)$project['public_id'],'meaningbeta',['folder_id'=>(string)$folder['public_id']],10,true);
p54(($semanticAfterMove['mode']??'')==='hybrid'&&count(array_filter($semanticAfterMove['results'],fn($x)=>($x['public_id']??'')===$semanticDoc['public_id']))===1,'Folder moves refresh retrieval scope without dropping hybrid semantic results.');
$q=$pdo->prepare("SELECT c.embedding_status,c.embedding_json,d.folder_public_id FROM research_retrieval_documents d JOIN research_retrieval_chunks c ON c.document_id=d.id WHERE d.project_id=? AND d.object_type='document' AND d.object_public_id=? LIMIT 1");
$q->execute([$project['id'],$semanticDoc['public_id']]);$semanticMoved=$q->fetch();
p54($semanticMoved&&$semanticMoved['embedding_status']==='ready'&&!empty($semanticMoved['embedding_json'])&&$semanticMoved['folder_public_id']===$folder['public_id'],'Incremental rebuild preserves unchanged chunk embeddings while updating folder metadata.');



$pdfSearch=research_retrieval_search($pdo,[],$researcher,(string)$project['public_id'],'pricingdelta',[],10,true);
$pdfResult=current(array_filter($pdfSearch['results'],fn($x)=>($x['object_type']??'')==='upload'));
p54($pdfResult&&($pdfResult['locator_label']??'')==='Page 2','PDF retrieval returns the exact page locator.');

$recordingSearch=research_retrieval_search($pdo,[],$researcher,(string)$project['public_id'],'decisionomega',[],10,true);
$recordingResult=current(array_filter($recordingSearch['results'],fn($x)=>($x['object_type']??'')==='recording'));
p54($recordingResult&&($recordingResult['locator_label']??'')==='00:42–00:49','Recording retrieval returns timestamp evidence locators.');

$segmentsShifted=[
 ['start'=>5.0,'end'=>11.0,'text'=>'Initial context for the interview.'],
 ['start'=>44.0,'end'=>50.0,'text'=>'decisionomega customer approved the pilot direction.']
];
$pdo->prepare("UPDATE research_workspace_recording_transcripts SET segments_json=?,updated_at=NOW() WHERE object_id=?")
  ->execute([json_encode($segmentsShifted),(int)$recording['id']]);
$recordingLocatorRefresh=research_retrieval_search($pdo,[],$researcher,(string)$project['public_id'],'decisionomega',[],10,true);
$recordingLocatorResult=current(array_filter($recordingLocatorRefresh['results'],fn($x)=>($x['object_type']??'')==='recording'));
p54($recordingLocatorResult&&($recordingLocatorResult['locator_label']??'')==='00:44–00:50','Locator-only transcript changes invalidate derived chunks and refresh citation timestamps.');


$annotationSearch=research_retrieval_search($pdo,[],$researcher,(string)$project['public_id'],'publicannotationbeta',[],10,true);
$annotationResult=current(array_filter($annotationSearch['results'],fn($x)=>($x['object_type']??'')==='annotation'));
p54($annotationResult&&($annotationResult['locator_label']??'')==='00:12–00:18','Media annotations preserve captured timestamp locators.');

$sourceSearch=research_retrieval_search($pdo,[],$researcher,(string)$project['public_id'],'launchwindowalpha',['type'=>'source'],10,true);
p54(count($sourceSearch['results'])===1&&$sourceSearch['results'][0]['object_type']==='source','Project Source content participates in the same retrieval layer.');

$folderSearch=research_retrieval_search($pdo,[],$researcher,(string)$project['public_id'],'folderdelta',['folder_id'=>(string)$folder['public_id']],20,true);
p54(count($folderSearch['results'])>=1&&count(array_filter($folderSearch['results'],fn($x)=>($x['object_type']??'')==='sticky'))===0,'Folder-scoped retrieval excludes matching evidence outside the selected folder.');
p54(count(array_filter($folderSearch['results'],fn($x)=>($x['object_type']??'')==='document'))===1,'Folder-scoped retrieval keeps matching evidence inside the folder.');

$trashFolder=research_agent_workspace_create_folder($pdo,$owner,$project,'Temporary Evidence Folder');
$trashCapture=$pub('cap');$pdo->prepare("INSERT INTO captures(public_id,source_id,source_version_id,user_id,capture_type,selected_text) VALUES(?,?,?,?, 'text',?)")
  ->execute([$trashCapture,$source['id'],$sourceVersion,$owner['id'],'trashedfolderzeta evidence']);$trashCaptureId=(int)$pdo->lastInsertId();
$trashAnn=$pub('ann');$pdo->prepare("INSERT INTO annotations(public_id,user_id,source_id,source_version_id,capture_id,text_commentary,visibility,status) VALUES(?,?,?,?,?,?,'public','published')")
  ->execute([$trashAnn,$owner['id'],$source['id'],$sourceVersion,$trashCaptureId,'trashedfolderzeta commentary']);$trashAnnId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO project_annotations(project_id,annotation_id,added_by_user_id) VALUES(?,?,?)')->execute([$project['id'],$trashAnnId,$owner['id']]);
research_agent_workspace_desktop_move($pdo,$owner,$project,'annotation',$trashAnn,(string)$trashFolder['public_id']);
$trashActive=research_retrieval_search($pdo,[],$researcher,(string)$project['public_id'],'trashedfolderzeta',[],10,true);
p54(count(array_filter($trashActive['results'],fn($x)=>($x['public_id']??'')===$trashAnn))===1,'Annotation in an active folder participates in unified retrieval.');
research_agent_workspace_trash($pdo,$owner,(string)$trashFolder['public_id']);
$trashHidden=research_retrieval_search($pdo,[],$researcher,(string)$project['public_id'],'trashedfolderzeta',[],10,true);
p54(count(array_filter($trashHidden['results'],fn($x)=>($x['public_id']??'')===$trashAnn))===0,'Trashing a folder removes linked annotation evidence from unified retrieval instead of leaking it to root.');
research_agent_workspace_restore($pdo,$owner,(string)$trashFolder['public_id']);
$trashRestored=research_retrieval_search($pdo,[],$researcher,(string)$project['public_id'],'trashedfolderzeta',[],10,true);
p54(count(array_filter($trashRestored['results'],fn($x)=>($x['public_id']??'')===$trashAnn))===1,'Restoring a folder restores its linked annotation evidence to retrieval.');


$privateOwner=research_retrieval_search($pdo,[],$owner,(string)$project['public_id'],'secretprivategamma',[],10,true);
p54(count(array_filter($privateOwner['results'],fn($x)=>($x['public_id']??'')===$privateAnn))===1,'Private annotation remains retrievable to its owner.');
$privateResearcher=research_retrieval_search($pdo,[],$researcher,(string)$project['public_id'],'secretprivategamma',[],10,true);
p54(count($privateResearcher['results'])===0,'Derived index never leaks a private annotation to another Team member.');
$privateRelated=research_retrieval_related($pdo,[],$researcher,(string)$project['public_id'],'annotation',$privateAnn,8);
p54($privateRelated===[],'Related-evidence discovery cannot use an unauthorized private object as its retrieval seed.');


$related=research_retrieval_related($pdo,[],$owner,(string)$project['public_id'],'document',(string)$doc['public_id'],8);
p54(count(array_filter($related,fn($x)=>($x['object_type']??'')==='sticky'))>=1,'Related evidence discovers a separate object through shared indexed concepts.');

$beforeHash=(string)$state['state_hash'];$freshDoc=research_agent_workspace_object($pdo,$owner,(string)$doc['public_id'],false);
$saved=research_agent_workspace_save_document($pdo,$owner,(string)$doc['public_id'],[
 'title'=>'Pilot Pricing Memo','content_html'=>'<h1>Pilot Plan</h1><p>newrevisiontheta replaces the earlier revision.</p>','base_revision'=>(int)$freshDoc['revision_number']
]);
$newSearch=research_retrieval_search($pdo,[],$owner,(string)$project['public_id'],'newrevisiontheta',[],10,true);
p54(count(array_filter($newSearch['results'],fn($x)=>($x['public_id']??'')===$doc['public_id']))===1,'Search self-heals a stale index immediately after a document edit.');
$oldSearch=research_retrieval_search($pdo,[],$owner,(string)$project['public_id'],'oldrevisiontheta',[],10,true);
p54(count(array_filter($oldSearch['results'],fn($x)=>($x['public_id']??'')===$doc['public_id']))===0,'Self-healing index removes superseded document content.');
$q=$pdo->prepare('SELECT state_hash FROM research_retrieval_projects WHERE project_id=?');$q->execute([$project['id']]);$afterHash=(string)$q->fetchColumn();
p54($afterHash!==$beforeHash,'Index state hash changes when authoritative Research content changes.');

$ctx=research_retrieval_context($pdo,[],$owner,(string)$project['public_id'],'pricingdelta',[],5);
p54(str_contains((string)$ctx['text'],'[UPLOAD '.$upload['public_id'].' · Page 2]'),'Agent retrieval context preserves exact object and page citation labels.');
p54(count($ctx['refs'])>=1&&($ctx['refs'][0]['type']??'')==='upload','Agent retrieval context returns authoritative refs rather than copied workspace state.');

$q=$pdo->prepare('SELECT COUNT(*) FROM research_retrieval_queries WHERE project_id=? AND user_id=?');$q->execute([$project['id'],$researcher['id']]);
p54((int)$q->fetchColumn()>=5,'Research retrieval queries are recorded in an auditable project/user ledger.');

research_retrieval_queue_project($pdo,(int)$project['id']);
$q=$pdo->prepare("UPDATE research_retrieval_jobs SET status='processing',claim_token='fixtureclaim',lease_expires_at=DATE_ADD(NOW(),INTERVAL 10 MINUTE),started_at=NOW(),rerun_requested=0 WHERE project_id=?");$q->execute([$project['id']]);
research_retrieval_queue_project($pdo,(int)$project['id']);
$q=$pdo->prepare('SELECT status,rerun_requested,claim_token FROM research_retrieval_jobs WHERE project_id=?');$q->execute([$project['id']]);$queued=$q->fetch();
p54($queued&&$queued['status']==='processing'&&(int)$queued['rerun_requested']===1&&$queued['claim_token']==='fixtureclaim','A mutation during active indexing preserves the worker lease and requests a rerun.');
$pdo->prepare("UPDATE research_retrieval_jobs SET status='queued',claim_token=NULL,lease_expires_at=NULL,rerun_requested=0,attempts=0,started_at=NULL WHERE project_id=?")->execute([$project['id']]);

p54throws(fn()=>research_retrieval_search($pdo,[],$outsider,(string)$project['public_id'],'pricingdelta',[],10,true),'Non-member cannot query a Team Research index.');
$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$teamId,$researcher['id']]);
p54throws(fn()=>research_retrieval_search($pdo,[],$researcher,(string)$project['public_id'],'pricingdelta',[],10,true),'Removing a Team member immediately revokes retrieval access.');

@unlink($embedScript);
echo "Phase 54 Unified Research Knowledge & Retrieval MariaDB suite passed.\n";
