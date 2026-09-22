<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/installer.php';require_once $root.'/app/functions.php';require_once $root.'/app/data-attribution.php';require_once $root.'/app/data-datasets.php';require_once $root.'/app/notifications.php';require_once $root.'/app/ai.php';require_once $root.'/app/data-evaluations.php';require_once $root.'/app/data-model-registry.php';require_once $root.'/app/data-training.php';require_once $root.'/app/data-post-training.php';require_once $root.'/app/data-model-release.php';require_once $root.'/app/data-model-deployment.php';require_once $root.'/app/data-model-observability.php';
function p45(bool $v,string $m): void {if(!$v)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
p45(data_model_observability_ready($pdo),'Phase 45 Production Model Observability schema is available');

$q=$pdo->query("SELECT public_id FROM data_model_deployments ORDER BY id DESC LIMIT 1");$deploymentPublic=(string)($q->fetchColumn()?:'');if($deploymentPublic==='')throw new RuntimeException('Phase 45 requires the Phase 44 deployment fixture.');
$q=$pdo->prepare('SELECT id FROM data_model_deployments WHERE public_id=?');$q->execute([$deploymentPublic]);$deploymentId=(int)$q->fetchColumn();$deployment=data_model_observability_deployment_row($pdo,$deploymentId);if(!$deployment)throw new RuntimeException('Phase 44 deployment fixture is unavailable.');
p45(in_array('research',(array)$deployment['route_keys'],true),'Phase 45 fixture uses a governed research route');

$q=$pdo->query("SELECT id,public_id,role FROM users WHERE display_name='DeploymentCreator' ORDER BY id DESC LIMIT 1");$admin=$q->fetch();if(!$admin)throw new RuntimeException('Phase 44 admin fixture missing.');
$q=$pdo->query("SELECT id,public_id,role FROM users WHERE display_name='DeploymentOutsider' ORDER BY id DESC LIMIT 1");$outsider=$q->fetch();if(!$outsider)throw new RuntimeException('Phase 44 outsider fixture missing.');

$modelId=(int)$deployment['candidate_ai_model_id'];$q=$pdo->prepare('SELECT input_cost_per_million_usd,output_cost_per_million_usd FROM ai_models WHERE id=?');$q->execute([$modelId]);$oldPricing=$q->fetch()?:['input_cost_per_million_usd'=>null,'output_cost_per_million_usd'=>null];$pdo->prepare('UPDATE ai_models SET input_cost_per_million_usd=1.25,output_cost_per_million_usd=2.50 WHERE id=?')->execute([$modelId]);

$routingBefore=$pdo->query('SELECT admin_default_model_id,pro_default_model_id,source_monitor_model_id,moderation_model_id,research_model_id,transcript_cleanup_model_id,annotation_intelligence_model_id FROM ai_settings WHERE id=1')->fetch();
$deploymentBefore=$pdo->prepare('SELECT status,current_traffic_percent,revision FROM data_model_deployments WHERE id=?');$deploymentBefore->execute([$deploymentId]);$deploymentBefore=$deploymentBefore->fetch();
$versionsBefore=$pdo->prepare('SELECT id,status FROM data_model_versions WHERE id IN (?,?) ORDER BY id');$versionsBefore->execute([$deployment['candidate_version_id'],$deployment['rollback_version_id']]);$versionsBefore=$versionsBefore->fetchAll();
$trainingBefore=(int)$pdo->query('SELECT COUNT(*) FROM data_training_jobs')->fetchColumn();

$insertRun=function(string $task,string $status,int $latency,int $input,int $output,?string $error=null,array $refs=[],?int $runtimeModelId=null)use($pdo,$admin,$modelId,$deploymentPublic): array{
    $runtimeModelId=$runtimeModelId?:$modelId;$public=ulid_like();$pdo->prepare("INSERT INTO ai_runs(public_id,user_id,initiated_by,task_type,model_id,prompt_version,scope_type,scope_public_id,input_refs_json,status,error_text,input_tokens,output_tokens,created_at,completed_at) VALUES(?,?,'admin',?,?,'v1','model_deployment',?,?,?, ?,?,?,DATE_SUB(NOW(),INTERVAL 2 SECOND),NOW())")->execute([$public,$admin['id'],$task,$runtimeModelId,$deploymentPublic,json_encode($refs,JSON_UNESCAPED_SLASHES),$status,$error,$input,$output]);
    return ['id'=>(int)$pdo->lastInsertId(),'public_id'=>$public,'latency'=>$latency];
};

$run1=$insertRun('deployment_shadow','completed',1200,1000,200,null,[['type'=>'deployment_shadow_route','id'=>'research']]);$obs1=data_model_observability_record_ai_run($pdo,$run1['id'],$run1['latency'],false);
p45($obs1&&(int)$obs1['deployment_id']===$deploymentId&&(int)$obs1['is_shadow']===1&&$obs1['route_key']==='research','Shadow inference is attributed to its governed production route and deployment');
p45((int)$obs1['estimated_cost_micros']===1750,'configured token pricing produces deterministic micro-USD cost telemetry');
p45(data_model_observability_observation_integrity($obs1)['ok'],'AI run observation is integrity-valid');
$tampered=$obs1;$tampered['latency_ms']=(int)$tampered['latency_ms']+1;p45(!data_model_observability_observation_integrity($tampered)['ok'],'observation hash detects telemetry tampering');

$run2=$insertRun('research_workspace_intelligence','failed',5000,800,0,'AI provider connection timed out.');$obs2=data_model_observability_record_ai_run($pdo,$run2['id'],$run2['latency'],false);
p45($obs2&&$obs2['run_status']==='failed'&&$obs2['error_class']==='timeout','failed inference records normalized provider/runtime failure evidence');

$baselineRun=$insertRun('research_workspace_intelligence','completed',500,700,150,null,[],(int)$deployment['rollback_ai_model_id']);$baselineObs=data_model_observability_record_ai_run($pdo,$baselineRun['id'],$baselineRun['latency'],false);
p45($baselineObs&&(int)$baselineObs['model_version_id']===(int)$deployment['rollback_version_id'],'concurrent baseline inference is attributed to the rollback/baseline governed version');

$signal1=data_model_observability_signal_for_run($pdo,$run1['public_id'],'reviewer_positive',1.0,'human_review','phase45-review',(int)$admin['id'],'Reviewer accepted the output.');
$signal2=data_model_observability_signal_for_run($pdo,$run2['public_id'],'downstream_failure',-1.0,'system','phase45-failure',null,'Provider failure prevented downstream use.');
p45($signal1&&hash_equals((string)$signal1['signal_hash'],data_model_observability_signal_hash($signal1)),'positive human/product outcome signal is hashed and traceable');
p45($signal2&&hash_equals((string)$signal2['signal_hash'],data_model_observability_signal_hash($signal2)),'negative outcome signal is hashed and traceable');

$policy=data_model_observability_policy_update($pdo,$admin,$deploymentId,[
 'enabled'=>'1','window_minutes'=>120,'min_samples'=>1,'max_failure_rate'=>0.10,'max_p95_latency_ms'=>60000,
 'max_failure_rate_delta'=>0.10,'max_p95_latency_delta_ms'=>1000,
 'max_avg_input_tokens'=>'','max_avg_output_tokens'=>'','max_avg_cost_micros'=>'','min_avg_outcome_score'=>''
]);
p45(hash_equals((string)$policy['policy_hash'],data_model_observability_policy_hash($policy)),'monitoring policy hash binds the configured thresholds');

$snapshots=data_model_observability_evaluate_deployment($pdo,$deploymentId,true,'research');p45(count($snapshots)===1,'forced health evaluation creates one research-route snapshot');$snapshot=$snapshots[0];$metrics=is_array($snapshot['metrics']??null)?$snapshot['metrics']:(json_decode((string)$snapshot['metrics_json'],true)?:[]);
p45(data_model_observability_snapshot_integrity($snapshot)['ok'],'health snapshot metrics are tamper-evident');
p45((int)$snapshot['sample_count']>=2&&$snapshot['health_status']==='attention','rolling health snapshot evaluates production samples against the configured policy');
p45((float)$metrics['failure_rate']>=0.30,'rolling health snapshot measures the observed failure rate');
p45(($metrics['candidate']['sample_count']??0)>=2&&($metrics['baseline']['sample_count']??0)>=1,'health snapshot separates candidate and concurrent baseline cohorts');
p45((float)($metrics['candidate_vs_baseline']['failure_rate_delta']??0)>=0.5,'health snapshot detects candidate failure-rate drift against the baseline');
p45((float)($metrics['candidate_vs_baseline']['p95_latency_delta_ms']??0)>=4500,'health snapshot measures candidate p95 latency drift against the baseline');

$incidents=data_model_observability_incidents($pdo,50);$incident=null;foreach($incidents as $i)if((int)$i['deployment_id']===$deploymentId&&$i['route_key']==='research'&&$i['metric_name']==='failure_rate'&&$i['status']==='open'){$incident=$i;break;}
p45($incident!==null&&$incident['severity']==='critical','threshold breach creates a deduplicated critical production-health incident');
$driftIncident=null;foreach($incidents as $i)if((int)$i['deployment_id']===$deploymentId&&$i['route_key']==='research'&&$i['metric_name']==='candidate_failure_rate_delta'&&$i['status']==='open'){$driftIncident=$i;break;}
p45($driftIncident!==null&&$driftIncident['severity']==='critical','candidate-vs-baseline failure drift creates its own production-health incident');
$reviewed=data_model_observability_incident_update($pdo,$admin,(string)$incident['public_id'],'investigating','Human is reviewing evidence before any deployment action.');p45($reviewed['status']==='investigating','incident state changes require explicit human review');
$q=$pdo->prepare('SELECT COUNT(*) FROM data_model_incident_events WHERE incident_id=? AND event_type=\'human_review\'');$q->execute([$incident['id']]);p45((int)$q->fetchColumn()>=1,'incident human review is recorded in the audit event ledger');

$routingAfter=$pdo->query('SELECT admin_default_model_id,pro_default_model_id,source_monitor_model_id,moderation_model_id,research_model_id,transcript_cleanup_model_id,annotation_intelligence_model_id FROM ai_settings WHERE id=1')->fetch();
$q=$pdo->prepare('SELECT status,current_traffic_percent,revision FROM data_model_deployments WHERE id=?');$q->execute([$deploymentId]);$deploymentAfter=$q->fetch();
$q=$pdo->prepare('SELECT id,status FROM data_model_versions WHERE id IN (?,?) ORDER BY id');$q->execute([$deployment['candidate_version_id'],$deployment['rollback_version_id']]);$versionsAfter=$q->fetchAll();
$trainingAfter=(int)$pdo->query('SELECT COUNT(*) FROM data_training_jobs')->fetchColumn();
p45($routingAfter===$routingBefore,'health evaluation and incidents never change AI routing');
p45($deploymentAfter===$deploymentBefore,'health evaluation and incidents never change Phase 44 deployment state or traffic');
p45($versionsAfter===$versionsBefore,'health evaluation and incidents never change governed model lifecycle');
p45($trainingAfter===$trainingBefore,'health evaluation and incidents never launch training');

$blocked=false;try{data_model_observability_policy_update($pdo,$outsider,$deploymentId,['enabled'=>'1']);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'Administrator');}p45($blocked,'monitoring policy changes are administrator-only');

$run3=$insertRun('research_summary','completed',900,600,120,null);$backfilled=data_model_observability_backfill($pdo,$admin,20);$q=$pdo->prepare('SELECT COUNT(*) FROM data_model_observations WHERE ai_run_id=?');$q->execute([$run3['id']]);p45($backfilled>=1&&(int)$q->fetchColumn()===1,'backfill captures existing completed AI runs without replaying inference');
$again=data_model_observability_record_ai_run($pdo,$run3['id'],900,false);$q->execute([$run3['id']]);p45($again&&(int)$q->fetchColumn()===1,'AI run observation recording is idempotent');

$pdo->prepare('UPDATE ai_models SET input_cost_per_million_usd=?,output_cost_per_million_usd=? WHERE id=?')->execute([$oldPricing['input_cost_per_million_usd'],$oldPricing['output_cost_per_million_usd'],$modelId]);
echo "Phase 45 Production Model Observability & Outcome Monitoring MariaDB suite passed.\n";
