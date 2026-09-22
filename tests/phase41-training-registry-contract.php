<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach(['database/migrations/20260921_040_training_job_registry_controlled_fine_tuning.sql','app/data-training.php','admin/training.php','admin/training-export.php','bin/training-worker.php','tests/phase41-training-registry-db.php','tests/phase41-training-registry-contract.php'] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 41 file missing: '.$file;

$migration=(string)file_get_contents($root.'/database/migrations/20260921_040_training_job_registry_controlled_fine_tuning.sql');
foreach(['data_training_jobs','data_training_attempts','data_training_logs','data_training_events','use_class','dataset_manifest_hash','base_model_version_hash','base_approval_receipt_hash','rights_snapshot_hash','provider_snapshot_hash','runtime_snapshot_hash','package_hash','job_hash','completion_hash','output_model_version_id'] as $needle)if(!str_contains($migration,$needle))$fail[]='Phase 41 migration contract missing: '.$needle;
$avoid('database/migrations/20260921_040_training_job_registry_controlled_fine_tuning.sql','DROP TABLE','Phase 41 migration must be expand-only.');
$avoid('database/migrations/20260921_040_training_job_registry_controlled_fine_tuning.sql','TRUNCATE','Phase 41 migration must be expand-only.');

$runtime=(string)file_get_contents($root.'/app/data-training.php');
foreach(['data_training_ready','data_training_job_create','data_training_job_update_draft','data_training_preflight','data_training_package_inspect','data_training_queue','data_training_job_integrity','data_training_current_use_status','data_training_runtime_snapshot','data_training_approval_receipt_status','data_training_provider_submit','data_training_provider_retrieve','data_training_provider_cancel','data_training_provider_events','data_training_register_output','data_training_completion_integrity','data_training_finalize_provider_success','data_training_handle_worker_exception','data_training_request_cancel','data_training_retry','data_training_manual_complete','data_training_export_package','data_training_handle_worker_exception','data_training_process_next'] as $fn)if(!str_contains($runtime,'function '.$fn))$fail[]='Phase 41 runtime helper missing: '.$fn;
$need('app/data-training.php','Training use class $useClass requires a frozen dataset with purpose $required.','Training jobs must enforce internal vs commercial dataset purpose separation.');
$need('app/data-training.php',"Training base model requires an integrity-valid Phase 40 approval receipt.",'Training base must have a valid Phase 40 approval receipt.');
$need('app/data-training.php',"Phase 41 v1 supports supervised fine-tuning only.",'Phase 41 method scope must be explicit.');
$need('app/data-training.php',"missing_or_invalid_training_example",'Ordinary corpus text must not be silently converted into supervised training labels.');
$need('app/data-training.php',"attribution_blocked_items",'Training preflight must detect frozen items whose attribution requirement cannot be preserved in weights.');
$need('app/data-training.php',"external_provider_acknowledged",'External/provider training transfer must require explicit Admin acknowledgement.');
$need('app/data-training.php',"package_hash",'Training package identity must be frozen and hashed before execution.');
$need('app/data-training.php',"job_hash",'Queued training decisions must have an immutable job hash.');
$need('app/data-training.php','base_approval_receipt_hash','Queued training jobs must freeze the exact Phase 40 approval receipt that authorized the base model.');
$need('app/data-training.php','runtime_snapshot_hash','Queued training jobs must freeze runtime/build identity.');
$need('app/data-training.php','provider_transient_error','Provider polling/cancellation outages must remain retryable operational errors.');
$need('app/data-training.php','output_registration_blocked','Provider-success output handoff failures must be blocked rather than retrained.');
$need('app/data-training.php',"base_approval_receipt_hash",'Queued training jobs must bind the exact Phase 40 approval receipt used for the base model.');
$need('app/data-training.php',"training_runtime_version",'Queued jobs must snapshot the Phase 41/app runtime identity.');
$need('app/data-training.php',"provider_transient_error",'Provider polling/cancellation transport errors must remain retryable operational failures.');
$need('app/data-training.php',"output_registration_blocked",'Provider-success output registration conflicts must block handoff rather than retrain the provider job.');
$need('app/data-training.php',"completion_integrity_failure",'Completed training artifacts must have recomputable integrity validation.');
$need('app/data-training.php',"Current rights or integrity changed while training was active.",'Active training must stop when current training rights/integrity changes.');
$need('app/data-training.php',"provider-model:",'Provider completion must preserve an output artifact reference.');
$need('app/data-training.php',"'origin'=>'annotated_trained'",'Training outputs must re-enter Phase 40 as Annotated-trained model versions.');
$need('app/data-training.php',"Requires Phase 39 evaluation and Phase 40 approval before governed activation.",'Training outputs must remain explicitly pre-approval.');
$need('app/data-training.php',"status='succeeded'",'Training registry must retain terminal provider success state.');
$need('app/data-training.php',"/v1/fine_tuning/jobs",'Provider execution must use a dedicated fine-tuning job endpoint rather than general inference.');
$need('app/data-training.php',"/v1/files",'Provider execution must upload an exact governed training file.');
$need('app/data-training.php',"'purpose'=>'fine-tune'",'Provider file upload must identify the file as training data.');
$need('app/data-training.php',"'type'=>'supervised'",'Provider request must carry the explicit supervised method.');
$avoid('app/data-training.php',"ai_queue_job(",'Fine-tuning must not be dispatched through the generic AI job queue.');
$avoid('app/data-training.php',"UPDATE ai_settings",'Training completion must never change production AI task routing.');
$avoid('app/data-training.php',"data_model_transition(",'Training completion must never approve or activate the output model automatically.');

$need('app/data-model-registry.php','function data_model_version_bind_runtime','Manual/self-hosted training outputs must be able to bind runtime identity while still experimental.');
$need('app/data-model-registry.php',"Runtime binding can change only while a model version is experimental.",'Runtime binding must freeze before candidate promotion.');

foreach(['CREATE JOB','PREFLIGHT','CURRENT GOVERNANCE','MANUAL COMPLETION','PROVIDER','ATTEMPTS','TRAINING LOG','AUDIT EVENTS','Controlled execution worker'] as $needle)$need('admin/training.php',$needle,'Phase 41 Admin Training Registry contract missing: '.$needle);
$need('admin/training.php','Export exact training JSONL','Admin UI must expose the exact governed package export.');
$need('admin/training.php','experimental','Admin UI must communicate that training outputs are experimental.');
$need('admin/training.php','COMPLETION INTEGRITY','Admin UI must surface completed training artifact integrity.');
$need('admin/training.php','approval receipt:','Admin UI must surface current base approval-receipt validity.');
$need('admin/training.php','runtime <?=h(substr','Admin UI must surface the immutable runtime snapshot hash.');
$need('admin/training.php','I acknowledge that Provider API training sends','Admin UI must disclose external/provider training transfer.');
$need('admin/training-export.php',"\$_SERVER['REQUEST_METHOD']!=='POST'",'Training package export must be POST-only.');
$need('admin/training-export.php','require_csrf()','Training package export must require CSRF.');
$need('bin/training-worker.php','data_training_process_next','Training execution must have an out-of-request worker path.');
$need('bin/training-worker.php',"release_worker_heartbeat(\$pdo,'training'",'Training worker must heartbeat into release health.');
$need('app/release.php',"'training'=>'data_training_jobs'",'System Health must include the governed training queue.');
$need('admin/system-health.php',"\$name==='training'",'System Health UI must render Phase 41 training lifecycle states.');
$need('app/shell.php','Training Registry','Training Registry must be a first-class Admin navigation item.');
$need('admin/index.php','Training Registry','Admin Home must surface Training Registry.');
$need('admin/ai.php','Open Training Registry','AI Admin must hand off to Training Registry.');
$need('admin/datasets.php','Training Registry','Dataset Registry must hand off to Training Registry.');
$need('admin/model-registry.php','Training Registry','Model Registry must hand off to Training Registry.');
$need('app/bootstrap.php',"require_once __DIR__ . '/data-training.php';",'Training Registry runtime must load with the application.');

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 41 Training Job Registry & Controlled Fine-Tuning architecture and Admin UI contract suite passed.\n";
