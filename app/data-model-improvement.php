<?php
declare(strict_types=1);

/**
 * Phase 46 — Production Feedback & Model Improvement Loop.
 *
 * Converts Phase 45 production evidence into human-governed improvement work.
 * This module does not train, evaluate, promote, deploy, roll back, or rewrite routing.
 */
function data_model_improvement_ready(PDO $pdo): bool {
    static $ready=null;if($ready!==null)return $ready;
    try{$ready=data_model_observability_ready($pdo)
        &&installer_table_exists($pdo,'data_model_improvement_cases')
        &&installer_table_exists($pdo,'data_model_improvement_evidence')
        &&installer_table_exists($pdo,'data_model_improvement_proposals')
        &&installer_table_exists($pdo,'data_model_regression_cases')
        &&installer_table_exists($pdo,'data_model_improvement_events');}
    catch(Throwable $e){$ready=false;}return $ready;
}
function data_model_improvement_require_admin(array $viewer): void {
    if(($viewer['role']??'')!=='admin')throw new RuntimeException('Administrator access is required for Model Improvement operations.');
}
function data_model_improvement_classifications(): array {
    return [
      'untriaged'=>'Untriaged',
      'model_defect'=>'Model defect',
      'prompt_tool_defect'=>'Prompt / tool defect',
      'data_defect'=>'Data defect',
      'provider_runtime_defect'=>'Provider / runtime defect',
      'expected_behavior'=>'Expected behavior',
      'no_action'=>'No action',
    ];
}
function data_model_improvement_statuses(): array {
    return [
      'new'=>'New','investigating'=>'Investigating','ready_for_evaluation'=>'Ready for evaluation',
      'ready_for_training'=>'Ready for training','resolved'=>'Resolved','no_action'=>'No action',
    ];
}
function data_model_improvement_case_actionable(array $case): bool {
    $status=(string)($case['status']??$case['case_status']??'');
    return !in_array((string)($case['classification']??'untriaged'),['untriaged','expected_behavior','no_action'],true)
        && $status!=='no_action';
}

function data_model_improvement_case_locked_campaign(PDO $pdo,int $caseId): ?array {
    if(!installer_table_exists($pdo,'data_model_improvement_campaigns')||!installer_table_exists($pdo,'data_model_improvement_campaign_cases'))return null;
    $q=$pdo->prepare("SELECT c.public_id,c.title,c.status,c.plan_hash FROM data_model_improvement_campaign_cases cc JOIN data_model_improvement_campaigns c ON c.id=cc.campaign_id WHERE cc.improvement_case_id=? AND c.plan_hash IS NOT NULL AND c.status NOT IN ('completed','abandoned') ORDER BY c.id DESC LIMIT 1");$q->execute([$caseId]);return $q->fetch()?:null;
}
function data_model_improvement_actionable_proposal_locked(PDO $pdo,string $publicId,callable $callback): mixed {
    $seed=data_model_improvement_proposal_get($pdo,$publicId);if(!$seed)throw new RuntimeException('Improvement proposal not found.');
    return data_model_improvement_case_locked($pdo,(string)$seed['case_public_id'],function(array $case) use($pdo,$publicId,$callback){
        if(!data_model_improvement_case_actionable($case))throw new RuntimeException('The source improvement case is no longer actionable.');
        return data_model_improvement_proposal_locked($pdo,$publicId,function(array $proposal) use($callback,$case){return $callback($proposal,$case);});
    });
}

