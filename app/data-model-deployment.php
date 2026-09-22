<?php
declare(strict_types=1);

/**
 * Phase 44 — Governed Model Deployment & Rollout.
 *
 * Execution layer for signed Phase 43 Proceed decisions.
 * No stage advances automatically. Shadow never serves the candidate.
 */
function data_model_deployment_ready(PDO $pdo): bool {
    static $ready=null;if($ready!==null)return $ready;
    try{$ready=data_model_release_ready($pdo)
        &&installer_table_exists($pdo,'data_model_deployments')
        &&installer_table_exists($pdo,'data_model_deployment_checkpoints')
        &&installer_table_exists($pdo,'data_model_deployment_events')
        &&installer_table_exists($pdo,'data_model_routing_overrides');}
    catch(Throwable $e){$ready=false;}return $ready;
}
function data_model_deployment_require_admin(array $viewer): void {
    if(($viewer['role']??'')!=='admin')throw new RuntimeException('Administrator access is required for Model Deployment operations.');
}
function data_model_deployment_route_map(): array {
    return [
        'admin'=>'admin_default_model_id',
        'pro'=>'pro_default_model_id',
        'source_monitor'=>'source_monitor_model_id',
        'moderation'=>'moderation_model_id',
        'research'=>'research_model_id',
        'transcript_cleanup'=>'transcript_cleanup_model_id',
        'annotation_intelligence'=>'annotation_intelligence_model_id',
    ];
}
function data_model_deployment_task_route(string $taskType): string {
    return match(true){
        $taskType==='research_workspace_intelligence'=>'research',
        $taskType==='annotation_intelligence'=>'annotation_intelligence',
        str_starts_with($taskType,'moderation_')=>'moderation',
        $taskType==='source_change_summary'=>'source_monitor',
        str_starts_with($taskType,'research_')=>'research',
        $taskType==='transcript_cleanup'=>'transcript_cleanup',
        default=>'admin',
    };
}
function data_model_deployment_queue_shadow(PDO $pdo,?array $user,string $taskType,int $servedModelId,string $system,string $prompt,array $refs=[],?string $scopeType=null,?string $scopePublicId=null): ?string {
    if($taskType==='deployment_shadow'||!data_model_deployment_ready($pdo)||!$servedModelId)return null;
    $routeKey=data_model_deployment_task_route($taskType);
    try{
        $q=$pdo->prepare("SELECT o.candidate_ai_model_id,d.id deployment_id,d.public_id deployment_public_id,d.revision FROM data_model_routing_overrides o JOIN data_model_deployments d ON d.id=o.deployment_id WHERE o.route_key=? AND o.enabled=1 AND o.mode='shadow' AND o.baseline_ai_model_id=? AND d.status='shadow' LIMIT 1");
        $q->execute([$routeKey,$servedModelId]);$row=$q->fetch();if(!$row)return null;
        $candidate=(int)$row['candidate_ai_model_id'];ai_model_record($pdo,$candidate);
        $shadowRefs=$refs;$shadowRefs[]=['type'=>'deployment_shadow_route','id'=>$routeKey];
        $job=ai_queue_job($pdo,$user['id']??null,'deployment_shadow',$candidate,'model_deployment',(string)$row['deployment_public_id'],[
            'deployment_public_id'=>(string)$row['deployment_public_id'],'deployment_revision'=>(int)$row['revision'],'source_task_type'=>$taskType,
            'system'=>$system,'prompt'=>$prompt,'refs'=>$shadowRefs,'scope_type'=>$scopeType,'scope_public_id'=>$scopePublicId,
        ],8);
        data_model_deployment_event($pdo,(int)$row['deployment_id'],$user['id']??null,'shadow_inference_queued','shadow',['job_public_id'=>$job,'source_task_type'=>$taskType,'candidate_ai_model_id'=>$candidate]);
        return $job;
    }catch(Throwable $e){return null;}
}
function data_model_deployment_route_labels(): array {
    return [
        'admin'=>'Admin default','pro'=>'Pro default','source_monitor'=>'Source monitoring',
        'moderation'=>'Moderation triage','research'=>'Research synthesis',
        'transcript_cleanup'=>'Transcript cleanup','annotation_intelligence'=>'Annotation intelligence',
    ];
}
function data_model_deployment_route_keys(array|string $value): array {
    $raw=is_array($value)?$value:(preg_split('/[\s,]+/',trim($value),-1,PREG_SPLIT_NO_EMPTY)?:[]);
    $map=data_model_deployment_route_map();$out=[];
    foreach($raw as $v){$k=trim((string)$v);if(isset($map[$k]))$out[$k]=1;}
    $keys=array_keys($out);sort($keys,SORT_STRING);
    if(!$keys)throw new InvalidArgumentException('Select at least one governed AI routing task.');
    return $keys;
}
function data_model_deployment_assert_routing_edit_allowed(PDO $pdo,array $routeKeys): void {
    if(!data_model_deployment_ready($pdo))return;
    $wanted=data_model_deployment_route_keys($routeKeys);$blocked=[];
    $q=$pdo->query("SELECT public_id,status,route_keys_json FROM data_model_deployments WHERE status IN ('preflight_passed','shadow','canary','limited','paused','full')");
    foreach($q->fetchAll() as $row){$reserved=json_decode((string)$row['route_keys_json'],true);if(!is_array($reserved))continue;$hit=array_values(array_intersect($wanted,$reserved));foreach($hit as $key)$blocked[$key]=(string)$row['public_id'];}
    if($blocked)throw new RuntimeException('AI routing change is blocked by active Phase 44 deployment reservation: '.implode(', ',array_keys($blocked)).'. Use the Model Deployments workspace.');
}
function data_model_deployment_monitoring_policy(array $input): array {
    return [
        'monitoring_window_minutes'=>max(15,min(10080,(int)($input['monitoring_window_minutes']??120))),
        'success_criteria'=>mb_substr(trim((string)($input['success_criteria']??'')),0,5000),
        'monitoring_owner'=>mb_substr(trim((string)($input['monitoring_owner']??'')),0,255),
        'hard_stop_on_provider_failure'=>1,
        'require_human_checkpoint_between_stages'=>1,
        'policy_version'=>'phase44-v1',
    ];
}
function data_model_deployment_routing_snapshot(PDO $pdo,array $routeKeys): array {
    $settings=$pdo->query('SELECT * FROM ai_settings WHERE id=1')->fetch()?:[];$map=data_model_deployment_route_map();$out=[];
    foreach($routeKeys as $key){if(isset($map[$key])){$v=$settings[$map[$key]]??null;$out[$key]=$v!==null?(int)$v:null;}}
    ksort($out,SORT_STRING);return $out;
}
function data_model_deployment_apply_routing(PDO $pdo,array $viewer,array $snapshot): void {
    $map=data_model_deployment_route_map();
    foreach($snapshot as $key=>$modelId){if(!isset($map[$key]))continue;$col=$map[$key];$pdo->prepare("UPDATE ai_settings SET $col=?,updated_by_user_id=? WHERE id=1")->execute([$modelId?:null,$viewer['id']]);}
}
function data_model_deployment_event(PDO $pdo,int $deploymentId,?int $actorId,string $type,?string $stage,array $details=[],?string $routeHash=null): void {
    $pdo->prepare('INSERT INTO data_model_deployment_events(deployment_id,actor_user_id,event_type,stage,route_snapshot_hash,details_json) VALUES(?,?,?,?,?,?)')
        ->execute([$deploymentId,$actorId,$type,$stage,$routeHash,$details?data_attribution_encode($details):null]);
}
function data_model_deployment_get(PDO $pdo,string $publicId): ?array {
    if(!data_model_deployment_ready($pdo))return null;
    $q=$pdo->prepare('SELECT d.*,rd.public_id release_decision_public_id,rd.title release_title,rd.outcome release_outcome,mv.public_id model_version_public_id,mv.version_label model_version_label,mv.status model_status,mv.ai_model_id candidate_ai_model_id,rv.public_id rollback_version_public_id,rv.version_label rollback_version_label,rv.status rollback_status,rv.ai_model_id rollback_ai_model_id,r.public_id registry_public_id,r.name registry_name,u.display_name creator_name FROM data_model_deployments d JOIN data_model_release_decisions rd ON rd.id=d.release_decision_id JOIN data_model_versions mv ON mv.id=d.model_version_id JOIN data_model_versions rv ON rv.id=d.rollback_version_id JOIN data_model_registry r ON r.id=d.registry_id JOIN users u ON u.id=d.created_by_user_id WHERE d.public_id=? LIMIT 1');
    $q->execute([trim($publicId)]);$d=$q->fetch();if(!$d)return null;
    foreach(['route_keys','monitoring_policy','routing_before','routing_current'] as $k)$d[$k]=json_decode((string)$d[$k.'_json'],true)?:[];
    return $d;
}
function data_model_deployments(PDO $pdo,int $limit=100): array {
    if(!data_model_deployment_ready($pdo))return [];$limit=max(1,min(250,$limit));
    return $pdo->query("SELECT d.*,rd.public_id release_decision_public_id,mv.public_id model_version_public_id,mv.version_label model_version_label,rv.version_label rollback_version_label,r.name registry_name FROM data_model_deployments d JOIN data_model_release_decisions rd ON rd.id=d.release_decision_id JOIN data_model_versions mv ON mv.id=d.model_version_id JOIN data_model_versions rv ON rv.id=d.rollback_version_id JOIN data_model_registry r ON r.id=d.registry_id ORDER BY d.id DESC LIMIT $limit")->fetchAll();
}
function data_model_deployment_checkpoints(PDO $pdo,int $deploymentId): array {
    $q=$pdo->prepare('SELECT c.*,u.display_name reviewer_name FROM data_model_deployment_checkpoints c JOIN users u ON u.id=c.reviewer_user_id WHERE c.deployment_id=? ORDER BY c.id DESC');$q->execute([$deploymentId]);return $q->fetchAll();
}
function data_model_deployment_events(PDO $pdo,int $deploymentId,int $limit=150): array {
    $limit=max(1,min(500,$limit));$q=$pdo->prepare("SELECT e.*,u.display_name actor_name FROM data_model_deployment_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.deployment_id=? ORDER BY e.id DESC LIMIT $limit");$q->execute([$deploymentId]);return $q->fetchAll();
}
function data_model_deployment_eligible_decisions(PDO $pdo,int $limit=100): array {
    if(!data_model_deployment_ready($pdo))return [];$limit=max(1,min(250,$limit));
    $q=$pdo->query("SELECT rd.public_id,rd.title,rd.decision_hash,rd.final_signature_hash,rd.decided_at,mv.public_id model_version_public_id,mv.version_label model_version_label,mv.status model_status,mv.ai_model_id,r.name registry_name FROM data_model_release_decisions rd JOIN data_model_versions mv ON mv.id=rd.model_version_id JOIN data_model_registry r ON r.id=rd.registry_id LEFT JOIN data_model_deployments d ON d.release_decision_id=rd.id WHERE rd.status='decision_recorded' AND rd.outcome='proceed_to_governed_release' AND d.id IS NULL ORDER BY rd.id DESC LIMIT $limit");
    $out=[];foreach($q->fetchAll() as $row){$d=data_model_release_decision_get($pdo,(string)$row['public_id']);if($d&&data_model_release_decision_integrity($pdo,$d)['ok'])$out[]=$row;}return $out;
}
function data_model_deployment_subject_material(PDO $pdo,array $d): array {
    return [
        'public_id'=>$d['public_id'],'release_decision_hash'=>$d['release_decision_hash'],
        'release_signature_hash'=>$d['release_signature_hash'],'model_version_hash'=>$d['model_version_hash'],
        'rollback_version_hash'=>$d['rollback_version_hash'],'status'=>$d['status'],'revision'=>(int)$d['revision'],
        'route_keys_hash'=>$d['route_keys_hash'],'routing_current_hash'=>$d['routing_current_hash'],
        'monitoring_policy_hash'=>$d['monitoring_policy_hash'],'current_traffic_percent'=>(int)$d['current_traffic_percent'],
    ];
}
function data_model_deployment_subject_hash(PDO $pdo,array $d): string {return data_attribution_hash(data_model_deployment_subject_material($pdo,$d));}
function data_model_deployment_checkpoint_signature_hash(array $c): string {
    return data_attribution_hash(['deployment_id'=>(int)$c['deployment_id'],'stage'=>$c['stage'],'reviewer_user_id'=>(int)$c['reviewer_user_id'],'recommendation'=>$c['recommendation'],'note'=>(string)($c['note']??''),'deployment_snapshot_hash'=>$c['deployment_snapshot_hash'],'signed_at'=>$c['signed_at']]);
}
function data_model_deployment_checkpoint_integrity(PDO $pdo,array $d,array $c): array {
    $sig=data_model_deployment_checkpoint_signature_hash($c);$subject=data_model_deployment_subject_hash($pdo,$d);
    $sigOk=hash_equals((string)$c['signature_hash'],$sig);$current=hash_equals((string)$c['deployment_snapshot_hash'],$subject);
    return ['ok'=>$sigOk&&$current,'signature_ok'=>$sigOk,'current_snapshot'=>$current,'computed_signature'=>$sig,'current_subject_hash'=>$subject];
}
function data_model_deployment_checkpoint_summary(PDO $pdo,array $d,string $stage): array {
    $current=[];$stale=[];$counts=['proceed'=>0,'hold'=>0,'stop'=>0];
    foreach(data_model_deployment_checkpoints($pdo,(int)$d['id']) as $c){
        if($c['stage']!==$stage)continue;$c['integrity']=data_model_deployment_checkpoint_integrity($pdo,$d,$c);
        if($c['integrity']['ok']){$current[]=$c;if(isset($counts[$c['recommendation']]))$counts[$c['recommendation']]++;}else$stale[]=$c;
    }
    return ['current'=>$current,'stale'=>$stale,'counts'=>$counts,'pass'=>$counts['proceed']>=1&&$counts['hold']===0&&$counts['stop']===0];
}
function data_model_deployment_locked(PDO $pdo,string $publicId,callable $callback): mixed {
    $seed=data_model_deployment_get($pdo,$publicId);if(!$seed)throw new RuntimeException('Deployment not found.');
    return app_with_advisory_lock($pdo,'model-deployment',(int)$seed['id'],function() use($pdo,$publicId,$callback){
        $fresh=data_model_deployment_get($pdo,$publicId);if(!$fresh)throw new RuntimeException('Deployment not found.');
        return $callback($fresh);
    },5);
}

