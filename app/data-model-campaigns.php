<?php
declare(strict_types=1);

/**
 * Phase 47 — Model Improvement Campaigns & Controlled Retraining Handoff.
 *
 * Orchestration only. This module may create governed drafts in existing registries
 * but never freezes datasets, queues evaluation runs, queues/submits training,
 * promotes models, deploys/rolls back models, or rewrites AI routing.
 */
function data_model_campaign_ready(PDO $pdo): bool {
    static $ready=null;if($ready!==null)return $ready;
    try{$ready=data_model_improvement_ready($pdo)
        &&installer_table_exists($pdo,'data_model_improvement_campaigns')
        &&installer_table_exists($pdo,'data_model_improvement_campaign_cases')
        &&installer_table_exists($pdo,'data_model_improvement_campaign_proposals')
        &&installer_table_exists($pdo,'data_model_improvement_campaign_events');}
    catch(Throwable $e){$ready=false;}return $ready;
}
function data_model_campaign_require_admin(array $viewer): void {
    if(($viewer['role']??'')!=='admin')throw new RuntimeException('Administrator access is required for Model Improvement Campaign operations.');
}
function data_model_campaign_strategies(): array {
    return ['evaluation_only'=>'Evaluation only','prompt_tool_data'=>'Prompt / tool / data remediation','model_training'=>'Controlled model training'];
}
function data_model_campaign_statuses(): array {
    return ['planned'=>'Planned','dataset_preparation'=>'Dataset preparation','ready_for_training'=>'Ready for training','training'=>'Training','evaluation'=>'Evaluation','release_review'=>'Release review','completed'=>'Completed','abandoned'=>'Abandoned'];
}
function data_model_campaign_event(PDO $pdo,int $campaignId,?int $actorId,string $type,array $details=[]): void {
    $json=$details?data_attribution_encode($details):null;$hash=data_attribution_hash(['campaign_id'=>$campaignId,'event_type'=>$type,'details'=>$details]);
    $pdo->prepare('INSERT INTO data_model_improvement_campaign_events(campaign_id,actor_user_id,event_type,details_json,event_hash) VALUES(?,?,?,?,?)')->execute([$campaignId,$actorId,$type,$json,$hash]);
}
function data_model_campaign_model_version_by_id(PDO $pdo,int $id): ?array {
    $q=$pdo->prepare('SELECT v.*,r.public_id registry_public_id,r.name registry_name,r.active_version_id,m.public_id ai_model_public_id,m.display_name ai_model_name,m.model_name,p.label provider_label,p.provider_type FROM data_model_versions v JOIN data_model_registry r ON r.id=v.registry_id LEFT JOIN ai_models m ON m.id=v.ai_model_id LEFT JOIN ai_providers p ON p.id=m.provider_id WHERE v.id=? LIMIT 1');$q->execute([$id]);$v=$q->fetch();if(!$v)return null;$v['gate_policy']=json_decode((string)$v['gate_policy_json'],true)?:[];$v['metadata']=json_decode((string)($v['metadata_json']??''),true)?:[];return $v;
}
function data_model_campaign_get(PDO $pdo,string $publicId,bool $sync=false): ?array {
    if(!data_model_campaign_ready($pdo))return null;
    $q=$pdo->prepare('SELECT c.*,r.public_id registry_public_id,r.name registry_name,b.public_id base_version_public_id,b.version_label base_version_label,b.status base_version_status,b.version_hash base_version_hash,b.ai_model_id base_ai_model_id,ed.public_id evaluation_dataset_public_id,ed.status evaluation_dataset_status,ed.selection_policy_hash evaluation_dataset_policy_hash,ed.manifest_hash evaluation_dataset_manifest_hash,td.public_id training_dataset_public_id,td.status training_dataset_status,td.selection_policy_hash training_dataset_policy_hash,td.manifest_hash training_dataset_manifest_hash,es.public_id baseline_suite_public_id,es.status baseline_suite_status,tj.public_id training_job_public_id,tj.status training_job_status,tj.output_model_version_id,pt.public_id post_training_plan_public_id,pt.status post_training_plan_status,u.display_name created_by_name,l.display_name locked_by_name,x.display_name closed_by_name FROM data_model_improvement_campaigns c JOIN data_model_registry r ON r.id=c.registry_id JOIN data_model_versions b ON b.id=c.base_model_version_id LEFT JOIN data_datasets ed ON ed.id=c.evaluation_dataset_id LEFT JOIN data_datasets td ON td.id=c.training_dataset_id LEFT JOIN data_evaluation_suites es ON es.id=c.baseline_evaluation_suite_id LEFT JOIN data_training_jobs tj ON tj.id=c.training_job_id LEFT JOIN data_post_training_plans pt ON pt.id=c.post_training_plan_id JOIN users u ON u.id=c.created_by_user_id LEFT JOIN users l ON l.id=c.locked_by_user_id LEFT JOIN users x ON x.id=c.closed_by_user_id WHERE c.public_id=? LIMIT 1');
    $q->execute([trim($publicId)]);$c=$q->fetch();if(!$c)return null;$c['plan']=$c['plan_json']?json_decode((string)$c['plan_json'],true):null;
    if($sync&&!in_array($c['status'],['completed','abandoned'],true)){data_model_campaign_sync_status($pdo,$c);$q->execute([trim($publicId)]);$c=$q->fetch();if(!$c)return null;$c['plan']=$c['plan_json']?json_decode((string)$c['plan_json'],true):null;}
    return $c;
}
function data_model_campaign_locked(PDO $pdo,string $publicId,callable $callback): mixed {
    $seed=data_model_campaign_get($pdo,$publicId,false);if(!$seed)throw new RuntimeException('Improvement campaign not found.');
    return app_with_advisory_lock($pdo,'model-campaign',(int)$seed['id'],function() use($pdo,$publicId,$callback){
        $fresh=data_model_campaign_get($pdo,$publicId,false);if(!$fresh)throw new RuntimeException('Improvement campaign not found.');
        return $callback($fresh);
    },5);
}