function data_model_improvement_event(PDO $pdo,int $caseId,?int $actorId,string $type,array $details=[]): void {
    $json=$details?data_attribution_encode($details):null;$hash=data_attribution_hash(['case_id'=>$caseId,'event_type'=>$type,'details'=>$details]);
    $pdo->prepare('INSERT INTO data_model_improvement_events(case_id,actor_user_id,event_type,details_json,evidence_hash) VALUES(?,?,?,?,?)')->execute([$caseId,$actorId,$type,$json,$hash]);
}
function data_model_improvement_case_get(PDO $pdo,string $publicId): ?array {
    if(!data_model_improvement_ready($pdo))return null;
    $q=$pdo->prepare('SELECT c.*,mv.public_id model_version_public_id,mv.version_label model_version_label,d.public_id deployment_public_id,i.public_id incident_public_id,u.display_name owner_name,t.display_name triaged_by_name FROM data_model_improvement_cases c LEFT JOIN data_model_versions mv ON mv.id=c.model_version_id LEFT JOIN data_model_deployments d ON d.id=c.deployment_id LEFT JOIN data_model_incidents i ON i.id=c.primary_incident_id LEFT JOIN users u ON u.id=c.owner_user_id LEFT JOIN users t ON t.id=c.triaged_by_user_id WHERE c.public_id=? LIMIT 1');
    $q->execute([trim($publicId)]);return $q->fetch()?:null;
}
function data_model_improvement_case_for_incident(PDO $pdo,int $incidentId): ?array {
    $q=$pdo->prepare("SELECT c.public_id FROM data_model_improvement_evidence e JOIN data_model_improvement_cases c ON c.id=e.case_id WHERE e.incident_id=? ORDER BY e.id DESC LIMIT 1");$q->execute([$incidentId]);$p=(string)($q->fetchColumn()?:'');return $p!==''?data_model_improvement_case_get($pdo,$p):null;
}
function data_model_improvement_case_by_cluster(PDO $pdo,string $clusterKey): ?array {
    $q=$pdo->prepare('SELECT public_id FROM data_model_improvement_cases WHERE cluster_key=? LIMIT 1');$q->execute([$clusterKey]);$p=(string)($q->fetchColumn()?:'');return $p!==''?data_model_improvement_case_get($pdo,$p):null;
}
function data_model_improvement_cases(PDO $pdo,int $limit=100,?string $status=null): array {
    if(!data_model_improvement_ready($pdo))return [];$limit=max(1,min(500,$limit));$where=$status!==null?' WHERE c.status=?':'';
    $q=$pdo->prepare("SELECT c.*,mv.version_label model_version_label,d.public_id deployment_public_id FROM data_model_improvement_cases c LEFT JOIN data_model_versions mv ON mv.id=c.model_version_id LEFT JOIN data_model_deployments d ON d.id=c.deployment_id$where ORDER BY FIELD(c.status,'new','investigating','ready_for_evaluation','ready_for_training','resolved','no_action'),FIELD(c.severity,'critical','warning'),c.updated_at DESC LIMIT $limit");$q->execute($status!==null?[$status]:[]);return $q->fetchAll();
}
function data_model_improvement_evidence(PDO $pdo,int $caseId): array {
    $q=$pdo->prepare('SELECT e.*,i.public_id incident_public_id,o.public_id observation_public_id,s.public_id signal_public_id FROM data_model_improvement_evidence e LEFT JOIN data_model_incidents i ON i.id=e.incident_id LEFT JOIN data_model_observations o ON o.id=e.observation_id LEFT JOIN data_model_outcome_signals s ON s.id=e.outcome_signal_id WHERE e.case_id=? ORDER BY e.id');$q->execute([$caseId]);return $q->fetchAll();
}
function data_model_improvement_events(PDO $pdo,int $caseId,int $limit=100): array {
    $limit=max(1,min(500,$limit));$q=$pdo->prepare("SELECT e.*,u.display_name actor_name FROM data_model_improvement_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.case_id=? ORDER BY e.id DESC LIMIT $limit");$q->execute([$caseId]);return $q->fetchAll();
}
function data_model_improvement_refresh_evidence(PDO $pdo,int $caseId): void {
    $q=$pdo->prepare('SELECT evidence_hash FROM data_model_improvement_evidence WHERE case_id=? ORDER BY id');$q->execute([$caseId]);$hashes=$q->fetchAll(PDO::FETCH_COLUMN);$hash=data_attribution_hash(['case_id'=>$caseId,'evidence_hashes'=>$hashes]);
    $pdo->prepare('UPDATE data_model_improvement_cases SET evidence_count=?,evidence_hash=?,updated_at=NOW() WHERE id=?')->execute([count($hashes),$hash,$caseId]);
}
function data_model_improvement_add_evidence(PDO $pdo,int $caseId,string $type,string $refPublicId,string $hash,?int $incidentId=null,?int $observationId=null,?int $signalId=null): bool {
    $q=$pdo->prepare('SELECT evidence_hash FROM data_model_improvement_evidence WHERE case_id=? AND evidence_type=? AND evidence_ref_public_id=? LIMIT 1');$q->execute([$caseId,$type,$refPublicId]);$before=$q->fetchColumn();
    $q=$pdo->prepare('INSERT INTO data_model_improvement_evidence(case_id,incident_id,observation_id,outcome_signal_id,evidence_type,evidence_ref_public_id,evidence_hash) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE incident_id=VALUES(incident_id),observation_id=VALUES(observation_id),outcome_signal_id=VALUES(outcome_signal_id),evidence_hash=VALUES(evidence_hash)');$q->execute([$caseId,$incidentId,$observationId,$signalId,$type,$refPublicId,$hash]);
    $changed=$before===false||!hash_equals((string)$before,$hash);data_model_improvement_refresh_evidence($pdo,$caseId);return $changed;
}
function data_model_improvement_notify_case(PDO $pdo,array $case): void {
    if(!function_exists('notification_create'))return;$admins=$pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
    foreach($admins as $uid)notification_create($pdo,(int)$uid,null,'model_improvement_case','model_improvement_case',(string)$case['public_id'],(string)$case['title'],['category'=>'research','dedupe_key'=>'model-improvement:'.$case['public_id'],'group_key'=>'model-improvement:'.$case['cluster_key'],'context'=>['severity'=>$case['severity'],'route_key'=>$case['route_key'],'classification'=>$case['classification']]]);
}
function data_model_improvement_case_locked(PDO $pdo,string $publicId,callable $callback): mixed {
    $seed=data_model_improvement_case_get($pdo,$publicId);if(!$seed)throw new RuntimeException('Improvement case not found.');
    return app_with_advisory_lock($pdo,'model-improvement-case',(int)$seed['id'],function() use($pdo,$publicId,$callback){
        $fresh=data_model_improvement_case_get($pdo,$publicId);if(!$fresh)throw new RuntimeException('Improvement case not found.');
        return $callback($fresh);
    },5);
}
function data_model_improvement_proposal_locked(PDO $pdo,string $publicId,callable $callback): mixed {
    $seed=data_model_improvement_proposal_get($pdo,$publicId);if(!$seed)throw new RuntimeException('Improvement proposal not found.');
    return app_with_advisory_lock($pdo,'model-improvement-proposal',(int)$seed['id'],function() use($pdo,$publicId,$callback){
        $fresh=data_model_improvement_proposal_get($pdo,$publicId);if(!$fresh)throw new RuntimeException('Improvement proposal not found.');
        return $callback($fresh);
    },5);
}

