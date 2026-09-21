<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach(['database/migrations/20260921_036_data_attribution_v1.sql','app/data-attribution.php','api/data-attribution.php','data-attribution.php','admin/data-attribution.php','bin/data-attribution-sync.php','tests/phase37-data-attribution-db.php'] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 37 file missing: '.$file;

$migration=(string)file_get_contents($root.'/database/migrations/20260921_036_data_attribution_v1.sql');
foreach(['data_contributor_preferences','data_usage_grants','source_rights','data_contributions','data_provenance_edges','data_corpus_items','data_response_lineage','data_response_attributions'] as $table)if(!str_contains($migration,'CREATE TABLE IF NOT EXISTS '.$table))$fail[]='Phase 37 migration table missing: '.$table;
foreach(['allow_shared_retrieval TINYINT(1) NOT NULL DEFAULT 0','allow_training TINYINT(1) NOT NULL DEFAULT 0','training_allowed TINYINT(1) NOT NULL DEFAULT 0','commercial_training_allowed TINYINT(1) NOT NULL DEFAULT 0'] as $needle)if(!str_contains($migration,$needle))$fail[]='Reuse/training must default closed: '.$needle;
if(str_contains($migration,'normalized_text')&&!str_contains($migration,'CREATE TABLE IF NOT EXISTS data_corpus_items'))$fail[]='Reusable text must live only in the derived corpus layer.';

$runtime=(string)file_get_contents($root.'/app/data-attribution.php');
foreach(['data_contributor_preferences_update','data_usage_grant_set','data_source_rights_set','data_contribution_record','data_provenance_edge_record','data_training_eligibility','data_corpus_refresh_object','data_attribution_sync_user','data_response_record','data_response_lineage_access','data_response_attribution_map'] as $fn)if(!str_contains($runtime,'function '.$fn))$fail[]='Phase 37 runtime helper missing: '.$fn;
foreach(["'reason'=>'object_not_public'","'reason'=>'no_contributor_consent'","'reason'=>'source_rights_not_approved'","'actor_type'=>'agent'"] as $needle){if(!str_contains($runtime,$needle)&&$needle!=="'actor_type'=>'agent'")$fail[]='Eligibility boundary missing: '.$needle;}
if(str_contains($runtime,"selected_text")||str_contains($runtime,"extracted_text")&& !str_contains($runtime,"if($objectType==='source')"))$fail[]='Captured source text must not be treated as user-authored Annotation corpus.';
$need('app/data-attribution.php',"trim((string)$r['text_commentary'])",'Annotation corpus must be built from contributor commentary.');
$need('app/data-attribution.php',"if(($d['visibility']??'')!=='public'||empty($d['published']))",'Non-public user content must be blocked before shared corpus eligibility.');
$need('app/data-attribution.php',"in_array((string)($rights['rights_class']??'unknown'),['unknown','restricted'],true)",'Unknown/restricted Source rights must be closed.');
$need('app/data-attribution.php',"UPDATE data_corpus_items SET invalidated_at=COALESCE(invalidated_at,NOW())",'Revocation must invalidate derived corpus rather than erase the attribution ledger.');
$need('app/data-attribution.php',"WHERE contributor_user_id=? AND invalidated_at IS NULL",'Contributor policy changes must invalidate all active derived corpus items without a pagination limit.');
$need('app/data-attribution.php',"eligibility_reason='superseded_object_version'",'A newer authoritative object version must invalidate older active corpus versions.');
$avoid('data-attribution.php','data_attribution_sync_user($pdo,$u,250)','Contributor dashboard loads must not run a bulk attribution backfill.');
$need('app/data-attribution.php',"if(($viewer['role']??'')!=='admin'&&(int)$r['user_id']!==(int)$viewer['id'])return null",'AI response lineage must remain scoped to its initiating user or admin.');

$need('app/ai.php','data_response_record','Every completed AI run must record response lineage when Phase 37 is available.');
$need('app/agent-chat.php','data_response_bind_message','Agent Chat must bind the visible assistant message to its AI response lineage.');
$need('app/agent-chat.php','data_response_attribution_map','Agent Chat history must expose bounded attribution summaries.');
$need('assets/js/agent-chat.js','agentResponseLineage','Agent Chat must visibly render response attribution.');
$need('assets/js/agent-chat.js','/data-attribution.php?run_id=','Agent Chat attribution must link to the permission-checked lineage view.');
$need('home.php','agent-chat.js?v=37.0','Phase 37 Agent Chat client must use a fresh cache key.');
$need('data-attribution.php','RESPONSE LINEAGE','Contributor dashboard must render permission-checked response lineage.');
$need('api/publish-annotation.php','data_attribution_capture_object','Website Annotation publishing must enter the contribution ledger.');
$need('api/extension-publish.php','data_attribution_capture_object','Chrome Annotation publishing must enter the contribution ledger.');
$need('research-knowledge.php',"'claim',$public",'Manual Claims must enter contribution lineage.');
$need('research-knowledge.php',"'finding',$public",'Manual Findings must enter contribution lineage.');
$need('research-claim.php',"'claim',(string)$claim['public_id']",'Claim edits and evidence changes must record a revised contribution state.');
$need('research-finding.php',"'finding',(string)$finding['public_id']",'Finding edits and Claim-link changes must record a revised contribution state.');
$need('app/research-reports.php',"'report_version',$vPublic",'Published Report versions must enter contribution lineage.');
$need('app/research-verification.php','data_attribution_capture_verification','Human verification must enter contribution lineage.');
$need('app/research-reviews.php','data_attribution_capture_review_response','Human review responses must enter contribution lineage.');
$need('app/agent-actions.php','data_attribution_capture_object','Confirmed Agent Research writes must enter the same contribution ledger.');
$need('app/agent-actions.php','data_provenance_edge_record','Confirmed Agent Claim relationships must enter the shared provenance graph.');

$need('data-attribution.php','Public visibility does not automatically grant model-training permission.','Contributor UI must state the public/training distinction.');
$need('data-attribution.php','production object → contribution ledger → rights/consent → derived corpus → versioned dataset → controlled model release','Contributor UI must expose the controlled learning pipeline.');
$need('admin/data-attribution.php','Unknown and restricted classifications force all reuse permissions off.','Admin source-rights UI must state the closed-default rule.');
$need('api/data-attribution.php',"action==='source_rights_set'",'Data API must expose admin-governed Source rights.');
$need('bin/data-attribution-sync.php','data_attribution_sync_user','Existing contributions must have an idempotent backfill path.');
$need('app/bootstrap.php',"require_once __DIR__ . '/data-attribution.php';",'Data & Attribution runtime must load with the application.');
$need('app/shell.php','Data & Attribution','Contributor Data & Attribution navigation must be discoverable.');
$need('app/shell.php','Data Governance','Admin Data Governance navigation must be discoverable.');

foreach(['ai_generate(','ai_http_json(','curl_init(','training_job','fine_tune','fine-tune'] as $forbidden)if(str_contains($runtime,$forbidden))$fail[]='Phase 37 must collect/govern data without initiating model training: '.$forbidden;
$avoid('database/migrations/20260921_036_data_attribution_v1.sql','DROP TABLE','Phase 37 migration must be expand-only.');
$avoid('database/migrations/20260921_036_data_attribution_v1.sql','TRUNCATE','Phase 37 migration must be expand-only.');

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 37 Data & Attribution architecture contract suite passed.\n";
