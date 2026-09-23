<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use($root,&$fail): void{
    $path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}
    if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;
};
$avoid=function(string $file,string $needle,string $message)use($root,&$fail): void{
    $path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;
};

foreach([
 'database/migrations/20260923_052_unified_research_retrieval.sql',
 'app/research-retrieval.php','api/research-retrieval.php','worker/research-retrieval-worker.php',
 'docs/phase-54-unified-research-retrieval.md','tests/phase54-unified-research-retrieval-db.php'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 54 file missing: '.$file;

$migration=(string)file_get_contents($root.'/database/migrations/20260923_052_unified_research_retrieval.sql');
foreach(['research_retrieval_projects','research_retrieval_documents','research_retrieval_chunks','research_retrieval_jobs','research_retrieval_queries'] as $table)
    if(!str_contains($migration,'CREATE TABLE IF NOT EXISTS '.$table))$fail[]='Phase 54 table missing: '.$table;
$need('database/migrations/20260923_052_unified_research_retrieval.sql','FULLTEXT KEY ft_retrieval_chunk_text','Unified retrieval must provide database-native lexical search.');
$need('database/migrations/20260923_052_unified_research_retrieval.sql','rerun_requested TINYINT(1)','Index jobs must preserve refreshes that arrive during an active lease.');

$need('app/research-retrieval.php','function research_retrieval_collect_records','Phase 54 must build one corpus from authoritative Research objects.');
foreach(["'source'","'annotation'","'document'","'bookmark'","'sticky'","'upload'","'recording'"] as $type)
    $need('app/research-retrieval.php',$type,'Unified corpus must include '.$type.'.');
$need('app/research-retrieval.php','function research_retrieval_pdf_chunks','PDF evidence must support page-level locators.');
$need('app/research-retrieval.php','function research_retrieval_recording_chunks','Recording evidence must support timestamp locators.');
$need('app/research-retrieval.php','function research_retrieval_folder_scope','Retrieval must understand folder descendants.');
$need('app/research-retrieval.php','function research_retrieval_result_allowed','Every indexed result must be re-authorized against its live object.');
$need('app/research-retrieval.php','function research_retrieval_annotation_allowed','Derived retrieval must apply stricter live visibility rules to indexed Annotations.');
$need('app/research-retrieval.php',"if((\$row['visibility']??'')==='team'",'Team annotation retrieval must require actual annotation-Team membership rather than project membership alone.');
$need('app/research-retrieval.php',"LEFT JOIN research_retrieval_chunks c ON c.document_id=d.id",'Lexical search must retain title-only objects that have no extracted chunks.');
$need('app/research-retrieval.php','$semanticRows','Hybrid retrieval must consider semantic candidates beyond lexical matches.');
$need('app/research-retrieval.php',"c.embedding_status='ready'",'Semantic candidate search must use only ready embeddings.');
$need('app/research-retrieval.php',"(rdp.folder_object_id IS NULL OR folder.status='active')",'Annotations inside trashed folders must be excluded from the derived corpus.');

$need('app/research-retrieval.php','function research_retrieval_search','Research search must use the unified index.');
$need('app/research-retrieval.php','$chunksChanged','Index refreshes must preserve chunks and embeddings for unchanged evidence.');
$need('app/research-retrieval.php','chunkSignature','Evidence fingerprints must include locator metadata so citation-only changes invalidate derived chunks.');

$need('app/research-retrieval.php','function research_retrieval_context','Agent context must come from ranked retrieval results.');
$need('app/research-retrieval.php','function research_retrieval_related','Phase 54 must expose related evidence.');
$need('app/research-retrieval.php',"research_retrieval_result_allowed(\$pdo,\$viewer,['object_type'=>\$type,'object_public_id'=>\$publicId])",'Related evidence must authorize the seed object before reading derived index content.');

$need('app/research-retrieval.php','function research_retrieval_embed_text','Embeddings must remain provider-neutral and optional.');
$need('app/research-retrieval.php',"research_retrieval_embed_command(\$config)!==''",'Hybrid retrieval must activate only when embeddings are configured.');
$need('app/research-retrieval.php','research_retrieval_queries','Search provenance must be auditable.');
$need('worker/research-retrieval-worker.php',"job_claim(\$pdo,'research_retrieval_jobs'",'Retrieval rebuilds must use leased worker claims.');
$need('worker/research-retrieval-worker.php','rerun_requested','Worker completion must not lose a newer reindex request.');
$need('app/jobs.php',"'research_retrieval_jobs'",'Retrieval jobs must participate in generic lease recovery.');
$need('app/release.php',"'research_retrieval'=>",'Production health must monitor the retrieval worker.');
$need('app/release.php',"'research_retrieval'=>'research_retrieval_jobs'",'Production health must expose the retrieval queue.');

$need('worker/research-file-worker.php','page_count=COALESCE','PDF extraction must persist page count for provenance.');
$need('worker/research-transcription-worker.php',"\$decoded['segments']", 'Recording transcription must accept timestamped structured segments.');
$need('config.example.php',"'research_retrieval' => [",'Optional semantic retrieval configuration must be documented.');
$need('config.example.php',"'embedding_command' => ''",'Lexical retrieval must remain the zero-configuration default.');

$need('api/research-retrieval.php',"\$action==='search'",'Research Library must have a governed search API.');
$need('api/research-retrieval.php',"\$action==='related'",'Related-evidence discovery must be exposed by the same governed API.');
$need('api/research-retrieval.php',"\$action==='rebuild'",'Authorized users must be able to queue a retrieval rebuild.');
$need('api/research-workspace-objects.php','research_retrieval_queue_project','Workspace mutations must invalidate the unified index.');
$need('api/research-workspace-upload.php','research_retrieval_queue_project','New uploads must invalidate the unified index.');

$need('home.php','data-research-library-filter="source"','Research Library must include project Sources.');
$need('home.php','data-research-library-folder','Research Library must expose folder scope.');
$need('home.php','data-research-library-status','Research Library must expose processing-state filtering.');
$need('home.php','data-research-library-date-from','Research Library must expose date filtering.');
$need('home.php','data-research-library-ask','Research Library must support multi-select Ask Agent.');
$need('assets/js/research-agent-workspace-ui.js','/api/research-retrieval.php','Research Library must use server-backed unified retrieval.');
$need('assets/js/research-agent-workspace-ui.js','librarySelected.size>=6','Multi-select Agent context must remain bounded.');
$need('assets/js/research-agent-workspace-ui.js','loadRelated(item)','Research Library must surface related evidence.');
$need('assets/js/research-agent-workspace-ui.js','askAgentAboutLibrarySelection','Selected retrieval results must hand authoritative objects to Agent Chat.');
$need('assets/js/research-agent-workspace-ui.js','folder_id:String(libraryFolder?.value','Folder scope must be sent to the retrieval API.');
$need('assets/css/app.css','/* Phase 54 — Unified Research retrieval console */','Phase 54 retrieval UI styling must ship.');
$need('home.php','/assets/css/app.css?v=56.0','Phase 54 stylesheet must have a fresh cache key.');
$need('home.php','research-agent-workspace-ui.js?v=56.0','Phase 54 Library runtime must have a fresh cache key.');

$need('app/agent-chat.php','research_retrieval_context($pdo,$config,$viewer','Research Agent Chat must retrieve against the latest user prompt.');
$need('app/agent-chat.php',"research_retrieval_ready(\$pdo))?['text'=>'','refs'=>[]]:ai_research_context",'Phase 54 must suppress the old broad source/annotation dump when unified retrieval is available.');
$need('app/agent-chat.php','preserve the supplied locator exactly','Agent system instructions must preserve evidence locators.');
$need('app/research-agent-workspace.php','function research_agent_workspace_sticky_context','Sticky search results must be usable as Agent context.');
$avoid('app/workspace-context.php','research_retrieval_chunks','Cross-surface workspace continuity must not copy derived retrieval content.');

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 54 Unified Research Knowledge & Retrieval contract passed.\n";
