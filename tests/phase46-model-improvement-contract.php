<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach([
 'database/migrations/20260922_045_production_feedback_improvement.sql',
 'app/data-model-improvement.php','admin/model-improvements.php','admin/model-improvements-export.php',
 'tests/phase46-model-improvement-db.php','tests/phase46-model-improvement-contract.php',
 'docs/phase-46-production-feedback-improvement.md'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 46 file missing: '.$file;

$migration=(string)file_get_contents($root.'/database/migrations/20260922_045_production_feedback_improvement.sql');
foreach(['data_model_improvement_cases','data_model_improvement_evidence','data_model_improvement_proposals','data_model_regression_cases','data_model_improvement_events','cluster_key','evidence_hash','content_hash','approval_hash','redaction_attested','rights_attested'] as $needle)if(!str_contains($migration,$needle))$fail[]='Phase 46 migration contract missing: '.$needle;
$avoid('database/migrations/20260922_045_production_feedback_improvement.sql','DROP TABLE','Phase 46 migration must be expand-only.');
$avoid('database/migrations/20260922_045_production_feedback_improvement.sql','TRUNCATE','Phase 46 migration must be expand-only.');

$runtime=(string)file_get_contents($root.'/app/data-model-improvement.php');
foreach([
 'data_model_improvement_ready','data_model_improvement_ingest_incident','data_model_improvement_ingest_negative_signal',
 'data_model_improvement_triage','data_model_improvement_proposal_create','data_model_improvement_proposal_approve',
 'data_model_improvement_proposal_publish','data_model_improvement_create_dataset_draft','data_model_improvement_backfill',
 'data_model_improvement_regression_cases','data_model_improvement_regression_gate','data_model_improvement_cognitive_observations'
] as $fn)if(!str_contains($runtime,'function '.$fn))$fail[]='Phase 46 runtime helper missing: '.$fn;

foreach([
 ['UPDATE ai_settings','Phase 46 must never rewrite AI routing.'],
 ['data_model_deployment_advance(','Phase 46 must never advance deployment state.'],
 ['data_model_deployment_rollback(','Phase 46 must never roll back deployment state.'],
 ['data_model_transition(','Phase 46 must never change Model Registry lifecycle.'],
 ['data_model_rollback(','Phase 46 must never change Model Registry rollback state.'],
 ['data_training_provider_submit(','Phase 46 must never launch provider training.'],
 ['data_training_job_create(','Phase 46 must never create a training job.'],
 ['data_evaluation_run_queue(','Phase 46 must never launch an evaluation run.'],
 ['ai_generate(','Phase 46 must not delegate improvement governance to model inference.']
] as [$needle,$message])$avoid('app/data-model-improvement.php',$needle,$message);

$need('app/data-model-improvement.php',"'evaluation_case','training_example'",'Phase 46 must distinguish evaluation and training proposals.');
$need('app/data-model-improvement.php','Redaction and rights attestations are required before approval.','Phase 46 approval must require both human reuse attestations.');
$need('app/data-model-improvement.php','Proposal content changed after approval.','Phase 46 publication must revalidate the approved content hash.');
$need('app/data-model-improvement.php',"source_object_type='model_improvement_example'",'Phase 46 publication must verify governed corpus admission.');
$need('app/data-model-improvement.php','data_dataset_create','Phase 46 may create only an explicit Dataset Registry draft handoff.');
$need('app/data-model-improvement.php','production_regression_coverage_missing','Phase 46 must report missing permanent regression coverage for future candidates.');
$need('app/data-model-registry.php','data_model_improvement_regression_gate','Phase 40 release gates must consult active Phase 46 production regressions when the runtime is available.');
$need('app/data-model-improvement.php',"'model_regression_case':'model_training_example'",'Phase 46 dataset drafts must keep evaluation/training corpus types separate.');

$need('app/data-attribution.php','if($objectType===\'model_improvement_example\')','Phase 37 corpus governance must understand approved Phase 46 examples.');
$need('app/data-attribution.php',"'source_material_policy'=>'human_sanitized_no_raw_production_text'",'Phase 46 corpus metadata must state the sanitized-source boundary.');
$need('app/data-attribution.php','evaluation\'=>$evaluation,\'training\'=>$training,\'commercial_training\'=>false','Phase 46 examples must never become commercial-training eligible automatically.');
$need('app/data-attribution.php',"'shared_retrieval'=>false",'Phase 46 improvement examples must not silently enter shared retrieval.');

$need('app/data-model-observability.php','data_model_improvement_ingest_incident','Phase 45 incidents must feed Phase 46 best-effort.');
$need('app/data-model-observability.php','data_model_improvement_ingest_negative_signal','Negative production outcomes must feed Phase 46 best-effort.');
$need('app/data-model-observability.php','catch(Throwable $ignored)','Phase 46 ingestion failures must never break Phase 45.');

foreach(['PHASE 46 · PRODUCTION FEEDBACK & MODEL IMPROVEMENT','Human-governed improvement loop','IMPROVEMENT BACKLOG','SANITIZED IMPROVEMENT PROPOSAL','REGRESSION LIBRARY','Create evaluation dataset draft','Create training dataset draft'] as $needle)$need('admin/model-improvements.php',$needle,'Phase 46 Admin workspace contract missing: '.$needle);
$need('admin/model-improvements.php','Do not paste raw production prompts','Phase 46 UI must warn against copying raw production material.');
$need('admin/model-improvements.php','Publish to governed corpus','Phase 46 publication must remain an explicit human action.');

$need('admin/model-improvements-export.php',"\$_SERVER['REQUEST_METHOD']!=='POST'",'Phase 46 case export must be POST-only.');
$need('admin/model-improvements-export.php','require_csrf()','Phase 46 case export must require CSRF.');
$need('admin/model-improvements-export.php','human-sanitized proposals only','Phase 46 export must state its privacy boundary.');
$avoid('admin/model-improvements-export.php','FROM ai_runs','Phase 46 export must not query raw AI runs.');
$avoid('admin/model-improvements-export.php','output_text','Phase 46 export must not include model output text.');

$need('app/notifications.php','model_improvement_case','Improvement cases must integrate with existing Notifications.');
$need('app/cognitive-feed.php','data_model_improvement_cognitive_observations','Improvement cases must integrate with Agent Now.');
$need('app/action-center.php',"'model_improvement_case'=>'review'",'Improvement cases must route to Action Center Review.');
$need('app/shell.php','Model Improvements','Model Improvements must be first-class Admin navigation.');
$need('admin/index.php','Model Improvement Loop','Admin Home must surface Phase 46.');
$need('admin/model-observability.php','Open Improvement Case','Phase 45 incident detail must hand off to Phase 46.');
$need('admin/model-registry.php','PHASE 46 IMPROVEMENT LOOP','Model Registry must show Phase 46 improvement lineage.');
$need('app/bootstrap.php',"require_once __DIR__ . '/data-model-improvement.php';",'Phase 46 runtime must load with the application.');

$need('tests/release-contracts.php','20260922_045_production_feedback_improvement.sql','Release contracts must require the Phase 46 migration.');
$need('tests/ci/run-full-regression.sh','tests/phase46-model-improvement-db.php','Full regression gate must include Phase 46 DB coverage.');
$need('tests/phase44-5-workflow-contract.php','tests/phase46-model-improvement-db.php','Workflow hardening contract must protect Phase 46 full-regression coverage.');

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 46 Production Feedback & Model Improvement architecture and Admin UI contract passed.\n";
