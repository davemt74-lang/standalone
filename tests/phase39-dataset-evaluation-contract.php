<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach(['database/migrations/20260921_038_dataset_evaluation_benchmark_harness.sql','app/data-evaluations.php','admin/evaluations.php','admin/evaluation-export.php','bin/evaluation-worker.php','tests/phase39-dataset-evaluation-db.php','tests/phase39-dataset-evaluation-contract.php'] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 39 file missing: '.$file;

$migration=(string)file_get_contents($root.'/database/migrations/20260921_038_dataset_evaluation_benchmark_harness.sql');
foreach(['data_evaluation_suites','data_evaluation_cases','data_evaluation_runs','data_evaluation_results','data_evaluation_reviews','data_evaluation_events','dataset_manifest_hash','suite_config_hash','cases_hash','result_hash','run_hash'] as $needle)if(!str_contains($migration,$needle))$fail[]='Phase 39 migration contract missing: '.$needle;
$avoid('database/migrations/20260921_038_dataset_evaluation_benchmark_harness.sql','DROP TABLE','Phase 39 migration must be expand-only.');
$avoid('database/migrations/20260921_038_dataset_evaluation_benchmark_harness.sql','TRUNCATE','Phase 39 migration must be expand-only.');

$runtime=(string)file_get_contents($root.'/app/data-evaluations.php');
foreach(['data_evaluation_ready','data_evaluation_suite_create','data_evaluation_case_add','data_evaluation_suite_activate','data_evaluation_run_queue','data_evaluation_rank_rows','data_evaluation_model_prompt','data_evaluation_execute_run','data_evaluation_process_next','data_evaluation_run_integrity','data_evaluation_run_requeue','data_evaluation_review_save','data_evaluation_set_baseline','data_evaluation_regression','data_evaluation_export_run'] as $fn)if(!str_contains($runtime,'function '.$fn))$fail[]='Phase 39 runtime helper missing: '.$fn;
$need('app/data-evaluations.php',"if(\$d['purpose']!=='evaluation')",'Formal benchmarks must require a dataset explicitly governed for evaluation use.');
$need('app/data-evaluations.php',"if(\$s['status']!=='draft')throw new RuntimeException('Active or retired evaluation suites are immutable.')",'Active evaluation-suite configuration must be immutable.');
$need('app/data-evaluations.php',"Cases can only be changed while the suite is draft.",'Benchmark cases must freeze when the suite activates.');
$need('app/data-evaluations.php',"dataset_manifest_hash",'Each run must snapshot the frozen dataset manifest hash.');
$need('app/data-evaluations.php',"suite_config_hash",'Each run must snapshot suite configuration.');
$need('app/data-evaluations.php',"cases_hash",'Each run must snapshot its benchmark case set.');
$need('app/data-evaluations.php',"data_evaluation_result_material",'Result integrity must hash retrieved evidence, response, citations, metrics, and pass state.');
$need('app/data-evaluations.php',"data_evaluation_run_integrity",'Completed runs must have independent integrity verification.');
$need('app/data-evaluations.php',"A run with failed integrity cannot become a regression baseline.",'Corrupted runs must not become regression baselines.');
$need('app/data-evaluations.php',"current_run_integrity_failure",'Regression comparison must stop when the current run fails integrity.');
$need('app/data-evaluations.php',"baseline_integrity_failure",'Regression comparison must stop when the baseline fails integrity.');
$need('app/data-evaluations.php',"Only failed or stale processing runs can be requeued.",'Run recovery must be bounded to failed or stale processing runs.');
$need('app/data-evaluations.php',"SELECT id FROM data_evaluation_runs WHERE status='queued' ORDER BY created_at,id LIMIT 1 FOR UPDATE",'Workers must claim queued runs under a row lock.');
$need('app/data-evaluations.php',"data_evaluation_review_save",'Human review must remain a first-class evaluation layer.');
$need('app/data-evaluations.php',"expected_citation",'Model benchmark automated metrics must explicitly include expected-citation grounding.');
$need('app/data-evaluations.php',"grounded_token_ratio",'Model benchmark automated metrics must expose transparent grounding overlap.');
$need('app/data-evaluations.php',"reference_token_f1",'Reference-answer similarity must be an explicit heuristic metric rather than a hidden score.');
$need('app/data-evaluations.php',"latency_ms",'Evaluation scorecards must capture per-case latency.');
$need('app/data-evaluations.php',"input_tokens",'Model evaluation scorecards must capture input-token usage when available.');
$need('app/data-evaluations.php',"output_tokens",'Model evaluation scorecards must capture output-token usage when available.');
$need('app/data-evaluations.php',"schema'=>'annotated.evaluation-run.v1'",'Completed benchmark runs must support a reproducible export artifact.');
$avoid('app/data-evaluations.php','fine_tune','Evaluation Harness must not fine-tune models.');
$avoid('app/data-evaluations.php','fine-tune','Evaluation Harness must not fine-tune models.');
$avoid('app/data-evaluations.php','training_job','Evaluation Harness must not create training jobs.');
$avoid('app/data-evaluations.php',"ai_queue_job(",'Evaluation Harness must not dispatch generic AI/training jobs.');
$need('app/data-evaluations.php',"ai_generate(",'Model benchmarks may use configured inference without creating training workflows.');

$need('admin/evaluations.php','CREATE SUITE','Admin Evaluation Harness must expose suite creation.');
$need('admin/evaluations.php','BENCHMARK CASES','Admin Evaluation Harness must expose benchmark-case authoring.');
$need('admin/evaluations.php','Activate evaluation suite','Admin Evaluation Harness must make suite immutability explicit.');
$need('admin/evaluations.php','Queue benchmark run','Admin Evaluation Harness must expose queued benchmark execution.');
$need('admin/evaluations.php','RUN INTEGRITY','Admin Evaluation Harness must surface completed-run integrity.');
$need('admin/evaluations.php','Set as regression baseline','Admin Evaluation Harness must expose explicit baseline selection.');
$need('admin/evaluations.php','BASELINE COMPARISON','Admin Evaluation Harness must surface regression comparison.');
$need('admin/evaluations.php','Save human review','Admin Evaluation Harness must expose human review controls.');
$need('admin/evaluations.php','Automated comparison is diagnostic, not a release verdict.','Admin UI must not present automated regression metrics as an authoritative release decision.');
$need('admin/evaluations.php','no model weights are modified','Admin UI must state that inference benchmarking does not modify model weights.');
$need('admin/evaluations.php','Requeue run','Admin UI must expose bounded failed/stale run recovery.');
$need('admin/evaluations.php','AUTOMATED SCORECARD','Admin UI must expose retrieval/model scorecard telemetry.');
$need('admin/evaluations.php','Export benchmark JSON','Admin UI must expose completed-run artifact export.');
$need('admin/evaluation-export.php',"\$_SERVER['REQUEST_METHOD']!=='POST'",'Benchmark artifact export must be POST-only.');
$need('admin/evaluation-export.php','require_csrf()','Benchmark artifact export must require CSRF protection.');
$need('bin/evaluation-worker.php','data_evaluation_process_next','Evaluation runs must have an out-of-request worker path.');
$need('app/shell.php','Evaluation Harness','Evaluation Harness must be a first-class Admin navigation item.');
$need('admin/index.php','Evaluation Harness','Admin Home must surface Evaluation Harness.');
$need('admin/datasets.php','Open Evaluation Harness','Dataset Registry must hand off to Evaluation Harness.');
$need('app/bootstrap.php',"require_once __DIR__ . '/data-evaluations.php';",'Evaluation Harness runtime must load with the application.');

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 39 Dataset Evaluation & Benchmark Harness architecture and Admin UI contract suite passed.\n";
