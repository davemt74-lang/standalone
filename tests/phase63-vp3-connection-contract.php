<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail){$path=$root.'/'.$file;if(!is_file($path)){$fail[]="$label missing: $file";return;}$body=(string)file_get_contents($path);foreach($needles as $needle)if(!str_contains($body,$needle))$fail[]="$label contract missing in $file: $needle";};
$must('database/migrations/20260923_060_vp3_account_connection_research_ingestion.sql',['vp3_connections','vp3_imports','access_token_cipher','refresh_token_cipher','remote_version_hash','source_version_id','transcript_document_object_id','summary_document_object_id','update_available','source_unavailable'],'Phase 63 migration');
$must('app/vp3-connector.php',[
 'aes-256-gcm','vp3_connector_begin','vp3_connector_callback','transcriptions.read','transcriptions.intelligence.read','vp3_connector_attach','vp3_connector_refresh','vp3_connector_disconnect',
 'An Annotated account already uses this email. Sign in to Annotated first','This VP3 account is already connected to another Annotated account.',
 'vp3_connector_import_payload',"['meeting','transcription']",'ensure_source','target_content_hash','project_sources','research_agent_workspace_create_document','research_agent_workspace_save_document',
 "'origin'=>'vp3'","'ai_generated'=>true",'vp3_connector_absolute_source_url','parse_url','vp3_connector_reconcile_imports','source_unavailable','update_available'
],'Phase 63 runtime');
$must('login.php',['Continue with VP3','/vp3/connect.php?mode=login'],'Phase 63 login');
$must('settings.php',['VP3 Account','Connect VP3 Account','Imported Research snapshots were preserved','vp3_disconnect'],'Phase 63 settings');
$must('research.php',['/vp3-library.php','VP3 Library'],'Phase 63 Research navigation');
$must('vp3-library.php',['Add to Research Agent','Update Research snapshot','View original in VP3','VP3 provenance','Transcript','AI summary'],'Phase 63 VP3 Library');
$must('vp3/connect.php',['vp3_connector_begin'],'Phase 63 connect entry');
$must('vp3/callback.php',['vp3_connector_callback','session_regenerate_id'],'Phase 63 callback');
$must('config.example.php',["'vp3' =>","'client_id' => 'annotated'","'client_secret'","/vp3/callback.php"],'Phase 63 config');
$must('docs/phase-63-vp3-account-connection-research-ingestion.md',['63A — VP3 account authorization','63B — Sign in with VP3 / Connect VP3','63C — VP3 Library','63D — Research Agent ingestion','63E — Versioning, permissions & disconnect','63F — Two-repository release gate'],'Phase 63 architecture');

$runtime=(string)file_get_contents($root.'/app/vp3-connector.php');
foreach(['UPDATE research_claims','UPDATE research_findings','agent_action_confirm_execute(','agent_action_execute_capability('] as $forbidden)if(str_contains($runtime,$forbidden))$fail[]="Phase 63 connector must not mutate Research conclusions or execute Agent actions: $forbidden";
if(str_contains($runtime,'WHERE email=?')&&!str_contains($runtime,'Sign in to Annotated first'))$fail[]='Phase 63 must not silently merge VP3 identity by email.';
if(!str_contains($runtime,"\$contentHash=hash('sha256',\$extracted)")||!str_contains($runtime,'target_content_hash'))$fail[]='Phase 63 must preserve local evidence content-hash semantics separately from the VP3 version hash.';

$css=(string)file_get_contents($root.'/assets/css/app.css');$ext=(string)file_get_contents($root.'/extension/landing-app.css');
if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension landing CSS must remain byte-identical.';
foreach(['.vp3LibraryHero','.vp3LibraryGrid','.vp3ArtifactImport','@media(max-width:720px)'] as $needle)if(!str_contains($css,$needle))$fail[]="Phase 63 responsive VP3 Library CSS missing: $needle";
if(glob($root.'/worker/*vp3*'))$fail[]='Phase 63 must not add a separate VP3 polling worker; manual import uses the existing request lifecycle.';

if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}
echo "Phase 63 VP3 Account Connection & Research Ingestion static contracts passed.\n";
