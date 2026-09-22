<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach(['database/migrations/20260921_039_model_registry_candidate_lifecycle.sql','app/data-model-registry.php','admin/model-registry.php','tests/phase40-model-registry-db.php','tests/phase40-model-registry-contract.php'] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 40 file missing: '.$file;

$migration=(string)file_get_contents($root.'/database/migrations/20260921_039_model_registry_candidate_lifecycle.sql');
foreach(['data_model_registry','data_model_versions','data_model_evaluation_links','data_model_promotion_receipts','data_model_events','gate_policy_hash','version_hash','receipt_hash','previous_active_version_id'] as $needle)if(!str_contains($migration,$needle))$fail[]='Phase 40 migration contract missing: '.$needle;
$avoid('database/migrations/20260921_039_model_registry_candidate_lifecycle.sql','DROP TABLE','Phase 40 migration must be expand-only.');
$avoid('database/migrations/20260921_039_model_registry_candidate_lifecycle.sql','TRUNCATE','Phase 40 migration must be expand-only.');

$runtime=(string)file_get_contents($root.'/app/data-model-registry.php');
foreach(['data_model_registry_ready','data_model_registry_create','data_model_version_create','data_model_version_integrity','data_model_link_evaluation','data_model_evidence_snapshot','data_model_gate_evaluate','data_model_receipt_create','data_model_receipt_integrity','data_model_transition','data_model_rollback','data_model_benchmark_fingerprint','data_model_comparison'] as $fn)if(!str_contains($runtime,'function '.$fn))$fail[]='Phase 40 runtime helper missing: '.$fn;
$need('app/data-model-registry.php',"['experimental','candidate','approved','active','deprecated','retired']",'Model Registry must expose the governed lifecycle states.');
$need('app/data-model-registry.php',"Candidate and later model versions are immutable",'Candidate metadata and gate policies must become immutable.');
$need('app/data-model-registry.php',"Only completed evaluation runs can be linked.",'Model evidence must require completed Phase 39 runs.');
$need('app/data-model-registry.php',"Model Registry evidence must come from a completed model-response benchmark.",'Model promotion evidence must come from model-response benchmarks.');
$need('app/data-model-registry.php',"Evaluation run model does not match this registered runtime model version.",'Evaluation evidence must match the registered runtime model.');
$need('app/data-model-registry.php',"Approved and later model evidence links are immutable.",'Approved model evidence must be immutable.');
$need('app/data-model-registry.php',"version_integrity",'Release gates must verify model-version identity integrity.');
$need('app/data-model-registry.php',"runtime_model",'Release gates must verify runtime-model availability.');
$need('app/data-model-registry.php',"regression_comparisons",'No-regression gates must require an actual baseline comparison.');
$need('app/data-model-registry.php',"valid_suite_public_ids",'Required benchmark suites must count only valid model-matched evidence.');
$need('app/data-model-registry.php',"Activation requires a prior approval receipt.",'Activation must require an explicit prior approval decision.');
$need('app/data-model-registry.php',"approval receipt failed integrity",'Activation must reject corrupted approval receipts.');
$need('app/data-model-registry.php',"Rollback target must have previously been active.",'Rollback must be restricted to prior active versions.');
$need('app/data-model-registry.php',"Rollback target active receipt failed integrity validation.",'Rollback must verify historical active receipt integrity.');
$need('app/data-model-registry.php',"SUPERSEDED BY",'Automatic deprecation must create an auditable receipt.');
$need('app/data-model-registry.php',"case_definition_hash",'Cross-model comparison must use stable benchmark case definitions rather than record identity.');
$need('app/data-model-registry.php',"common_benchmarks",'Model comparison must surface only equivalent benchmark definitions.');
$avoid('app/data-model-registry.php','fine_tune','Model Registry must not fine-tune models.');
$avoid('app/data-model-registry.php','fine-tune','Model Registry must not fine-tune models.');
$avoid('app/data-model-registry.php','training_job','Model Registry must not create training jobs.');
$avoid('app/data-model-registry.php',"ai_queue_job(",'Model Registry must not create generic AI or training jobs.');
$avoid('app/data-model-registry.php','UPDATE ai_settings','Governed activation must never silently rewrite AI task routing.');

$admin=(string)file_get_contents($root.'/admin/model-registry.php');
foreach(['CREATE MODEL','REGISTER VERSION','RELEASE GATES','EVALUATION EVIDENCE','LIFECYCLE','ROLLBACK','PROMOTION RECEIPTS','MODEL COMPARISON','AUDIT EVENTS','No training or automatic promotion'] as $needle)if(!str_contains($admin,$needle))$fail[]='Phase 40 Admin Model Registry contract missing: '.$needle;
$need('admin/model-registry.php','does not choose or rank a winner','Model comparison UI must not select a winner.');
$need('admin/model-registry.php','Registry status never silently rewrites AI task routing.','Admin UI must distinguish model governance from task routing.');
$need('admin/model-registry.php','Integrity <strong>','Admin UI must surface promotion receipt integrity.');
$need('app/shell.php','Model Registry','Model Registry must be a first-class Admin navigation item.');
$need('admin/index.php','Model Registry','Admin Home must surface Model Registry.');
$need('admin/ai.php','Open Model Registry','AI Admin must hand off to Model Registry.');
$need('admin/evaluations.php','Model Registry','Evaluation Harness must hand off to Model Registry.');
$need('app/bootstrap.php',"require_once __DIR__ . '/data-model-registry.php';",'Model Registry runtime must load with the application.');

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 40 Model Registry & Candidate Lifecycle architecture and Admin UI contract suite passed.\n";
