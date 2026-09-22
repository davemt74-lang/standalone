<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach([
 'database/migrations/20260922_044_production_model_observability.sql',
 'app/data-model-observability.php','admin/model-observability.php','admin/model-observability-export.php',
 'tests/phase45-model-observability-db.php','tests/phase45-model-observability-contract.php',
 'docs/phase-45-production-model-observability.md'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 45 file missing: '.$file;

$migration=(string)file_get_contents($root.'/database/migrations/20260922_044_production_model_observability.sql');
foreach(['input_cost_per_million_usd','output_cost_per_million_usd','data_model_observations','data_model_outcome_signals','data_model_monitoring_policies','data_model_health_snapshots','data_model_incidents','data_model_incident_events','observation_hash','metrics_hash','policy_hash','evidence_hash'] as $needle)if(!str_contains($migration,$needle))$fail[]='Phase 45 migration contract missing: '.$needle;
$avoid('database/migrations/20260922_044_production_model_observability.sql','DROP TABLE','Phase 45 migration must be expand-only.');
$avoid('database/migrations/20260922_044_production_model_observability.sql','TRUNCATE','Phase 45 migration must be expand-only.');

$runtime=(string)file_get_contents($root.'/app/data-model-observability.php');
foreach([
 'data_model_observability_ready','data_model_observability_record_ai_run','data_model_observability_signal_for_run',
 'data_model_observability_policy_update','data_model_observability_evaluate_deployment','data_model_observability_snapshot_integrity',
 'data_model_observability_incident_upsert','data_model_observability_incident_update','data_model_observability_backfill',
 'data_model_observability_cognitive_observations'
] as $fn)if(!str_contains($runtime,'function '.$fn))$fail[]='Phase 45 runtime helper missing: '.$fn;

foreach([
 ['UPDATE ai_settings','Phase 45 observability must never rewrite AI task routing.'],
 ['data_model_deployment_advance(','Phase 45 must never advance a Phase 44 rollout.'],
 ['data_model_deployment_rollback(','Phase 45 must never invoke Phase 44 rollback.'],
 ['data_model_transition(','Phase 45 must never change governed Model Registry lifecycle.'],
 ['data_model_rollback(','Phase 45 must never change governed Model Registry rollback state.'],
 ['data_training_provider_submit(','Phase 45 must never launch training.'],
 ['ai_generate(','Phase 45 monitoring must not delegate health decisions to model inference.']
] as [$needle,$message])$avoid('app/data-model-observability.php',$needle,$message);

$need('app/ai.php','data_model_observability_record_ai_run','Every AI run path must attempt production telemetry recording.');
$need('app/ai.php','microtime(true)','AI run telemetry must measure request latency.');
$need('app/ai.php','catch(Throwable $ignored)','Observability failures must fail open for user AI responses.');
$need('app/data-model-deployment.php','deployment_shadow_route','Phase 44 Shadow jobs must carry their mirrored production route into Phase 45.');
$need('worker/ai-worker.php','data_model_observability_signal_for_run','AI worker must record production outcome signals.');
$need('worker/ai-worker.php','stale_discarded','Stale worker results must become negative outcome evidence.');
$need('worker/ai-worker.php','downstream_applied','Successfully applied intelligence must become outcome evidence.');

foreach(['PHASE 45 · PRODUCTION MODEL OBSERVABILITY','Model health & outcomes','MONITORING POLICY','HEALTH SNAPSHOTS','RUN TELEMETRY','NEEDS ATTENTION','Rollback remains an explicit Phase 44 action'] as $needle)$need('admin/model-observability.php',$needle,'Phase 45 Admin observability contract missing: '.$needle);
$need('admin/model-observability-export.php',"\$_SERVER['REQUEST_METHOD']!=='POST'",'Phase 45 health export must be POST-only.');
$need('admin/model-observability-export.php','require_csrf()','Phase 45 health export must require CSRF.');
$need('admin/model-observability-export.php','Telemetry/outcomes only; no prompts or model output text.','Phase 45 export must state its privacy boundary.');
$avoid('admin/model-observability-export.php','output_text','Phase 45 export must not include model output text.');
$avoid('admin/model-observability-export.php','prompt','Phase 45 export must not include prompts.');

$need('app/notifications.php','model_observability_incident','Model health incidents must integrate with existing Notifications.');
$need('app/cognitive-feed.php','data_model_observability_cognitive_observations','Model health incidents must integrate with Agent Now / Cognitive Feed.');
$need('app/cognitive-feed.php',"'model_health_incident'=>96",'Model health incidents must rank as Needs Attention evidence.');
$need('app/action-center.php',"'model_health_incident'=>'review'",'Model health incidents must route to Action Center Review.');
$need('app/shell.php','Model Health','Model Health must be first-class Admin navigation.');
$need('admin/index.php','Model Health & Outcomes','Admin Home must surface Phase 45.');
$need('admin/model-deployment.php','Open Production Health','Phase 44 deployments must link to Phase 45 production health.');
$need('admin/model-registry.php','PHASE 45 PRODUCTION HEALTH','Model Registry must display Phase 45 production evidence.');

$need('admin/ai.php','input_cost_per_million_usd','AI Admin must support optional input token pricing.');
$need('admin/ai.php','output_cost_per_million_usd','AI Admin must support optional output token pricing.');
$need('admin/ai.php','Open Model Health','AI Admin must link to Phase 45 observability.');

$need('app/bootstrap.php',"require_once __DIR__ . '/data-model-observability.php';",'Phase 45 runtime must load with the application.');
$need('tests/release-contracts.php','20260922_044_production_model_observability.sql','Release contracts must require the Phase 45 migration.');

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 45 Production Model Observability & Outcome Monitoring architecture and Admin UI contract passed.\n";
