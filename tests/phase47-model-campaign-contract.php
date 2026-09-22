<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach([
 'database/migrations/20260922_046_model_improvement_campaigns.sql',
 'app/data-model-campaigns.php','admin/model-campaigns.php','admin/model-campaigns-export.php',
 'tests/phase47-model-campaign-db.php','tests/phase47-model-campaign-contract.php',
 'docs/phase-47-model-improvement-campaigns.md'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 47 file missing: '.$file;

$migration=(string)file_get_contents($root.'/database/migrations/20260922_046_model_improvement_campaigns.sql');
foreach(['data_model_improvement_campaigns','data_model_improvement_campaign_cases','data_model_improvement_campaign_proposals','data_model_improvement_campaign_events','plan_hash','evaluation_dataset_id','training_dataset_id','baseline_evaluation_suite_id','training_job_id','post_training_plan_id','baseline_evidence_hash','event_hash'] as $needle)if(!str_contains($migration,$needle))$fail[]='Phase 47 migration contract missing: '.$needle;
$avoid('database/migrations/20260922_046_model_improvement_campaigns.sql','DROP TABLE','Phase 47 migration must be expand-only.');
$avoid('database/migrations/20260922_046_model_improvement_campaigns.sql','TRUNCATE','Phase 47 migration must be expand-only.');

$runtime=(string)file_get_contents($root.'/app/data-model-campaigns.php');
foreach([
 'data_model_campaign_ready','data_model_campaign_create','data_model_campaign_add_case','data_model_campaign_add_proposal',
 'data_model_campaign_create_dataset_drafts','data_model_campaign_lock','data_model_campaign_plan_integrity',
 'data_model_campaign_current_use','data_model_campaign_prepare_evaluation_suite','data_model_campaign_prepare_training_draft',
 'data_model_campaign_prepare_post_training_plan','data_model_campaign_sync_status','data_model_campaign_outcome',
 'data_model_campaign_close','data_model_campaign_cognitive_observations'
] as $fn)if(!str_contains($runtime,'function '.$fn))$fail[]='Phase 47 runtime helper missing: '.$fn;

foreach([
 ['data_dataset_freeze(','Phase 47 must never freeze a dataset.'],
 ['data_evaluation_suite_activate(','Phase 47 must never activate an evaluation suite.'],
 ['data_evaluation_run_queue(','Phase 47 must never queue an evaluation run.'],
 ['data_training_queue(','Phase 47 must never queue training.'],
 ['data_training_provider_submit(','Phase 47 must never submit provider training.'],
 ['data_training_prepare_or_submit(','Phase 47 must never execute training preparation/submission.'],
 ['data_training_manual_complete(','Phase 47 must never register a manual training output.'],
 ['data_post_training_prepare(','Phase 47 must never prepare/queue Phase 42 candidate evaluations.'],
 ['data_model_transition(','Phase 47 must never change Model Registry lifecycle.'],
 ['data_model_rollback(','Phase 47 must never invoke Model Registry rollback.'],
 ['data_model_deployment_advance(','Phase 47 must never advance deployment state.'],
 ['data_model_deployment_rollback(','Phase 47 must never roll back deployment state.'],
 ['UPDATE ai_settings','Phase 47 must never rewrite AI routing.'],
 ['ai_generate(','Phase 47 must not delegate governance to model inference.']
] as [$needle,$message])$avoid('app/data-model-campaigns.php',$needle,$message);

$need('app/data-model-campaigns.php','data_dataset_create','Phase 47 must hand campaign-scoped drafts to the existing Dataset Registry.');
$need('app/data-model-campaigns.php','source_object_public_ids','Campaign dataset drafts must select exact approved Phase 46 proposal IDs.');
$need('app/data-model-campaigns.php','data_evaluation_suite_create','Phase 47 may create only a Phase 39 suite draft.');
$need('app/data-model-campaigns.php','data_evaluation_case_add','Phase 47 must materialize selected production regressions as Phase 39 benchmark cases.');
$need('app/data-model-campaigns.php','data_training_job_create','Phase 47 may create only a Phase 41 training draft.');
$need('app/data-model-campaigns.php','data_training_package_inspect','Phase 47 training handoff must prove Phase 41 supervised-package compatibility before creating the draft.');
$need('app/data-model-campaigns.php','data_post_training_plan_create','Phase 47 may create only a Phase 42 readiness draft after training succeeds.');
$need('app/data-model-campaigns.php','base_approval_receipt_hash','Locked campaign identity must bind the exact governed base approval receipt.');
$need('app/data-model-campaigns.php','baseline_evidence_hash','Locked campaign identity must snapshot original production evidence.');
$need('app/data-model-campaigns.php',"'not_reproduced'",'Phase 47 must distinguish output-model evidence that has not reproduced an original failure.');
$need('app/data-model-campaigns.php',"'improved'",'Phase 47 must support metric-backed improvement comparison.');
$need('app/data-model-campaigns.php',"'recurring'",'Phase 47 must surface recurring production failures.');

$need('app/data-datasets.php','source_object_public_ids','Phase 38 selection policy must support exact campaign source-object scoping.');
$need('app/data-datasets.php','source_object_public_id IN','Phase 38 candidate query must enforce the campaign source-object scope.');
$need('admin/datasets.php','CAMPAIGN-SCOPED SELECTION','Dataset Registry must visibly disclose Phase 47 exact-ID scoping before human freeze.');

$need('app/data-attribution.php','$meta[\'training_example\']','Approved Phase 46 training examples must expose Phase 41 supervised metadata.');
$need('app/data-attribution.php',"'attribution_required'=>false,'reason'=>'human_approved_model_improvement_example'",'Human-sanitized approved Phase 46 examples must not be blocked from internal Phase 41 training by attribution-required policy.');
$need('app/data-attribution.php',"'commercial_training'=>false",'Phase 46/47 approved examples must remain commercial-training ineligible.');
$need('app/data-attribution.php',"'shared_retrieval'=>false",'Phase 46/47 approved examples must remain outside shared retrieval.');

foreach(['PHASE 47 · MODEL IMPROVEMENT CAMPAIGNS','Controlled retraining & remediation handoff','1 · SCOPE','2 · APPROVED EVIDENCE','3 · DATASET DRAFTS','4 · LOCK PLAN','5 · HUMAN DATASET FREEZE','6 · REGRESSION BASELINE','7 · TRAINING HANDOFF','8 · POST-TRAINING HANDOFF','CLOSED-LOOP OUTCOME'] as $needle)$need('admin/model-campaigns.php',$needle,'Phase 47 Admin workspace contract missing: '.$needle);
$need('admin/model-campaigns.php','Phase 47 cannot freeze them','Phase 47 UI must state the human dataset-freeze boundary.');
$need('admin/model-campaigns.php','Queue/submission remains an explicit Training Registry action.','Phase 47 UI must state the Phase 41 execution boundary.');
$need('admin/model-campaigns.php','not a claim that the problem can never recur','Phase 47 UI must avoid overstating absence of recurrence.');

$need('admin/model-campaigns-export.php',"\$_SERVER['REQUEST_METHOD']!=='POST'",'Phase 47 campaign export must be POST-only.');
$need('admin/model-campaigns-export.php','require_csrf()','Phase 47 campaign export must require CSRF.');
$need('admin/model-campaigns-export.php','No raw production prompts or model output','Phase 47 export must state its privacy boundary.');
$avoid('admin/model-campaigns-export.php','FROM ai_runs','Phase 47 export must not query raw AI runs.');
$avoid('admin/model-campaigns-export.php','output_text','Phase 47 export must not include raw model output.');

$need('app/notifications.php','model_improvement_campaign','Phase 47 campaigns must integrate with Notifications.');
$need('app/cognitive-feed.php','data_model_campaign_cognitive_observations','Phase 47 campaigns must integrate with Agent Now.');
$need('app/action-center.php',"'model_improvement_campaign'=>'review'",'Phase 47 campaigns must integrate with Action Center Review.');
$need('app/shell.php','Improvement Campaigns','Phase 47 campaigns must be first-class Admin navigation.');
$need('admin/index.php','Improvement Campaigns','Admin Home must surface Phase 47.');
$need('admin/model-improvements.php','Improvement Campaigns','Phase 46 must link forward into Phase 47.');
$need('admin/training.php','Improvement Campaigns','Phase 41 Training Registry must link back to the orchestrating campaign layer.');
$need('admin/post-training.php','Improvement Campaigns','Phase 42 Post-Training Readiness must link back to the campaign layer.');
$need('admin/model-registry.php','PHASE 47 IMPROVEMENT CAMPAIGNS','Model Registry must display Phase 47 campaign lineage.');
$need('app/bootstrap.php',"require_once __DIR__ . '/data-model-campaigns.php';",'Phase 47 runtime must load with the application.');

$need('tests/release-contracts.php','20260922_046_model_improvement_campaigns.sql','Release contracts must require the Phase 47 migration.');
$need('tests/ci/run-full-regression.sh','tests/phase47-model-campaign-db.php','Full regression gate must include Phase 47 DB coverage.');
$need('tests/phase44-5-workflow-contract.php','tests/phase47-model-campaign-db.php','Workflow hardening contract must protect Phase 47 full-regression coverage.');

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 47 Model Improvement Campaigns & Controlled Retraining Handoff architecture and Admin UI contract passed.\n";