function data_model_deployment_create(PDO $pdo,array $viewer,string $releaseDecisionPublicId,array $input): array {
    data_model_deployment_require_admin($viewer);
    if(!data_model_deployment_ready($pdo))throw new RuntimeException('Model Deployment requires the Phase 44 database upgrade.');
    $decision=data_model_release_decision_get($pdo,$releaseDecisionPublicId);
    if(!$decision)throw new RuntimeException('Release decision not found.');
    if($decision['status']!=='decision_recorded'||$decision['outcome']!=='proceed_to_governed_release')throw new RuntimeException('Only a signed Phase 43 Proceed decision may create a deployment.');
    if(!data_model_release_decision_integrity($pdo,$decision)['ok'])throw new RuntimeException('Release decision failed integrity validation.');
    $routes=data_model_deployment_route_keys($input['route_keys']??[]);$routing=data_model_deployment_routing_snapshot($pdo,$routes);
    $routeJson=data_attribution_encode($routes);$routingJson=data_attribution_encode($routing);
    $monitor=data_model_deployment_monitoring_policy(array_merge((array)$decision['deployment_plan'],$input));
    if(trim((string)$monitor['monitoring_owner'])==='')throw new InvalidArgumentException('Monitoring owner is required.');
    if(trim((string)$monitor['success_criteria'])==='')throw new InvalidArgumentException('Success criteria are required.');
    $monitorJson=data_attribution_encode($monitor);
    $rollback=data_model_version_get($pdo,(string)($decision['rollback_plan']['target_model_version_public_id']??''));
    $model=data_model_version_get($pdo,(string)$decision['model_version_public_id']);
    if(!$model||!$rollback)throw new RuntimeException('Deployment model lineage is incomplete.');
    $traffic=max(1,min(50,(int)($input['planned_traffic_percent']??$decision['deployment_plan']['initial_traffic_percent']??10)));$public=ulid_like();
    $pdo->prepare("INSERT INTO data_model_deployments(public_id,release_decision_id,registry_id,model_version_id,rollback_version_id,status,route_keys_json,route_keys_hash,planned_traffic_percent,current_traffic_percent,monitoring_policy_json,monitoring_policy_hash,release_decision_hash,release_signature_hash,model_version_hash,rollback_version_hash,routing_before_json,routing_before_hash,routing_current_json,routing_current_hash,created_by_user_id) VALUES(?,?,?,?,?,'draft',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$public,$decision['id'],$decision['registry_id'],$model['id'],$rollback['id'],$routeJson,hash('sha256',$routeJson),$traffic,0,$monitorJson,hash('sha256',$monitorJson),$decision['decision_hash'],$decision['final_signature_hash'],$model['version_hash'],$rollback['version_hash'],$routingJson,hash('sha256',$routingJson),$routingJson,hash('sha256',$routingJson),$viewer['id']]);
    $id=(int)$pdo->lastInsertId();data_model_deployment_event($pdo,$id,(int)$viewer['id'],'deployment_created','draft',['release_decision_public_id'=>$decision['public_id'],'routes'=>$routes,'planned_traffic_percent'=>$traffic],hash('sha256',$routingJson));
    return data_model_deployment_get($pdo,$public)??[];
}
function data_model_deployment_runtime_context(PDO $pdo,array $d): array {
    $decision=data_model_release_decision_get($pdo,(string)$d['release_decision_public_id']);
    $model=data_model_version_get($pdo,(string)$d['model_version_public_id']);
    $rollback=data_model_version_get($pdo,(string)$d['rollback_version_public_id']);
    $registry=data_model_registry_get($pdo,(string)$d['registry_public_id']);
    $releaseIntegrity=$decision?data_model_release_decision_integrity($pdo,$decision):['ok'=>false];
    $releaseContext=$decision?data_model_release_current_context($pdo,$decision):['pass'=>false];
    $approval=null;$approvalIntegrity=['ok'=>false];
    if($model){$approval=data_model_latest_approval_receipt($pdo,$model);if($approval)$approvalIntegrity=data_model_receipt_integrity($approval,$model);}
    $candidateRuntime=null;$rollbackRuntime=null;
    try{if($model&&!empty($model['ai_model_id']))$candidateRuntime=ai_model_record($pdo,(int)$model['ai_model_id']);}catch(Throwable $e){}
    try{if($rollback&&!empty($rollback['ai_model_id']))$rollbackRuntime=ai_model_record($pdo,(int)$rollback['ai_model_id']);}catch(Throwable $e){}
    $routingNow=data_model_deployment_routing_snapshot($pdo,(array)$d['route_keys']);$routingNowJson=data_attribution_encode($routingNow);$before=(array)$d['routing_before'];
    $allRollback=true;foreach((array)$d['route_keys'] as $key){if((int)($before[$key]??0)!==(int)($rollback['ai_model_id']??0)){$allRollback=false;break;}}
    $overrideConflict=false;
    if($d['status']==='draft'&&$d['route_keys']){$marks=implode(',',array_fill(0,count($d['route_keys']),'?'));$q=$pdo->prepare("SELECT COUNT(*) FROM data_model_routing_overrides WHERE route_key IN ($marks)");$q->execute($d['route_keys']);$overrideConflict=(int)$q->fetchColumn()>0;}
    $checks=[
        'release_decision_integrity'=>$releaseIntegrity['ok']??false,
        'release_context_current'=>$releaseContext['pass']??false,
        'release_snapshot'=>$decision&&hash_equals((string)$d['release_decision_hash'],(string)$decision['decision_hash']),
        'release_signature_snapshot'=>$decision&&hash_equals((string)$d['release_signature_hash'],(string)$decision['final_signature_hash']),
        'model_integrity'=>$model?data_model_version_integrity($model)['ok']:false,
        'model_hash_snapshot'=>$model&&hash_equals((string)$d['model_version_hash'],(string)$model['version_hash']),
        'model_approved'=>$model&&$model['status']==='approved',
        'approval_receipt_integrity'=>$approvalIntegrity['ok']??false,
        'candidate_runtime_available'=>$candidateRuntime!==null,
        'rollback_integrity'=>$rollback?data_model_version_integrity($rollback)['ok']:false,
        'rollback_hash_snapshot'=>$rollback&&hash_equals((string)$d['rollback_version_hash'],(string)$rollback['version_hash']),
        'rollback_is_current_active'=>$rollback&&$registry&&(int)($registry['active_version_id']??0)===(int)$rollback['id']&&$rollback['status']==='active',
        'rollback_runtime_available'=>$rollbackRuntime!==null,
        'routing_matches_snapshot'=>hash_equals((string)$d['routing_before_hash'],hash('sha256',$routingNowJson)),
        'routing_uses_rollback_runtime'=>$allRollback,
        'no_route_override_conflict'=>!$overrideConflict,
    ];
    return ['pass'=>!in_array(false,$checks,true),'checks'=>$checks,'decision'=>$decision,'model'=>$model,'rollback'=>$rollback,'registry'=>$registry,'approval'=>$approval,'approval_integrity'=>$approvalIntegrity,'candidate_runtime'=>$candidateRuntime,'rollback_runtime'=>$rollbackRuntime,'routing_now'=>$routingNow];
}
function data_model_deployment_preflight(PDO $pdo,array $viewer,string $publicId): array {
    data_model_deployment_require_admin($viewer);
    return data_model_deployment_locked($pdo,$publicId,function(array $d) use($pdo,$viewer,$publicId){
        if($d['status']!=='draft')throw new RuntimeException('Preflight can run only for a draft deployment.');
        $ctx=data_model_deployment_runtime_context($pdo,$d);if(!$ctx['pass'])throw new RuntimeException('Deployment preflight failed: '.implode(', ',array_keys(array_filter($ctx['checks'],fn($v)=>!$v))));
        $routing=data_model_deployment_routing_snapshot($pdo,$d['route_keys']);$json=data_attribution_encode($routing);
        $pdo->beginTransaction();try{
            $q=$pdo->prepare("UPDATE data_model_deployments SET status='preflight_passed',revision=revision+1,routing_current_json=?,routing_current_hash=?,preflight_at=NOW(),stage_changed_at=NOW(),updated_at=NOW() WHERE id=? AND revision=? AND status='draft'");
            $q->execute([$json,hash('sha256',$json),$d['id'],$d['revision']]);if($q->rowCount()!==1)throw new RuntimeException('Deployment changed during preflight; reload and retry.');
            $fresh=data_model_deployment_get($pdo,$publicId);data_model_deployment_event($pdo,(int)$d['id'],(int)$viewer['id'],'preflight_passed','preflight_passed',['checks'=>$ctx['checks']],$fresh['routing_current_hash']??null);$pdo->commit();return $fresh??[];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}
function data_model_deployment_replace_overrides(PDO $pdo,array $d,string $mode,int $traffic,bool $enabled=true): void {
    $pdo->prepare('DELETE FROM data_model_routing_overrides WHERE deployment_id=?')->execute([$d['id']]);$before=(array)$d['routing_before'];
    foreach($d['route_keys'] as $key){$baseline=(int)($before[$key]??0);if(!$baseline)throw new RuntimeException('Deployment route baseline is missing.');$pdo->prepare('INSERT INTO data_model_routing_overrides(deployment_id,route_key,baseline_ai_model_id,candidate_ai_model_id,mode,traffic_percent,enabled) VALUES(?,?,?,?,?,?,?)')->execute([$d['id'],$key,$baseline,(int)$d['candidate_ai_model_id'],$mode,$traffic,$enabled?1:0]);}
}
function data_model_deployment_start_shadow(PDO $pdo,array $viewer,string $publicId): array {
    data_model_deployment_require_admin($viewer);
    return data_model_deployment_locked($pdo,$publicId,function(array $d) use($pdo,$viewer,$publicId){
        if($d['status']!=='preflight_passed')throw new RuntimeException('Shadow rollout requires a successful preflight.');
        $ctx=data_model_deployment_runtime_context($pdo,$d);if(!$ctx['pass'])throw new RuntimeException('Deployment context changed after preflight.');
        $pdo->beginTransaction();try{
            data_model_deployment_replace_overrides($pdo,$d,'shadow',0,true);
            $q=$pdo->prepare("UPDATE data_model_deployments SET status='shadow',revision=revision+1,current_traffic_percent=0,started_at=COALESCE(started_at,NOW()),stage_changed_at=NOW(),updated_at=NOW() WHERE id=? AND revision=? AND status='preflight_passed'");
            $q->execute([$d['id'],$d['revision']]);if($q->rowCount()!==1)throw new RuntimeException('Deployment changed before shadow start; reload and retry.');
            $fresh=data_model_deployment_get($pdo,$publicId);data_model_deployment_event($pdo,(int)$d['id'],(int)$viewer['id'],'shadow_started','shadow',['served_candidate_traffic_percent'=>0,'candidate_responses_user_visible'=>false],$fresh['routing_current_hash']??null);$pdo->commit();return $fresh??[];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}
function data_model_deployment_checkpoint_submit(PDO $pdo,array $viewer,string $publicId,string $recommendation,string $note=''): array {
    data_model_deployment_require_admin($viewer);
    return data_model_deployment_locked($pdo,$publicId,function(array $d) use($pdo,$viewer,$publicId,$recommendation,$note){
        if(!in_array($d['status'],['shadow','canary','limited'],true))throw new RuntimeException('Checkpoints can be signed only during shadow, canary, or limited rollout.');
        if((int)$viewer['id']===(int)$d['created_by_user_id'])throw new RuntimeException('The deployment creator cannot satisfy the independent rollout checkpoint.');
        if(!in_array($recommendation,['proceed','hold','stop'],true))throw new InvalidArgumentException('Invalid rollout checkpoint recommendation.');
        $subject=data_model_deployment_subject_hash($pdo,$d);$signedNote=mb_substr(trim($note),0,3000)?:null;$signedAt=gmdate('Y-m-d H:i:s');
        $row=['deployment_id'=>(int)$d['id'],'stage'=>$d['status'],'reviewer_user_id'=>(int)$viewer['id'],'recommendation'=>$recommendation,'note'=>$signedNote,'deployment_snapshot_hash'=>$subject,'signed_at'=>$signedAt];$sig=data_model_deployment_checkpoint_signature_hash($row);
        $pdo->beginTransaction();try{
            $pdo->prepare('INSERT INTO data_model_deployment_checkpoints(deployment_id,stage,reviewer_user_id,recommendation,note,deployment_snapshot_hash,signature_hash,signed_at) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE recommendation=VALUES(recommendation),note=VALUES(note),deployment_snapshot_hash=VALUES(deployment_snapshot_hash),signature_hash=VALUES(signature_hash),signed_at=VALUES(signed_at),updated_at=NOW()')->execute([$d['id'],$d['status'],$viewer['id'],$recommendation,$signedNote,$subject,$sig,$signedAt]);
            data_model_deployment_event($pdo,(int)$d['id'],(int)$viewer['id'],'checkpoint_signed',(string)$d['status'],['recommendation'=>$recommendation,'subject_hash'=>$subject,'signature_hash'=>$sig],$d['routing_current_hash']);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return data_model_deployment_get($pdo,$publicId)??[];
    });
}
function data_model_deployment_assert_stage_checkpoint(PDO $pdo,array $d): array {
    $summary=data_model_deployment_checkpoint_summary($pdo,$d,(string)$d['status']);
    if(!$summary['pass'])throw new RuntimeException('A current independent Proceed checkpoint is required before advancing rollout.');
    return $summary;
}
function data_model_deployment_full_activate(PDO $pdo,array $viewer,array $d,bool $lockHeld=false): array {
    data_model_deployment_require_admin($viewer);
    if(!$lockHeld){
        return data_model_deployment_locked($pdo,(string)$d['public_id'],function(array $fresh) use($pdo,$viewer){
            return data_model_deployment_full_activate($pdo,$viewer,$fresh,true);
        });
    }
    $d=data_model_deployment_get($pdo,(string)$d['public_id'])??$d;
    if($d['status']!=='limited')throw new RuntimeException('Full activation requires the limited rollout stage.');
    data_model_deployment_assert_stage_checkpoint($pdo,$d);
    $ctx=data_model_deployment_runtime_context($pdo,$d);if(!$ctx['pass'])throw new RuntimeException('Deployment context is no longer valid for full activation.');
    if($ctx['model']['status']!=='approved')throw new RuntimeException('Full deployment requires an approved candidate model.');
    $activated=false;
    try{
        data_model_transition($pdo,$viewer,(string)$d['model_version_public_id'],'active','PHASE 44 FULL DEPLOYMENT '.$d['public_id']);$activated=true;
        $target=[];foreach($d['route_keys'] as $key)$target[$key]=(int)$d['candidate_ai_model_id'];
        $pdo->beginTransaction();
        data_model_deployment_apply_routing($pdo,$viewer,$target);$routing=data_model_deployment_routing_snapshot($pdo,$d['route_keys']);$json=data_attribution_encode($routing);
        $pdo->prepare('DELETE FROM data_model_routing_overrides WHERE deployment_id=?')->execute([$d['id']]);
        $q=$pdo->prepare("UPDATE data_model_deployments SET status='full',revision=revision+1,current_traffic_percent=100,routing_current_json=?,routing_current_hash=?,full_at=NOW(),stage_changed_at=NOW(),updated_at=NOW() WHERE id=? AND revision=? AND status='limited'");
        $q->execute([$json,hash('sha256',$json),$d['id'],$d['revision']]);if($q->rowCount()!==1)throw new RuntimeException('Deployment changed during full activation; activation will be compensated.');
        $fresh=data_model_deployment_get($pdo,(string)$d['public_id']);data_model_deployment_event($pdo,(int)$d['id'],(int)$viewer['id'],'full_activated','full',['candidate_ai_model_id'=>(int)$d['candidate_ai_model_id'],'routes'=>$d['route_keys']],$fresh['routing_current_hash']??null);$pdo->commit();return $fresh??[];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($activated){
            try{data_model_rollback($pdo,$viewer,(string)$d['registry_public_id'],(string)$d['rollback_version_public_id'],'Phase 44 activation compensation after routing/state failure');}
            catch(Throwable $comp){try{data_model_deployment_event($pdo,(int)$d['id'],(int)$viewer['id'],'activation_compensation_failed',(string)$d['status'],['error'=>mb_substr($comp->getMessage(),0,1000)]);}catch(Throwable $ignored){}throw new RuntimeException($e->getMessage().' Activation compensation also failed: '.$comp->getMessage(),0,$e);}
        }
        throw $e;
    }
}
function data_model_deployment_advance(PDO $pdo,array $viewer,string $publicId): array {
    data_model_deployment_require_admin($viewer);
    return data_model_deployment_locked($pdo,$publicId,function(array $d) use($pdo,$viewer,$publicId){
        if(!in_array($d['status'],['shadow','canary','limited'],true))throw new RuntimeException('This deployment stage cannot advance.');
        data_model_deployment_assert_stage_checkpoint($pdo,$d);if($d['status']==='limited')return data_model_deployment_full_activate($pdo,$viewer,$d,true);
        $ctx=data_model_deployment_runtime_context($pdo,$d);if(!$ctx['pass'])throw new RuntimeException('Deployment context changed before stage advance: '.implode(', ',array_keys(array_filter($ctx['checks'],fn($v)=>!$v))));
        $next=$d['status']==='shadow'?'canary':'limited';$traffic=$next==='canary'?max(1,min(50,(int)$d['planned_traffic_percent'])):100;
        $pdo->beginTransaction();try{
            data_model_deployment_replace_overrides($pdo,$d,$next,$traffic,true);$routing=data_model_deployment_routing_snapshot($pdo,$d['route_keys']);$json=data_attribution_encode($routing);
            $q=$pdo->prepare('UPDATE data_model_deployments SET status=?,revision=revision+1,current_traffic_percent=?,routing_current_json=?,routing_current_hash=?,stage_changed_at=NOW(),updated_at=NOW() WHERE id=? AND revision=? AND status=?');
            $q->execute([$next,$traffic,$json,hash('sha256',$json),$d['id'],$d['revision'],$d['status']]);if($q->rowCount()!==1)throw new RuntimeException('Deployment changed during stage advance; reload and retry.');
            $fresh=data_model_deployment_get($pdo,$publicId);data_model_deployment_event($pdo,(int)$d['id'],(int)$viewer['id'],'stage_advanced',$next,['from'=>$d['status'],'to'=>$next,'served_candidate_traffic_percent'=>$traffic],$fresh['routing_current_hash']??null);$pdo->commit();return $fresh??[];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}
function data_model_deployment_pause(PDO $pdo,array $viewer,string $publicId): array {
    data_model_deployment_require_admin($viewer);
    return data_model_deployment_locked($pdo,$publicId,function(array $d) use($pdo,$viewer,$publicId){
        if(!in_array($d['status'],['shadow','canary','limited'],true))throw new RuntimeException('Only an in-progress staged rollout can be paused.');
        $pdo->beginTransaction();try{
            $pdo->prepare('UPDATE data_model_routing_overrides SET enabled=0 WHERE deployment_id=?')->execute([$d['id']]);
            $q=$pdo->prepare("UPDATE data_model_deployments SET paused_stage=status,status='paused',revision=revision+1,current_traffic_percent=0,paused_at=NOW(),stage_changed_at=NOW(),updated_at=NOW() WHERE id=? AND revision=? AND status=?");
            $q->execute([$d['id'],$d['revision'],$d['status']]);if($q->rowCount()!==1)throw new RuntimeException('Deployment changed during pause; reload and retry.');
            $fresh=data_model_deployment_get($pdo,$publicId);data_model_deployment_event($pdo,(int)$d['id'],(int)$viewer['id'],'deployment_paused','paused',['paused_stage'=>$d['status']],$fresh['routing_current_hash']??null);$pdo->commit();return $fresh??[];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}
function data_model_deployment_resume(PDO $pdo,array $viewer,string $publicId): array {
    data_model_deployment_require_admin($viewer);
    return data_model_deployment_locked($pdo,$publicId,function(array $d) use($pdo,$viewer,$publicId){
        if($d['status']!=='paused'||!in_array($d['paused_stage'],['shadow','canary','limited'],true))throw new RuntimeException('Deployment is not resumable.');
        $ctx=data_model_deployment_runtime_context($pdo,$d);if(!$ctx['pass'])throw new RuntimeException('Deployment context changed while paused: '.implode(', ',array_keys(array_filter($ctx['checks'],fn($v)=>!$v))));
        $stage=(string)$d['paused_stage'];$traffic=$stage==='shadow'?0:($stage==='canary'?max(1,min(50,(int)$d['planned_traffic_percent'])):100);
        $pdo->beginTransaction();try{
            $pdo->prepare('UPDATE data_model_routing_overrides SET enabled=1 WHERE deployment_id=?')->execute([$d['id']]);
            $q=$pdo->prepare("UPDATE data_model_deployments SET status=?,paused_stage=NULL,revision=revision+1,current_traffic_percent=?,stage_changed_at=NOW(),updated_at=NOW() WHERE id=? AND revision=? AND status='paused'");
            $q->execute([$stage,$traffic,$d['id'],$d['revision']]);if($q->rowCount()!==1)throw new RuntimeException('Deployment changed during resume; reload and retry.');
            $fresh=data_model_deployment_get($pdo,$publicId);data_model_deployment_event($pdo,(int)$d['id'],(int)$viewer['id'],'deployment_resumed',$stage,['served_candidate_traffic_percent'=>$traffic],$fresh['routing_current_hash']??null);$pdo->commit();return $fresh??[];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}
function data_model_deployment_stop(PDO $pdo,array $viewer,string $publicId,string $note=''): array {
    data_model_deployment_require_admin($viewer);
    return data_model_deployment_locked($pdo,$publicId,function(array $d) use($pdo,$viewer,$publicId,$note){
        if(!in_array($d['status'],['preflight_passed','shadow','canary','limited','paused'],true))throw new RuntimeException('This deployment cannot be stopped; use rollback after full activation.');
        $stopNote=mb_substr(trim($note),0,3000);
        $pdo->beginTransaction();try{
            $pdo->prepare('DELETE FROM data_model_routing_overrides WHERE deployment_id=?')->execute([$d['id']]);
            $q=$pdo->prepare("UPDATE data_model_deployments SET status='stopped',paused_stage=NULL,revision=revision+1,current_traffic_percent=0,stopped_at=NOW(),stage_changed_at=NOW(),updated_at=NOW() WHERE id=? AND revision=? AND status=?");
            $q->execute([$d['id'],$d['revision'],$d['status']]);if($q->rowCount()!==1)throw new RuntimeException('Deployment changed during stop; reload and retry.');
            $fresh=data_model_deployment_get($pdo,$publicId);data_model_deployment_event($pdo,(int)$d['id'],(int)$viewer['id'],'deployment_stopped','stopped',['note'=>$stopNote],$fresh['routing_current_hash']??null);$pdo->commit();return $fresh??[];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}
function data_model_deployment_rollback(PDO $pdo,array $viewer,string $publicId,string $note=''): array {
    data_model_deployment_require_admin($viewer);
    return data_model_deployment_locked($pdo,$publicId,function(array $d) use($pdo,$viewer,$publicId,$note){
        if(in_array($d['status'],['rolled_back','stopped'],true))return $d;if($d['status']==='draft')throw new RuntimeException('Draft deployment has nothing to roll back.');
        $before=(array)$d['routing_before'];$wasFull=$d['status']==='full';$registryRolledBack=false;$rollbackNote=mb_substr(trim($note),0,3000);
        try{
            if($wasFull){data_model_rollback($pdo,$viewer,(string)$d['registry_public_id'],(string)$d['rollback_version_public_id'],'PHASE 44 ROLLBACK '.$d['public_id'].' '.$rollbackNote);$registryRolledBack=true;}
            $pdo->beginTransaction();
            data_model_deployment_apply_routing($pdo,$viewer,$before);$pdo->prepare('DELETE FROM data_model_routing_overrides WHERE deployment_id=?')->execute([$d['id']]);
            $routing=data_model_deployment_routing_snapshot($pdo,$d['route_keys']);$json=data_attribution_encode($routing);
            $q=$pdo->prepare("UPDATE data_model_deployments SET status='rolled_back',paused_stage=NULL,revision=revision+1,current_traffic_percent=0,routing_current_json=?,routing_current_hash=?,rolled_back_at=NOW(),stage_changed_at=NOW(),updated_at=NOW() WHERE id=? AND revision=? AND status=?");
            $q->execute([$json,hash('sha256',$json),$d['id'],$d['revision'],$d['status']]);if($q->rowCount()!==1)throw new RuntimeException('Deployment changed during rollback; rollback will be compensated.');
            $fresh=data_model_deployment_get($pdo,$publicId);data_model_deployment_event($pdo,(int)$d['id'],(int)$viewer['id'],'deployment_rolled_back','rolled_back',['from'=>$d['status'],'note'=>$rollbackNote],$fresh['routing_current_hash']??null);$pdo->commit();return $fresh??[];
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            if($registryRolledBack){
                try{data_model_rollback($pdo,$viewer,(string)$d['registry_public_id'],(string)$d['model_version_public_id'],'Phase 44 rollback compensation after routing/state failure');}
                catch(Throwable $comp){try{data_model_deployment_event($pdo,(int)$d['id'],(int)$viewer['id'],'rollback_compensation_failed',(string)$d['status'],['error'=>mb_substr($comp->getMessage(),0,1000)]);}catch(Throwable $ignored){}throw new RuntimeException($e->getMessage().' Rollback compensation also failed: '.$comp->getMessage(),0,$e);}
            }
            throw $e;
        }
    });
}
function data_model_deployment_resolve_route(PDO $pdo,string $routeKey,int $baseModelId): int {
    if(!$baseModelId||!data_model_deployment_ready($pdo)||!isset(data_model_deployment_route_map()[$routeKey]))return $baseModelId;
    try{
        $q=$pdo->prepare('SELECT o.* FROM data_model_routing_overrides o JOIN data_model_deployments d ON d.id=o.deployment_id WHERE o.route_key=? AND o.enabled=1 LIMIT 1');$q->execute([$routeKey]);$o=$q->fetch();
        if(!$o||(int)$o['baseline_ai_model_id']!==$baseModelId)return $baseModelId;$candidate=(int)$o['candidate_ai_model_id'];ai_model_record($pdo,$candidate);
        if($o['mode']==='shadow')return $baseModelId;
        if($o['mode']==='limited')return $candidate;
        if($o['mode']==='canary'&&random_int(1,100)<=max(0,min(100,(int)$o['traffic_percent'])))return $candidate;
    }catch(Throwable $e){}
    return $baseModelId;
}
function data_model_deployment_summary(PDO $pdo): array {
    if(!data_model_deployment_ready($pdo))return ['ready'=>false];$scalar=fn(string $sql)=>(int)$pdo->query($sql)->fetchColumn();
    return ['ready'=>true,'deployments'=>$scalar('SELECT COUNT(*) FROM data_model_deployments'),'in_progress'=>$scalar("SELECT COUNT(*) FROM data_model_deployments WHERE status IN ('preflight_passed','shadow','canary','limited','paused')"),'full'=>$scalar("SELECT COUNT(*) FROM data_model_deployments WHERE status='full'"),'rolled_back'=>$scalar("SELECT COUNT(*) FROM data_model_deployments WHERE status='rolled_back'"),'overrides'=>$scalar('SELECT COUNT(*) FROM data_model_routing_overrides WHERE enabled=1')];
}
