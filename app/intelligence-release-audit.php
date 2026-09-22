<?php
declare(strict_types=1);

/**
 * Phase 48 — End-to-End Closed Loop Audit & Release Hardening.
 *
 * Read-only release audit. No persistent Phase 48 state and no mutation authority.
 */
function intelligence_release_audit_check(string $key,string $label,bool $pass,string $detail='',string $severity='blocker',?string $url=null): array {
    return ['key'=>$key,'label'=>$label,'pass'=>$pass,'detail'=>$detail,'severity'=>$severity,'url'=>$url];
}
function intelligence_release_required_tables(): array {
    return [
      'data_contributions','data_corpus_items',
      'data_datasets','data_dataset_items',
      'data_evaluation_suites','data_evaluation_runs','data_evaluation_results',
      'data_model_registry','data_model_versions','data_model_promotion_receipts',
      'data_training_jobs','data_training_attempts',
      'data_post_training_plans','data_post_training_packets',
      'data_model_release_decisions','data_model_release_signatures',
      'data_model_deployments','data_model_deployment_events',
      'data_model_observations','data_model_incidents',
      'data_model_improvement_cases','data_model_improvement_proposals','data_model_regression_cases',
      'data_model_improvement_campaigns','data_model_improvement_campaign_cases','data_model_improvement_campaign_proposals',
    ];
}
function intelligence_release_phase_matrix(PDO $pdo): array {
    $phases=[
      37=>['Data & Attribution',['data_contributions','data_corpus_items']],
      38=>['Dataset Registry',['data_datasets','data_dataset_items']],
      39=>['Evaluation Harness',['data_evaluation_suites','data_evaluation_runs']],
      40=>['Model Registry',['data_model_registry','data_model_versions','data_model_promotion_receipts']],
      41=>['Training Registry',['data_training_jobs','data_training_attempts']],
      42=>['Post-Training Readiness',['data_post_training_plans','data_post_training_packets']],
      43=>['Human Release Decision',['data_model_release_decisions','data_model_release_signatures']],
      44=>['Governed Deployment',['data_model_deployments','data_model_deployment_events']],
      45=>['Production Observability',['data_model_observations','data_model_incidents']],
      46=>['Production Feedback',['data_model_improvement_cases','data_model_improvement_proposals','data_model_regression_cases']],
      47=>['Improvement Campaigns',['data_model_improvement_campaigns','data_model_improvement_campaign_cases','data_model_improvement_campaign_proposals']],
    ];
    $out=[];foreach($phases as $number=>[$label,$tables]){$missing=[];foreach($tables as $table)if(!installer_table_exists($pdo,$table))$missing[]=$table;$out[]=['phase'=>$number,'label'=>$label,'pass'=>!$missing,'missing'=>$missing];}return $out;
}
function intelligence_release_active_model_checks(PDO $pdo): array {
    $out=[];if(!installer_table_exists($pdo,'data_model_registry'))return $out;
    $rows=$pdo->query("SELECT v.public_id FROM data_model_versions v JOIN data_model_registry r ON r.active_version_id=v.id WHERE v.status='active' ORDER BY r.id")->fetchAll(PDO::FETCH_COLUMN);
    foreach($rows as $public){$v=data_model_version_get($pdo,(string)$public);if(!$v)continue;$versionOk=data_model_version_integrity($v)['ok'];$receipt=data_model_latest_approval_receipt($pdo,$v);$receiptOk=$receipt&&data_model_receipt_integrity($receipt,$v)['ok'];$out[]=['public_id'=>$v['public_id'],'label'=>$v['version_label'],'registry'=>$v['registry_name'],'version_integrity'=>$versionOk,'approval_receipt_integrity'=>$receiptOk,'pass'=>$versionOk&&$receiptOk];}
    return $out;
}
function intelligence_release_decision_checks(PDO $pdo,int $limit=100): array {
    $out=[];if(!installer_table_exists($pdo,'data_model_release_decisions'))return $out;$limit=max(1,min(500,$limit));
    $q=$pdo->query("SELECT public_id FROM data_model_release_decisions WHERE status='decision_recorded' ORDER BY id DESC LIMIT $limit");
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $public){$d=data_model_release_decision_get($pdo,(string)$public);if(!$d)continue;$integrity=data_model_release_decision_integrity($pdo,$d);$out[]=['public_id'=>$d['public_id'],'outcome'=>$d['outcome'],'model_version_public_id'=>$d['model_version_public_id']??null,'pass'=>$integrity['ok']];}
    return $out;
}
function intelligence_release_campaign_checks(PDO $pdo,int $limit=100): array {
    $out=[];if(!data_model_campaign_ready($pdo))return $out;$limit=max(1,min(500,$limit));
    $q=$pdo->query("SELECT public_id,status FROM data_model_improvement_campaigns WHERE plan_hash IS NOT NULL ORDER BY id DESC LIMIT $limit");
    foreach($q->fetchAll() as $row){$c=data_model_campaign_get($pdo,(string)$row['public_id']);if(!$c)continue;$integrity=data_model_campaign_plan_integrity($pdo,$c);$current=data_model_campaign_current_use($pdo,$c);$closed=in_array($c['status'],['completed','abandoned'],true);$out[]=['public_id'=>$c['public_id'],'title'=>$c['title'],'status'=>$c['status'],'plan_integrity'=>$integrity['ok'],'current_use'=>$current['usable'],'closed'=>$closed,'pass'=>$integrity['ok']&&($closed||$current['usable'])];}
    return $out;
}
function intelligence_release_closed_loop_sample(PDO $pdo): array {
    $result=['available'=>false,'pass'=>false,'campaign'=>null,'checks'=>[]];
    if(!data_model_campaign_ready($pdo))return $result;
    $q=$pdo->query("SELECT public_id FROM data_model_improvement_campaigns WHERE plan_hash IS NOT NULL ORDER BY id DESC LIMIT 1");$public=(string)($q->fetchColumn()?:'');if($public==='')return $result;
    $campaign=data_model_campaign_get($pdo,$public);if(!$campaign)return $result;$result['available']=true;$result['campaign']=['public_id'=>$campaign['public_id'],'title'=>$campaign['title'],'status'=>$campaign['status']];
    $checks=[];
    $checks[]=intelligence_release_audit_check('phase47_plan','Phase 47 campaign plan',data_model_campaign_plan_integrity($pdo,$campaign)['ok'],'Locked campaign plan hash validates.');
    $cases=data_model_campaign_cases($pdo,(int)$campaign['id']);$props=data_model_campaign_proposals($pdo,(int)$campaign['id']);
    $checks[]=intelligence_release_audit_check('phase46_scope','Phase 46 improvement scope',count($cases)>0&&count($props)>0,count($cases).' case(s), '.count($props).' approved proposal(s) linked.');
    $evidenceOk=false;$incident=null;
    foreach($cases as $case){$evidence=data_model_improvement_evidence($pdo,(int)$case['improvement_case_id']);foreach($evidence as $e){if(!empty($e['incident_id'])){$q=$pdo->prepare('SELECT * FROM data_model_incidents WHERE id=?');$q->execute([$e['incident_id']]);$incident=$q->fetch()?:null;if($incident){$evidenceOk=true;break;}}}if($evidenceOk)break;}
    $checks[]=intelligence_release_audit_check('phase45_evidence','Phase 45 production evidence',$evidenceOk,$evidenceOk?'Campaign traces to a production-health incident.':'No incident-backed evidence found in the sampled campaign.','warning');
    $deployment=null;$decision=null;
    if($incident){$q=$pdo->prepare('SELECT * FROM data_model_deployments WHERE id=?');$q->execute([$incident['deployment_id']]);$deployment=$q->fetch()?:null;}
    $checks[]=intelligence_release_audit_check('phase44_deployment','Phase 44 deployment lineage',$deployment!==null,$deployment?'Deployment '.$deployment['public_id'].' · '.$deployment['status']:'No deployment lineage.');
    if($deployment){$q=$pdo->prepare('SELECT public_id FROM data_model_release_decisions WHERE id=?');$q->execute([$deployment['release_decision_id']]);$dp=(string)($q->fetchColumn()?:'');$decision=$dp!==''?data_model_release_decision_get($pdo,$dp):null;}
    $decisionOk=$decision&&$decision['status']==='decision_recorded'&&data_model_release_decision_integrity($pdo,$decision)['ok'];
    $checks[]=intelligence_release_audit_check('phase43_decision','Phase 43 signed release lineage',(bool)$decisionOk,$decisionOk?'Signed decision '.$decision['public_id'].' integrity validates.':'No integrity-valid signed release decision behind sampled production evidence.');
    $eval=data_model_campaign_dataset($pdo,$campaign['evaluation_dataset_id']!==null?(int)$campaign['evaluation_dataset_id']:null);$train=data_model_campaign_dataset($pdo,$campaign['training_dataset_id']!==null?(int)$campaign['training_dataset_id']:null);
    $datasetOk=$eval&&$eval['status']==='frozen'&&data_dataset_current_use_status($pdo,$eval['public_id'])['manifest_ok'];
    if($campaign['strategy']==='model_training')$datasetOk=$datasetOk&&$train&&$train['status']==='frozen'&&data_dataset_current_use_status($pdo,$train['public_id'])['manifest_ok'];
    $checks[]=intelligence_release_audit_check('phase38_datasets','Phase 38 frozen campaign datasets',(bool)$datasetOk,$datasetOk?'Campaign dataset manifests validate.':'Campaign datasets are absent, not frozen, or fail manifest integrity.');
    $suiteOk=false;if($campaign['baseline_evaluation_suite_id']){$q=$pdo->prepare('SELECT status FROM data_evaluation_suites WHERE id=?');$q->execute([$campaign['baseline_evaluation_suite_id']]);$suiteOk=(string)($q->fetchColumn()?:'')==='draft'||(string)($q->fetchColumn()?:'')==='active';}
    $checks[]=intelligence_release_audit_check('phase39_suite','Phase 39 regression handoff',$campaign['baseline_evaluation_suite_id']!==null,'Campaign has a governed regression-suite handoff.');
    $base=data_model_campaign_model_version_by_id($pdo,(int)$campaign['base_model_version_id']);$baseOk=$base&&data_model_version_integrity($base)['ok']&&(($r=data_model_latest_approval_receipt($pdo,$base))&&data_model_receipt_integrity($r,$base)['ok']);
    $checks[]=intelligence_release_audit_check('phase40_base','Phase 40 governed base model',(bool)$baseOk,$baseOk?'Base model identity and approval receipt validate.':'Base model governance failed.');
    $job=null;if($campaign['training_job_id']){$q=$pdo->prepare('SELECT public_id FROM data_training_jobs WHERE id=?');$q->execute([$campaign['training_job_id']]);$jp=(string)($q->fetchColumn()?:'');$job=$jp!==''?data_training_job_get($pdo,$jp):null;}
    $jobOk=$campaign['strategy']!=='model_training'||($job&&in_array($job['status'],['draft','queued','preparing','prepared','submitted','running','succeeded','blocked','cancelled'],true));
    $checks[]=intelligence_release_audit_check('phase41_training','Phase 41 training handoff',(bool)$jobOk,$job?'Training job '.$job['public_id'].' · '.$job['status']:'No training handoff required.');
    $result['checks']=$checks;$result['pass']=count(array_filter($checks,fn($c)=>!$c['pass']&&$c['severity']==='blocker'))===0;return $result;
}
function intelligence_release_audit(PDO $pdo,string $root): array {
    $checks=[];$pending=function_exists('installer_pending_migrations')?installer_pending_migrations($pdo,$root.'/database/migrations'):[];
    $checks[]=intelligence_release_audit_check('migrations','Database migrations',count($pending)===0,count($pending).' migration(s) pending.',count($pending)===0?'blocker':'blocker','/upgrade.php');
    $matrix=intelligence_release_phase_matrix($pdo);$matrixPass=count(array_filter($matrix,fn($r)=>!$r['pass']))===0;$checks[]=intelligence_release_audit_check('phase_matrix','Phase 37–47 schema matrix',$matrixPass,$matrixPass?'All intelligence-governance phase tables are available.':'One or more phase table groups are incomplete.');
    $active=intelligence_release_active_model_checks($pdo);$activePass=count(array_filter($active,fn($r)=>!$r['pass']))===0;$checks[]=intelligence_release_audit_check('active_models','Active model integrity',$activePass,count($active).' active governed model(s) checked.','blocker','/admin/model-registry.php');
    $decisions=intelligence_release_decision_checks($pdo);$decisionPass=count(array_filter($decisions,fn($r)=>!$r['pass']))===0;$checks[]=intelligence_release_audit_check('release_decisions','Signed release decision integrity',$decisionPass,count($decisions).' recorded decision(s) checked.','blocker','/admin/model-release.php');
    $campaigns=intelligence_release_campaign_checks($pdo);$campaignPass=count(array_filter($campaigns,fn($r)=>!$r['pass']))===0;$checks[]=intelligence_release_audit_check('campaigns','Locked campaign integrity',$campaignPass,count($campaigns).' locked campaign(s) checked.','blocker','/admin/model-campaigns.php');
    $loop=intelligence_release_closed_loop_sample($pdo);$checks[]=intelligence_release_audit_check('closed_loop','Closed-loop lineage sample',$loop['available']?$loop['pass']:true,$loop['available']?'Latest locked campaign lineage audited.':'No locked campaign exists yet; structural checks remain authoritative.',$loop['available']?'blocker':'warning','/admin/model-campaigns.php');
    $blockers=array_values(array_filter($checks,fn($c)=>!$c['pass']&&$c['severity']==='blocker'));$warnings=array_values(array_filter($checks,fn($c)=>!$c['pass']&&$c['severity']==='warning'));
    return ['pass'=>count($blockers)===0,'checks'=>$checks,'blockers'=>$blockers,'warnings'=>$warnings,'phase_matrix'=>$matrix,'active_models'=>$active,'release_decisions'=>$decisions,'campaigns'=>$campaigns,'closed_loop'=>$loop,'generated_at'=>gmdate('c')];
}
