<?php
declare(strict_types=1);

/**
 * Phase 45 — Production Model Observability & Outcome Monitoring.
 *
 * Read/measure/alert only. This module never changes ai_settings, model lifecycle,
 * deployment stages, rollout percentages, training state, or release decisions.
 */
function data_model_observability_ready(PDO $pdo): bool {
    static $ready=null;if($ready!==null)return $ready;
    try{$ready=data_model_deployment_ready($pdo)
        &&installer_table_exists($pdo,'data_model_observations')
        &&installer_table_exists($pdo,'data_model_outcome_signals')
        &&installer_table_exists($pdo,'data_model_monitoring_policies')
        &&installer_table_exists($pdo,'data_model_health_snapshots')
        &&installer_table_exists($pdo,'data_model_incidents')
        &&installer_table_exists($pdo,'data_model_incident_events');}
    catch(Throwable $e){$ready=false;}return $ready;
}
function data_model_observability_require_admin(array $viewer): void {
    if(($viewer['role']??'')!=='admin')throw new RuntimeException('Administrator access is required for Model Observability operations.');
}
function data_model_observability_error_class(?string $error): ?string {
    $e=strtolower(trim((string)$error));if($e==='')return null;
    return match(true){
        str_contains($e,'timeout')||str_contains($e,'timed out')=>'timeout',
        str_contains($e,'connection')||str_contains($e,'could not resolve')=>'connection',
        str_contains($e,'disabled')||str_contains($e,'unavailable')=>'model_unavailable',
        str_contains($e,'no text')||str_contains($e,'invalid ai provider response')=>'invalid_response',
        str_contains($e,'rate limit')||str_contains($e,'429')=>'rate_limit',
        str_contains($e,'lease')||str_contains($e,'stale')||str_contains($e,'discarded')=>'stale_result',
        default=>'provider_or_runtime_error',
    };
}
function data_model_observability_deployment_row(PDO $pdo,int $deploymentId): ?array {
    $q=$pdo->prepare('SELECT d.*,cv.ai_model_id candidate_ai_model_id,rv.ai_model_id rollback_ai_model_id,cv.id candidate_version_id,rv.id rollback_version_id,cv.public_id candidate_version_public_id,rv.public_id rollback_version_public_id FROM data_model_deployments d JOIN data_model_versions cv ON cv.id=d.model_version_id JOIN data_model_versions rv ON rv.id=d.rollback_version_id WHERE d.id=? LIMIT 1');
    $q->execute([$deploymentId]);$r=$q->fetch();if(!$r)return null;$r['route_keys']=json_decode((string)$r['route_keys_json'],true)?:[];return $r;
}
function data_model_observability_route_from_run(array $run): string {
    $task=(string)$run['task_type'];
    if($task==='deployment_shadow'){
        $refs=json_decode((string)($run['input_refs_json']??''),true);
        if(is_array($refs))foreach($refs as $ref)if(($ref['type']??'')==='deployment_shadow_route'&&!empty($ref['id']))return (string)$ref['id'];
    }
    return function_exists('data_model_deployment_task_route')?data_model_deployment_task_route($task):'admin';
}
function data_model_observability_context_for_run(PDO $pdo,array $run): array {
    $modelId=(int)($run['model_id']??0);$route=data_model_observability_route_from_run($run);$deployment=null;
    if(($run['scope_type']??'')==='model_deployment'&&!empty($run['scope_public_id'])){
        $q=$pdo->prepare('SELECT id FROM data_model_deployments WHERE public_id=? LIMIT 1');$q->execute([$run['scope_public_id']]);$id=(int)($q->fetchColumn()?:0);if($id)$deployment=data_model_observability_deployment_row($pdo,$id);
    }
    if(!$deployment&&$modelId){
        $q=$pdo->prepare("SELECT DISTINCT d.id FROM data_model_routing_overrides o JOIN data_model_deployments d ON d.id=o.deployment_id WHERE o.route_key=? AND (o.baseline_ai_model_id=? OR o.candidate_ai_model_id=?) AND d.status IN ('shadow','canary','limited','paused') ORDER BY d.id DESC LIMIT 1");
        $q->execute([$route,$modelId,$modelId]);$id=(int)($q->fetchColumn()?:0);if($id)$deployment=data_model_observability_deployment_row($pdo,$id);
    }
    if(!$deployment&&$modelId){
        $q=$pdo->prepare("SELECT d.id,d.route_keys_json FROM data_model_deployments d JOIN data_model_versions mv ON mv.id=d.model_version_id WHERE d.status='full' AND mv.ai_model_id=? ORDER BY d.id DESC LIMIT 20");$q->execute([$modelId]);
        foreach($q->fetchAll() as $row){$keys=json_decode((string)$row['route_keys_json'],true)?:[];if(in_array($route,$keys,true)){$deployment=data_model_observability_deployment_row($pdo,(int)$row['id']);break;}}
    }
    $versionId=null;
    if($deployment){
        if($modelId===(int)$deployment['candidate_ai_model_id'])$versionId=(int)$deployment['candidate_version_id'];
        elseif($modelId===(int)$deployment['rollback_ai_model_id'])$versionId=(int)$deployment['rollback_version_id'];
    }
    if(!$versionId&&$modelId){
        $q=$pdo->prepare("SELECT id FROM data_model_versions WHERE ai_model_id=? ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'approved' THEN 1 WHEN 'candidate' THEN 2 ELSE 3 END,id DESC LIMIT 1");$q->execute([$modelId]);$versionId=(int)($q->fetchColumn()?:0)?:null;
    }
    return ['route_key'=>$route,'deployment'=>$deployment,'model_version_id'=>$versionId,'rollout_stage'=>$deployment['status']??null,'is_shadow'=>(int)(($run['task_type']??'')==='deployment_shadow')];
}
function data_model_observability_observation_hash(array $row): string {
    $material=[
      'ai_run_id'=>(int)$row['ai_run_id'],'deployment_id'=>$row['deployment_id']!==null?(int)$row['deployment_id']:null,
      'model_version_id'=>$row['model_version_id']!==null?(int)$row['model_version_id']:null,'ai_model_id'=>$row['ai_model_id']!==null?(int)$row['ai_model_id']:null,
      'route_key'=>$row['route_key'],'source_task_type'=>$row['source_task_type'],'rollout_stage'=>$row['rollout_stage'],
      'is_shadow'=>(int)$row['is_shadow'],'run_status'=>$row['run_status'],'initiated_by'=>$row['initiated_by'],
      'actor_plan_tier'=>$row['actor_plan_tier'],'latency_ms'=>$row['latency_ms'],'input_tokens'=>$row['input_tokens'],
      'output_tokens'=>$row['output_tokens'],'estimated_cost_micros'=>$row['estimated_cost_micros'],'error_class'=>$row['error_class'],
      'scope_type'=>$row['scope_type'],'scope_public_id'=>$row['scope_public_id'],
    ];
    return data_attribution_hash($material);
}
function data_model_observability_observation_integrity(array $row): array {
    $computed=data_model_observability_observation_hash($row);return ['ok'=>hash_equals((string)$row['observation_hash'],$computed),'computed_hash'=>$computed];
}
function data_model_observability_record_ai_run(PDO $pdo,int $aiRunId,?int $latencyMs=null,bool $evaluate=true): ?array {
    if(!data_model_observability_ready($pdo)||$aiRunId<=0)return null;
    $existing=$pdo->prepare('SELECT * FROM data_model_observations WHERE ai_run_id=? LIMIT 1');$existing->execute([$aiRunId]);if($row=$existing->fetch())return $row;
    $q=$pdo->prepare("SELECT r.*,u.plan_tier,CAST(TIMESTAMPDIFF(MICROSECOND,r.created_at,r.completed_at)/1000 AS UNSIGNED) derived_latency_ms,m.input_cost_per_million_usd,m.output_cost_per_million_usd FROM ai_runs r LEFT JOIN users u ON u.id=r.user_id LEFT JOIN ai_models m ON m.id=r.model_id WHERE r.id=? LIMIT 1");$q->execute([$aiRunId]);$run=$q->fetch();if(!$run)return null;
    $ctx=data_model_observability_context_for_run($pdo,$run);$input=$run['input_tokens']!==null?(int)$run['input_tokens']:null;$output=$run['output_tokens']!==null?(int)$run['output_tokens']:null;
    $inRate=$run['input_cost_per_million_usd']!==null?(float)$run['input_cost_per_million_usd']:null;$outRate=$run['output_cost_per_million_usd']!==null?(float)$run['output_cost_per_million_usd']:null;$cost=null;
    if(($input!==null&&$inRate!==null)||($output!==null&&$outRate!==null))$cost=(int)round(($input??0)*($inRate??0)+($output??0)*($outRate??0));
    $row=[
      'ai_run_id'=>(int)$run['id'],'deployment_id'=>$ctx['deployment']['id']??null,'model_version_id'=>$ctx['model_version_id'],'ai_model_id'=>$run['model_id']!==null?(int)$run['model_id']:null,
      'route_key'=>$ctx['route_key'],'source_task_type'=>(string)$run['task_type'],'rollout_stage'=>$ctx['rollout_stage'],'is_shadow'=>$ctx['is_shadow'],
      'run_status'=>(string)$run['status'],'initiated_by'=>(string)$run['initiated_by'],'actor_plan_tier'=>$run['plan_tier']??null,
      'latency_ms'=>$latencyMs??($run['derived_latency_ms']!==null?(int)$run['derived_latency_ms']:null),'input_tokens'=>$input,'output_tokens'=>$output,
      'estimated_cost_micros'=>$cost,'error_class'=>data_model_observability_error_class($run['error_text']??null),
      'scope_type'=>$run['scope_type']??null,'scope_public_id'=>$run['scope_public_id']??null,
    ];$row['observation_hash']=data_model_observability_observation_hash($row);$public=ulid_like();
    $pdo->prepare('INSERT IGNORE INTO data_model_observations(public_id,ai_run_id,deployment_id,model_version_id,ai_model_id,route_key,source_task_type,rollout_stage,is_shadow,run_status,initiated_by,actor_plan_tier,latency_ms,input_tokens,output_tokens,estimated_cost_micros,error_class,scope_type,scope_public_id,observation_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$public,$row['ai_run_id'],$row['deployment_id'],$row['model_version_id'],$row['ai_model_id'],$row['route_key'],$row['source_task_type'],$row['rollout_stage'],$row['is_shadow'],$row['run_status'],$row['initiated_by'],$row['actor_plan_tier'],$row['latency_ms'],$row['input_tokens'],$row['output_tokens'],$row['estimated_cost_micros'],$row['error_class'],$row['scope_type'],$row['scope_public_id'],$row['observation_hash']]);
    $q=$pdo->prepare('SELECT * FROM data_model_observations WHERE ai_run_id=? LIMIT 1');$q->execute([$aiRunId]);$saved=$q->fetch()?:null;
    if($saved&&$evaluate&&!empty($saved['deployment_id'])){try{data_model_observability_evaluate_deployment($pdo,(int)$saved['deployment_id'],false,(string)$saved['route_key']);}catch(Throwable $ignored){}}
    return $saved;
}
function data_model_observability_observation_for_run(PDO $pdo,string $runPublicId): ?array {
    $q=$pdo->prepare('SELECT o.* FROM data_model_observations o JOIN ai_runs r ON r.id=o.ai_run_id WHERE r.public_id=? LIMIT 1');$q->execute([$runPublicId]);return $q->fetch()?:null;
}
function data_model_observability_signal_hash(array $row): string {
    return data_attribution_hash(['observation_id'=>(int)$row['observation_id'],'signal_type'=>$row['signal_type'],'signal_value'=>(float)$row['signal_value'],'source_type'=>$row['source_type'],'source_public_id'=>$row['source_public_id'],'actor_user_id'=>$row['actor_user_id']!==null?(int)$row['actor_user_id']:null,'note'=>(string)($row['note']??'')]);
}
function data_model_observability_signal_for_run(PDO $pdo,string $runPublicId,string $signalType,float $value,string $sourceType='system',?string $sourcePublicId=null,?int $actorUserId=null,?string $note=null): ?array {
    if(!data_model_observability_ready($pdo))return null;$obs=data_model_observability_observation_for_run($pdo,$runPublicId);
    if(!$obs){$q=$pdo->prepare('SELECT id FROM ai_runs WHERE public_id=? LIMIT 1');$q->execute([$runPublicId]);$id=(int)($q->fetchColumn()?:0);if($id)$obs=data_model_observability_record_ai_run($pdo,$id,null,false);}
    if(!$obs)return null;$signalType=mb_substr(trim($signalType),0,64);$sourceType=mb_substr(trim($sourceType),0,32);if($signalType===''||$sourceType==='')throw new InvalidArgumentException('Outcome signal type/source are required.');
    $value=max(-1,min(1,$value));$sourcePublicId=mb_substr(trim((string)$sourcePublicId),0,64);$note=mb_substr(trim((string)$note),0,2000)?:null;
    $row=['observation_id'=>(int)$obs['id'],'signal_type'=>$signalType,'signal_value'=>$value,'source_type'=>$sourceType,'source_public_id'=>$sourcePublicId,'actor_user_id'=>$actorUserId,'note'=>$note];$hash=data_model_observability_signal_hash($row);$public=ulid_like();
    $pdo->prepare('INSERT INTO data_model_outcome_signals(public_id,observation_id,signal_type,signal_value,source_type,source_public_id,actor_user_id,note,signal_hash) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE signal_value=VALUES(signal_value),actor_user_id=VALUES(actor_user_id),note=VALUES(note),signal_hash=VALUES(signal_hash)')
      ->execute([$public,$row['observation_id'],$signalType,$value,$sourceType,$sourcePublicId,$actorUserId,$note,$hash]);
    $q=$pdo->prepare('SELECT * FROM data_model_outcome_signals WHERE observation_id=? AND signal_type=? AND source_type=? AND source_public_id=? LIMIT 1');$q->execute([$row['observation_id'],$signalType,$sourceType,$sourcePublicId]);$saved=$q->fetch()?:null;
    if($saved&&(float)$saved['signal_value']<0&&function_exists('data_model_improvement_ingest_negative_signal')){try{data_model_improvement_ingest_negative_signal($pdo,$saved);}catch(Throwable $ignored){}}
    return $saved;
}
function data_model_observability_policy_material(array $p): array {
    return ['deployment_id'=>(int)$p['deployment_id'],'enabled'=>(int)$p['enabled'],'window_minutes'=>(int)$p['window_minutes'],'min_samples'=>(int)$p['min_samples'],'max_failure_rate'=>(float)$p['max_failure_rate'],'max_p95_latency_ms'=>(int)$p['max_p95_latency_ms'],'max_failure_rate_delta'=>$p['max_failure_rate_delta']!==null?(float)$p['max_failure_rate_delta']:null,'max_p95_latency_delta_ms'=>$p['max_p95_latency_delta_ms']!==null?(int)$p['max_p95_latency_delta_ms']:null,'max_avg_input_tokens'=>$p['max_avg_input_tokens']!==null?(int)$p['max_avg_input_tokens']:null,'max_avg_output_tokens'=>$p['max_avg_output_tokens']!==null?(int)$p['max_avg_output_tokens']:null,'max_avg_cost_micros'=>$p['max_avg_cost_micros']!==null?(int)$p['max_avg_cost_micros']:null,'min_avg_outcome_score'=>$p['min_avg_outcome_score']!==null?(float)$p['min_avg_outcome_score']:null];
}
function data_model_observability_policy_hash(array $p): string {return data_attribution_hash(data_model_observability_policy_material($p));}
function data_model_observability_policy_get(PDO $pdo,int $deploymentId,bool $create=true): ?array {
    $q=$pdo->prepare('SELECT * FROM data_model_monitoring_policies WHERE deployment_id=? LIMIT 1');$q->execute([$deploymentId]);if($p=$q->fetch())return $p;if(!$create)return null;
    $dep=data_model_observability_deployment_row($pdo,$deploymentId);if(!$dep)return null;$monitor=json_decode((string)$dep['monitoring_policy_json'],true)?:[];
    $p=['deployment_id'=>$deploymentId,'enabled'=>1,'window_minutes'=>max(15,min(10080,(int)($monitor['monitoring_window_minutes']??120))),'min_samples'=>5,'max_failure_rate'=>0.10,'max_p95_latency_ms'=>30000,'max_failure_rate_delta'=>0.10,'max_p95_latency_delta_ms'=>10000,'max_avg_input_tokens'=>null,'max_avg_output_tokens'=>null,'max_avg_cost_micros'=>null,'min_avg_outcome_score'=>null];$hash=data_model_observability_policy_hash($p);
    $pdo->prepare('INSERT IGNORE INTO data_model_monitoring_policies(deployment_id,enabled,window_minutes,min_samples,max_failure_rate,max_p95_latency_ms,max_failure_rate_delta,max_p95_latency_delta_ms,max_avg_input_tokens,max_avg_output_tokens,max_avg_cost_micros,min_avg_outcome_score,policy_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$deploymentId,1,$p['window_minutes'],$p['min_samples'],$p['max_failure_rate'],$p['max_p95_latency_ms'],$p['max_failure_rate_delta'],$p['max_p95_latency_delta_ms'],null,null,null,null,$hash]);
    $q->execute([$deploymentId]);return $q->fetch()?:null;
}
function data_model_observability_policy_update(PDO $pdo,array $viewer,int $deploymentId,array $input): array {
    data_model_observability_require_admin($viewer);$existing=data_model_observability_policy_get($pdo,$deploymentId,true);if(!$existing)throw new RuntimeException('Deployment not found.');
    $nullableInt=fn(string $key)=>trim((string)($input[$key]??''))===''?null:max(0,(int)$input[$key]);$nullableFloat=fn(string $key)=>trim((string)($input[$key]??''))===''?null:(float)$input[$key];
    $p=['deployment_id'=>$deploymentId,'enabled'=>isset($input['enabled'])?1:0,'window_minutes'=>max(15,min(10080,(int)($input['window_minutes']??120))),'min_samples'=>max(1,min(10000,(int)($input['min_samples']??5))),'max_failure_rate'=>max(0,min(1,(float)($input['max_failure_rate']??0.1))),'max_p95_latency_ms'=>max(1,min(3600000,(int)($input['max_p95_latency_ms']??30000))),'max_failure_rate_delta'=>$nullableFloat('max_failure_rate_delta'),'max_p95_latency_delta_ms'=>$nullableInt('max_p95_latency_delta_ms'),'max_avg_input_tokens'=>$nullableInt('max_avg_input_tokens'),'max_avg_output_tokens'=>$nullableInt('max_avg_output_tokens'),'max_avg_cost_micros'=>$nullableInt('max_avg_cost_micros'),'min_avg_outcome_score'=>$nullableFloat('min_avg_outcome_score')];if($p['max_failure_rate_delta']!==null)$p['max_failure_rate_delta']=max(0,min(1,$p['max_failure_rate_delta']));if($p['min_avg_outcome_score']!==null)$p['min_avg_outcome_score']=max(-1,min(1,$p['min_avg_outcome_score']));$hash=data_model_observability_policy_hash($p);
    $pdo->prepare('UPDATE data_model_monitoring_policies SET enabled=?,window_minutes=?,min_samples=?,max_failure_rate=?,max_p95_latency_ms=?,max_failure_rate_delta=?,max_p95_latency_delta_ms=?,max_avg_input_tokens=?,max_avg_output_tokens=?,max_avg_cost_micros=?,min_avg_outcome_score=?,policy_hash=?,updated_by_user_id=? WHERE deployment_id=?')
      ->execute([$p['enabled'],$p['window_minutes'],$p['min_samples'],$p['max_failure_rate'],$p['max_p95_latency_ms'],$p['max_failure_rate_delta'],$p['max_p95_latency_delta_ms'],$p['max_avg_input_tokens'],$p['max_avg_output_tokens'],$p['max_avg_cost_micros'],$p['min_avg_outcome_score'],$hash,$viewer['id'],$deploymentId]);
    return data_model_observability_policy_get($pdo,$deploymentId,false)??[];
}
function data_model_observability_percentile(array $values,float $p): ?float {
    if(!$values)return null;sort($values,SORT_NUMERIC);$n=count($values);$idx=max(0,min($n-1,(int)ceil($p*$n)-1));return (float)$values[$idx];
}
function data_model_observability_cohort_metrics(array $rows): array {
    $count=count($rows);$fail=0;$lat=[];$in=[];$out=[];$cost=[];$shadow=0;
    foreach($rows as $r){if(($r['run_status']??'')!=='completed')$fail++;if($r['latency_ms']!==null)$lat[]=(float)$r['latency_ms'];if($r['input_tokens']!==null)$in[]=(float)$r['input_tokens'];if($r['output_tokens']!==null)$out[]=(float)$r['output_tokens'];if($r['estimated_cost_micros']!==null)$cost[]=(float)$r['estimated_cost_micros'];if((int)($r['is_shadow']??0))$shadow++;}
    $avg=fn(array $v)=>$v?array_sum($v)/count($v):null;
    return ['sample_count'=>$count,'failure_count'=>$fail,'failure_rate'=>$count?$fail/$count:0.0,'avg_latency_ms'=>$avg($lat),'p95_latency_ms'=>data_model_observability_percentile($lat,0.95),'avg_input_tokens'=>$avg($in),'avg_output_tokens'=>$avg($out),'avg_cost_micros'=>$avg($cost),'shadow_count'=>$shadow];
}
function data_model_observability_snapshot_integrity(array $s): array {
    $computed=hash('sha256',(string)$s['metrics_json']);return ['ok'=>hash_equals((string)$s['metrics_hash'],$computed),'computed_hash'=>$computed];
}
function data_model_observability_incident_event(PDO $pdo,int $incidentId,?int $actorId,string $type,?string $from,?string $to,?string $note,string $evidenceHash): void {
    $pdo->prepare('INSERT INTO data_model_incident_events(incident_id,actor_user_id,event_type,from_status,to_status,note,evidence_hash) VALUES(?,?,?,?,?,?,?)')->execute([$incidentId,$actorId,$type,$from,$to,mb_substr(trim((string)$note),0,3000)?:null,$evidenceHash]);
}
function data_model_observability_notify_incident(PDO $pdo,array $incident): void {
    if(!function_exists('notification_create'))return;
    $admins=$pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
    foreach($admins as $uid)notification_create($pdo,(int)$uid,null,'model_health_incident','model_observability_incident',(string)$incident['public_id'],(string)$incident['title'],['category'=>'research','dedupe_key'=>'model-health:'.$incident['public_id'],'group_key'=>'model-health:'.$incident['deployment_id'].':'.$incident['route_key'],'context'=>['severity'=>$incident['severity'],'route_key'=>$incident['route_key'],'deployment_id'=>(int)$incident['deployment_id']]]);
}
function data_model_observability_incident_upsert(PDO $pdo,array $snapshot,array $breach): array {
    $q=$pdo->prepare("SELECT * FROM data_model_incidents WHERE deployment_id=? AND route_key=? AND metric_name=? AND status IN ('open','investigating') ORDER BY id DESC LIMIT 1");$q->execute([$snapshot['deployment_id'],$snapshot['route_key'],$breach['metric']]);$existing=$q->fetch();$evidence=data_attribution_hash(['snapshot_hash'=>$snapshot['metrics_hash'],'metric'=>$breach['metric'],'current'=>$breach['current'],'threshold'=>$breach['threshold'],'severity'=>$breach['severity']]);
    if($existing){$pdo->prepare('UPDATE data_model_incidents SET snapshot_id=?,model_version_id=?,severity=?,current_value=?,threshold_value=?,summary=?,evidence_hash=?,last_seen_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$snapshot['id'],$snapshot['model_version_id'],$breach['severity'],$breach['current'],$breach['threshold'],$breach['summary'],$evidence,$existing['id']]);$q=$pdo->prepare('SELECT * FROM data_model_incidents WHERE id=?');$q->execute([$existing['id']]);$incident=$q->fetch();if($incident&&function_exists('data_model_improvement_ingest_incident')){try{data_model_improvement_ingest_incident($pdo,$incident);}catch(Throwable $ignored){}}return $incident;}
    $public=ulid_like();$title='Model health '.$breach['severity'].': '.str_replace('_',' ',$breach['metric']);
    $pdo->prepare("INSERT INTO data_model_incidents(public_id,deployment_id,model_version_id,snapshot_id,route_key,incident_type,severity,status,metric_name,current_value,threshold_value,title,summary,evidence_hash) VALUES(?,?,?,?,?,'threshold_breach',?,'open',?,?,?,?,?,?)")
      ->execute([$public,$snapshot['deployment_id'],$snapshot['model_version_id'],$snapshot['id'],$snapshot['route_key'],$breach['severity'],$breach['metric'],$breach['current'],$breach['threshold'],$title,$breach['summary'],$evidence]);
    $id=(int)$pdo->lastInsertId();data_model_observability_incident_event($pdo,$id,null,'opened',null,'open',$breach['summary'],$evidence);$q=$pdo->prepare('SELECT * FROM data_model_incidents WHERE id=?');$q->execute([$id]);$incident=$q->fetch();data_model_observability_notify_incident($pdo,$incident);if($incident&&function_exists('data_model_improvement_ingest_incident')){try{data_model_improvement_ingest_incident($pdo,$incident);}catch(Throwable $ignored){}}return $incident;
}
function data_model_observability_evaluate_deployment(PDO $pdo,int $deploymentId,bool $force=false,?string $onlyRoute=null): array {
    if(!data_model_observability_ready($pdo))return [];$dep=data_model_observability_deployment_row($pdo,$deploymentId);if(!$dep)return [];$policy=data_model_observability_policy_get($pdo,$deploymentId,true);if(!$policy||(int)$policy['enabled']!==1)return [];
    $routes=$onlyRoute!==null?[$onlyRoute]:(array)$dep['route_keys'];$out=[];$windowStart=gmdate('Y-m-d H:i:s',time()-(int)$policy['window_minutes']*60);
    foreach($routes as $route){
        if(!$force){$q=$pdo->prepare('SELECT * FROM data_model_health_snapshots WHERE deployment_id=? AND route_key=? AND created_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE) ORDER BY id DESC LIMIT 1');$q->execute([$deploymentId,$route]);if($recent=$q->fetch()){$out[]=$recent;continue;}}
        $q=$pdo->prepare('SELECT * FROM data_model_observations WHERE deployment_id=? AND route_key=? AND observed_at>=? ORDER BY observed_at ASC,id ASC');$q->execute([$deploymentId,$route,$windowStart]);$rows=$q->fetchAll();$count=count($rows);
        $candidateRows=array_values(array_filter($rows,fn($r)=>(int)($r['model_version_id']??0)===(int)$dep['candidate_version_id']));
        $baselineRows=array_values(array_filter($rows,fn($r)=>(int)($r['model_version_id']??0)===(int)$dep['rollback_version_id']));
        $overall=data_model_observability_cohort_metrics($rows);$candidate=data_model_observability_cohort_metrics($candidateRows);$baseline=data_model_observability_cohort_metrics($baselineRows);
        $q=$pdo->prepare('SELECT s.signal_value FROM data_model_outcome_signals s JOIN data_model_observations o ON o.id=s.observation_id WHERE o.deployment_id=? AND o.route_key=? AND o.observed_at>=?');$q->execute([$deploymentId,$route,$windowStart]);$signals=array_map('floatval',$q->fetchAll(PDO::FETCH_COLUMN));$avgOutcome=$signals?array_sum($signals)/count($signals):null;
        $failureDelta=$candidate['sample_count']&&$baseline['sample_count']?$candidate['failure_rate']-$baseline['failure_rate']:null;
        $latencyDelta=$candidate['p95_latency_ms']!==null&&$baseline['p95_latency_ms']!==null?$candidate['p95_latency_ms']-$baseline['p95_latency_ms']:null;
        $metrics=[
          'sample_count'=>$count,'completed_count'=>$count-$overall['failure_count'],'failure_count'=>$overall['failure_count'],'failure_rate'=>$overall['failure_rate'],
          'avg_latency_ms'=>$overall['avg_latency_ms'],'p95_latency_ms'=>$overall['p95_latency_ms'],'avg_input_tokens'=>$overall['avg_input_tokens'],'avg_output_tokens'=>$overall['avg_output_tokens'],
          'avg_cost_micros'=>$overall['avg_cost_micros'],'total_cost_micros'=>array_sum(array_map(fn($r)=>(float)($r['estimated_cost_micros']??0),array_filter($rows,fn($r)=>$r['estimated_cost_micros']!==null)))?:null,
          'outcome_signal_count'=>count($signals),'avg_outcome_score'=>$avgOutcome,
          'candidate'=>$candidate,'baseline'=>$baseline,'candidate_vs_baseline'=>['failure_rate_delta'=>$failureDelta,'p95_latency_delta_ms'=>$latencyDelta],
          'shadow_candidate_count'=>array_sum(array_map(fn($r)=>(int)$r['is_shadow'],$candidateRows)),'served_baseline_or_candidate_count'=>array_sum(array_map(fn($r)=>(int)!$r['is_shadow'],$rows)),
        ];
        $breaches=[];$enough=$count>=(int)$policy['min_samples'];
        if($enough&&$overall['failure_rate']>(float)$policy['max_failure_rate'])$breaches[]=['metric'=>'failure_rate','current'=>$overall['failure_rate'],'threshold'=>(float)$policy['max_failure_rate'],'severity'=>'critical','summary'=>'Observed failure rate exceeds the configured production threshold.'];
        if($enough&&$overall['p95_latency_ms']!==null&&$overall['p95_latency_ms']>(float)$policy['max_p95_latency_ms'])$breaches[]=['metric'=>'p95_latency_ms','current'=>$overall['p95_latency_ms'],'threshold'=>(float)$policy['max_p95_latency_ms'],'severity'=>'critical','summary'=>'Observed p95 latency exceeds the configured production threshold.'];
        $cohortsEnough=$candidate['sample_count']>=(int)$policy['min_samples']&&$baseline['sample_count']>=(int)$policy['min_samples'];
        if($cohortsEnough&&$policy['max_failure_rate_delta']!==null&&$failureDelta!==null&&$failureDelta>(float)$policy['max_failure_rate_delta'])$breaches[]=['metric'=>'candidate_failure_rate_delta','current'=>$failureDelta,'threshold'=>(float)$policy['max_failure_rate_delta'],'severity'=>'critical','summary'=>'Candidate failure rate has drifted above the concurrent baseline by more than the configured threshold.'];
        if($cohortsEnough&&$policy['max_p95_latency_delta_ms']!==null&&$latencyDelta!==null&&$latencyDelta>(float)$policy['max_p95_latency_delta_ms'])$breaches[]=['metric'=>'candidate_p95_latency_delta_ms','current'=>$latencyDelta,'threshold'=>(float)$policy['max_p95_latency_delta_ms'],'severity'=>'warning','summary'=>'Candidate p95 latency has drifted above the concurrent baseline by more than the configured threshold.'];
        foreach([['avg_input_tokens','max_avg_input_tokens'],['avg_output_tokens','max_avg_output_tokens'],['avg_cost_micros','max_avg_cost_micros']] as [$metric,$threshold])if($enough&&$policy[$threshold]!==null&&$metrics[$metric]!==null&&$metrics[$metric]>(float)$policy[$threshold])$breaches[]=['metric'=>$metric,'current'=>$metrics[$metric],'threshold'=>(float)$policy[$threshold],'severity'=>'warning','summary'=>str_replace('_',' ',$metric).' exceeds the configured production threshold.'];
        if($enough&&$policy['min_avg_outcome_score']!==null&&$avgOutcome!==null&&$avgOutcome<(float)$policy['min_avg_outcome_score'])$breaches[]=['metric'=>'avg_outcome_score','current'=>$avgOutcome,'threshold'=>(float)$policy['min_avg_outcome_score'],'severity'=>'warning','summary'=>'Observed outcome score is below the configured production threshold.'];
        $health=!$enough?'insufficient':($breaches?'attention':'healthy');$metrics['breaches']=$breaches;$json=data_attribution_encode($metrics);$hash=hash('sha256',$json);$versionId=(int)$dep['candidate_version_id']?:null;$public=ulid_like();
        $pdo->prepare('INSERT INTO data_model_health_snapshots(public_id,deployment_id,model_version_id,route_key,rollout_stage,window_start,window_end,sample_count,health_status,metrics_json,metrics_hash,policy_hash) VALUES(?,?,?,?,?,?,NOW(),?,?,?,?,?)')
          ->execute([$public,$deploymentId,$versionId,$route,$dep['status'],$windowStart,$count,$health,$json,$hash,$policy['policy_hash']]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM data_model_health_snapshots WHERE id=?');$q->execute([$id]);$snapshot=$q->fetch();$snapshot['metrics']=$metrics;
        foreach($breaches as $breach)data_model_observability_incident_upsert($pdo,$snapshot,$breach);$out[]=$snapshot;
    }
    return $out;
}
function data_model_observability_incident_get(PDO $pdo,string $publicId): ?array {
    $q=$pdo->prepare('SELECT i.*,d.public_id deployment_public_id,mv.public_id model_version_public_id,mv.version_label model_version_label FROM data_model_incidents i JOIN data_model_deployments d ON d.id=i.deployment_id LEFT JOIN data_model_versions mv ON mv.id=i.model_version_id WHERE i.public_id=? LIMIT 1');$q->execute([$publicId]);return $q->fetch()?:null;
}
function data_model_observability_incident_update(PDO $pdo,array $viewer,string $publicId,string $status,string $note=''): array {
    data_model_observability_require_admin($viewer);if(!in_array($status,['open','investigating','resolved','accepted'],true))throw new InvalidArgumentException('Invalid incident review status.');$i=data_model_observability_incident_get($pdo,$publicId);if(!$i)throw new RuntimeException('Model health incident not found.');$from=(string)$i['status'];$ack=in_array($status,['investigating','accepted','resolved'],true)?(int)$viewer['id']:($i['acknowledged_by_user_id']??null);$resolved=$status==='resolved'?(int)$viewer['id']:null;$reviewed=in_array($status,['investigating','accepted','resolved'],true)?gmdate('Y-m-d H:i:s'):null;
    $pdo->prepare('UPDATE data_model_incidents SET status=?,acknowledged_by_user_id=?,resolved_by_user_id=?,review_note=?,reviewed_at=?,updated_at=NOW() WHERE id=?')->execute([$status,$ack,$resolved,mb_substr(trim($note),0,3000)?:null,$reviewed,$i['id']]);$fresh=data_model_observability_incident_get($pdo,$publicId);data_model_observability_incident_event($pdo,(int)$i['id'],(int)$viewer['id'],'human_review',$from,$status,$note,(string)$fresh['evidence_hash']);return $fresh??[];
}
function data_model_observability_backfill(PDO $pdo,array $viewer,int $limit=500): int {
    data_model_observability_require_admin($viewer);$limit=max(1,min(5000,$limit));$q=$pdo->query("SELECT r.id FROM ai_runs r LEFT JOIN data_model_observations o ON o.ai_run_id=r.id WHERE o.id IS NULL AND r.status IN ('completed','failed','blocked') ORDER BY r.id DESC LIMIT $limit");$count=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)if(data_model_observability_record_ai_run($pdo,(int)$id,null,false))$count++;return $count;
}
function data_model_observability_incidents(PDO $pdo,int $limit=100,?string $status=null): array {
    $limit=max(1,min(500,$limit));$where=$status!==null?' WHERE i.status=?':'';$q=$pdo->prepare("SELECT i.*,d.public_id deployment_public_id,mv.version_label model_version_label FROM data_model_incidents i JOIN data_model_deployments d ON d.id=i.deployment_id LEFT JOIN data_model_versions mv ON mv.id=i.model_version_id$where ORDER BY FIELD(i.status,'open','investigating','accepted','resolved'),FIELD(i.severity,'critical','warning'),i.last_seen_at DESC LIMIT $limit");$q->execute($status!==null?[$status]:[]);return $q->fetchAll();
}
function data_model_observability_snapshots(PDO $pdo,?int $deploymentId=null,int $limit=100): array {
    $limit=max(1,min(500,$limit));$where=$deploymentId?' WHERE s.deployment_id=?':'';$q=$pdo->prepare("SELECT s.*,d.public_id deployment_public_id,mv.version_label model_version_label FROM data_model_health_snapshots s JOIN data_model_deployments d ON d.id=s.deployment_id LEFT JOIN data_model_versions mv ON mv.id=s.model_version_id$where ORDER BY s.id DESC LIMIT $limit");$q->execute($deploymentId?[$deploymentId]:[]);$rows=$q->fetchAll();foreach($rows as &$r)$r['metrics']=json_decode((string)$r['metrics_json'],true)?:[];unset($r);return $rows;
}
function data_model_observability_observations(PDO $pdo,?int $deploymentId=null,int $limit=100): array {
    $limit=max(1,min(500,$limit));$where=$deploymentId?' WHERE o.deployment_id=?':'';$q=$pdo->prepare("SELECT o.*,r.public_id ai_run_public_id,m.display_name ai_model_name,mv.version_label model_version_label,d.public_id deployment_public_id FROM data_model_observations o JOIN ai_runs r ON r.id=o.ai_run_id LEFT JOIN ai_models m ON m.id=o.ai_model_id LEFT JOIN data_model_versions mv ON mv.id=o.model_version_id LEFT JOIN data_model_deployments d ON d.id=o.deployment_id$where ORDER BY o.id DESC LIMIT $limit");$q->execute($deploymentId?[$deploymentId]:[]);return $q->fetchAll();
}
function data_model_observability_summary(PDO $pdo): array {
    if(!data_model_observability_ready($pdo))return ['ready'=>false];$scalar=fn(string $sql)=>(int)$pdo->query($sql)->fetchColumn();
    return ['ready'=>true,'observations'=>$scalar('SELECT COUNT(*) FROM data_model_observations'),'last_24h'=>$scalar("SELECT COUNT(*) FROM data_model_observations WHERE observed_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)"),'open_incidents'=>$scalar("SELECT COUNT(*) FROM data_model_incidents WHERE status='open'"),'investigating'=>$scalar("SELECT COUNT(*) FROM data_model_incidents WHERE status='investigating'"),'critical'=>$scalar("SELECT COUNT(*) FROM data_model_incidents WHERE status IN ('open','investigating') AND severity='critical'"),'snapshots'=>$scalar('SELECT COUNT(*) FROM data_model_health_snapshots')];
}
function data_model_observability_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=12): void {
    if(($viewer['role']??'')!=='admin'||!data_model_observability_ready($pdo)||!function_exists('cognitive_feed_add'))return;$limit=max(1,min(30,$limit));
    foreach(data_model_observability_incidents($pdo,$limit) as $i){if(!in_array($i['status'],['open','investigating'],true))continue;$priority=$i['severity']==='critical'?'high':'medium';cognitive_feed_add($items,[
      'key'=>cognitive_feed_key('model_health_incident','model_observability_incident',(string)$i['public_id'],(string)$i['evidence_hash']),
      'type'=>'model_health_incident','section'=>'needs_attention','priority'=>$priority,'created_at'=>$i['last_seen_at'],
      'title'=>(string)$i['title'],'body'=>(string)($i['summary']??'Production model health requires review.'),
      'meta'=>['route'=>$i['route_key'],'severity'=>$i['severity'],'model'=>$i['model_version_label']??''],
      'actions'=>[cognitive_feed_action_link('Review model health','/admin/model-observability.php?incident='.rawurlencode((string)$i['public_id']))],
    ]);}
}