function data_model_campaign_lock(PDO $pdo,array $viewer,string $campaignPublicId): array {
    data_model_campaign_require_admin($viewer);
    $locked=data_model_campaign_locked($pdo,$campaignPublicId,function(array $campaign) use($pdo,$viewer,$campaignPublicId){
        if($campaign['plan_hash'])throw new RuntimeException('Campaign plan is already locked.');
        $cases=data_model_campaign_cases($pdo,(int)$campaign['id']);$props=data_model_campaign_proposals($pdo,(int)$campaign['id']);if(!$cases)throw new RuntimeException('Campaign requires at least one human-triaged Phase 46 improvement case.');$eval=array_values(array_filter($props,fn($p)=>$p['proposal_type']==='evaluation_case'));$train=array_values(array_filter($props,fn($p)=>$p['proposal_type']==='training_example'));if(!$eval)throw new RuntimeException('Campaign requires at least one published production regression proposal.');if($campaign['strategy']==='model_training'&&!$train)throw new RuntimeException('Controlled model-training campaign requires at least one approved training example.');
        if(!$campaign['evaluation_dataset_id'])throw new RuntimeException('Create the campaign evaluation dataset draft before locking the plan.');if($campaign['strategy']==='model_training'&&!$campaign['training_dataset_id'])throw new RuntimeException('Create the campaign training dataset draft before locking the plan.');
        foreach($props as $p)if($p['proposal_status']!=='published'||!hash_equals((string)$p['content_hash'],(string)$p['current_content_hash'])||!hash_equals((string)$p['approval_hash'],(string)$p['current_approval_hash'])||(string)$p['corpus_item_public_id']!==(string)$p['current_corpus_item_public_id'])throw new RuntimeException('A linked improvement proposal is no longer the exact approved published evidence selected for this campaign.');
        $base=data_training_base_assert($pdo,(int)$campaign['base_model_version_id'],'manual');if($campaign['strategy']==='model_training')data_training_output_label_assert($pdo,(int)$campaign['registry_id'],(string)$campaign['target_version_label']);
        $pdo->beginTransaction();try{
            foreach($cases as $case){$metric=$case['incident_metric_name']??null;$value=$case['incident_current_value']??null;$threshold=$case['incident_threshold_value']??null;$pdo->prepare('UPDATE data_model_improvement_campaign_cases SET baseline_recurrence_count=?,baseline_evidence_count=?,baseline_evidence_hash=?,baseline_incident_metric=?,baseline_incident_value=?,baseline_incident_threshold=?,baseline_captured_at=NOW() WHERE id=?')->execute([$case['recurrence_count'],$case['evidence_count'],$case['evidence_hash'],$metric,$value,$threshold,$case['id']]);}
            $campaign=data_model_campaign_get($pdo,$campaignPublicId,false);$material=data_model_campaign_plan_material($pdo,$campaign);$json=data_attribution_encode($material);$hash=hash('sha256',$json);
            $q=$pdo->prepare("UPDATE data_model_improvement_campaigns SET plan_json=?,plan_hash=?,locked_by_user_id=?,locked_at=NOW(),status='dataset_preparation',updated_at=NOW() WHERE id=? AND plan_hash IS NULL");$q->execute([$json,$hash,$viewer['id'],$campaign['id']]);if($q->rowCount()!==1)throw new RuntimeException('Campaign changed while locking; reload and retry.');
            data_model_campaign_event($pdo,(int)$campaign['id'],(int)$viewer['id'],'campaign_plan_locked',['plan_hash'=>$hash,'base_model_version_hash'=>$base['version_hash'],'case_count'=>count($cases),'proposal_count'=>count($props)]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return data_model_campaign_get($pdo,$campaignPublicId,false)??[];
    });
    if($locked)data_model_campaign_notify($pdo,$locked,'plan_locked');return $locked;
}
function data_model_campaign_list(PDO $pdo,int $limit=100): array {
    if(!data_model_campaign_ready($pdo))return [];$limit=max(1,min(500,$limit));
    $q=$pdo->query("SELECT c.*,r.name registry_name,b.version_label base_version_label,tj.status training_job_status,pt.status post_training_plan_status FROM data_model_improvement_campaigns c JOIN data_model_registry r ON r.id=c.registry_id JOIN data_model_versions b ON b.id=c.base_model_version_id LEFT JOIN data_training_jobs tj ON tj.id=c.training_job_id LEFT JOIN data_post_training_plans pt ON pt.id=c.post_training_plan_id ORDER BY FIELD(c.status,'release_review','ready_for_training','dataset_preparation','planned','training','evaluation','completed','abandoned'),c.updated_at DESC LIMIT $limit");
    return $q->fetchAll();
}
function data_model_campaign_create(PDO $pdo,array $viewer,array $input): array {
    data_model_campaign_require_admin($viewer);if(!data_model_campaign_ready($pdo))throw new RuntimeException('Model Improvement Campaigns requires the Phase 47 database upgrade.');
    $strategy=(string)($input['strategy']??'model_training');if(!isset(data_model_campaign_strategies()[$strategy]))throw new InvalidArgumentException('Invalid campaign strategy.');
    $baseId=(int)($input['base_model_version_id']??0);if(!$baseId)throw new InvalidArgumentException('Select a governed base model version.');$base=data_training_base_assert($pdo,$baseId,'manual');
    $title=mb_substr(trim((string)($input['title']??'')),0,255);$objective=mb_substr(trim((string)($input['objective']??'')),0,5000);$success=mb_substr(trim((string)($input['success_criteria']??'')),0,5000);$target=mb_substr(trim((string)($input['target_version_label']??'')),0,120)?:null;
    if($title===''||$objective===''||$success==='')throw new InvalidArgumentException('Campaign title, objective, and success criteria are required.');if($strategy==='model_training'&&$target===null)throw new InvalidArgumentException('Controlled model-training campaigns require a target model version label.');
    if($target!==null){$q=$pdo->prepare('SELECT COUNT(*) FROM data_model_versions WHERE registry_id=? AND version_label=?');$q->execute([$base['registry_id'],$target]);if((int)$q->fetchColumn()>0)throw new RuntimeException('The target model version label already exists in this Model Registry.');}
    $public=ulid_like();$pdo->prepare("INSERT INTO data_model_improvement_campaigns(public_id,registry_id,base_model_version_id,strategy,status,title,objective,success_criteria,target_version_label,created_by_user_id) VALUES(?,?,?,?,'planned',?,?,?,?,?)")->execute([$public,$base['registry_id'],$baseId,$strategy,$title,$objective,$success,$target,$viewer['id']]);$id=(int)$pdo->lastInsertId();
    data_model_campaign_event($pdo,$id,(int)$viewer['id'],'campaign_created',['strategy'=>$strategy,'base_model_version_public_id'=>$base['public_id'],'target_version_label'=>$target]);$created=data_model_campaign_get($pdo,$public)??[];if($created)data_model_campaign_notify($pdo,$created,'created');return $created;
}
function data_model_campaign_cases(PDO $pdo,int $campaignId): array {
    $q=$pdo->prepare('SELECT l.*,c.public_id case_public_id,c.title case_title,c.classification,c.status case_status,c.severity,c.route_key,c.recurrence_count,c.evidence_count,c.evidence_hash,c.model_version_id,mv.version_label model_version_label,i.public_id incident_public_id,i.metric_name incident_metric_name,i.current_value incident_current_value,i.threshold_value incident_threshold_value FROM data_model_improvement_campaign_cases l JOIN data_model_improvement_cases c ON c.id=l.improvement_case_id LEFT JOIN data_model_versions mv ON mv.id=c.model_version_id LEFT JOIN data_model_incidents i ON i.id=c.primary_incident_id WHERE l.campaign_id=? ORDER BY FIELD(c.severity,\'critical\',\'warning\'),c.updated_at DESC,c.id');$q->execute([$campaignId]);return $q->fetchAll();
}
function data_model_campaign_proposals(PDO $pdo,int $campaignId): array {
    $q=$pdo->prepare('SELECT l.*,p.public_id proposal_public_id,p.status proposal_status,p.title proposal_title,p.proposal_type current_proposal_type,p.content_hash current_content_hash,p.approval_hash current_approval_hash,p.corpus_item_public_id current_corpus_item_public_id,c.public_id case_public_id,c.title case_title FROM data_model_improvement_campaign_proposals l JOIN data_model_improvement_proposals p ON p.id=l.proposal_id JOIN data_model_improvement_cases c ON c.id=p.case_id WHERE l.campaign_id=? ORDER BY p.proposal_type,p.id');$q->execute([$campaignId]);return $q->fetchAll();
}
function data_model_campaign_add_case(PDO $pdo,array $viewer,string $campaignPublicId,string $casePublicId): array {
    data_model_campaign_require_admin($viewer);
    return data_model_campaign_locked($pdo,$campaignPublicId,function(array $campaign) use($pdo,$viewer,$campaignPublicId,$casePublicId){
        if($campaign['plan_hash'])throw new RuntimeException('Locked campaign scope is immutable.');
        $case=data_model_improvement_case_get($pdo,$casePublicId);if(!$case)throw new RuntimeException('Improvement case not found.');if(in_array($case['classification'],['untriaged','expected_behavior','no_action'],true))throw new RuntimeException('Campaigns require an actionable human-triaged Phase 46 case.');
        if($case['model_version_id']!==null){$v=data_model_campaign_model_version_by_id($pdo,(int)$case['model_version_id']);if($v&&(int)$v['registry_id']!==(int)$campaign['registry_id'])throw new RuntimeException('Improvement case belongs to a different logical Model Registry.');}
        $metric=null;$value=null;$threshold=null;if($case['primary_incident_id']){$q=$pdo->prepare('SELECT metric_name,current_value,threshold_value FROM data_model_incidents WHERE id=?');$q->execute([$case['primary_incident_id']]);if($i=$q->fetch()){$metric=$i['metric_name'];$value=$i['current_value'];$threshold=$i['threshold_value'];}}
        $pdo->beginTransaction();try{
            $pdo->prepare('INSERT IGNORE INTO data_model_improvement_campaign_cases(campaign_id,improvement_case_id,baseline_recurrence_count,baseline_evidence_count,baseline_evidence_hash,baseline_incident_metric,baseline_incident_value,baseline_incident_threshold) VALUES(?,?,?,?,?,?,?,?)')->execute([$campaign['id'],$case['id'],$case['recurrence_count'],$case['evidence_count'],$case['evidence_hash'],$metric,$value,$threshold]);
            data_model_campaign_event($pdo,(int)$campaign['id'],(int)$viewer['id'],'case_linked',['case_public_id'=>$case['public_id'],'evidence_hash'=>$case['evidence_hash']]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return data_model_campaign_get($pdo,$campaignPublicId,false)??[];
    });
}
function data_model_campaign_remove_case(PDO $pdo,array $viewer,string $campaignPublicId,string $casePublicId): array {
    data_model_campaign_require_admin($viewer);
    return data_model_campaign_locked($pdo,$campaignPublicId,function(array $campaign) use($pdo,$viewer,$campaignPublicId,$casePublicId){
        if($campaign['plan_hash'])throw new RuntimeException('Locked campaign scope is immutable.');
        $q=$pdo->prepare('SELECT id FROM data_model_improvement_cases WHERE public_id=?');$q->execute([$casePublicId]);$id=(int)($q->fetchColumn()?:0);
        $pdo->beginTransaction();try{
            if($id){$pdo->prepare('DELETE FROM data_model_improvement_campaign_proposals WHERE campaign_id=? AND proposal_id IN (SELECT id FROM data_model_improvement_proposals WHERE case_id=?)')->execute([$campaign['id'],$id]);$pdo->prepare('DELETE FROM data_model_improvement_campaign_cases WHERE campaign_id=? AND improvement_case_id=?')->execute([$campaign['id'],$id]);}
            data_model_campaign_event($pdo,(int)$campaign['id'],(int)$viewer['id'],'case_unlinked',['case_public_id'=>$casePublicId]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return data_model_campaign_get($pdo,$campaignPublicId,false)??[];
    });
}
function data_model_campaign_add_proposal(PDO $pdo,array $viewer,string $campaignPublicId,string $proposalPublicId): array {
    data_model_campaign_require_admin($viewer);
    return data_model_campaign_locked($pdo,$campaignPublicId,function(array $campaign) use($pdo,$viewer,$campaignPublicId,$proposalPublicId){
        if($campaign['plan_hash'])throw new RuntimeException('Locked campaign scope is immutable.');
        $p=data_model_improvement_proposal_get($pdo,$proposalPublicId);if(!$p)throw new RuntimeException('Improvement proposal not found.');if($p['status']!=='published'||empty($p['approval_hash'])||empty($p['corpus_item_public_id']))throw new RuntimeException('Campaigns may use only published, human-approved Phase 46 proposals.');
        $q=$pdo->prepare('SELECT COUNT(*) FROM data_model_improvement_campaign_cases WHERE campaign_id=? AND improvement_case_id=?');$q->execute([$campaign['id'],$p['case_id']]);if(!(int)$q->fetchColumn())throw new RuntimeException('Link the proposal\'s improvement case to the campaign first.');
        if($p['proposal_type']==='training_example'&&$campaign['strategy']!=='model_training')throw new RuntimeException('Training examples may only be linked to a controlled model-training campaign.');
        $refresh=data_corpus_refresh_object($pdo,'model_improvement_example',$proposalPublicId);if(empty($refresh['active']))throw new RuntimeException('Selected improvement example is no longer eligible for governed reuse.');
        $q=$pdo->prepare("SELECT public_id,evaluation_eligible,training_eligible,commercial_training_eligible,shared_retrieval_eligible,attribution_required FROM data_corpus_items WHERE source_object_type='model_improvement_example' AND source_object_public_id=? AND invalidated_at IS NULL ORDER BY id DESC LIMIT 1");$q->execute([$proposalPublicId]);$corpus=$q->fetch();if(!$corpus)throw new RuntimeException('Selected improvement example is missing from the governed corpus.');
        if($p['proposal_type']==='evaluation_case'&&(int)$corpus['evaluation_eligible']!==1)throw new RuntimeException('Selected regression proposal is not currently evaluation eligible.');
        if($p['proposal_type']==='training_example'&&((int)$corpus['training_eligible']!==1||(int)$corpus['attribution_required']!==0))throw new RuntimeException('Selected training proposal is not currently compatible with Phase 41 internal training.');
        if((int)$corpus['commercial_training_eligible']!==0||(int)$corpus['shared_retrieval_eligible']!==0)throw new RuntimeException('Improvement examples must remain outside commercial training and shared retrieval.');
        if((string)$p['corpus_item_public_id']!==(string)$corpus['public_id']){$pdo->prepare('UPDATE data_model_improvement_proposals SET corpus_item_public_id=? WHERE id=?')->execute([$corpus['public_id'],$p['id']]);$p=data_model_improvement_proposal_get($pdo,$proposalPublicId)??$p;}
        $pdo->beginTransaction();try{
            $pdo->prepare('INSERT IGNORE INTO data_model_improvement_campaign_proposals(campaign_id,proposal_id,proposal_type,content_hash,approval_hash,corpus_item_public_id) VALUES(?,?,?,?,?,?)')->execute([$campaign['id'],$p['id'],$p['proposal_type'],$p['content_hash'],$p['approval_hash'],$p['corpus_item_public_id']]);
            data_model_campaign_event($pdo,(int)$campaign['id'],(int)$viewer['id'],'proposal_linked',['proposal_public_id'=>$p['public_id'],'proposal_type'=>$p['proposal_type'],'content_hash'=>$p['content_hash'],'approval_hash'=>$p['approval_hash']]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return data_model_campaign_get($pdo,$campaignPublicId,false)??[];
    });
}
function data_model_campaign_remove_proposal(PDO $pdo,array $viewer,string $campaignPublicId,string $proposalPublicId): array {
    data_model_campaign_require_admin($viewer);
    return data_model_campaign_locked($pdo,$campaignPublicId,function(array $campaign) use($pdo,$viewer,$campaignPublicId,$proposalPublicId){
        if($campaign['plan_hash'])throw new RuntimeException('Locked campaign scope is immutable.');
        $q=$pdo->prepare('SELECT id FROM data_model_improvement_proposals WHERE public_id=?');$q->execute([$proposalPublicId]);$id=(int)($q->fetchColumn()?:0);
        $pdo->beginTransaction();try{
            if($id)$pdo->prepare('DELETE FROM data_model_improvement_campaign_proposals WHERE campaign_id=? AND proposal_id=?')->execute([$campaign['id'],$id]);
            data_model_campaign_event($pdo,(int)$campaign['id'],(int)$viewer['id'],'proposal_unlinked',['proposal_public_id'=>$proposalPublicId]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return data_model_campaign_get($pdo,$campaignPublicId,false)??[];
    });
}
function data_model_campaign_dataset(PDO $pdo,?int $id): ?array {
    if(!$id)return null;$q=$pdo->prepare('SELECT * FROM data_datasets WHERE id=? LIMIT 1');$q->execute([$id]);$d=$q->fetch();if(!$d)return null;$d['selection_policy']=json_decode((string)$d['selection_policy_json'],true)?:[];return $d;
}
function data_model_campaign_create_dataset_drafts(PDO $pdo,array $viewer,string $campaignPublicId): array {
    data_model_campaign_require_admin($viewer);
    return data_model_campaign_locked($pdo,$campaignPublicId,function(array $campaign) use($pdo,$viewer,$campaignPublicId){
        if($campaign['plan_hash'])throw new RuntimeException('Locked campaign plan cannot create replacement dataset drafts.');
        $links=data_model_campaign_proposals($pdo,(int)$campaign['id']);$eval=[];$train=[];foreach($links as $p){if($p['proposal_status']!=='published'||!hash_equals((string)$p['content_hash'],(string)$p['current_content_hash'])||!hash_equals((string)$p['approval_hash'],(string)$p['current_approval_hash'])||(string)$p['corpus_item_public_id']!==(string)$p['current_corpus_item_public_id'])throw new RuntimeException('A linked Phase 46 proposal changed after campaign selection.');if($p['proposal_type']==='evaluation_case')$eval[]=(string)$p['proposal_public_id'];elseif($p['proposal_type']==='training_example')$train[]=(string)$p['proposal_public_id'];}
        if(!$eval)throw new RuntimeException('Campaign requires at least one published Phase 46 evaluation/regression proposal.');if($campaign['strategy']==='model_training'&&!$train)throw new RuntimeException('Controlled model-training campaign requires at least one published Phase 46 training example.');
        $pdo->beginTransaction();try{
            if(!$campaign['evaluation_dataset_id']){$d=data_dataset_create($pdo,$viewer,['name'=>'Campaign '.$campaign['title'].' · Regression','description'=>'Phase 47 campaign-specific regression dataset draft. Human freeze required.','purpose'=>'evaluation','corpus_types'=>['model_regression_case'],'source_object_public_ids'=>$eval,'max_items'=>count($eval)]);$pdo->prepare("UPDATE data_model_improvement_campaigns SET evaluation_dataset_id=?,status='dataset_preparation',updated_at=NOW() WHERE id=? AND evaluation_dataset_id IS NULL AND plan_hash IS NULL")->execute([$d['id'],$campaign['id']]);data_model_campaign_event($pdo,(int)$campaign['id'],(int)$viewer['id'],'evaluation_dataset_draft_created',['dataset_public_id'=>$d['public_id'],'selection_policy_hash'=>$d['selection_policy_hash'],'proposal_count'=>count($eval)]);}
            if($campaign['strategy']==='model_training'&&!$campaign['training_dataset_id']){$d=data_dataset_create($pdo,$viewer,['name'=>'Campaign '.$campaign['title'].' · Training','description'=>'Phase 47 campaign-specific approved training dataset draft. Human freeze required.','purpose'=>'training','corpus_types'=>['model_training_example'],'source_object_public_ids'=>$train,'max_items'=>count($train)]);$pdo->prepare("UPDATE data_model_improvement_campaigns SET training_dataset_id=?,status='dataset_preparation',updated_at=NOW() WHERE id=? AND training_dataset_id IS NULL AND plan_hash IS NULL")->execute([$d['id'],$campaign['id']]);data_model_campaign_event($pdo,(int)$campaign['id'],(int)$viewer['id'],'training_dataset_draft_created',['dataset_public_id'=>$d['public_id'],'selection_policy_hash'=>$d['selection_policy_hash'],'proposal_count'=>count($train)]);}
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return data_model_campaign_get($pdo,$campaignPublicId,false)??[];
    });
}
function data_model_campaign_plan_material(PDO $pdo,array $campaign): array {
    $base=data_model_campaign_model_version_by_id($pdo,(int)$campaign['base_model_version_id']);if(!$base)throw new RuntimeException('Campaign base model version not found.');$receipt=data_model_latest_approval_receipt($pdo,$base);if(!$receipt||!data_model_receipt_integrity($receipt,$base)['ok'])throw new RuntimeException('Campaign base model approval receipt is unavailable or invalid.');
    $cases=[];foreach(data_model_campaign_cases($pdo,(int)$campaign['id']) as $c)$cases[]=['case_public_id'=>$c['case_public_id'],'classification'=>$c['classification'],'route_key'=>$c['route_key'],'severity'=>$c['severity'],'baseline_recurrence_count'=>(int)$c['baseline_recurrence_count'],'baseline_evidence_count'=>(int)$c['baseline_evidence_count'],'baseline_evidence_hash'=>$c['baseline_evidence_hash'],'baseline_incident_metric'=>$c['baseline_incident_metric'],'baseline_incident_value'=>$c['baseline_incident_value']!==null?(float)$c['baseline_incident_value']:null,'baseline_incident_threshold'=>$c['baseline_incident_threshold']!==null?(float)$c['baseline_incident_threshold']:null];
    $proposals=[];foreach(data_model_campaign_proposals($pdo,(int)$campaign['id']) as $p)$proposals[]=['proposal_public_id'=>$p['proposal_public_id'],'proposal_type'=>$p['proposal_type'],'content_hash'=>$p['content_hash'],'approval_hash'=>$p['approval_hash'],'corpus_item_public_id'=>$p['corpus_item_public_id']];
    $ed=data_model_campaign_dataset($pdo,$campaign['evaluation_dataset_id']!==null?(int)$campaign['evaluation_dataset_id']:null);$td=data_model_campaign_dataset($pdo,$campaign['training_dataset_id']!==null?(int)$campaign['training_dataset_id']:null);
    return ['schema'=>'annotated.model-improvement-campaign-plan.v1','campaign_public_id'=>$campaign['public_id'],'strategy'=>$campaign['strategy'],'title'=>$campaign['title'],'objective'=>$campaign['objective'],'success_criteria'=>$campaign['success_criteria'],'registry_public_id'=>$campaign['registry_public_id'],'base_model_version_public_id'=>$base['public_id'],'base_model_version_hash'=>$base['version_hash'],'base_approval_receipt_hash'=>$receipt['receipt_hash'],'target_version_label'=>$campaign['target_version_label'],'cases'=>$cases,'proposals'=>$proposals,'evaluation_dataset'=>$ed?['public_id'=>$ed['public_id'],'purpose'=>$ed['purpose'],'selection_policy_hash'=>$ed['selection_policy_hash']]:null,'training_dataset'=>$td?['public_id'=>$td['public_id'],'purpose'=>$td['purpose'],'selection_policy_hash'=>$td['selection_policy_hash']]:null];
}
function data_model_campaign_plan_integrity(PDO $pdo,array $campaign): array {
    if(empty($campaign['plan_hash'])||empty($campaign['plan_json']))return ['ok'=>false,'reason'=>'campaign_not_locked'];$computed=hash('sha256',(string)$campaign['plan_json']);return ['ok'=>hash_equals((string)$campaign['plan_hash'],$computed),'reason'=>hash_equals((string)$campaign['plan_hash'],$computed)?'current':'plan_hash_mismatch','stored_hash'=>$campaign['plan_hash'],'computed_hash'=>$computed];
}
function data_model_campaign_current_use(PDO $pdo,array $campaign): array {
    $integrity=data_model_campaign_plan_integrity($pdo,$campaign);$checks=['plan_integrity'=>$integrity];if(!$integrity['ok'])return ['usable'=>false,'checks'=>$checks];
    $plan=$campaign['plan']??json_decode((string)$campaign['plan_json'],true)?:[];$base=data_model_campaign_model_version_by_id($pdo,(int)$campaign['base_model_version_id']);$receipt=$base?data_model_latest_approval_receipt($pdo,$base):null;$checks['base_model']=['pass'=>$base&&in_array($base['status'],['approved','active'],true)&&data_model_version_integrity($base)['ok'],'status'=>$base['status']??null];$checks['base_approval_receipt']=['pass'=>$receipt&&data_model_receipt_integrity($receipt,$base)['ok']&&hash_equals((string)($plan['base_approval_receipt_hash']??''),(string)$receipt['receipt_hash'])];
    $props=data_model_campaign_proposals($pdo,(int)$campaign['id']);$proposalPass=true;foreach($props as $p)if($p['proposal_status']!=='published'||!hash_equals((string)$p['content_hash'],(string)$p['current_content_hash'])||!hash_equals((string)$p['approval_hash'],(string)$p['current_approval_hash'])||(string)$p['corpus_item_public_id']!==(string)$p['current_corpus_item_public_id']){$proposalPass=false;break;}$checks['proposal_integrity']=['pass'=>$proposalPass,'count'=>count($props)];
    foreach([['evaluation_dataset_id','evaluation_dataset','evaluation'],['training_dataset_id','training_dataset','training']] as [$idKey,$planKey,$purpose]){if($campaign[$idKey]===null){if($purpose==='training'&&$campaign['strategy']!=='model_training')continue;$checks[$planKey]=['pass'=>false,'reason'=>'missing'];continue;}$d=data_model_campaign_dataset($pdo,(int)$campaign[$idKey]);$expected=(string)($plan[$planKey]['selection_policy_hash']??'');$checks[$planKey]=['pass'=>$d&&$d['purpose']===$purpose&&hash_equals($expected,(string)$d['selection_policy_hash']),'status'=>$d['status']??null,'public_id'=>$d['public_id']??null,'selection_policy_hash'=>$d['selection_policy_hash']??null];}
    $usable=true;foreach($checks as $check)if(isset($check['pass'])&&!$check['pass']){$usable=false;break;}return ['usable'=>$usable,'checks'=>$checks];
}
function data_model_campaign_prepare_evaluation_suite(PDO $pdo,array $viewer,string $campaignPublicId): array {
    data_model_campaign_require_admin($viewer);
    return data_model_campaign_locked($pdo,$campaignPublicId,function(array $campaign) use($pdo,$viewer,$campaignPublicId){
        if(!$campaign['plan_hash'])throw new RuntimeException('Lock the campaign plan before preparing evaluation handoff.');$current=data_model_campaign_current_use($pdo,$campaign);if(!$current['usable'])throw new RuntimeException('Campaign plan is no longer current.');
        if($campaign['baseline_evaluation_suite_id']){$q=$pdo->prepare('SELECT public_id FROM data_evaluation_suites WHERE id=?');$q->execute([$campaign['baseline_evaluation_suite_id']]);$pub=(string)($q->fetchColumn()?:'');return $pub!==''?data_evaluation_suite_get($pdo,$pub):[];}
        $dataset=data_evaluation_dataset_assert($pdo,(int)$campaign['evaluation_dataset_id']);$base=data_model_campaign_model_version_by_id($pdo,(int)$campaign['base_model_version_id']);if(!$base||!$base['ai_model_id'])throw new RuntimeException('Baseline model version must be bound to an enabled runtime model before preparing its campaign benchmark.');
        $pdo->beginTransaction();try{
            $suite=data_evaluation_suite_create($pdo,$viewer,['dataset_id'=>$dataset['id'],'name'=>'Campaign · '.$campaign['title'].' · Baseline','description'=>'Phase 47 production-regression baseline for campaign '.$campaign['public_id'].'. Human activation and run queueing remain separate Evaluation Harness actions.','benchmark_type'=>'model','model_id'=>$base['ai_model_id'],'top_k'=>5]);
            $items=$pdo->prepare("SELECT di.*,p.title proposal_title,p.sanitized_input,p.sanitized_expected_output,c.route_key FROM data_dataset_items di JOIN data_model_improvement_proposals p ON p.public_id=di.source_object_public_id JOIN data_model_improvement_cases c ON c.id=p.case_id WHERE di.dataset_id=? AND p.proposal_type='evaluation_case' ORDER BY di.position,di.id");$items->execute([$dataset['id']]);$rows=$items->fetchAll();if(!$rows)throw new RuntimeException('Frozen campaign evaluation dataset contains no Phase 46 regression examples.');
            foreach($rows as $row)data_evaluation_case_add($pdo,$viewer,$suite['public_id'],['label'=>$row['proposal_title'],'query_text'=>$row['sanitized_input'],'expected_dataset_item_id'=>$row['id'],'reference_answer'=>$row['sanitized_expected_output'],'weight'=>1,'tags'=>['phase47','production_regression',(string)$row['route_key']]]);
            $q=$pdo->prepare('UPDATE data_model_improvement_campaigns SET baseline_evaluation_suite_id=?,updated_at=NOW() WHERE id=? AND baseline_evaluation_suite_id IS NULL');$q->execute([$suite['id'],$campaign['id']]);if($q->rowCount()!==1)throw new RuntimeException('Campaign evaluation handoff changed concurrently.');
            data_model_campaign_event($pdo,(int)$campaign['id'],(int)$viewer['id'],'baseline_evaluation_suite_draft_created',['suite_public_id'=>$suite['public_id'],'dataset_public_id'=>$dataset['public_id'],'case_count'=>count($rows)]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        $fresh=data_model_campaign_get($pdo,$campaignPublicId,false)??$campaign;data_model_campaign_sync_status($pdo,$fresh);return data_evaluation_suite_get($pdo,$suite['public_id'])??[];
    });
}
function data_model_campaign_prepare_training_draft(PDO $pdo,array $viewer,string $campaignPublicId,array $input=[]): array {
    data_model_campaign_require_admin($viewer);
    return data_model_campaign_locked($pdo,$campaignPublicId,function(array $campaign) use($pdo,$viewer,$campaignPublicId,$input){
        if($campaign['strategy']!=='model_training')throw new RuntimeException('Only controlled model-training campaigns can create a Phase 41 training draft.');if(!$campaign['plan_hash'])throw new RuntimeException('Lock the campaign plan before preparing training handoff.');$current=data_model_campaign_current_use($pdo,$campaign);if(!$current['usable'])throw new RuntimeException('Campaign plan is no longer current.');
        if($campaign['training_job_id']){$q=$pdo->prepare('SELECT public_id FROM data_training_jobs WHERE id=?');$q->execute([$campaign['training_job_id']]);$pub=(string)($q->fetchColumn()?:'');return $pub!==''?data_training_job_get($pdo,$pub):[];}
        if(!$campaign['baseline_evaluation_suite_id'])throw new RuntimeException('Prepare the campaign regression benchmark suite before creating the training draft.');
        $dataset=data_training_dataset_assert($pdo,(int)$campaign['training_dataset_id'],'internal_training');$inspect=data_training_package_inspect($pdo,['dataset_id'=>$dataset['id']],false);if($inspect['valid_examples']<1||$inspect['invalid_examples']>0||$inspect['attribution_blocked_items']>0)throw new RuntimeException('Campaign training dataset is not a valid Phase 41 supervised package.');
        $executor=(string)($input['executor_type']??'manual');if(!isset(data_training_executors()[$executor]))throw new InvalidArgumentException('Invalid training executor.');
        $pdo->beginTransaction();try{
            $job=data_training_job_create($pdo,$viewer,['dataset_id'=>$dataset['id'],'base_model_version_id'=>$campaign['base_model_version_id'],'output_registry_id'=>$campaign['registry_id'],'use_class'=>'internal_training','executor_type'=>$executor,'method'=>'supervised','training_format'=>'chat_messages','output_version_label'=>$campaign['target_version_label'],'description'=>'Phase 47 controlled improvement campaign '.$campaign['public_id'].'. Queue/submission remains an explicit Phase 41 action.','n_epochs'=>$input['n_epochs']??'auto','batch_size'=>$input['batch_size']??'auto','learning_rate_multiplier'=>$input['learning_rate_multiplier']??'auto','seed'=>$input['seed']??'','suffix'=>$input['suffix']??'','file_expiry_seconds'=>$input['file_expiry_seconds']??604800,'cost_rate_usd_per_million_tokens'=>$input['cost_rate_usd_per_million_tokens']??'','max_attempts'=>$input['max_attempts']??3,'external_provider_acknowledged'=>$input['external_provider_acknowledged']??0]);
            $q=$pdo->prepare('UPDATE data_model_improvement_campaigns SET training_job_id=?,updated_at=NOW() WHERE id=? AND training_job_id IS NULL');$q->execute([$job['id'],$campaign['id']]);if($q->rowCount()!==1)throw new RuntimeException('Campaign training handoff changed concurrently.');
            data_model_campaign_event($pdo,(int)$campaign['id'],(int)$viewer['id'],'training_job_draft_created',['training_job_public_id'=>$job['public_id'],'dataset_public_id'=>$dataset['public_id'],'executor_type'=>$executor,'package_valid_examples'=>$inspect['valid_examples']]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        $fresh=data_model_campaign_get($pdo,$campaignPublicId,false)??$campaign;data_model_campaign_sync_status($pdo,$fresh);return data_training_job_get($pdo,$job['public_id'])??[];
    });
}
function data_model_campaign_prepare_post_training_plan(PDO $pdo,array $viewer,string $campaignPublicId,array $input=[]): array {
    data_model_campaign_require_admin($viewer);
    return data_model_campaign_locked($pdo,$campaignPublicId,function(array $campaign) use($pdo,$viewer,$campaignPublicId,$input){
        if($campaign['strategy']!=='model_training'||!$campaign['training_job_id'])throw new RuntimeException('Campaign does not have a controlled training job handoff.');
        if($campaign['post_training_plan_id']){$q=$pdo->prepare('SELECT public_id FROM data_post_training_plans WHERE id=?');$q->execute([$campaign['post_training_plan_id']]);$pub=(string)($q->fetchColumn()?:'');return $pub!==''?data_post_training_plan_get($pdo,$pub):[];}
        $q=$pdo->prepare('SELECT public_id FROM data_training_jobs WHERE id=?');$q->execute([$campaign['training_job_id']]);$jobPublic=(string)($q->fetchColumn()?:'');$job=$jobPublic!==''?data_training_job_get($pdo,$jobPublic):null;if(!$job||$job['status']!=='succeeded')throw new RuntimeException('Post-training handoff requires the Phase 41 job to succeed and register its experimental output.');
        if(!$campaign['baseline_evaluation_suite_id'])throw new RuntimeException('Campaign has no baseline production-regression suite.');$q=$pdo->prepare('SELECT public_id FROM data_evaluation_suites WHERE id=?');$q->execute([$campaign['baseline_evaluation_suite_id']]);$suitePublic=(string)($q->fetchColumn()?:'');if($suitePublic==='')throw new RuntimeException('Campaign baseline evaluation suite is unavailable.');
        $pdo->beginTransaction();try{
            $plan=data_post_training_plan_create($pdo,$viewer,$jobPublic,[$suitePublic],$input);
            $q=$pdo->prepare("UPDATE data_model_improvement_campaigns SET post_training_plan_id=?,status='evaluation',updated_at=NOW() WHERE id=? AND post_training_plan_id IS NULL");$q->execute([$plan['id'],$campaign['id']]);if($q->rowCount()!==1)throw new RuntimeException('Campaign post-training handoff changed concurrently.');
            data_model_campaign_event($pdo,(int)$campaign['id'],(int)$viewer['id'],'post_training_plan_draft_created',['post_training_plan_public_id'=>$plan['public_id'],'training_job_public_id'=>$jobPublic,'baseline_suite_public_id'=>$suitePublic]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return data_post_training_plan_get($pdo,$plan['public_id'])??[];
    });
}
function data_model_campaign_sync_status(PDO $pdo,array $campaign): string {
    if(in_array($campaign['status'],['completed','abandoned'],true))return (string)$campaign['status'];$status='planned';
    if($campaign['plan_hash']){
        $status='dataset_preparation';$eval=data_model_campaign_dataset($pdo,$campaign['evaluation_dataset_id']!==null?(int)$campaign['evaluation_dataset_id']:null);$train=data_model_campaign_dataset($pdo,$campaign['training_dataset_id']!==null?(int)$campaign['training_dataset_id']:null);
        if($campaign['strategy']==='model_training'){
            if($eval&&$eval['status']==='frozen'&&$train&&$train['status']==='frozen')$status='ready_for_training';
            if($campaign['training_job_id']){$q=$pdo->prepare('SELECT status FROM data_training_jobs WHERE id=?');$q->execute([$campaign['training_job_id']]);$js=(string)($q->fetchColumn()?:'');if($js==='draft')$status='ready_for_training';elseif(in_array($js,['queued','preparing','prepared','submitted','running','cancel_requested'],true))$status='training';elseif($js==='succeeded')$status='evaluation';elseif(in_array($js,['failed','cancelled','blocked'],true))$status='training';}
            if($campaign['post_training_plan_id']){$q=$pdo->prepare('SELECT status FROM data_post_training_plans WHERE id=?');$q->execute([$campaign['post_training_plan_id']]);$ps=(string)($q->fetchColumn()?:'');if($ps==='ready')$status='release_review';else $status='evaluation';}
        }else{if($eval&&$eval['status']==='frozen')$status='evaluation';}
    }
    if($status!==$campaign['status']){
        $q=$pdo->prepare("UPDATE data_model_improvement_campaigns SET status=?,updated_at=NOW() WHERE id=? AND status=? AND status NOT IN ('completed','abandoned')");$q->execute([$status,$campaign['id'],$campaign['status']]);
        if($q->rowCount()===1&&in_array($status,['ready_for_training','release_review'],true)){$fresh=data_model_campaign_get($pdo,(string)$campaign['public_id'],false);if($fresh)data_model_campaign_notify($pdo,$fresh,'status_'.$status);}
        elseif($q->rowCount()===0){$fresh=data_model_campaign_get($pdo,(string)$campaign['public_id'],false);if($fresh)return (string)$fresh['status'];}
    }
    return $status;
}
function data_model_campaign_outcome(PDO $pdo,array $campaign): array {
    $cases=data_model_campaign_cases($pdo,(int)$campaign['id']);$result=['state'=>'awaiting_output_model','output_model_version_public_id'=>null,'deployment_public_id'=>null,'observations'=>0,'cases'=>[],'counts'=>['improved'=>0,'recurring'=>0,'not_reproduced'=>0,'awaiting_evidence'=>0]];
    if(!$campaign['training_job_id'])return $result;$q=$pdo->prepare('SELECT output_model_version_id FROM data_training_jobs WHERE id=?');$q->execute([$campaign['training_job_id']]);$outputId=(int)($q->fetchColumn()?:0);if(!$outputId)return $result;$output=data_model_campaign_model_version_by_id($pdo,$outputId);if(!$output)return $result;$result['output_model_version_public_id']=$output['public_id'];
    $q=$pdo->prepare("SELECT * FROM data_model_deployments WHERE model_version_id=? AND status IN ('shadow','canary','limited','full','paused','rolled_back') ORDER BY id DESC LIMIT 1");$q->execute([$outputId]);$deployment=$q->fetch();if(!$deployment){$result['state']='awaiting_deployment';return $result;}$result['deployment_public_id']=$deployment['public_id'];$since=(string)$deployment['created_at'];
    $q=$pdo->prepare('SELECT COUNT(*) FROM data_model_observations WHERE model_version_id=? AND observed_at>=?');$q->execute([$outputId,$since]);$result['observations']=(int)$q->fetchColumn();if(!$result['observations']){$result['state']='awaiting_production_evidence';return $result;}$result['state']='observed';
    foreach($cases as $case){$state='awaiting_evidence';$detail=null;$route=(string)$case['route_key'];
        if($case['baseline_incident_metric']!==null&&$case['baseline_incident_value']!==null){$q=$pdo->prepare('SELECT * FROM data_model_incidents WHERE model_version_id=? AND route_key=? AND metric_name=? AND first_seen_at>=? ORDER BY id DESC LIMIT 1');$q->execute([$outputId,$route,$case['baseline_incident_metric'],$since]);$new=$q->fetch();if($new){$state=(float)$new['current_value']<(float)$case['baseline_incident_value']?'improved':'recurring';$detail=['baseline'=>(float)$case['baseline_incident_value'],'current'=>(float)$new['current_value'],'metric'=>$case['baseline_incident_metric'],'incident_public_id'=>$new['public_id']];}else{$q=$pdo->prepare('SELECT COUNT(*) FROM data_model_observations WHERE model_version_id=? AND route_key=? AND observed_at>=?');$q->execute([$outputId,$route,$since]);$state=(int)$q->fetchColumn()>0?'not_reproduced':'awaiting_evidence';}}
        else{$q=$pdo->prepare("SELECT s.signal_type FROM data_model_improvement_evidence e JOIN data_model_outcome_signals s ON s.id=e.outcome_signal_id WHERE e.case_id=? AND e.evidence_type='negative_outcome_signal' ORDER BY e.id LIMIT 1");$q->execute([$case['improvement_case_id']]);$signal=(string)($q->fetchColumn()?:'');if($signal!==''){$q=$pdo->prepare('SELECT COUNT(*) FROM data_model_outcome_signals s JOIN data_model_observations o ON o.id=s.observation_id WHERE o.model_version_id=? AND o.route_key=? AND s.signal_type=? AND s.signal_value<0 AND o.observed_at>=?');$q->execute([$outputId,$route,$signal,$since]);if((int)$q->fetchColumn()>0)$state='recurring';else{$q=$pdo->prepare('SELECT COUNT(*) FROM data_model_observations WHERE model_version_id=? AND route_key=? AND observed_at>=?');$q->execute([$outputId,$route,$since]);$state=(int)$q->fetchColumn()>0?'not_reproduced':'awaiting_evidence';}}}
        $result['counts'][$state]++;$result['cases'][]=['case_public_id'=>$case['case_public_id'],'title'=>$case['case_title'],'route_key'=>$route,'state'=>$state,'detail'=>$detail];
    }
    return $result;
}
function data_model_campaign_close(PDO $pdo,array $viewer,string $campaignPublicId,string $status,string $note=''): array {
    data_model_campaign_require_admin($viewer);if(!in_array($status,['completed','abandoned'],true))throw new InvalidArgumentException('Campaign may only be completed or abandoned explicitly.');
    return data_model_campaign_locked($pdo,$campaignPublicId,function(array $campaign) use($pdo,$viewer,$campaignPublicId,$status,$note){
        if(in_array($campaign['status'],['completed','abandoned'],true))return $campaign;
        if($status==='completed'){
            if($campaign['strategy']==='model_training'){$outcome=data_model_campaign_outcome($pdo,$campaign);if($outcome['state']!=='observed')throw new RuntimeException('Controlled model-training campaign can only complete after the output model has governed production observations.');}
            else{if(!$campaign['baseline_evaluation_suite_id'])throw new RuntimeException('Evaluation/remediation campaign requires a prepared evaluation suite before completion.');$q=$pdo->prepare("SELECT COUNT(*) FROM data_evaluation_runs WHERE suite_id=? AND status='completed'");$q->execute([$campaign['baseline_evaluation_suite_id']]);if(!(int)$q->fetchColumn())throw new RuntimeException('Evaluation/remediation campaign requires at least one completed benchmark run before completion.');}
        }
        $closeNote=mb_substr(trim($note),0,5000)?:null;
        $pdo->beginTransaction();try{
            $q=$pdo->prepare("UPDATE data_model_improvement_campaigns SET status=?,close_note=?,closed_by_user_id=?,closed_at=NOW(),updated_at=NOW() WHERE id=? AND status NOT IN ('completed','abandoned')");$q->execute([$status,$closeNote,$viewer['id'],$campaign['id']]);if($q->rowCount()!==1)throw new RuntimeException('Campaign changed while closing; reload and retry.');
            data_model_campaign_event($pdo,(int)$campaign['id'],(int)$viewer['id'],'campaign_'.$status,['note'=>$closeNote]);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return data_model_campaign_get($pdo,$campaignPublicId,false)??[];
    });
}
function data_model_campaign_events(PDO $pdo,int $campaignId,int $limit=100): array {
    $limit=max(1,min(500,$limit));$q=$pdo->prepare("SELECT e.*,u.display_name actor_name FROM data_model_improvement_campaign_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.campaign_id=? ORDER BY e.id DESC LIMIT $limit");$q->execute([$campaignId]);return $q->fetchAll();
}
function data_model_campaign_summary(PDO $pdo): array {
    if(!data_model_campaign_ready($pdo))return ['ready'=>false];$scalar=fn(string $sql)=>(int)$pdo->query($sql)->fetchColumn();
    return ['ready'=>true,'campaigns'=>$scalar('SELECT COUNT(*) FROM data_model_improvement_campaigns'),'open'=>$scalar("SELECT COUNT(*) FROM data_model_improvement_campaigns WHERE status NOT IN ('completed','abandoned')"),'ready_for_training'=>$scalar("SELECT COUNT(*) FROM data_model_improvement_campaigns WHERE status='ready_for_training'"),'training'=>$scalar("SELECT COUNT(*) FROM data_model_improvement_campaigns WHERE status='training'"),'evaluation'=>$scalar("SELECT COUNT(*) FROM data_model_improvement_campaigns WHERE status='evaluation'"),'release_review'=>$scalar("SELECT COUNT(*) FROM data_model_improvement_campaigns WHERE status='release_review'")];
}
function data_model_campaign_notify(PDO $pdo,array $campaign,string $reason): void {
    if(!function_exists('notification_create'))return;$admins=$pdo->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);foreach($admins as $uid)notification_create($pdo,(int)$uid,null,'model_improvement_campaign','model_improvement_campaign',(string)$campaign['public_id'],'Model improvement campaign: '.$campaign['title'],['category'=>'research','dedupe_key'=>'model-campaign:'.$campaign['public_id'].':'.$reason,'group_key'=>'model-campaign:'.$campaign['public_id'],'context'=>['status'=>$campaign['status'],'strategy'=>$campaign['strategy'],'reason'=>$reason]]);
}
function data_model_campaign_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limit=12): void {
    if(($viewer['role']??'')!=='admin'||!data_model_campaign_ready($pdo)||!function_exists('cognitive_feed_add'))return;$limit=max(1,min(30,$limit));foreach(data_model_campaign_list($pdo,$limit) as $c){if(in_array($c['status'],['completed','abandoned'],true))continue;$section=in_array($c['status'],['release_review','ready_for_training'],true)?'needs_attention':'next_up';$priority=$c['status']==='release_review'?'high':'medium';cognitive_feed_add($items,['key'=>cognitive_feed_key('model_improvement_campaign','model_improvement_campaign',(string)$c['public_id'],(string)($c['plan_hash']??$c['updated_at'])),'type'=>'model_improvement_campaign','section'=>$section,'priority'=>$priority,'created_at'=>$c['updated_at'],'title'=>(string)$c['title'],'body'=>'Governed model-improvement campaign · '.(data_model_campaign_statuses()[$c['status']]??$c['status']),'meta'=>['strategy'=>data_model_campaign_strategies()[$c['strategy']]??$c['strategy'],'base_model'=>$c['base_version_label'],'registry'=>$c['registry_name']],'actions'=>[cognitive_feed_action_link('Open campaign','/admin/model-campaigns.php?campaign='.rawurlencode((string)$c['public_id']))]]);}
}
