<?php
declare(strict_types=1);

/**
 * Phase 40 — Model Registry & Candidate Lifecycle
 *
 * Governance layer above existing ai_models runtime/provider configuration.
 * This file never trains, fine-tunes, mutates weights, or auto-promotes a model.
 */

function data_model_registry_ready(PDO $pdo): bool {
    try{return data_evaluation_ready($pdo)&&installer_table_exists($pdo,'data_model_registry')&&installer_table_exists($pdo,'data_model_versions')&&installer_table_exists($pdo,'data_model_promotion_receipts');}
    catch(Throwable $e){return false;}
}
function data_model_require_admin(array $viewer): void {
    if(($viewer['role']??'')!=='admin')throw new RuntimeException('Administrator access is required for Model Registry operations.');
}
function data_model_origins(): array {
    return [
        'external_hosted'=>'External hosted',
        'open_source'=>'Open-source model',
        'self_hosted'=>'Self-hosted model',
        'annotated_trained'=>'Annotated-trained model',
        'imported_artifact'=>'Imported artifact',
    ];
}
function data_model_statuses(): array {
    return ['experimental','candidate','approved','active','deprecated','retired'];
}
function data_model_slug(string $name): string {
    $slug=strtolower(trim($name));$slug=(string)preg_replace('/[^a-z0-9]+/','-',$slug);$slug=trim($slug,'-');return mb_substr($slug!==''?$slug:'model',0,180);
}
function data_model_gate_policy_normalize(array $input): array {
    $requiredSuites=$input['required_suite_public_ids']??[];
    if(is_string($requiredSuites))$requiredSuites=preg_split('/[\s,]+/',$requiredSuites,-1,PREG_SPLIT_NO_EMPTY)?:[];
    $requiredSuites=array_values(array_unique(array_filter(array_map(fn($v)=>mb_substr(trim((string)$v),0,64),(array)$requiredSuites))));
    sort($requiredSuites,SORT_STRING);
    return [
        'required_model_runs'=>max(1,min(100,(int)($input['required_model_runs']??1))),
        'required_human_reviews'=>max(1,min(1000,(int)($input['required_human_reviews']??1))),
        'require_integrity'=>array_key_exists('require_integrity',$input)?data_attribution_bool($input['require_integrity']):1,
        'require_no_regression'=>data_attribution_bool($input['require_no_regression']??0),
        'max_regressed_metrics'=>max(0,min(50,(int)($input['max_regressed_metrics']??0))),
        'minimum_human_pass_rate'=>max(0.0,min(1.0,(float)($input['minimum_human_pass_rate']??0.5))),
        'required_suite_public_ids'=>$requiredSuites,
        'policy_version'=>'phase40-v1',
    ];
}
function data_model_version_hash_material(array $v): array {
    return ['registry_id'=>(int)$v['registry_id'],'ai_model_id'=>$v['ai_model_id']!==null?(int)$v['ai_model_id']:null,'version_label'=>(string)$v['version_label'],'origin'=>(string)$v['origin'],'architecture'=>(string)($v['architecture']??''),'intended_use'=>(string)($v['intended_use']??''),'license_code'=>(string)($v['license_code']??''),'source_uri'=>(string)($v['source_uri']??''),'context_window'=>$v['context_window']!==null?(int)$v['context_window']:null,'artifact_ref'=>(string)($v['artifact_ref']??''),'artifact_hash'=>(string)($v['artifact_hash']??''),'gate_policy_hash'=>(string)$v['gate_policy_hash']];
}
function data_model_version_integrity(array $v): array {
    $computed=data_attribution_hash(data_model_version_hash_material($v));$stored=(string)($v['version_hash']??'');return ['ok'=>$stored!==''&&hash_equals($stored,$computed),'stored_hash'=>$stored,'computed_hash'=>$computed];
}
function data_model_benchmark_fingerprint(PDO $pdo,array $run): array {
    $suite=data_evaluation_suite_get($pdo,(string)$run['suite_public_id']);if(!$suite)throw new RuntimeException('Evaluation suite unavailable for model comparison.');$config=(array)$suite['config'];unset($config['model_id']);$cases=data_evaluation_cases($pdo,(int)$suite['id']);$caseDefinitionHash=data_attribution_hash(array_map(fn($c)=>['position'=>(int)$c['position'],'case_hash'=>(string)$c['case_hash']],$cases));$payload=['dataset_manifest_hash'=>(string)$run['dataset_manifest_hash'],'case_definition_hash'=>$caseDefinitionHash,'benchmark_type'=>(string)$run['benchmark_type'],'top_k'=>(int)$suite['top_k'],'prompt_template'=>(string)($suite['prompt_template']??''),'config_without_model'=>$config];return ['hash'=>data_attribution_hash($payload),'payload'=>$payload,'suite'=>$suite];
}
function data_model_event(PDO $pdo,int $registryId,?int $versionId,?int $actorUserId,string $eventType,array $details=[]): void {
    $pdo->prepare('INSERT INTO data_model_events(registry_id,version_id,actor_user_id,event_type,details_json) VALUES(?,?,?,?,?)')->execute([$registryId,$versionId,$actorUserId,$eventType,$details?data_attribution_encode($details):null]);
}
function data_model_registry_create(PDO $pdo,array $viewer,array $input): array {
    data_model_require_admin($viewer);if(!data_model_registry_ready($pdo))throw new RuntimeException('Model Registry requires the Phase 40 database upgrade.');
    $name=mb_substr(trim((string)($input['name']??'')),0,180);if($name==='')throw new InvalidArgumentException('Model registry name is required.');$slug=data_model_slug($name);$description=mb_substr(trim((string)($input['description']??'')),0,5000)?:null;$public=ulid_like();
    $pdo->prepare('INSERT INTO data_model_registry(public_id,name,slug,description,created_by_user_id) VALUES(?,?,?,?,?)')->execute([$public,$name,$slug,$description,$viewer['id']]);$id=(int)$pdo->lastInsertId();
    data_model_event($pdo,$id,null,(int)$viewer['id'],'registry_created',['name'=>$name,'slug'=>$slug]);return data_model_registry_get($pdo,$public)??[];
}
function data_model_registry_get(PDO $pdo,string $publicId): ?array {
    if(!data_model_registry_ready($pdo))return null;$q=$pdo->prepare('SELECT r.*,v.public_id active_version_public_id,v.version_label active_version_label,v.status active_version_status FROM data_model_registry r LEFT JOIN data_model_versions v ON v.id=r.active_version_id WHERE r.public_id=? LIMIT 1');$q->execute([trim($publicId)]);return $q->fetch()?:null;
}
function data_model_registry_list(PDO $pdo,int $limit=100): array {
    if(!data_model_registry_ready($pdo))return [];$limit=max(1,min(250,$limit));$q=$pdo->query("SELECT r.*,v.public_id active_version_public_id,v.version_label active_version_label,v.status active_version_status,(SELECT COUNT(*) FROM data_model_versions mv WHERE mv.registry_id=r.id) version_count FROM data_model_registry r LEFT JOIN data_model_versions v ON v.id=r.active_version_id ORDER BY r.id DESC LIMIT $limit");return $q->fetchAll();
}
function data_model_version_create(PDO $pdo,array $viewer,string $registryPublicId,array $input): array {
    data_model_require_admin($viewer);$registry=data_model_registry_get($pdo,$registryPublicId);if(!$registry)throw new RuntimeException('Model registry not found.');
    $label=mb_substr(trim((string)($input['version_label']??'')),0,120);if($label==='')throw new InvalidArgumentException('Version label is required.');$origin=(string)($input['origin']??'external_hosted');if(!isset(data_model_origins()[$origin]))throw new InvalidArgumentException('Invalid model origin.');
    $aiModelId=($input['ai_model_id']??'')!==''?max(1,(int)$input['ai_model_id']):null;if($aiModelId)ai_model_record($pdo,$aiModelId);
    if($origin==='external_hosted'&&!$aiModelId)throw new InvalidArgumentException('External hosted model versions require an existing runtime AI model.');
    $architecture=mb_substr(trim((string)($input['architecture']??'')),0,180)?:null;$intended=mb_substr(trim((string)($input['intended_use']??'')),0,5000)?:null;$license=mb_substr(trim((string)($input['license_code']??'')),0,120)?:null;$source=mb_substr(trim((string)($input['source_uri']??'')),0,1000)?:null;$context=($input['context_window']??'')!==''?max(1,(int)$input['context_window']):null;$artifact=mb_substr(trim((string)($input['artifact_ref']??'')),0,1000)?:null;$artifactHash=strtolower(trim((string)($input['artifact_hash']??'')))?:null;if($artifactHash!==null&&!preg_match('/^[a-f0-9]{64}$/',$artifactHash))throw new InvalidArgumentException('Artifact hash must be a SHA-256 value.');
    $policy=data_model_gate_policy_normalize($input);$policyJson=data_attribution_encode($policy);$policyHash=hash('sha256',$policyJson);$metadata=['registered_at'=>gmdate('c')];$public=ulid_like();$hashMaterial=['registry_id'=>(int)$registry['id'],'ai_model_id'=>$aiModelId,'version_label'=>$label,'origin'=>$origin,'architecture'=>(string)($architecture??''),'intended_use'=>(string)($intended??''),'license_code'=>(string)($license??''),'source_uri'=>(string)($source??''),'context_window'=>$context,'artifact_ref'=>(string)($artifact??''),'artifact_hash'=>(string)($artifactHash??''),'gate_policy_hash'=>$policyHash];$versionHash=data_attribution_hash($hashMaterial);
    $pdo->prepare("INSERT INTO data_model_versions(public_id,registry_id,ai_model_id,version_label,origin,status,architecture,intended_use,license_code,source_uri,context_window,artifact_ref,artifact_hash,metadata_json,gate_policy_json,gate_policy_hash,version_hash,created_by_user_id) VALUES(?,?,?,?,?,'experimental',?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$public,$registry['id'],$aiModelId,$label,$origin,$architecture,$intended,$license,$source,$context,$artifact,$artifactHash,data_attribution_encode($metadata),$policyJson,$policyHash,$versionHash,$viewer['id']]);$id=(int)$pdo->lastInsertId();
    data_model_event($pdo,(int)$registry['id'],$id,(int)$viewer['id'],'version_created',['origin'=>$origin,'ai_model_id'=>$aiModelId,'gate_policy_hash'=>$policyHash]);return data_model_version_get($pdo,$public)??[];
}
function data_model_version_get(PDO $pdo,string $publicId): ?array {
    if(!data_model_registry_ready($pdo))return null;$q=$pdo->prepare('SELECT v.*,r.public_id registry_public_id,r.name registry_name,r.active_version_id,m.public_id ai_model_public_id,m.display_name ai_model_name,m.model_name,p.label provider_label,p.provider_type FROM data_model_versions v JOIN data_model_registry r ON r.id=v.registry_id LEFT JOIN ai_models m ON m.id=v.ai_model_id LEFT JOIN ai_providers p ON p.id=m.provider_id WHERE v.public_id=? LIMIT 1');$q->execute([trim($publicId)]);$v=$q->fetch();if(!$v)return null;$v['gate_policy']=json_decode((string)$v['gate_policy_json'],true)?:[];$v['metadata']=json_decode((string)($v['metadata_json']??''),true)?:[];return $v;
}
function data_model_versions(PDO $pdo,int $registryId): array {
    $q=$pdo->prepare('SELECT v.*,m.display_name ai_model_name,p.label provider_label,(SELECT COUNT(*) FROM data_model_evaluation_links l WHERE l.version_id=v.id) evaluation_count FROM data_model_versions v LEFT JOIN ai_models m ON m.id=v.ai_model_id LEFT JOIN ai_providers p ON p.id=m.provider_id WHERE v.registry_id=? ORDER BY v.id DESC');$q->execute([$registryId]);return $q->fetchAll();
}
function data_model_version_update_experimental(PDO $pdo,array $viewer,string $versionPublicId,array $input): array {
    data_model_require_admin($viewer);$v=data_model_version_get($pdo,$versionPublicId);if(!$v)throw new RuntimeException('Model version not found.');if($v['status']!=='experimental')throw new RuntimeException('Candidate and later model versions are immutable except for lifecycle transitions and evidence links.');
    $architecture=mb_substr(trim((string)($input['architecture']??$v['architecture']??'')),0,180)?:null;$intended=mb_substr(trim((string)($input['intended_use']??$v['intended_use']??'')),0,5000)?:null;$license=mb_substr(trim((string)($input['license_code']??$v['license_code']??'')),0,120)?:null;$source=mb_substr(trim((string)($input['source_uri']??$v['source_uri']??'')),0,1000)?:null;$context=($input['context_window']??$v['context_window']??'')!==''?max(1,(int)($input['context_window']??$v['context_window'])):null;$artifact=mb_substr(trim((string)($input['artifact_ref']??$v['artifact_ref']??'')),0,1000)?:null;$artifactHash=strtolower(trim((string)($input['artifact_hash']??$v['artifact_hash']??'')))?:null;if($artifactHash!==null&&!preg_match('/^[a-f0-9]{64}$/',$artifactHash))throw new InvalidArgumentException('Artifact hash must be a SHA-256 value.');
    $policy=data_model_gate_policy_normalize(array_merge((array)$v['gate_policy'],$input));$policyJson=data_attribution_encode($policy);$policyHash=hash('sha256',$policyJson);$hashMaterial=['registry_id'=>(int)$v['registry_id'],'ai_model_id'=>$v['ai_model_id']!==null?(int)$v['ai_model_id']:null,'version_label'=>(string)$v['version_label'],'origin'=>(string)$v['origin'],'architecture'=>(string)($architecture??''),'intended_use'=>(string)($intended??''),'license_code'=>(string)($license??''),'source_uri'=>(string)($source??''),'context_window'=>$context,'artifact_ref'=>(string)($artifact??''),'artifact_hash'=>(string)($artifactHash??''),'gate_policy_hash'=>$policyHash];$versionHash=data_attribution_hash($hashMaterial);
    $pdo->prepare('UPDATE data_model_versions SET architecture=?,intended_use=?,license_code=?,source_uri=?,context_window=?,artifact_ref=?,artifact_hash=?,gate_policy_json=?,gate_policy_hash=?,version_hash=?,updated_at=NOW() WHERE id=?')->execute([$architecture,$intended,$license,$source,$context,$artifact,$artifactHash,$policyJson,$policyHash,$versionHash,$v['id']]);data_model_event($pdo,(int)$v['registry_id'],(int)$v['id'],(int)$viewer['id'],'version_updated',['gate_policy_hash'=>$policyHash]);return data_model_version_get($pdo,$versionPublicId)??[];
}
function data_model_version_bind_runtime(PDO $pdo,array $viewer,string $versionPublicId,int $aiModelId): array {
    data_model_require_admin($viewer);$v=data_model_version_get($pdo,$versionPublicId);if(!$v)throw new RuntimeException('Model version not found.');if($v['status']!=='experimental')throw new RuntimeException('Runtime binding can change only while a model version is experimental.');ai_model_record($pdo,$aiModelId);
    $hashMaterial=data_model_version_hash_material(array_merge($v,['ai_model_id'=>$aiModelId]));$versionHash=data_attribution_hash($hashMaterial);$pdo->prepare('UPDATE data_model_versions SET ai_model_id=?,version_hash=?,updated_at=NOW() WHERE id=?')->execute([$aiModelId,$versionHash,$v['id']]);data_model_event($pdo,(int)$v['registry_id'],(int)$v['id'],(int)$viewer['id'],'runtime_model_bound',['ai_model_id'=>$aiModelId,'version_hash'=>$versionHash]);return data_model_version_get($pdo,$versionPublicId)??[];
}
function data_model_link_evaluation(PDO $pdo,array $viewer,string $versionPublicId,string $runPublicId,string $note=''): array {
    data_model_require_admin($viewer);$v=data_model_version_get($pdo,$versionPublicId);$run=data_evaluation_run_get($pdo,$runPublicId);if(!$v||!$run)throw new RuntimeException('Model version or evaluation run not found.');if(in_array($v['status'],['approved','active','deprecated','retired'],true))throw new RuntimeException('Approved and later model evidence links are immutable.');if($run['status']!=='completed')throw new RuntimeException('Only completed evaluation runs can be linked.');
    $integrity=data_evaluation_run_integrity($pdo,$run);if(!$integrity['ok'])throw new RuntimeException('Evaluation run integrity must be valid before linking.');
    if($run['benchmark_type']!=='model')throw new RuntimeException('Model Registry evidence must come from a completed model-response benchmark.');
    if(!$v['ai_model_id']||(int)$run['model_id']!==(int)$v['ai_model_id'])throw new RuntimeException('Evaluation run model does not match this registered runtime model version.');
    $pdo->prepare('INSERT INTO data_model_evaluation_links(version_id,evaluation_run_id,linked_by_user_id,note) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE note=VALUES(note)')->execute([$v['id'],$run['id'],$viewer['id'],mb_substr(trim($note),0,5000)?:null]);data_model_event($pdo,(int)$v['registry_id'],(int)$v['id'],(int)$viewer['id'],'evaluation_linked',['run_public_id'=>$runPublicId,'run_hash'=>$run['run_hash']]);return data_model_version_get($pdo,$versionPublicId)??[];
}
function data_model_unlink_evaluation(PDO $pdo,array $viewer,string $versionPublicId,string $runPublicId): bool {
    data_model_require_admin($viewer);$v=data_model_version_get($pdo,$versionPublicId);$run=data_evaluation_run_get($pdo,$runPublicId);if(!$v||!$run)return false;if(in_array($v['status'],['approved','active','deprecated','retired'],true))throw new RuntimeException('Approved and later model evidence links are immutable.');
    $q=$pdo->prepare('DELETE FROM data_model_evaluation_links WHERE version_id=? AND evaluation_run_id=?');$q->execute([$v['id'],$run['id']]);if($q->rowCount())data_model_event($pdo,(int)$v['registry_id'],(int)$v['id'],(int)$viewer['id'],'evaluation_unlinked',['run_public_id'=>$runPublicId]);return $q->rowCount()>0;
}
function data_model_evaluation_links(PDO $pdo,int $versionId): array {
    $q=$pdo->prepare('SELECT l.*,r.public_id run_public_id,r.status run_status,r.run_hash,r.benchmark_type,r.model_id,r.result_summary_json,s.public_id suite_public_id,s.name suite_name,d.public_id dataset_public_id,d.name dataset_name,u.display_name linked_by_name FROM data_model_evaluation_links l JOIN data_evaluation_runs r ON r.id=l.evaluation_run_id JOIN data_evaluation_suites s ON s.id=r.suite_id JOIN data_datasets d ON d.id=r.dataset_id JOIN users u ON u.id=l.linked_by_user_id WHERE l.version_id=? ORDER BY l.id');$q->execute([$versionId]);$rows=$q->fetchAll();foreach($rows as &$r)$r['summary']=$r['result_summary_json']?json_decode((string)$r['result_summary_json'],true):null;unset($r);return $rows;
}
function data_model_evidence_snapshot(PDO $pdo,array $version): array {
    $links=data_model_evaluation_links($pdo,(int)$version['id']);$runs=[];$validModelRuns=0;$totalReviews=0;$humanPass=0;$humanFail=0;$integrityFailures=0;$regressedMetrics=0;$regressionComparisons=0;$suites=[];$validSuites=[];
    foreach($links as $link){$run=data_evaluation_run_get($pdo,(string)$link['run_public_id']);if(!$run)continue;$integrity=data_evaluation_run_integrity($pdo,$run);if(!$integrity['ok'])$integrityFailures++;$human=data_evaluation_human_summary($pdo,(int)$run['id']);$totalReviews+=$human['reviews'];$humanPass+=$human['pass'];$humanFail+=$human['fail'];$suite=data_evaluation_suite_get($pdo,(string)$run['suite_public_id']);$reg=$suite?data_evaluation_regression($pdo,$suite,$run):null;if($reg&&($reg['available']??false)){$regressionComparisons++;$regressedMetrics+=max(0,(int)$reg['regressed_metrics']);}$validModel=$run['benchmark_type']==='model'&&$integrity['ok']&&$version['ai_model_id']&&(int)$run['model_id']===(int)$version['ai_model_id'];if($validModel){$validModelRuns++;$validSuites[]=(string)$run['suite_public_id'];}$suites[]=(string)$run['suite_public_id'];$runs[]=['public_id'=>$run['public_id'],'run_hash'=>$run['run_hash'],'suite_public_id'=>$run['suite_public_id'],'dataset_public_id'=>$run['dataset_public_id'],'dataset_manifest_hash'=>$run['dataset_manifest_hash'],'benchmark_type'=>$run['benchmark_type'],'model_id'=>$run['model_id']!==null?(int)$run['model_id']:null,'integrity_ok'=>$integrity['ok'],'model_match'=>$validModel,'summary_hash'=>$run['result_summary_hash'],'human_summary'=>$human,'regression'=>$reg];}
    $suites=array_values(array_unique($suites));sort($suites,SORT_STRING);$validSuites=array_values(array_unique($validSuites));sort($validSuites,SORT_STRING);$reviewed=$humanPass+$humanFail;$passRate=$reviewed>0?$humanPass/$reviewed:0.0;
    return ['version_public_id'=>$version['public_id'],'ai_model_id'=>$version['ai_model_id']!==null?(int)$version['ai_model_id']:null,'linked_runs'=>count($runs),'valid_model_runs'=>$validModelRuns,'integrity_failures'=>$integrityFailures,'human_reviews'=>$totalReviews,'human_pass'=>$humanPass,'human_fail'=>$humanFail,'human_pass_rate'=>$passRate,'regressed_metrics'=>$regressedMetrics,'regression_comparisons'=>$regressionComparisons,'suite_public_ids'=>$suites,'valid_suite_public_ids'=>$validSuites,'runs'=>$runs];
}
function data_model_gate_evaluate(PDO $pdo,array $version): array {
    $policy=(array)$version['gate_policy'];$evidence=data_model_evidence_snapshot($pdo,$version);$checks=[];$versionIntegrity=data_model_version_integrity($version);$runtimeAvailable=false;if($version['ai_model_id']){try{ai_model_record($pdo,(int)$version['ai_model_id']);$runtimeAvailable=true;}catch(Throwable $e){$runtimeAvailable=false;}}
    $checks['version_integrity']=['pass'=>$versionIntegrity['ok'],'actual'=>$versionIntegrity['ok']?'valid':'failed','required'=>'valid'];
    $checks['runtime_model']=['pass'=>$runtimeAvailable,'actual'=>$runtimeAvailable?'available':'unavailable','required'=>'available'];
    $checks['model_runs']=['pass'=>$evidence['valid_model_runs']>=(int)$policy['required_model_runs'],'actual'=>$evidence['valid_model_runs'],'required'=>(int)$policy['required_model_runs']];
    $checks['human_reviews']=['pass'=>$evidence['human_reviews']>=(int)$policy['required_human_reviews'],'actual'=>$evidence['human_reviews'],'required'=>(int)$policy['required_human_reviews']];
    $checks['human_pass_rate']=['pass'=>$evidence['human_pass_rate']>=(float)$policy['minimum_human_pass_rate'],'actual'=>$evidence['human_pass_rate'],'required'=>(float)$policy['minimum_human_pass_rate']];
    $checks['integrity']=['pass'=>!(int)$policy['require_integrity']||$evidence['integrity_failures']===0,'actual'=>$evidence['integrity_failures'],'required'=>0];
    $max=(int)$policy['max_regressed_metrics'];$checks['regression']=['pass'=>!(int)$policy['require_no_regression']||($evidence['regression_comparisons']>0&&$evidence['regressed_metrics']<=$max),'actual'=>$evidence['regressed_metrics'],'comparisons'=>$evidence['regression_comparisons'],'required_max'=>$max];
    $missing=array_values(array_diff((array)$policy['required_suite_public_ids'],$evidence['valid_suite_public_ids']));$checks['required_suites']=['pass'=>count($missing)===0,'missing'=>$missing,'required'=>(array)$policy['required_suite_public_ids']];
    $pass=true;foreach($checks as $c)if(!$c['pass']){$pass=false;break;}
    return ['pass'=>$pass,'policy'=>$policy,'policy_hash'=>$version['gate_policy_hash'],'checks'=>$checks,'evidence'=>$evidence,'evaluated_at'=>gmdate('c')];
}
function data_model_receipt_create(PDO $pdo,array $viewer,array $version,string $from,string $to,?int $previousActiveId,array $gate,string $note=''): array {
    $gateJson=data_attribution_encode(['policy'=>$gate['policy'],'policy_hash'=>$gate['policy_hash'],'version_hash'=>$version['version_hash'],'checks'=>$gate['checks'],'pass'=>$gate['pass'],'evaluated_at'=>$gate['evaluated_at']]);$gateHash=hash('sha256',$gateJson);$evidenceJson=data_attribution_encode($gate['evidence']);$evidenceHash=hash('sha256',$evidenceJson);$public=ulid_like();
    $noteValue=mb_substr(trim($note),0,5000)?:null;$core=['public_id'=>$public,'version_public_id'=>$version['public_id'],'version_hash'=>$version['version_hash'],'from_status'=>$from,'to_status'=>$to,'previous_active_version_id'=>$previousActiveId,'gate_snapshot_hash'=>$gateHash,'evidence_snapshot_hash'=>$evidenceHash,'actor_user_id'=>(int)$viewer['id'],'note'=>(string)($noteValue??'')];$receiptHash=data_attribution_hash($core);
    $pdo->prepare('INSERT INTO data_model_promotion_receipts(public_id,version_id,actor_user_id,from_status,to_status,previous_active_version_id,gate_snapshot_json,gate_snapshot_hash,evidence_snapshot_json,evidence_snapshot_hash,note,receipt_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$public,$version['id'],$viewer['id'],$from,$to,$previousActiveId,$gateJson,$gateHash,$evidenceJson,$evidenceHash,$noteValue,$receiptHash]);
    return ['public_id'=>$public,'receipt_hash'=>$receiptHash,'gate_snapshot_hash'=>$gateHash,'evidence_snapshot_hash'=>$evidenceHash];
}
function data_model_receipt_integrity(array $receipt,array $version): array {
    $gate=json_decode((string)$receipt['gate_snapshot_json'],true);if(!is_array($gate))$gate=[];$evidence=json_decode((string)$receipt['evidence_snapshot_json'],true);if(!is_array($evidence))$evidence=[];$gateHash=hash('sha256',data_attribution_encode($gate));$evidenceHash=hash('sha256',data_attribution_encode($evidence));
    $core=['public_id'=>$receipt['public_id'],'version_public_id'=>$version['public_id'],'version_hash'=>$version['version_hash'],'from_status'=>$receipt['from_status'],'to_status'=>$receipt['to_status'],'previous_active_version_id'=>$receipt['previous_active_version_id']!==null?(int)$receipt['previous_active_version_id']:null,'gate_snapshot_hash'=>$gateHash,'evidence_snapshot_hash'=>$evidenceHash,'actor_user_id'=>(int)$receipt['actor_user_id'],'note'=>(string)($receipt['note']??'')];$receiptHash=data_attribution_hash($core);
    $ok=hash_equals((string)$receipt['gate_snapshot_hash'],$gateHash)&&hash_equals((string)$receipt['evidence_snapshot_hash'],$evidenceHash)&&hash_equals((string)$receipt['receipt_hash'],$receiptHash);
    return ['ok'=>$ok,'gate_hash_ok'=>hash_equals((string)$receipt['gate_snapshot_hash'],$gateHash),'evidence_hash_ok'=>hash_equals((string)$receipt['evidence_snapshot_hash'],$evidenceHash),'receipt_hash_ok'=>hash_equals((string)$receipt['receipt_hash'],$receiptHash),'computed_receipt_hash'=>$receiptHash];
}
function data_model_latest_approval_receipt(PDO $pdo,array $version): ?array {
    $q=$pdo->prepare("SELECT * FROM data_model_promotion_receipts WHERE version_id=? AND to_status='approved' ORDER BY id DESC LIMIT 1");$q->execute([$version['id']]);$r=$q->fetch();return $r?:null;
}
function data_model_was_active(PDO $pdo,int $versionId): bool {
    $q=$pdo->prepare("SELECT COUNT(*) FROM data_model_promotion_receipts WHERE version_id=? AND to_status='active'");$q->execute([$versionId]);return (int)$q->fetchColumn()>0;
}
function data_model_transition(PDO $pdo,array $viewer,string $versionPublicId,string $toStatus,string $note=''): array {
    data_model_require_admin($viewer);$version=data_model_version_get($pdo,$versionPublicId);if(!$version)throw new RuntimeException('Model version not found.');$from=(string)$version['status'];$allowed=['experimental'=>['candidate'],'candidate'=>['approved','retired'],'approved'=>['active','retired'],'active'=>['deprecated'],'deprecated'=>['retired'],'retired'=>[]];if(!in_array($toStatus,$allowed[$from]??[],true))throw new RuntimeException("Invalid model lifecycle transition: $from → $toStatus.");
    $gate=['pass'=>true,'policy'=>$version['gate_policy'],'policy_hash'=>$version['gate_policy_hash'],'checks'=>[],'evidence'=>data_model_evidence_snapshot($pdo,$version),'evaluated_at'=>gmdate('c')];
    if(in_array($toStatus,['approved','active'],true)){$gate=data_model_gate_evaluate($pdo,$version);if(!$gate['pass'])throw new RuntimeException('Model release gates are not satisfied.');}
    if($toStatus==='active'){$approval=data_model_latest_approval_receipt($pdo,$version);if(!$approval)throw new RuntimeException('Activation requires a prior approval receipt.');$approvalIntegrity=data_model_receipt_integrity($approval,$version);if(!$approvalIntegrity['ok'])throw new RuntimeException('Activation is blocked because the prior approval receipt failed integrity validation.');}
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT active_version_id FROM data_model_registry WHERE id=? FOR UPDATE');$q->execute([$version['registry_id']]);$previousActive=(int)($q->fetchColumn()?:0)?:null;
        if($toStatus==='active'){
            if($previousActive&&$previousActive!==(int)$version['id']){$q=$pdo->prepare("SELECT public_id FROM data_model_versions WHERE id=? FOR UPDATE");$q->execute([$previousActive]);$previousPublic=(string)($q->fetchColumn()?:'');$previousVersion=$previousPublic!==''?data_model_version_get($pdo,$previousPublic):null;if($previousVersion&&$previousVersion['status']==='active'){$previousGate=['pass'=>true,'policy'=>$previousVersion['gate_policy'],'policy_hash'=>$previousVersion['gate_policy_hash'],'checks'=>[],'evidence'=>data_model_evidence_snapshot($pdo,$previousVersion),'evaluated_at'=>gmdate('c')];$pdo->prepare("UPDATE data_model_versions SET status='deprecated',status_changed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$previousActive]);$previousReceipt=data_model_receipt_create($pdo,$viewer,$previousVersion,'active','deprecated',null,$previousGate,'SUPERSEDED BY '.$versionPublicId);data_model_event($pdo,(int)$version['registry_id'],$previousActive,(int)$viewer['id'],'superseded_by_activation',['new_active_version_public_id'=>$versionPublicId,'receipt_public_id'=>$previousReceipt['public_id']]);}}
            $pdo->prepare('UPDATE data_model_registry SET active_version_id=?,updated_at=NOW() WHERE id=?')->execute([$version['id'],$version['registry_id']]);
        }elseif($from==='active'&&$toStatus==='deprecated'){
            $pdo->prepare('UPDATE data_model_registry SET active_version_id=NULL,updated_at=NOW() WHERE id=? AND active_version_id=?')->execute([$version['registry_id'],$version['id']]);
        }
        $retired=$toStatus==='retired'?',retired_at=NOW()':'';$pdo->prepare("UPDATE data_model_versions SET status=?,status_changed_at=NOW(),updated_at=NOW()$retired WHERE id=?")->execute([$toStatus,$version['id']]);
        $receipt=data_model_receipt_create($pdo,$viewer,$version,$from,$toStatus,$previousActive,$gate,$note);data_model_event($pdo,(int)$version['registry_id'],(int)$version['id'],(int)$viewer['id'],'status_transition',['from'=>$from,'to'=>$toStatus,'receipt_public_id'=>$receipt['public_id'],'receipt_hash'=>$receipt['receipt_hash']]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return data_model_version_get($pdo,$versionPublicId)??[];
}
function data_model_rollback(PDO $pdo,array $viewer,string $registryPublicId,string $targetVersionPublicId,string $note=''): array {
    data_model_require_admin($viewer);$registry=data_model_registry_get($pdo,$registryPublicId);$target=data_model_version_get($pdo,$targetVersionPublicId);if(!$registry||!$target||(int)$target['registry_id']!==(int)$registry['id'])throw new RuntimeException('Rollback target does not belong to this model registry.');if(!in_array($target['status'],['approved','deprecated'],true))throw new RuntimeException('Rollback target must be an approved or deprecated prior model version.');
    if(!data_model_was_active($pdo,(int)$target['id']))throw new RuntimeException('Rollback target must have previously been active.');$q=$pdo->prepare("SELECT * FROM data_model_promotion_receipts WHERE version_id=? AND to_status='active' ORDER BY id DESC LIMIT 1");$q->execute([$target['id']]);$activeReceipt=$q->fetch();if(!$activeReceipt||!data_model_receipt_integrity($activeReceipt,$target)['ok'])throw new RuntimeException('Rollback target active receipt failed integrity validation.');
    $gate=data_model_gate_evaluate($pdo,$target);if(!$gate['pass'])throw new RuntimeException('Rollback target no longer satisfies current model release gates.');
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT active_version_id FROM data_model_registry WHERE id=? FOR UPDATE');$q->execute([$registry['id']]);$current=(int)($q->fetchColumn()?:0)?:null;if($current===(int)$target['id']){$pdo->commit();return $target;}
        if($current){$q=$pdo->prepare("SELECT public_id FROM data_model_versions WHERE id=? FOR UPDATE");$q->execute([$current]);$currentPublic=(string)($q->fetchColumn()?:'');$currentVersion=$currentPublic!==''?data_model_version_get($pdo,$currentPublic):null;if($currentVersion&&$currentVersion['status']==='active'){$currentGate=['pass'=>true,'policy'=>$currentVersion['gate_policy'],'policy_hash'=>$currentVersion['gate_policy_hash'],'checks'=>[],'evidence'=>data_model_evidence_snapshot($pdo,$currentVersion),'evaluated_at'=>gmdate('c')];$pdo->prepare("UPDATE data_model_versions SET status='deprecated',status_changed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$current]);$currentReceipt=data_model_receipt_create($pdo,$viewer,$currentVersion,'active','deprecated',null,$currentGate,'SUPERSEDED BY ROLLBACK TO '.$targetVersionPublicId);data_model_event($pdo,(int)$registry['id'],$current,(int)$viewer['id'],'superseded_by_rollback',['target_version_public_id'=>$targetVersionPublicId,'receipt_public_id'=>$currentReceipt['public_id']]);}}
        $from=(string)$target['status'];$pdo->prepare("UPDATE data_model_versions SET status='active',status_changed_at=NOW(),retired_at=NULL,updated_at=NOW() WHERE id=?")->execute([$target['id']]);$pdo->prepare('UPDATE data_model_registry SET active_version_id=?,updated_at=NOW() WHERE id=?')->execute([$target['id'],$registry['id']]);$receipt=data_model_receipt_create($pdo,$viewer,$target,$from,'active',$current,$gate,'ROLLBACK: '.trim($note));data_model_event($pdo,(int)$registry['id'],(int)$target['id'],(int)$viewer['id'],'rollback_activated',['previous_active_version_id'=>$current,'receipt_public_id'=>$receipt['public_id'],'receipt_hash'=>$receipt['receipt_hash']]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return data_model_version_get($pdo,$targetVersionPublicId)??[];
}
function data_model_receipts(PDO $pdo,int $versionId): array {
    $q=$pdo->prepare('SELECT r.*,u.display_name actor_name,pv.public_id previous_active_public_id,pv.version_label previous_active_label FROM data_model_promotion_receipts r JOIN users u ON u.id=r.actor_user_id LEFT JOIN data_model_versions pv ON pv.id=r.previous_active_version_id WHERE r.version_id=? ORDER BY r.id DESC');$q->execute([$versionId]);$rows=$q->fetchAll();$vq=$pdo->prepare('SELECT public_id FROM data_model_versions WHERE id=?');$vq->execute([$versionId]);$public=(string)($vq->fetchColumn()?:'');$version=$public!==''?data_model_version_get($pdo,$public):null;if($version)foreach($rows as &$row)$row['integrity']=data_model_receipt_integrity($row,$version);unset($row);return $rows;
}
function data_model_events(PDO $pdo,int $registryId,int $limit=100): array {
    $limit=max(1,min(250,$limit));$q=$pdo->prepare("SELECT e.*,v.public_id version_public_id,v.version_label,u.display_name actor_name FROM data_model_events e LEFT JOIN data_model_versions v ON v.id=e.version_id LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.registry_id=? ORDER BY e.id DESC LIMIT $limit");$q->execute([$registryId]);return $q->fetchAll();
}
function data_model_comparison(PDO $pdo,array $versionPublicIds): array {
    $ids=array_values(array_unique(array_filter(array_map('trim',$versionPublicIds))));if(count($ids)<2||count($ids)>6)throw new InvalidArgumentException('Compare between 2 and 6 model versions.');
    $rows=[];$fingerprintSets=[];foreach($ids as $id){$v=data_model_version_get($pdo,$id);if(!$v)throw new RuntimeException('Model version not found: '.$id);$gate=data_model_gate_evaluate($pdo,$v);$links=data_model_evaluation_links($pdo,(int)$v['id']);$valid=[];foreach($links as $link){$run=data_evaluation_run_get($pdo,(string)$link['run_public_id']);if(!$run||$run['benchmark_type']!=='model'||(int)$run['model_id']!==(int)$v['ai_model_id'])continue;$integrity=data_evaluation_run_integrity($pdo,$run);if(!$integrity['ok'])continue;$fp=data_model_benchmark_fingerprint($pdo,$run);$valid[$fp['hash']][]=['run'=>$run,'fingerprint'=>$fp];}$fingerprintSets[]=array_keys($valid);$rows[]=['version'=>$v,'gate'=>$gate,'valid_runs_by_fingerprint'=>$valid];}
    $common=$fingerprintSets?array_shift($fingerprintSets):[];foreach($fingerprintSets as $set)$common=array_values(array_intersect($common,$set));sort($common,SORT_STRING);$comparisons=[];
    foreach($common as $fingerprint){$first=null;foreach($rows as $row){$entries=$row['valid_runs_by_fingerprint'][$fingerprint]??[];if($entries){$first=$entries[0]['fingerprint'];break;}}$entry=['benchmark_fingerprint'=>$fingerprint,'dataset_manifest_hash'=>$first['payload']['dataset_manifest_hash']??null,'case_definition_hash'=>$first['payload']['case_definition_hash']??null,'versions'=>[]];foreach($rows as $row){$entries=$row['valid_runs_by_fingerprint'][$fingerprint]??[];usort($entries,fn($a,$b)=>(int)$b['run']['id']<=>(int)$a['run']['id']);$chosen=$entries[0]??null;$run=$chosen['run']??null;$entry['versions'][]=['version'=>$row['version'],'run'=>$run,'suite'=>$chosen['fingerprint']['suite']??null,'summary'=>$run['summary']??null,'human'=>$run?data_evaluation_human_summary($pdo,(int)$run['id']):null];}$comparisons[]=$entry;}
    foreach($rows as &$row)unset($row['valid_runs_by_fingerprint']);unset($row);return ['versions'=>$rows,'common_benchmarks'=>$comparisons,'generated_at'=>gmdate('c')];
}
function data_model_summary(PDO $pdo): array {
    if(!data_model_registry_ready($pdo))return ['ready'=>false];$scalar=fn(string $sql)=>(int)$pdo->query($sql)->fetchColumn();return ['ready'=>true,'registries'=>$scalar('SELECT COUNT(*) FROM data_model_registry'),'versions'=>$scalar('SELECT COUNT(*) FROM data_model_versions'),'candidates'=>$scalar("SELECT COUNT(*) FROM data_model_versions WHERE status='candidate'"),'approved'=>$scalar("SELECT COUNT(*) FROM data_model_versions WHERE status='approved'"),'active'=>$scalar("SELECT COUNT(*) FROM data_model_versions WHERE status='active'"),'retired'=>$scalar("SELECT COUNT(*) FROM data_model_versions WHERE status='retired'"),'receipts'=>$scalar('SELECT COUNT(*) FROM data_model_promotion_receipts')];
}