function data_model_improvement_ingest_incident(PDO $pdo,array $incident): ?array {
    if(!data_model_improvement_ready($pdo))return null;$route=(string)$incident['route_key'];$modelVersionId=$incident['model_version_id']!==null?(int)$incident['model_version_id']:null;$deploymentId=(int)$incident['deployment_id'];
    $cluster=data_attribution_hash(['kind'=>'incident','model_version_id'=>$modelVersionId,'route'=>$route,'incident_type'=>$incident['incident_type'],'metric'=>$incident['metric_name']]);
    return app_with_advisory_lock($pdo,'model-improvement-cluster',$cluster,function() use($pdo,$incident,$route,$modelVersionId,$deploymentId,$cluster){
        $case=data_model_improvement_case_by_cluster($pdo,$cluster);$created=false;
        if(!$case){$created=true;
            $public=ulid_like();$title='Production improvement: '.str_replace('_',' ',(string)$incident['metric_name']);$summary=mb_substr((string)($incident['summary']??'Production model-health evidence requires improvement review.'),0,3000);
            $pdo->prepare("INSERT INTO data_model_improvement_cases(public_id,model_version_id,deployment_id,primary_incident_id,route_key,cluster_key,classification,severity,status,title,summary,recurrence_count,evidence_count,evidence_hash) VALUES(?,?,?,?,?,?,'untriaged',?,'new',?,?,1,0,?)")
              ->execute([$public,$modelVersionId,$deploymentId,$incident['id'],$route,$cluster,$incident['severity'],$title,$summary,data_attribution_hash([])]);
            $case=data_model_improvement_case_get($pdo,$public);if(!$case)return null;data_model_improvement_event($pdo,(int)$case['id'],null,'case_created_from_incident',['incident_public_id'=>$incident['public_id'],'incident_evidence_hash'=>$incident['evidence_hash']]);data_model_improvement_notify_case($pdo,$case);
        }
        $inserted=data_model_improvement_add_evidence($pdo,(int)$case['id'],'model_health_incident',(string)$incident['public_id'],(string)$incident['evidence_hash'],(int)$incident['id'],null,null);
        if($inserted&&!$created){$severity=$case['severity']==='critical'||$incident['severity']!=='critical'?$case['severity']:'critical';$pdo->prepare('UPDATE data_model_improvement_cases SET recurrence_count=recurrence_count+1,severity=?,updated_at=NOW() WHERE id=?')->execute([$severity,$case['id']]);}
        return data_model_improvement_case_get($pdo,(string)$case['public_id']);
    },5);
}
function data_model_improvement_ingest_negative_signal(PDO $pdo,array $signal): ?array {
    if(!data_model_improvement_ready($pdo)||(float)$signal['signal_value']>=0)return null;
    $q=$pdo->prepare('SELECT o.*,d.public_id deployment_public_id FROM data_model_observations o LEFT JOIN data_model_deployments d ON d.id=o.deployment_id WHERE o.id=? LIMIT 1');$q->execute([$signal['observation_id']]);$obs=$q->fetch();if(!$obs)return null;
    $cluster=data_attribution_hash(['kind'=>'outcome','model_version_id'=>$obs['model_version_id']!==null?(int)$obs['model_version_id']:null,'route'=>$obs['route_key'],'task'=>$obs['source_task_type'],'signal'=>$signal['signal_type']]);
    return app_with_advisory_lock($pdo,'model-improvement-cluster',$cluster,function() use($pdo,$signal,$obs,$cluster){
        $case=data_model_improvement_case_by_cluster($pdo,$cluster);$created=false;
        if(!$case){$created=true;
            $public=ulid_like();$title='Production feedback: '.str_replace('_',' ',(string)$signal['signal_type']);$summary='Repeated negative production outcome evidence requires human triage before any evaluation or training reuse.';
            $pdo->prepare("INSERT INTO data_model_improvement_cases(public_id,model_version_id,deployment_id,route_key,cluster_key,classification,severity,status,title,summary,recurrence_count,evidence_count,evidence_hash) VALUES(?,?,?,?,?,'untriaged','warning','new',?,?,1,0,?)")
              ->execute([$public,$obs['model_version_id'],$obs['deployment_id'],$obs['route_key'],$cluster,$title,$summary,data_attribution_hash([])]);
            $case=data_model_improvement_case_get($pdo,$public);if(!$case)return null;data_model_improvement_event($pdo,(int)$case['id'],null,'case_created_from_outcome_signal',['signal_public_id'=>$signal['public_id'],'signal_hash'=>$signal['signal_hash']]);data_model_improvement_notify_case($pdo,$case);
        }
        $inserted=data_model_improvement_add_evidence($pdo,(int)$case['id'],'negative_outcome_signal',(string)$signal['public_id'],(string)$signal['signal_hash'],null,(int)$obs['id'],(int)$signal['id']);
        if($inserted&&!$created)$pdo->prepare('UPDATE data_model_improvement_cases SET recurrence_count=recurrence_count+1,updated_at=NOW() WHERE id=?')->execute([$case['id']]);
        return data_model_improvement_case_get($pdo,(string)$case['public_id']);
    },5);
}
function data_model_improvement_triage(PDO $pdo,array $viewer,string $publicId,array $input): array {
    data_model_improvement_require_admin($viewer);
    return data_model_improvement_case_locked($pdo,$publicId,function(array $case) use($pdo,$viewer,$publicId,$input){
        $lockedCampaign=data_model_improvement_case_locked_campaign($pdo,(int)$case['id']);if($lockedCampaign)throw new RuntimeException('Human triage is locked by active campaign '.$lockedCampaign['public_id'].'; close or abandon that campaign before reclassifying this case.');
        $classification=(string)($input['classification']??'untriaged');$status=(string)($input['status']??'investigating');if(!isset(data_model_improvement_classifications()[$classification]))throw new InvalidArgumentException('Invalid improvement classification.');if(!isset(data_model_improvement_statuses()[$status]))throw new InvalidArgumentException('Invalid improvement status.');
        if($classification==='no_action')$status='no_action';$note=mb_substr(trim((string)($input['triage_note']??'')),0,5000)?:null;$owner=(int)($input['owner_user_id']??0)?:null;$resolved=in_array($status,['resolved','no_action'],true)?gmdate('Y-m-d H:i:s'):null;
        $pdo->beginTransaction();try{
            $pdo->prepare('UPDATE data_model_improvement_cases SET classification=?,status=?,triage_note=?,owner_user_id=?,triaged_by_user_id=?,triaged_at=NOW(),resolved_at=?,updated_at=NOW() WHERE id=?')->execute([$classification,$status,$note,$owner,$viewer['id'],$resolved,$case['id']]);
            data_model_improvement_event($pdo,(int)$case['id'],(int)$viewer['id'],'human_triage',['classification'=>$classification,'status'=>$status,'note'=>$note,'owner_user_id'=>$owner]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return data_model_improvement_case_get($pdo,$publicId)??[];
    });
}
function data_model_improvement_proposal_material(array $p): array {
    return ['case_id'=>(int)$p['case_id'],'proposal_type'=>$p['proposal_type'],'title'=>$p['title'],'sanitized_input'=>$p['sanitized_input'],'sanitized_expected_output'=>$p['sanitized_expected_output'],'sanitized_context'=>(string)($p['sanitized_context']??''),'purpose_justification'=>(string)($p['purpose_justification']??''),'redaction_attested'=>(int)$p['redaction_attested'],'rights_attested'=>(int)$p['rights_attested']];
}
function data_model_improvement_proposal_hash(array $p): string {return data_attribution_hash(data_model_improvement_proposal_material($p));}
function data_model_improvement_proposal_get(PDO $pdo,string $publicId): ?array {
    $q=$pdo->prepare('SELECT p.*,c.public_id case_public_id,c.route_key,c.model_version_id,c.title case_title,mv.version_label model_version_label FROM data_model_improvement_proposals p JOIN data_model_improvement_cases c ON c.id=p.case_id LEFT JOIN data_model_versions mv ON mv.id=c.model_version_id WHERE p.public_id=? LIMIT 1');$q->execute([$publicId]);return $q->fetch()?:null;
}
function data_model_improvement_proposals(PDO $pdo,int $caseId): array {
    $q=$pdo->prepare('SELECT p.*,u.display_name created_by_name,a.display_name approved_by_name FROM data_model_improvement_proposals p JOIN users u ON u.id=p.created_by_user_id LEFT JOIN users a ON a.id=p.approved_by_user_id WHERE p.case_id=? ORDER BY p.id DESC');$q->execute([$caseId]);return $q->fetchAll();
}
function data_model_improvement_proposal_create(PDO $pdo,array $viewer,string $casePublicId,array $input): array {
    data_model_improvement_require_admin($viewer);
    return data_model_improvement_case_locked($pdo,$casePublicId,function(array $case) use($pdo,$viewer,$input){
        if(!data_model_improvement_case_actionable($case))throw new RuntimeException('Human triage must identify an actionable defect before creating reusable improvement evidence.');
        $type=(string)($input['proposal_type']??'');if(!in_array($type,['evaluation_case','training_example'],true))throw new InvalidArgumentException('Proposal type must be evaluation_case or training_example.');
        $title=mb_substr(trim((string)($input['title']??'')),0,255);$sanitizedInput=mb_substr(trim((string)($input['sanitized_input']??'')),0,20000);$expected=mb_substr(trim((string)($input['sanitized_expected_output']??'')),0,20000);$context=mb_substr(trim((string)($input['sanitized_context']??'')),0,10000)?:null;$why=mb_substr(trim((string)($input['purpose_justification']??'')),0,3000)?:null;
        if($title===''||$sanitizedInput===''||$expected==='')throw new InvalidArgumentException('Title, sanitized input, and sanitized expected behavior are required.');
        $p=['case_id'=>(int)$case['id'],'proposal_type'=>$type,'title'=>$title,'sanitized_input'=>$sanitizedInput,'sanitized_expected_output'=>$expected,'sanitized_context'=>$context,'purpose_justification'=>$why,'redaction_attested'=>isset($input['redaction_attested'])?1:0,'rights_attested'=>isset($input['rights_attested'])?1:0];$hash=data_model_improvement_proposal_hash($p);$public=ulid_like();
        $pdo->beginTransaction();try{
            $pdo->prepare("INSERT INTO data_model_improvement_proposals(public_id,case_id,proposal_type,status,title,sanitized_input,sanitized_expected_output,sanitized_context,purpose_justification,redaction_attested,rights_attested,content_hash,created_by_user_id) VALUES(?,? ,?,'draft',?,?,?,?,?,?,?,?,?)")
              ->execute([$public,$case['id'],$type,$title,$sanitizedInput,$expected,$context,$why,$p['redaction_attested'],$p['rights_attested'],$hash,$viewer['id']]);
            data_model_improvement_event($pdo,(int)$case['id'],(int)$viewer['id'],'proposal_created',['proposal_public_id'=>$public,'proposal_type'=>$type,'content_hash'=>$hash]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return data_model_improvement_proposal_get($pdo,$public)??[];
    });
}
function data_model_improvement_proposal_update(PDO $pdo,array $viewer,string $publicId,array $input): array {
    data_model_improvement_require_admin($viewer);
    return data_model_improvement_proposal_locked($pdo,$publicId,function(array $p) use($pdo,$viewer,$publicId,$input){
        if($p['status']!=='draft')throw new RuntimeException('Only draft improvement proposals can be edited.');
        $m=['case_id'=>(int)$p['case_id'],'proposal_type'=>$p['proposal_type'],'title'=>mb_substr(trim((string)($input['title']??$p['title'])),0,255),'sanitized_input'=>mb_substr(trim((string)($input['sanitized_input']??$p['sanitized_input'])),0,20000),'sanitized_expected_output'=>mb_substr(trim((string)($input['sanitized_expected_output']??$p['sanitized_expected_output'])),0,20000),'sanitized_context'=>mb_substr(trim((string)($input['sanitized_context']??$p['sanitized_context']??'')),0,10000)?:null,'purpose_justification'=>mb_substr(trim((string)($input['purpose_justification']??$p['purpose_justification']??'')),0,3000)?:null,'redaction_attested'=>isset($input['redaction_attested'])?1:0,'rights_attested'=>isset($input['rights_attested'])?1:0];
        if($m['title']===''||$m['sanitized_input']===''||$m['sanitized_expected_output']==='')throw new InvalidArgumentException('Proposal content cannot be empty.');$hash=data_model_improvement_proposal_hash($m);
        $pdo->beginTransaction();try{
            $q=$pdo->prepare("UPDATE data_model_improvement_proposals SET title=?,sanitized_input=?,sanitized_expected_output=?,sanitized_context=?,purpose_justification=?,redaction_attested=?,rights_attested=?,content_hash=?,updated_at=NOW() WHERE id=? AND status='draft'");$q->execute([$m['title'],$m['sanitized_input'],$m['sanitized_expected_output'],$m['sanitized_context'],$m['purpose_justification'],$m['redaction_attested'],$m['rights_attested'],$hash,$p['id']]);
            $fresh=data_model_improvement_proposal_get($pdo,$publicId);if(!$fresh||$fresh['status']!=='draft'||!hash_equals($hash,(string)$fresh['content_hash']))throw new RuntimeException('Proposal changed while being edited; reload and retry.');
            data_model_improvement_event($pdo,(int)$p['case_id'],(int)$viewer['id'],'proposal_updated',['proposal_public_id'=>$publicId,'content_hash'=>$hash]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return data_model_improvement_proposal_get($pdo,$publicId)??[];
    });
}
function data_model_improvement_proposal_approve(PDO $pdo,array $viewer,string $publicId): array {
    data_model_improvement_require_admin($viewer);
    return data_model_improvement_actionable_proposal_locked($pdo,$publicId,function(array $p,array $case) use($pdo,$viewer,$publicId){
        if($p['status']!=='draft')throw new RuntimeException('Only draft improvement proposals can be approved.');
        if(!(int)$p['redaction_attested']||!(int)$p['rights_attested'])throw new RuntimeException('Redaction and rights attestations are required before approval.');if(!hash_equals((string)$p['content_hash'],data_model_improvement_proposal_hash($p)))throw new RuntimeException('Proposal content integrity failed.');
        $approvedAt=gmdate('Y-m-d H:i:s');$approvalHash=data_attribution_hash(['proposal_public_id'=>$p['public_id'],'content_hash'=>$p['content_hash'],'approved_by_user_id'=>(int)$viewer['id'],'approved_at'=>$approvedAt,'boundary'=>'sanitized_human_approved_reuse']);
        $pdo->beginTransaction();try{
            $q=$pdo->prepare("UPDATE data_model_improvement_proposals SET status='approved',approval_hash=?,approved_by_user_id=?,approved_at=?,updated_at=NOW() WHERE id=? AND status='draft'");$q->execute([$approvalHash,$viewer['id'],$approvedAt,$p['id']]);if($q->rowCount()!==1)throw new RuntimeException('Proposal changed during approval; reload and retry.');
            data_model_improvement_event($pdo,(int)$p['case_id'],(int)$viewer['id'],'proposal_approved',['proposal_public_id'=>$publicId,'approval_hash'=>$approvalHash]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return data_model_improvement_proposal_get($pdo,$publicId)??[];
    });
}
function data_model_improvement_proposal_publish(PDO $pdo,array $viewer,string $publicId): array {
    data_model_improvement_require_admin($viewer);
    return data_model_improvement_actionable_proposal_locked($pdo,$publicId,function(array $p,array $case) use($pdo,$viewer,$publicId){
        if($p['status']!=='approved')throw new RuntimeException('Only an approved sanitized proposal can be published to the governed corpus.');
        if(!(int)$p['redaction_attested']||!(int)$p['rights_attested']||empty($p['approval_hash']))throw new RuntimeException('Proposal reuse approval is incomplete.');if(!hash_equals((string)$p['content_hash'],data_model_improvement_proposal_hash($p)))throw new RuntimeException('Proposal content changed after approval.');
        $pdo->beginTransaction();try{
            $q=$pdo->prepare("UPDATE data_model_improvement_proposals SET status='published',published_at=NOW(),updated_at=NOW() WHERE id=? AND status='approved'");$q->execute([$p['id']]);if($q->rowCount()!==1)throw new RuntimeException('Proposal changed during publication; reload and retry.');
            if($p['proposal_type']==='evaluation_case'){
                $caseHash=data_attribution_hash(['proposal_public_id'=>$p['public_id'],'content_hash'=>$p['content_hash'],'route_key'=>$p['route_key'],'model_version_id'=>$p['model_version_id']!==null?(int)$p['model_version_id']:null]);
                $pdo->prepare('INSERT IGNORE INTO data_model_regression_cases(public_id,proposal_id,case_id,model_version_id,route_key,label,query_text,expected_behavior,case_hash) VALUES(?,?,?,?,?,?,?,?,?)')->execute([ulid_like(),$p['id'],$p['case_id'],$p['model_version_id'],$p['route_key'],$p['title'],$p['sanitized_input'],$p['sanitized_expected_output'],$caseHash]);
            }
            $capture=data_attribution_capture_object($pdo,(int)$viewer['id'],'model_improvement_example',$publicId);if(!$capture)throw new RuntimeException('Approved improvement example could not be captured by corpus governance.');
            $q=$pdo->prepare("SELECT public_id FROM data_corpus_items WHERE source_object_type='model_improvement_example' AND source_object_public_id=? AND invalidated_at IS NULL ORDER BY id DESC LIMIT 1");$q->execute([$publicId]);$corpus=(string)($q->fetchColumn()?:'');if($corpus==='')throw new RuntimeException('Approved improvement example was not admitted to the governed corpus.');
            $pdo->prepare('UPDATE data_model_improvement_proposals SET corpus_item_public_id=? WHERE id=?')->execute([$corpus,$p['id']]);
            data_model_improvement_event($pdo,(int)$p['case_id'],(int)$viewer['id'],'proposal_published_to_corpus',['proposal_public_id'=>$publicId,'corpus_item_public_id'=>$corpus,'proposal_type'=>$p['proposal_type'],'approval_hash'=>$p['approval_hash']]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return data_model_improvement_proposal_get($pdo,$publicId)??[];
    });
}
function data_model_improvement_create_dataset_draft(PDO $pdo,array $viewer,string $purpose): array {
    data_model_improvement_require_admin($viewer);if(!in_array($purpose,['evaluation','training'],true))throw new InvalidArgumentException('Improvement dataset purpose must be evaluation or training.');
    $corpusType=$purpose==='evaluation'?'model_regression_case':'model_training_example';$label=$purpose==='evaluation'?'Production Regression Library':'Approved Production Training Examples';
    $d=data_dataset_create($pdo,$viewer,['name'=>$label,'description'=>'Human-sanitized Phase 46 production feedback examples. Freezing remains a separate explicit Dataset Registry action.','purpose'=>$purpose,'corpus_types'=>[$corpusType],'max_items'=>10000]);
    return $d;
}
function data_model_improvement_regression_cases(PDO $pdo,?int $modelVersionId=null,int $limit=100): array {
    $limit=max(1,min(500,$limit));$where=$modelVersionId?' WHERE r.model_version_id=?':'';$q=$pdo->prepare("SELECT r.*,p.public_id proposal_public_id,c.public_id case_public_id,mv.version_label model_version_label FROM data_model_regression_cases r JOIN data_model_improvement_proposals p ON p.id=r.proposal_id JOIN data_model_improvement_cases c ON c.id=r.case_id LEFT JOIN data_model_versions mv ON mv.id=r.model_version_id$where ORDER BY r.id DESC LIMIT $limit");$q->execute($modelVersionId?[$modelVersionId]:[]);return $q->fetchAll();
}
function data_model_improvement_backfill(PDO $pdo,array $viewer,int $limit=500): array {
    data_model_improvement_require_admin($viewer);$limit=max(1,min(5000,$limit));$cases=[];
    $q=$pdo->query("SELECT * FROM data_model_incidents ORDER BY id DESC LIMIT $limit");foreach($q->fetchAll() as $incident){$case=data_model_improvement_ingest_incident($pdo,$incident);if($case)$cases[$case['public_id']]=1;}
    $q=$pdo->query("SELECT * FROM data_model_outcome_signals WHERE signal_value<0 ORDER BY id DESC LIMIT $limit");foreach($q->fetchAll() as $signal){$case=data_model_improvement_ingest_negative_signal($pdo,$signal);if($case)$cases[$case['public_id']]=1;}
    return ['cases_touched'=>count($cases),'evidence_scanned'=>(int)$limit];
}
function data_model_improvement_regression_gate(PDO $pdo,array $version): array {
    if(!data_model_improvement_ready($pdo))return ['pass'=>true,'required'=>0,'covered'=>0,'missing'=>[],'reason'=>'phase46_unavailable'];
    if(in_array((string)($version['status']??''),['active','deprecated','retired'],true))return ['pass'=>true,'required'=>0,'covered'=>0,'missing'=>[],'reason'=>'prior_lifecycle_state_exempt'];
    $q=$pdo->prepare("SELECT r.public_id,r.label,p.corpus_item_public_id FROM data_model_regression_cases r JOIN data_model_improvement_proposals p ON p.id=r.proposal_id JOIN data_model_versions src ON src.id=r.model_version_id WHERE src.registry_id=? AND r.active=1 AND p.status='published' ORDER BY r.id");
    $q->execute([$version['registry_id']]);$required=$q->fetchAll();if(!$required)return ['pass'=>true,'required'=>0,'covered'=>0,'missing'=>[],'reason'=>'no_active_production_regressions'];
    $links=data_model_evaluation_links($pdo,(int)$version['id']);$covered=[];$missing=[];
    foreach($required as $reg){
        $ok=false;foreach($links as $link){
            if($link['run_status']!=='completed'||$link['benchmark_type']!=='model'||(int)$link['model_id']!==(int)$version['ai_model_id'])continue;
            $run=data_evaluation_run_get($pdo,(string)$link['run_public_id']);if(!$run||!data_evaluation_run_integrity($pdo,$run)['ok'])continue;
            $cq=$pdo->prepare('SELECT COUNT(*) FROM data_evaluation_cases ec JOIN data_dataset_items di ON di.id=ec.expected_dataset_item_id WHERE ec.suite_id=? AND di.corpus_public_id=?');$cq->execute([$run['suite_id'],$reg['corpus_item_public_id']]);if((int)$cq->fetchColumn()>0){$ok=true;break;}
        }
        if($ok)$covered[]=(string)$reg['public_id'];else$missing[]=['public_id'=>$reg['public_id'],'label'=>$reg['label']];
    }
    return ['pass'=>count($missing)===0,'required'=>count($required),'covered'=>count($covered),'covered_public_ids'=>$covered,'missing'=>$missing,'reason'=>$missing?'production_regression_coverage_missing':'all_active_production_regressions_covered'];
}
function data_model_improvement_summary(PDO $pdo): array {
    if(!data_model_improvement_ready($pdo))return ['ready'=>false];$scalar=fn(string $sql)=>(int)$pdo->query($sql)->fetchColumn();
    return ['ready'=>true,'cases'=>$scalar('SELECT COUNT(*) FROM data_model_improvement_cases'),'needs_triage'=>$scalar("SELECT COUNT(*) FROM data_model_improvement_cases WHERE status IN ('new','investigating')"),'eval_ready'=>$scalar("SELECT COUNT(*) FROM data_model_improvement_cases WHERE status='ready_for_evaluation'"),'training_ready'=>$scalar("SELECT COUNT(*) FROM data_model_improvement_cases WHERE status='ready_for_training'"),'published_eval'=>$scalar("SELECT COUNT(*) FROM data_model_improvement_proposals WHERE proposal_type='evaluation_case' AND status='published'"),'published_training'=>$scalar("SELECT COUNT(*) FROM data_model_improvement_proposals WHERE proposal_type='training_example' AND status='published'"),'regression_cases'=>$scalar('SELECT COUNT(*) FROM data_model_regression_cases WHERE active=1')];
}
function data_model_improvement_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=12): void {
    if(($viewer['role']??'')!=='admin'||!data_model_improvement_ready($pdo)||!function_exists('cognitive_feed_add'))return;$limit=max(1,min(30,$limit));
    foreach(data_model_improvement_cases($pdo,$limit) as $c){if(!in_array($c['status'],['new','investigating','ready_for_evaluation','ready_for_training'],true))continue;$section=in_array($c['status'],['new','investigating'],true)?'needs_attention':'next_up';$priority=$c['severity']==='critical'?'high':'medium';cognitive_feed_add($items,[
      'key'=>cognitive_feed_key('model_improvement_case','model_improvement_case',(string)$c['public_id'],(string)$c['evidence_hash']),
      'type'=>'model_improvement_case','section'=>$section,'priority'=>$priority,'created_at'=>$c['updated_at'],
      'title'=>(string)$c['title'],'body'=>'Production evidence has been clustered into a governed model-improvement case.',
      'meta'=>['route'=>$c['route_key'],'classification'=>$c['classification'],'recurrences'=>(int)$c['recurrence_count'],'evidence'=>(int)$c['evidence_count']],
      'actions'=>[cognitive_feed_action_link('Review improvement case','/admin/model-improvements.php?case='.rawurlencode((string)$c['public_id']))],
    ]);}
}
