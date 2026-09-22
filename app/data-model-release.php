<?php
declare(strict_types=1);

/**
 * Phase 43 — Model Release Decision Workspace
 *
 * Human release-decision layer above Phase 42 readiness packets.
 * This runtime records release evidence, reviews, plans, and signed decisions.
 * It never changes Phase 40 lifecycle state and never mutates ai_settings.
 */

function data_model_release_ready(PDO $pdo): bool {
    try{return data_post_training_ready($pdo)&&installer_table_exists($pdo,'data_model_release_decisions')&&installer_table_exists($pdo,'data_model_release_checklist_items')&&installer_table_exists($pdo,'data_model_release_reviews')&&installer_table_exists($pdo,'data_model_release_signatures');}
    catch(Throwable $e){return false;}
}
function data_model_release_require_admin(array $viewer): void {
    if(($viewer['role']??'')!=='admin')throw new RuntimeException('Administrator access is required for Model Release Decision operations.');
}
function data_model_release_event(PDO $pdo,int $decisionId,?int $actorUserId,string $type,array $details=[]): void {
    $pdo->prepare('INSERT INTO data_model_release_events(decision_id,actor_user_id,event_type,details_json) VALUES(?,?,?,?)')->execute([$decisionId,$actorUserId,$type,$details?data_attribution_encode($details):null]);
}
function data_model_release_risk_policy(array $input): array {
    return [
        'required_reviewers'=>max(1,min(5,(int)($input['required_reviewers']??1))),
        'require_independent_review'=>1,
        'require_current_readiness_packet'=>1,
        'require_model_integrity'=>1,
        'require_all_required_checklist_pass'=>1,
        'require_rollback_plan'=>1,
        'policy_version'=>'phase43-v1',
    ];
}
function data_model_release_deployment_plan(array $input): array {
    $strategy=(string)($input['rollout_strategy']??'canary');if(!in_array($strategy,['shadow','canary','limited','full'],true))throw new InvalidArgumentException('Invalid rollout strategy.');
    $traffic=max(0,min(100,(int)($input['initial_traffic_percent']??($strategy==='full'?100:10))));
    return [
        'rollout_strategy'=>$strategy,
        'target_tasks'=>mb_substr(trim((string)($input['target_tasks']??'')),0,3000),
        'initial_traffic_percent'=>$traffic,
        'monitoring_window_minutes'=>max(15,min(10080,(int)($input['monitoring_window_minutes']??120))),
        'success_criteria'=>mb_substr(trim((string)($input['success_criteria']??'')),0,5000),
        'monitoring_owner'=>mb_substr(trim((string)($input['monitoring_owner']??'')),0,255),
        'change_window'=>mb_substr(trim((string)($input['change_window']??'')),0,500),
        'routing_notes'=>mb_substr(trim((string)($input['routing_notes']??'')),0,5000),
    ];
}
function data_model_release_rollback_plan(array $input,string $baselinePublicId): array {
    return [
        'target_model_version_public_id'=>mb_substr(trim((string)($input['rollback_model_version_public_id']??$baselinePublicId)),0,40),
        'trigger_conditions'=>mb_substr(trim((string)($input['rollback_trigger_conditions']??'')),0,5000),
        'rollback_steps'=>mb_substr(trim((string)($input['rollback_steps']??'')),0,5000),
        'recovery_target_minutes'=>max(1,min(10080,(int)($input['recovery_target_minutes']??30))),
        'rollback_owner'=>mb_substr(trim((string)($input['rollback_owner']??'')),0,255),
        'validation_steps'=>mb_substr(trim((string)($input['rollback_validation_steps']??'')),0,5000),
    ];
}
function data_model_release_default_checklist(): array {
    return [
        ['key'=>'evidence_reviewed','category'=>'Evidence','label'=>'Readiness evidence reviewed','description'=>'A human reviewed the Phase 42 readiness packet, benchmark comparisons, human reviews, and Phase 40 gate snapshot.','required'=>1],
        ['key'=>'rights_consent_reviewed','category'=>'Data governance','label'=>'Training rights and consent reviewed','description'=>'Current training-data rights, contributor consent, and dataset integrity remain acceptable for the planned release.','required'=>1],
        ['key'=>'limitations_documented','category'=>'Model risk','label'=>'Known limitations documented','description'=>'Known weaknesses, regressions, unsupported use cases, and expected failure modes are documented.','required'=>1],
        ['key'=>'safety_abuse_reviewed','category'=>'Model risk','label'=>'Safety and abuse risks reviewed','description'=>'Relevant misuse, abuse, and safety risks were reviewed for the intended deployment scope.','required'=>1],
        ['key'=>'privacy_security_reviewed','category'=>'Security','label'=>'Privacy and security reviewed','description'=>'Privacy, security, secret-handling, and data-exposure implications were reviewed.','required'=>1],
        ['key'=>'operational_capacity','category'=>'Operations','label'=>'Operational capacity confirmed','description'=>'Provider/runtime capacity, quotas, latency, cost, and failure handling are acceptable for the rollout scope.','required'=>1],
        ['key'=>'monitoring_alerting','category'=>'Operations','label'=>'Monitoring and alerting defined','description'=>'Success/failure signals, monitoring owner, and alert/escalation path are defined.','required'=>1],
        ['key'=>'routing_change_reviewed','category'=>'Deployment','label'=>'Routing change plan reviewed','description'=>'The intended task/routing changes are explicit and will be applied separately through governed Admin controls.','required'=>1],
        ['key'=>'rollback_validated','category'=>'Rollback','label'=>'Rollback path validated','description'=>'Rollback target, trigger conditions, steps, recovery target, and validation procedure are explicit.','required'=>1],
        ['key'=>'incident_owner_assigned','category'=>'Operations','label'=>'Release/incident owner assigned','description'=>'A named human owner is responsible for monitoring the release and coordinating rollback if required.','required'=>1],
    ];
}
function data_model_release_packet_by_id(PDO $pdo,int $id): ?array {
    $q=$pdo->prepare('SELECT p.*,pt.public_id plan_public_id,pt.status plan_status,pt.output_model_version_id,pt.baseline_model_version_id FROM data_post_training_packets p JOIN data_post_training_plans pt ON pt.id=p.plan_id WHERE p.id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch();if(!$row)return null;$row['packet']=json_decode((string)$row['packet_json'],true)?:[];return $row;
}
function data_model_release_packet_by_public(PDO $pdo,string $publicId): ?array {
    $q=$pdo->prepare('SELECT id FROM data_post_training_packets WHERE public_id=? LIMIT 1');$q->execute([trim($publicId)]);$id=(int)($q->fetchColumn()?:0);return $id?data_model_release_packet_by_id($pdo,$id):null;
}
function data_model_release_eligible_packets(PDO $pdo,int $limit=100): array {
    if(!data_model_release_ready($pdo))return [];$limit=max(1,min(250,$limit));$q=$pdo->query("SELECT pk.id,pk.public_id,pk.packet_hash,pk.sequence_number,pk.created_at,p.public_id plan_public_id,p.status plan_status,p.output_model_version_id,p.baseline_model_version_id,mv.public_id model_version_public_id,mv.version_label,mv.status model_status,r.name registry_name FROM data_post_training_packets pk JOIN data_post_training_plans p ON p.id=pk.plan_id JOIN data_model_versions mv ON mv.id=p.output_model_version_id JOIN data_model_registry r ON r.id=mv.registry_id LEFT JOIN data_model_release_decisions d ON d.readiness_packet_id=pk.id WHERE p.status='ready' AND d.id IS NULL AND mv.status IN ('experimental','candidate','approved') ORDER BY pk.id DESC LIMIT $limit");$out=[];foreach($q->fetchAll() as $row){$packet=data_model_release_packet_by_id($pdo,(int)$row['id']);if($packet&&data_post_training_packet_integrity($packet)['ok'])$out[]=$row;}return $out;
}
function data_model_release_decision_get(PDO $pdo,string $publicId): ?array {
    if(!data_model_release_ready($pdo))return null;$q=$pdo->prepare('SELECT d.*,p.public_id plan_public_id,p.status plan_status,pk.public_id packet_public_id,mv.public_id model_version_public_id,mv.version_label model_version_label,mv.status model_status,mv.ai_model_id,bv.public_id baseline_version_public_id,bv.version_label baseline_version_label,bv.status baseline_status,r.public_id registry_public_id,r.name registry_name,u.display_name creator_name,fs.display_name final_signer_name FROM data_model_release_decisions d JOIN data_post_training_plans p ON p.id=d.post_training_plan_id JOIN data_post_training_packets pk ON pk.id=d.readiness_packet_id JOIN data_model_versions mv ON mv.id=d.model_version_id JOIN data_model_versions bv ON bv.id=d.baseline_model_version_id JOIN data_model_registry r ON r.id=d.registry_id JOIN users u ON u.id=d.created_by_user_id LEFT JOIN users fs ON fs.id=d.final_signed_by_user_id WHERE d.public_id=? LIMIT 1');$q->execute([trim($publicId)]);$d=$q->fetch();if(!$d)return null;$d['risk_policy']=json_decode((string)$d['risk_policy_json'],true)?:[];$d['deployment_plan']=json_decode((string)$d['deployment_plan_json'],true)?:[];$d['rollback_plan']=json_decode((string)$d['rollback_plan_json'],true)?:[];return $d;
}
function data_model_release_decisions(PDO $pdo,int $limit=100): array {
    if(!data_model_release_ready($pdo))return [];$limit=max(1,min(250,$limit));$q=$pdo->query("SELECT d.*,mv.version_label model_version_label,mv.status model_status,r.name registry_name,pk.public_id packet_public_id,(SELECT COUNT(*) FROM data_model_release_reviews rv WHERE rv.decision_id=d.id) review_count,(SELECT COUNT(*) FROM data_model_release_checklist_items c WHERE c.decision_id=d.id AND c.required=1 AND c.status='pass') required_pass_count,(SELECT COUNT(*) FROM data_model_release_checklist_items c WHERE c.decision_id=d.id AND c.required=1) required_check_count FROM data_model_release_decisions d JOIN data_model_versions mv ON mv.id=d.model_version_id JOIN data_model_registry r ON r.id=d.registry_id JOIN data_post_training_packets pk ON pk.id=d.readiness_packet_id ORDER BY d.id DESC LIMIT $limit");return $q->fetchAll();
}
function data_model_release_checklist(PDO $pdo,int $decisionId): array {
    $q=$pdo->prepare('SELECT c.*,u.display_name reviewer_name FROM data_model_release_checklist_items c LEFT JOIN users u ON u.id=c.reviewed_by_user_id WHERE c.decision_id=? ORDER BY c.position,c.id');$q->execute([$decisionId]);return $q->fetchAll();
}
function data_model_release_reviews(PDO $pdo,int $decisionId): array {
    $q=$pdo->prepare('SELECT r.*,u.display_name reviewer_name FROM data_model_release_reviews r JOIN users u ON u.id=r.reviewer_user_id WHERE r.decision_id=? ORDER BY r.id');$q->execute([$decisionId]);return $q->fetchAll();
}
function data_model_release_signatures(PDO $pdo,int $decisionId): array {
    $q=$pdo->prepare('SELECT s.*,u.display_name signer_name FROM data_model_release_signatures s JOIN users u ON u.id=s.signer_user_id WHERE s.decision_id=? ORDER BY s.id');$q->execute([$decisionId]);return $q->fetchAll();
}
function data_model_release_events(PDO $pdo,int $decisionId,int $limit=100): array {
    $limit=max(1,min(250,$limit));$q=$pdo->prepare("SELECT e.*,u.display_name actor_name FROM data_model_release_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.decision_id=? ORDER BY e.id DESC LIMIT $limit");$q->execute([$decisionId]);return $q->fetchAll();
}
function data_model_release_plans_complete(array $decision): array {
    $deployment=(array)$decision['deployment_plan'];$rollback=(array)$decision['rollback_plan'];$deploymentComplete=trim((string)($deployment['target_tasks']??''))!==''&&trim((string)($deployment['success_criteria']??''))!==''&&trim((string)($deployment['monitoring_owner']??''))!=='';$rollbackComplete=trim((string)($rollback['target_model_version_public_id']??''))!==''&&trim((string)($rollback['trigger_conditions']??''))!==''&&trim((string)($rollback['rollback_steps']??''))!==''&&trim((string)($rollback['rollback_owner']??''))!==''&&trim((string)($rollback['validation_steps']??''))!=='';return ['deployment_complete'=>$deploymentComplete,'rollback_complete'=>$rollbackComplete,'pass'=>$deploymentComplete&&$rollbackComplete];
}
function data_model_release_current_context(PDO $pdo,array $decision): array {
    $packet=data_model_release_packet_by_id($pdo,(int)$decision['readiness_packet_id']);$packetIntegrity=$packet?data_post_training_packet_integrity($packet):['ok'=>false];$plan=$packet?data_post_training_plan_get($pdo,(string)$packet['plan_public_id']):null;$model=data_model_version_get($pdo,(string)$decision['model_version_public_id']);$baseline=data_model_version_get($pdo,(string)$decision['baseline_version_public_id']);$rollbackTarget=null;$rollbackPublic=(string)($decision['rollback_plan']['target_model_version_public_id']??'');if($rollbackPublic!=='')$rollbackTarget=data_model_version_get($pdo,$rollbackPublic);
    $checks=[
        'readiness_packet_exists'=>$packet!==null,
        'readiness_packet_integrity'=>$packetIntegrity['ok']??false,
        'readiness_plan_ready'=>$plan&&$plan['status']==='ready',
        'packet_hash_snapshot'=>$packet&&hash_equals((string)$decision['readiness_packet_hash'],(string)$packet['packet_hash']),
        'model_exists'=>$model!==null,
        'model_integrity'=>$model?data_model_version_integrity($model)['ok']:false,
        'model_hash_snapshot'=>$model&&hash_equals((string)$decision['model_version_hash'],(string)$model['version_hash']),
        'model_pre_activation'=>$model&&in_array($model['status'],['experimental','candidate','approved'],true),
        'baseline_exists'=>$baseline!==null,
        'baseline_integrity'=>$baseline?data_model_version_integrity($baseline)['ok']:false,
        'baseline_hash_snapshot'=>$baseline&&hash_equals((string)$decision['baseline_version_hash'],(string)$baseline['version_hash']),
        'rollback_target_exists'=>$rollbackTarget!==null,
        'rollback_target_integrity'=>$rollbackTarget?data_model_version_integrity($rollbackTarget)['ok']:false,
        'rollback_target_not_candidate'=>$rollbackTarget&&$model&&(int)$rollbackTarget['id']!==(int)$model['id'],
    ];
    return ['pass'=>!in_array(false,$checks,true),'checks'=>$checks,'packet'=>$packet,'packet_integrity'=>$packetIntegrity,'plan'=>$plan,'model'=>$model,'baseline'=>$baseline,'rollback_target'=>$rollbackTarget];
}
function data_model_release_subject_material(PDO $pdo,array $decision): array {
    $checklist=[];foreach(data_model_release_checklist($pdo,(int)$decision['id']) as $item)$checklist[]=['check_key'=>$item['check_key'],'required'=>(int)$item['required'],'status'=>$item['status'],'note'=>$item['note'],'reviewed_by_user_id'=>$item['reviewed_by_user_id'],'reviewed_at'=>$item['reviewed_at']];
    return [
        'public_id'=>$decision['public_id'],
        'packet_public_id'=>$decision['packet_public_id'],
        'readiness_packet_hash'=>$decision['readiness_packet_hash'],
        'model_version_public_id'=>$decision['model_version_public_id'],
        'model_version_hash'=>$decision['model_version_hash'],
        'baseline_version_public_id'=>$decision['baseline_version_public_id'],
        'baseline_version_hash'=>$decision['baseline_version_hash'],
        'required_reviewers'=>(int)$decision['required_reviewers'],
        'risk_policy_hash'=>$decision['risk_policy_hash'],
        'deployment_plan_hash'=>$decision['deployment_plan_hash'],
        'rollback_plan_hash'=>$decision['rollback_plan_hash'],
        'checklist'=>$checklist,
    ];
}
function data_model_release_subject_hash(PDO $pdo,array $decision): string {
    return data_attribution_hash(data_model_release_subject_material($pdo,$decision));
}
function data_model_release_review_signature_hash(array $review): string {
    return data_attribution_hash(['decision_id'=>(int)$review['decision_id'],'reviewer_user_id'=>(int)$review['reviewer_user_id'],'recommendation'=>$review['recommendation'],'note'=>(string)($review['note']??''),'decision_snapshot_hash'=>$review['decision_snapshot_hash'],'signed_at'=>$review['signed_at']]);
}
function data_model_release_review_integrity(PDO $pdo,array $decision,array $review): array {
    $sig=data_model_release_review_signature_hash($review);$current=data_model_release_subject_hash($pdo,$decision);$sigOk=hash_equals((string)$review['signature_hash'],$sig);$currentOk=hash_equals((string)$review['decision_snapshot_hash'],$current);return ['ok'=>$sigOk&&$currentOk,'signature_ok'=>$sigOk,'current_snapshot'=>$currentOk,'computed_signature'=>$sig,'current_subject_hash'=>$current];
}
function data_model_release_checklist_summary(PDO $pdo,array $decision): array {
    $rows=data_model_release_checklist($pdo,(int)$decision['id']);$required=0;$passed=0;$failed=0;$pending=0;foreach($rows as $r){if((int)$r['required']){$required++;if($r['status']==='pass')$passed++;elseif($r['status']==='fail')$failed++;else$pending++;}}return ['required'=>$required,'passed'=>$passed,'failed'=>$failed,'pending'=>$pending,'pass'=>$required>0&&$passed===$required];
}
function data_model_release_review_summary(PDO $pdo,array $decision): array {
    $reviews=data_model_release_reviews($pdo,(int)$decision['id']);$current=[];$stale=[];$counts=['proceed'=>0,'hold'=>0,'reject'=>0];foreach($reviews as $r){$integrity=data_model_release_review_integrity($pdo,$decision,$r);$r['integrity']=$integrity;if($integrity['ok']){$current[]=$r;if(isset($counts[$r['recommendation']]))$counts[$r['recommendation']]++;}else$stale[]=$r;}return ['reviews'=>$reviews,'current'=>$current,'stale'=>$stale,'counts'=>$counts,'required'=>(int)$decision['required_reviewers'],'enough'=>count($current)>=(int)$decision['required_reviewers']];
}
function data_model_release_decision_create(PDO $pdo,array $viewer,string $packetPublicId,array $input=[]): array {
    data_model_release_require_admin($viewer);if(!data_model_release_ready($pdo))throw new RuntimeException('Model Release Decision Workspace requires the Phase 43 database upgrade.');$packet=data_model_release_packet_by_public($pdo,$packetPublicId);if(!$packet)throw new RuntimeException('Readiness packet not found.');if(!data_post_training_packet_integrity($packet)['ok'])throw new RuntimeException('Readiness packet failed integrity validation.');$plan=data_post_training_plan_get($pdo,(string)$packet['plan_public_id']);if(!$plan||$plan['status']!=='ready')throw new RuntimeException('Release decisions require a currently ready Phase 42 plan.');$model=data_model_version_get($pdo,(string)$plan['output_version_public_id']);$baseline=data_model_version_get($pdo,(string)$plan['baseline_version_public_id']);if(!$model||!$baseline)throw new RuntimeException('Release decision model lineage is incomplete.');if(!in_array($model['status'],['experimental','candidate','approved'],true))throw new RuntimeException('Release decision model must be pre-activation.');if(!data_model_version_integrity($model)['ok']||!data_model_version_integrity($baseline)['ok'])throw new RuntimeException('Release decision requires integrity-valid model versions.');
    $policy=data_model_release_risk_policy($input);$deployment=data_model_release_deployment_plan($input);$rollback=data_model_release_rollback_plan($input,(string)$baseline['public_id']);$title=mb_substr(trim((string)($input['title']??('Release decision · '.$model['version_label']))),0,190);if($title==='')throw new InvalidArgumentException('Release decision title is required.');$summary=mb_substr(trim((string)($input['decision_summary']??'')),0,5000)?:null;$policyJson=data_attribution_encode($policy);$deploymentJson=data_attribution_encode($deployment);$rollbackJson=data_attribution_encode($rollback);$public=ulid_like();
    $pdo->beginTransaction();try{$pdo->prepare("INSERT INTO data_model_release_decisions(public_id,post_training_plan_id,readiness_packet_id,model_version_id,baseline_model_version_id,registry_id,status,title,decision_summary,required_reviewers,risk_policy_json,risk_policy_hash,deployment_plan_json,deployment_plan_hash,rollback_plan_json,rollback_plan_hash,readiness_packet_hash,model_version_hash,baseline_version_hash,created_by_user_id) VALUES(?,?,?,?,?,?,'draft',?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$public,$plan['id'],$packet['id'],$model['id'],$baseline['id'],$model['registry_id'],$title,$summary,$policy['required_reviewers'],$policyJson,hash('sha256',$policyJson),$deploymentJson,hash('sha256',$deploymentJson),$rollbackJson,hash('sha256',$rollbackJson),$packet['packet_hash'],$model['version_hash'],$baseline['version_hash'],$viewer['id']]);$id=(int)$pdo->lastInsertId();$pos=0;foreach(data_model_release_default_checklist() as $item){$pos++;$pdo->prepare("INSERT INTO data_model_release_checklist_items(decision_id,check_key,category,label,description,required,position,status) VALUES(?,?,?,?,?,?,?,'pending')")->execute([$id,$item['key'],$item['category'],$item['label'],$item['description'],$item['required'],$pos]);}data_model_release_event($pdo,$id,(int)$viewer['id'],'decision_created',['packet_public_id'=>$packet['public_id'],'model_version_public_id'=>$model['public_id'],'required_reviewers'=>$policy['required_reviewers']]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}return data_model_release_decision_get($pdo,$public)??[];
}
function data_model_release_update_draft(PDO $pdo,array $viewer,string $publicId,array $input): array {
    data_model_release_require_admin($viewer);$d=data_model_release_decision_get($pdo,$publicId);if(!$d)throw new RuntimeException('Release decision not found.');if($d['status']!=='draft')throw new RuntimeException('Release decision plans are immutable after review opens.');$policy=data_model_release_risk_policy(array_merge((array)$d['risk_policy'],$input));$deployment=data_model_release_deployment_plan(array_merge((array)$d['deployment_plan'],$input));$rollback=data_model_release_rollback_plan(array_merge((array)$d['rollback_plan'],$input),(string)$d['baseline_version_public_id']);$title=mb_substr(trim((string)($input['title']??$d['title'])),0,190);$summary=mb_substr(trim((string)($input['decision_summary']??$d['decision_summary']??'')),0,5000)?:null;$pj=data_attribution_encode($policy);$dj=data_attribution_encode($deployment);$rj=data_attribution_encode($rollback);$pdo->prepare('UPDATE data_model_release_decisions SET title=?,decision_summary=?,required_reviewers=?,risk_policy_json=?,risk_policy_hash=?,deployment_plan_json=?,deployment_plan_hash=?,rollback_plan_json=?,rollback_plan_hash=?,updated_at=NOW() WHERE id=?')->execute([$title,$summary,$policy['required_reviewers'],$pj,hash('sha256',$pj),$dj,hash('sha256',$dj),$rj,hash('sha256',$rj),$d['id']]);data_model_release_event($pdo,(int)$d['id'],(int)$viewer['id'],'draft_updated',[]);return data_model_release_decision_get($pdo,$publicId)??[];
}
function data_model_release_check_update(PDO $pdo,array $viewer,string $publicId,string $checkKey,string $status,string $note=''): array {
    data_model_release_require_admin($viewer);$d=data_model_release_decision_get($pdo,$publicId);if(!$d)throw new RuntimeException('Release decision not found.');if(!in_array($d['status'],['draft','in_review'],true))throw new RuntimeException('Checklist is immutable after a release decision is recorded.');if(!in_array($status,['pending','pass','fail','not_applicable'],true))throw new InvalidArgumentException('Invalid checklist status.');$q=$pdo->prepare('SELECT * FROM data_model_release_checklist_items WHERE decision_id=? AND check_key=? LIMIT 1');$q->execute([$d['id'],$checkKey]);$item=$q->fetch();if(!$item)throw new RuntimeException('Release checklist item not found.');if((int)$item['required']&&$status==='not_applicable')throw new RuntimeException('Required release checklist items cannot be marked not applicable.');$note=mb_substr(trim($note),0,2000)?:null;$reviewer=$status==='pending'?null:(int)$viewer['id'];$reviewedAt=$status==='pending'?null:gmdate('Y-m-d H:i:s');$pdo->prepare('UPDATE data_model_release_checklist_items SET status=?,note=?,reviewed_by_user_id=?,reviewed_at=? WHERE id=?')->execute([$status,$note,$reviewer,$reviewedAt,$item['id']]);data_model_release_event($pdo,(int)$d['id'],(int)$viewer['id'],'checklist_updated',['check_key'=>$checkKey,'status'=>$status]);return data_model_release_decision_get($pdo,$publicId)??[];
}
function data_model_release_open_review(PDO $pdo,array $viewer,string $publicId): array {
    data_model_release_require_admin($viewer);$d=data_model_release_decision_get($pdo,$publicId);if(!$d)throw new RuntimeException('Release decision not found.');if($d['status']!=='draft')throw new RuntimeException('Only draft release decisions can open review.');$context=data_model_release_current_context($pdo,$d);if(!$context['pass'])throw new RuntimeException('Release decision context is not currently valid.');$plans=data_model_release_plans_complete($d);if(!$plans['pass'])throw new RuntimeException('Deployment and rollback plans must be complete before review opens.');$pdo->prepare("UPDATE data_model_release_decisions SET status='in_review',opened_for_review_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$d['id']]);data_model_release_event($pdo,(int)$d['id'],(int)$viewer['id'],'review_opened',['subject_hash'=>data_model_release_subject_hash($pdo,$d)]);return data_model_release_decision_get($pdo,$publicId)??[];
}
function data_model_release_review_submit(PDO $pdo,array $viewer,string $publicId,string $recommendation,string $note=''): array {
    data_model_release_require_admin($viewer);$d=data_model_release_decision_get($pdo,$publicId);if(!$d)throw new RuntimeException('Release decision not found.');if($d['status']!=='in_review')throw new RuntimeException('Independent reviews can be signed only while the release decision is in review.');if((int)$viewer['id']===(int)$d['created_by_user_id'])throw new RuntimeException('The release-decision creator cannot satisfy the independent reviewer requirement.');if(!in_array($recommendation,['proceed','hold','reject'],true))throw new InvalidArgumentException('Invalid reviewer recommendation.');$subject=data_model_release_subject_hash($pdo,$d);$note=mb_substr(trim($note),0,3000)?:null;$signedAt=gmdate('Y-m-d H:i:s');$review=['decision_id'=>(int)$d['id'],'reviewer_user_id'=>(int)$viewer['id'],'recommendation'=>$recommendation,'note'=>$note,'decision_snapshot_hash'=>$subject,'signed_at'=>$signedAt];$sig=data_model_release_review_signature_hash($review);$pdo->prepare("INSERT INTO data_model_release_reviews(decision_id,reviewer_user_id,recommendation,note,decision_snapshot_hash,signature_hash,signed_at) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE recommendation=VALUES(recommendation),note=VALUES(note),decision_snapshot_hash=VALUES(decision_snapshot_hash),signature_hash=VALUES(signature_hash),signed_at=VALUES(signed_at),updated_at=NOW()")->execute([$d['id'],$viewer['id'],$recommendation,$note,$subject,$sig,$signedAt]);$pdo->prepare("INSERT INTO data_model_release_signatures(decision_id,signer_user_id,signature_type,subject_hash,signature_hash,note,signed_at) VALUES(?,?,'review',?,?,?,?) ON DUPLICATE KEY UPDATE subject_hash=VALUES(subject_hash),signature_hash=VALUES(signature_hash),note=VALUES(note),signed_at=VALUES(signed_at)")->execute([$d['id'],$viewer['id'],$subject,$sig,$note,$signedAt]);data_model_release_event($pdo,(int)$d['id'],(int)$viewer['id'],'review_signed',['recommendation'=>$recommendation,'subject_hash'=>$subject,'signature_hash'=>$sig]);return data_model_release_decision_get($pdo,$publicId)??[];
}
function data_model_release_finalize_checks(PDO $pdo,array $decision,string $outcome): array {
    $context=data_model_release_current_context($pdo,$decision);$plans=data_model_release_plans_complete($decision);$checklist=data_model_release_checklist_summary($pdo,$decision);$reviews=data_model_release_review_summary($pdo,$decision);$checks=['context_current'=>$context['pass'],'deployment_plan'=>$plans['deployment_complete'],'rollback_plan'=>$plans['rollback_complete'],'independent_reviews'=>$reviews['enough']];
    if($outcome==='proceed_to_governed_release'){$checks['required_checklist']=$checklist['pass'];$checks['reviewers_recommend_proceed']=$reviews['counts']['proceed']>=(int)$decision['required_reviewers']&&$reviews['counts']['hold']===0&&$reviews['counts']['reject']===0;}else{$checks['required_checklist']=true;$checks['reviewers_recommend_proceed']=true;}
    return ['pass'=>!in_array(false,$checks,true),'checks'=>$checks,'context'=>$context,'plans'=>$plans,'checklist'=>$checklist,'reviews'=>$reviews];
}
function data_model_release_final_hash_material(PDO $pdo,array $decision,string $outcome,string $rationale,string $signedAt,int $signerUserId): array {
    $reviews=[];foreach(data_model_release_review_summary($pdo,$decision)['current'] as $r)$reviews[]=['reviewer_user_id'=>(int)$r['reviewer_user_id'],'recommendation'=>$r['recommendation'],'decision_snapshot_hash'=>$r['decision_snapshot_hash'],'signature_hash'=>$r['signature_hash'],'signed_at'=>$r['signed_at']];
    return ['subject_hash'=>data_model_release_subject_hash($pdo,$decision),'outcome'=>$outcome,'decision_summary'=>(string)($decision['decision_summary']??''),'rationale'=>$rationale,'reviews'=>$reviews,'final_signer_user_id'=>$signerUserId,'final_signed_at'=>$signedAt];
}
function data_model_release_finalize(PDO $pdo,array $viewer,string $publicId,string $outcome,string $rationale): array {
    data_model_release_require_admin($viewer);$d=data_model_release_decision_get($pdo,$publicId);if(!$d)throw new RuntimeException('Release decision not found.');if($d['status']!=='in_review')throw new RuntimeException('Release decision must be in review before recording the final human decision.');if(!in_array($outcome,['proceed_to_governed_release','hold','reject'],true))throw new InvalidArgumentException('Invalid release decision outcome.');$rationale=mb_substr(trim($rationale),0,8000);if($rationale==='')throw new InvalidArgumentException('Final release-decision rationale is required.');$gate=data_model_release_finalize_checks($pdo,$d,$outcome);if(!$gate['pass'])throw new RuntimeException('Final release decision is blocked: '.implode(', ',array_keys(array_filter($gate['checks'],fn($v)=>!$v))));
    $signedAt=gmdate('Y-m-d H:i:s');$material=data_model_release_final_hash_material($pdo,$d,$outcome,$rationale,$signedAt,(int)$viewer['id']);$decisionHash=data_attribution_hash($material);$signatureHash=data_attribution_hash(['decision_hash'=>$decisionHash,'signer_user_id'=>(int)$viewer['id'],'signed_at'=>$signedAt,'outcome'=>$outcome]);$pdo->beginTransaction();try{$q=$pdo->prepare('SELECT status FROM data_model_release_decisions WHERE id=? FOR UPDATE');$q->execute([$d['id']]);if((string)$q->fetchColumn()!=='in_review')throw new RuntimeException('Release decision changed before signing.');$pdo->prepare("UPDATE data_model_release_decisions SET status='decision_recorded',outcome=?,rationale=?,decision_hash=?,final_signature_hash=?,final_signed_by_user_id=?,final_signed_at=?,decided_at=?,updated_at=NOW() WHERE id=?")->execute([$outcome,$rationale,$decisionHash,$signatureHash,$viewer['id'],$signedAt,$signedAt,$d['id']]);$pdo->prepare("INSERT INTO data_model_release_signatures(decision_id,signer_user_id,signature_type,subject_hash,signature_hash,note,signed_at) VALUES(?,?,'final_decision',?,?,?,?)")->execute([$d['id'],$viewer['id'],$decisionHash,$signatureHash,mb_substr($rationale,0,1000),$signedAt]);data_model_release_event($pdo,(int)$d['id'],(int)$viewer['id'],'final_decision_signed',['outcome'=>$outcome,'decision_hash'=>$decisionHash,'signature_hash'=>$signatureHash]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}return data_model_release_decision_get($pdo,$publicId)??[];
}
function data_model_release_decision_integrity(PDO $pdo,array $decision): array {
    if(!in_array($decision['status'],['decision_recorded','archived'],true)||empty($decision['decision_hash'])||empty($decision['final_signature_hash'])||empty($decision['final_signed_at'])||empty($decision['final_signed_by_user_id']))return ['ok'=>false,'reason'=>'final_decision_unavailable'];$material=data_model_release_final_hash_material($pdo,$decision,(string)$decision['outcome'],(string)$decision['rationale'],(string)$decision['final_signed_at'],(int)$decision['final_signed_by_user_id']);$computed=data_attribution_hash($material);$sig=data_attribution_hash(['decision_hash'=>$computed,'signer_user_id'=>(int)$decision['final_signed_by_user_id'],'signed_at'=>$decision['final_signed_at'],'outcome'=>$decision['outcome']]);$ok=hash_equals((string)$decision['decision_hash'],$computed)&&hash_equals((string)$decision['final_signature_hash'],$sig);return ['ok'=>$ok,'reason'=>$ok?'current':'decision_integrity_failure','stored_hash'=>$decision['decision_hash'],'computed_hash'=>$computed,'stored_signature'=>$decision['final_signature_hash'],'computed_signature'=>$sig];
}
function data_model_release_archive(PDO $pdo,array $viewer,string $publicId): array {
    data_model_release_require_admin($viewer);$d=data_model_release_decision_get($pdo,$publicId);if(!$d)throw new RuntimeException('Release decision not found.');if($d['status']==='archived')return $d;if($d['status']!=='decision_recorded')throw new RuntimeException('Only recorded release decisions can be archived.');$pdo->prepare("UPDATE data_model_release_decisions SET status='archived',archived_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$d['id']]);data_model_release_event($pdo,(int)$d['id'],(int)$viewer['id'],'decision_archived',[]);return data_model_release_decision_get($pdo,$publicId)??[];
}
function data_model_release_summary(PDO $pdo): array {
    if(!data_model_release_ready($pdo))return ['ready'=>false];$scalar=fn(string $sql)=>(int)$pdo->query($sql)->fetchColumn();return ['ready'=>true,'decisions'=>$scalar('SELECT COUNT(*) FROM data_model_release_decisions'),'draft'=>$scalar("SELECT COUNT(*) FROM data_model_release_decisions WHERE status='draft'"),'in_review'=>$scalar("SELECT COUNT(*) FROM data_model_release_decisions WHERE status='in_review'"),'recorded'=>$scalar("SELECT COUNT(*) FROM data_model_release_decisions WHERE status='decision_recorded'"),'proceed'=>$scalar("SELECT COUNT(*) FROM data_model_release_decisions WHERE outcome='proceed_to_governed_release'"),'hold'=>$scalar("SELECT COUNT(*) FROM data_model_release_decisions WHERE outcome='hold'"),'reject'=>$scalar("SELECT COUNT(*) FROM data_model_release_decisions WHERE outcome='reject'")];
}
