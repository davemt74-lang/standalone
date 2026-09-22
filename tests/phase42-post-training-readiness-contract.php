<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach(['database/migrations/20260922_041_post_training_evaluation_readiness.sql','app/data-post-training.php','admin/post-training.php','admin/post-training-export.php','bin/post-training-worker.php','tests/phase42-post-training-readiness-db.php','tests/phase42-post-training-readiness-contract.php'] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 42 file missing: '.$file;

$migration=(string)file_get_contents($root.'/database/migrations/20260922_041_post_training_evaluation_readiness.sql');
foreach(['data_post_training_plans','data_post_training_suite_links','data_post_training_packets','data_post_training_events','training_completion_hash','output_version_hash','baseline_version_hash','baseline_approval_receipt_hash','benchmark_fingerprint','comparison_hash','packet_hash'] as $needle)if(!str_contains($migration,$needle))$fail[]='Phase 42 migration contract missing: '.$needle;
$avoid('database/migrations/20260922_041_post_training_evaluation_readiness.sql','DROP TABLE','Phase 42 migration must be expand-only.');
$avoid('database/migrations/20260922_041_post_training_evaluation_readiness.sql','TRUNCATE','Phase 42 migration must be expand-only.');

$runtime=(string)file_get_contents($root.'/app/data-post-training.php');
foreach(['data_post_training_ready','data_post_training_plan_create','data_post_training_lineage','data_post_training_template_suite','data_post_training_prepare','data_post_training_plan_integrity','data_post_training_clone_suite','data_post_training_compare_runs','data_post_training_link_candidate_evidence','data_post_training_packet_create','data_post_training_packet_integrity','data_post_training_refresh','data_post_training_process_batch'] as $fn)if(!str_contains($runtime,'function '.$fn))$fail[]='Phase 42 runtime helper missing: '.$fn;
$need('app/data-post-training.php','Baseline run must already be governed evidence linked to the Phase 40 base model version.','Phase 42 must compare against governed Phase 40 baseline evidence.');
$need('app/data-post-training.php','fingerprint-equivalent','Candidate suites must be exact benchmark-definition equivalents.');
$need('app/data-post-training.php',"status='awaiting_review'",'Completed automated evaluation must still wait for configured human review.');
$need('app/data-post-training.php','Phase 40 release gates are not yet satisfied','Readiness must incorporate existing Phase 40 gates.');
$need('app/data-post-training.php','ready_for_human_consideration','Readiness state must explicitly remain a human decision boundary.');
$need('app/data-post-training.php','This packet does not approve, promote, activate, or route the model.','Readiness packet must state its non-promotion boundary.');
$need('app/data-post-training.php','data_model_link_evaluation','Phase 42 may link valid Phase 39 evidence into Phase 40.');
$avoid('app/data-post-training.php','data_model_transition(','Phase 42 must never transition model lifecycle status automatically.');
$avoid('app/data-post-training.php','UPDATE ai_settings','Phase 42 must never change production AI routing.');
$avoid('app/data-post-training.php','ai_generate(','Phase 42 must orchestrate the Phase 39 evaluation harness rather than create a second model-evaluation engine.');
$avoid('app/data-post-training.php','data_training_provider_submit(','Phase 42 must not launch training jobs.');

foreach(['NEW PLAN','BASELINE BENCHMARKS','CURRENT LINEAGE','BENCHMARKS','READINESS PACKET','Ready for human consideration','WORKERS'] as $needle)$need('admin/post-training.php',$needle,'Phase 42 Admin readiness contract missing: '.$needle);
$need('admin/post-training.php','Open Model Registry for human decision','Admin UI must hand readiness to an explicit human Model Registry decision.');
$need('admin/post-training.php','not</strong> an approval or activation decision','Admin UI must state that readiness is not approval.');
$need('admin/post-training-export.php',"\$_SERVER['REQUEST_METHOD']!=='POST'",'Readiness packet export must be POST-only.');
$need('admin/post-training-export.php','require_csrf()','Readiness packet export must require CSRF.');
$need('admin/post-training-export.php','data_post_training_packet_integrity','Packet export must refuse failed packet integrity.');
$need('bin/post-training-worker.php','data_post_training_process_batch','Phase 42 reconciliation must use bounded worker batches.');
$need('bin/post-training-worker.php',"release_worker_heartbeat(\$pdo,'post_training'",'Phase 42 worker must heartbeat into System Health.');
$need('app/release.php',"'post_training'=>'data_post_training_plans'",'System Health must include post-training readiness plans.');
$need('admin/system-health.php',"\$name==='post_training'",'System Health UI must render readiness plan lifecycle states.');
$need('app/shell.php','Post-Training Readiness','Post-Training Readiness must be first-class Admin navigation.');
$need('admin/index.php','Post-Training Readiness','Admin Home must surface Post-Training Readiness.');
$need('admin/training.php','Start post-training evaluation','Training Registry must hand successful outputs into Phase 42.');
$need('admin/model-registry.php','Post-Training Readiness','Model Registry must link to Phase 42.');
$need('admin/evaluations.php','Post-Training Readiness','Evaluation Harness must link to Phase 42.');
$need('app/bootstrap.php',"require_once __DIR__ . '/data-post-training.php';",'Phase 42 runtime must load with the application.');

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 42 Post-Training Evaluation & Promotion Readiness architecture and Admin UI contract suite passed.\n";
