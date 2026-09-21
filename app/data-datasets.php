<?php
declare(strict_types=1);

/**
 * Phase 38 — Dataset Registry & Frozen Dataset Manifests
 *
 * Datasets are derived from the governed Phase 37 corpus only.
 * Drafts may be edited. Frozen datasets are immutable snapshots.
 * Historical manifests remain reproducible even when current rights/consent later block reuse.
 */

function data_dataset_ready(PDO $pdo): bool {
    try{return data_attribution_ready($pdo)&&installer_table_exists($pdo,'data_datasets')&&installer_table_exists($pdo,'data_dataset_items');}
    catch(Throwable $e){return false;}
}
function data_dataset_require_admin(array $viewer): void {
    if(($viewer['role']??'')!=='admin')throw new RuntimeException('Administrator access is required for Dataset Registry operations.');
}
function data_dataset_purposes(): array {
    return [
        'retrieval'=>['label'=>'Retrieval','column'=>'shared_retrieval_eligible','description'=>'Shared retrieval and grounding.'],
        'evaluation'=>['label'=>'Evaluation','column'=>'evaluation_eligible','description'=>'Controlled model and retrieval evaluation.'],
        'training'=>['label'=>'Training','column'=>'training_eligible','description'=>'Future versioned model-training datasets.'],
        'commercial_training'=>['label'=>'Commercial training','column'=>'commercial_training_eligible','description'=>'Future commercial model-training datasets.'],
    ];
}
function data_dataset_slug(string $name): string {
    $slug=strtolower(trim($name));$slug=(string)preg_replace('/[^a-z0-9]+/','-',$slug);$slug=trim($slug,'-');
    return mb_substr($slug!==''?$slug:'dataset',0,180);
}
function data_dataset_policy_normalize(array $input): array {
    $purpose=strtolower(trim((string)($input['purpose']??'retrieval')));
    if(!isset(data_dataset_purposes()[$purpose]))throw new InvalidArgumentException('Invalid dataset purpose.');
    $types=$input['corpus_types']??[];
    if(is_string($types))$types=preg_split('/[\s,]+/',$types,-1,PREG_SPLIT_NO_EMPTY)?:[];
    $types=array_values(array_unique(array_filter(array_map(fn($v)=>strtolower(trim((string)$v)),(array)$types),fn($v)=>$v!==''&&preg_match('/^[a-z0-9_\-]{2,64}$/',$v))));
    sort($types,SORT_STRING);
    $max=max(1,min(10000,(int)($input['max_items']??1000)));
    return ['purpose'=>$purpose,'corpus_types'=>$types,'max_items'=>$max,'order'=>'source_object_type,source_object_public_id,source_object_version,content_hash,id'];
}
function data_dataset_event(PDO $pdo,int $datasetId,?int $actorUserId,string $type,array $details=[]): void {
    $pdo->prepare('INSERT INTO data_dataset_events(dataset_id,actor_user_id,event_type,details_json) VALUES(?,?,?,?)')->execute([$datasetId,$actorUserId,$type,$details?data_attribution_encode($details):null]);
}
function data_dataset_create(PDO $pdo,array $viewer,array $input): array {
    data_dataset_require_admin($viewer);if(!data_dataset_ready($pdo))throw new RuntimeException('Dataset Registry requires the Phase 38 database upgrade.');
    $name=mb_substr(trim((string)($input['name']??'')),0,180);if($name==='')throw new InvalidArgumentException('Dataset name is required.');
    $description=mb_substr(trim((string)($input['description']??'')),0,5000)?:null;$policy=data_dataset_policy_normalize($input);$slug=data_dataset_slug($name);
    $q=$pdo->prepare('SELECT COALESCE(MAX(version_number),0)+1 FROM data_datasets WHERE slug=?');$q->execute([$slug]);$version=(int)$q->fetchColumn();
    $public=ulid_like();$policyJson=data_attribution_encode($policy);$policyHash=hash('sha256',$policyJson);
    $pdo->prepare("INSERT INTO data_datasets(public_id,name,slug,version_number,purpose,status,description,selection_policy_json,selection_policy_hash,created_by_user_id) VALUES(?,?,?,?,?,'draft',?,?,?,?)")
        ->execute([$public,$name,$slug,$version,$policy['purpose'],$description,$policyJson,$policyHash,$viewer['id']]);$id=(int)$pdo->lastInsertId();
    data_dataset_event($pdo,$id,(int)$viewer['id'],'created',['selection_policy_hash'=>$policyHash,'purpose'=>$policy['purpose']]);
    return data_dataset_get($pdo,$public)??[];
}
function data_dataset_get(PDO $pdo,string $publicId): ?array {
    if(!data_dataset_ready($pdo))return null;$q=$pdo->prepare('SELECT d.*,cu.display_name created_by_name,fu.display_name frozen_by_name FROM data_datasets d JOIN users cu ON cu.id=d.created_by_user_id LEFT JOIN users fu ON fu.id=d.frozen_by_user_id WHERE d.public_id=? LIMIT 1');$q->execute([trim($publicId)]);$r=$q->fetch();if(!$r)return null;$r['selection_policy']=json_decode((string)$r['selection_policy_json'],true)?:[];return $r;
}
function data_dataset_list(PDO $pdo,int $limit=100): array {
    if(!data_dataset_ready($pdo))return [];$limit=max(1,min(250,$limit));
    $q=$pdo->query("SELECT d.*,u.display_name created_by_name FROM data_datasets d JOIN users u ON u.id=d.created_by_user_id ORDER BY d.id DESC LIMIT $limit");$rows=$q->fetchAll();foreach($rows as &$r)$r['selection_policy']=json_decode((string)$r['selection_policy_json'],true)?:[];unset($r);return $rows;
}
function data_dataset_update_draft(PDO $pdo,array $viewer,string $publicId,array $input): array {
    data_dataset_require_admin($viewer);$d=data_dataset_get($pdo,$publicId);if(!$d)throw new RuntimeException('Dataset not found.');if($d['status']!=='draft')throw new RuntimeException('Frozen or retired datasets are immutable.');
    $name=mb_substr(trim((string)($input['name']??$d['name'])),0,180);if($name==='')throw new InvalidArgumentException('Dataset name is required.');$description=mb_substr(trim((string)($input['description']??$d['description']??'')),0,5000)?:null;
    $policy=data_dataset_policy_normalize(array_merge((array)$d['selection_policy'],$input));$policyJson=data_attribution_encode($policy);$policyHash=hash('sha256',$policyJson);
    $pdo->prepare('UPDATE data_datasets SET name=?,purpose=?,description=?,selection_policy_json=?,selection_policy_hash=?,updated_at=NOW() WHERE id=?')->execute([$name,$policy['purpose'],$description,$policyJson,$policyHash,$d['id']]);
    data_dataset_event($pdo,(int)$d['id'],(int)$viewer['id'],'draft_updated',['selection_policy_hash'=>$policyHash,'purpose'=>$policy['purpose']]);
    return data_dataset_get($pdo,$publicId)??[];
}
function data_dataset_candidate_sql(array $policy,bool $countOnly=false): array {
    $purpose=(string)$policy['purpose'];$purposes=data_dataset_purposes();if(!isset($purposes[$purpose]))throw new InvalidArgumentException('Invalid dataset purpose.');$column=$purposes[$purpose]['column'];
    $where=["invalidated_at IS NULL","$column=1"];$params=[];
    if(!empty($policy['corpus_types'])){$ph=implode(',',array_fill(0,count($policy['corpus_types']),'?'));$where[]="corpus_type IN ($ph)";$params=array_merge($params,$policy['corpus_types']);}
    if($countOnly)$sql='SELECT COUNT(*) item_count,COALESCE(SUM(OCTET_LENGTH(normalized_text)),0) content_bytes FROM data_corpus_items WHERE '.implode(' AND ',$where);
    else $sql='SELECT * FROM data_corpus_items WHERE '.implode(' AND ',$where).' ORDER BY source_object_type,source_object_public_id,COALESCE(source_object_version,\'\'),content_hash,id LIMIT '.(int)$policy['max_items'];
    return [$sql,$params];
}
function data_dataset_preview(PDO $pdo,string $publicId,int $sampleLimit=20): array {
    $d=data_dataset_get($pdo,$publicId);if(!$d)throw new RuntimeException('Dataset not found.');$policy=(array)$d['selection_policy'];
    [$countSql,$params]=data_dataset_candidate_sql($policy,true);$q=$pdo->prepare($countSql);$q->execute($params);$totals=$q->fetch()?:['item_count'=>0,'content_bytes'=>0];
    [$sql,$params]=data_dataset_candidate_sql($policy,false);$q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll();$sample=array_slice($rows,0,max(1,min(50,$sampleLimit)));
    return ['eligible_total'=>(int)$totals['item_count'],'eligible_bytes'=>(int)$totals['content_bytes'],'selected_count'=>count($rows),'selected_bytes'=>array_sum(array_map(fn($r)=>strlen((string)$r['normalized_text']),$rows)),'sample'=>$sample];
}
function data_dataset_item_snapshot(array $row,string $purpose,int $position): array {
    $eligibility=[
        'purpose'=>$purpose,
        'shared_retrieval_eligible'=>(int)$row['shared_retrieval_eligible'],
        'evaluation_eligible'=>(int)$row['evaluation_eligible'],
        'training_eligible'=>(int)$row['training_eligible'],
        'commercial_training_eligible'=>(int)$row['commercial_training_eligible'],
        'attribution_required'=>(int)$row['attribution_required'],
        'eligibility_reason'=>(string)$row['eligibility_reason'],
    ];
    $eligJson=data_attribution_encode($eligibility);$eligHash=hash('sha256',$eligJson);
    $metadata=is_array($row['metadata_json']??null)?$row['metadata_json']:json_decode((string)($row['metadata_json']??''),true);if(!is_array($metadata))$metadata=[];
    $metadataJson=data_attribution_encode($metadata);$metadataHash=hash('sha256',$metadataJson);
    $itemCore=[
        'position'=>$position,'corpus_public_id'=>(string)$row['public_id'],'source_object_type'=>(string)$row['source_object_type'],
        'source_object_public_id'=>(string)$row['source_object_public_id'],'source_object_version'=>(string)($row['source_object_version']??''),
        'corpus_type'=>(string)$row['corpus_type'],'content_hash'=>(string)$row['content_hash'],'metadata_snapshot_hash'=>$metadataHash,'provenance_hash'=>(string)$row['provenance_hash'],
        'eligibility_snapshot_hash'=>$eligHash,
    ];
    return ['eligibility'=>$eligibility,'eligibility_json'=>$eligJson,'eligibility_hash'=>$eligHash,'metadata_json'=>$metadataJson,'metadata_hash'=>$metadataHash,'item_hash'=>data_attribution_hash($itemCore)];
}
function data_dataset_recompute_item_hashes(array $row): array {
    $metadata=json_decode((string)($row['metadata_snapshot_json']??''),true);if(!is_array($metadata))$metadata=[];$metadataJson=data_attribution_encode($metadata);$metadataHash=hash('sha256',$metadataJson);
    $eligibility=json_decode((string)($row['eligibility_snapshot_json']??''),true);if(!is_array($eligibility))$eligibility=[];$eligibilityJson=data_attribution_encode($eligibility);$eligibilityHash=hash('sha256',$eligibilityJson);
    $contentHash=hash('sha256',(string)$row['normalized_text_snapshot']);
    $core=[
        'position'=>(int)$row['position'],'corpus_public_id'=>(string)$row['corpus_public_id'],'source_object_type'=>(string)$row['source_object_type'],
        'source_object_public_id'=>(string)$row['source_object_public_id'],'source_object_version'=>(string)($row['source_object_version']??''),
        'corpus_type'=>(string)$row['corpus_type'],'content_hash'=>$contentHash,'metadata_snapshot_hash'=>$metadataHash,'provenance_hash'=>(string)$row['provenance_hash'],
        'eligibility_snapshot_hash'=>$eligibilityHash,
    ];
    return ['content_hash'=>$contentHash,'metadata_hash'=>$metadataHash,'eligibility_hash'=>$eligibilityHash,'item_hash'=>data_attribution_hash($core)];
}
function data_dataset_manifest_payload(PDO $pdo,array $dataset,bool $includeText=false): array {
    $q=$pdo->prepare('SELECT * FROM data_dataset_items WHERE dataset_id=? ORDER BY position,id');$q->execute([$dataset['id']]);$rows=$q->fetchAll();$items=[];
    foreach($rows as $r){$actual=data_dataset_recompute_item_hashes($r);$item=[
        'position'=>(int)$r['position'],'corpus_public_id'=>$r['corpus_public_id'],'source_object_type'=>$r['source_object_type'],'source_object_public_id'=>$r['source_object_public_id'],
        'source_object_version'=>(string)($r['source_object_version']??''),'contributor_user_id'=>$r['contributor_user_id']!==null?(int)$r['contributor_user_id']:null,
        'corpus_type'=>$r['corpus_type'],'content_hash'=>$actual['content_hash'],'metadata_snapshot_hash'=>$actual['metadata_hash'],'provenance_hash'=>$r['provenance_hash'],
        'eligibility_snapshot_hash'=>$actual['eligibility_hash'],'item_hash'=>$actual['item_hash'],
    ];if($includeText){$item['normalized_text']=$r['normalized_text_snapshot'];$item['metadata']=json_decode((string)($r['metadata_snapshot_json']??''),true);$item['eligibility']=json_decode((string)$r['eligibility_snapshot_json'],true)?:[];}$items[]=$item;}
    return [
        'schema'=>'annotated.dataset-manifest.v1','dataset'=>['public_id'=>$dataset['public_id'],'name'=>$dataset['name'],'slug'=>$dataset['slug'],'version'=>(int)$dataset['version_number'],'purpose'=>$dataset['purpose']],
        'selection_policy'=>json_decode((string)$dataset['selection_policy_json'],true)?:[],'selection_policy_hash'=>$dataset['selection_policy_hash'],
        'item_count'=>count($items),'content_bytes'=>(int)$dataset['content_bytes'],'items'=>$items,
    ];
}
function data_dataset_manifest_hash(PDO $pdo,array $dataset): string {return data_attribution_hash(data_dataset_manifest_payload($pdo,$dataset,false));}
function data_dataset_freeze(PDO $pdo,array $viewer,string $publicId): array {
    data_dataset_require_admin($viewer);if(!data_dataset_ready($pdo))throw new RuntimeException('Dataset Registry requires the Phase 38 database upgrade.');
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT * FROM data_datasets WHERE public_id=? FOR UPDATE');$q->execute([$publicId]);$d=$q->fetch();if(!$d)throw new RuntimeException('Dataset not found.');if($d['status']!=='draft')throw new RuntimeException('Only draft datasets can be frozen.');
        $policy=json_decode((string)$d['selection_policy_json'],true)?:[];[$sql,$params]=data_dataset_candidate_sql($policy,false);$q=$pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll();if(!$rows)throw new RuntimeException('No currently eligible corpus items match this dataset policy.');
        $insert=$pdo->prepare('INSERT INTO data_dataset_items(dataset_id,corpus_item_id,position,corpus_public_id,source_object_type,source_object_public_id,source_object_version,contributor_user_id,corpus_type,normalized_text_snapshot,metadata_snapshot_json,metadata_snapshot_hash,content_hash,provenance_hash,eligibility_snapshot_json,eligibility_snapshot_hash,item_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $bytes=0;$position=0;foreach($rows as $row){$snap=data_dataset_item_snapshot($row,(string)$d['purpose'],$position);$text=(string)$row['normalized_text'];$bytes+=strlen($text);$insert->execute([$d['id'],$row['id'],$position,$row['public_id'],$row['source_object_type'],$row['source_object_public_id'],$row['source_object_version'],$row['contributor_user_id'],$row['corpus_type'],$text,$snap['metadata_json'],$snap['metadata_hash'],$row['content_hash'],$row['provenance_hash'],$snap['eligibility_json'],$snap['eligibility_hash'],$snap['item_hash']]);$position++;}
        $pdo->prepare("UPDATE data_datasets SET status='frozen',item_count=?,content_bytes=?,frozen_by_user_id=?,frozen_at=NOW(),updated_at=NOW() WHERE id=?")->execute([count($rows),$bytes,$viewer['id'],$d['id']]);
        $dataset=data_dataset_get($pdo,$publicId);if(!$dataset)throw new RuntimeException('Frozen dataset could not be reloaded.');$manifestHash=data_dataset_manifest_hash($pdo,$dataset);
        $pdo->prepare('UPDATE data_datasets SET manifest_hash=? WHERE id=?')->execute([$manifestHash,$d['id']]);data_dataset_event($pdo,(int)$d['id'],(int)$viewer['id'],'frozen',['manifest_hash'=>$manifestHash,'item_count'=>count($rows),'content_bytes'=>$bytes]);
        $pdo->commit();return data_dataset_get($pdo,$publicId)??[];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function data_dataset_current_use_status(PDO $pdo,string $publicId): array {
    $d=data_dataset_get($pdo,$publicId);if(!$d)return ['usable'=>false,'reason'=>'dataset_not_found','invalid_items'=>0];if($d['status']!=='frozen')return ['usable'=>false,'reason'=>'dataset_not_frozen','invalid_items'=>0];
    $purpose=(string)$d['purpose'];$purposes=data_dataset_purposes();$column=$purposes[$purpose]['column']??null;if(!$column)return ['usable'=>false,'reason'=>'invalid_purpose','invalid_items'=>0];
    $q=$pdo->prepare("SELECT COUNT(*) FROM data_dataset_items ddi LEFT JOIN data_corpus_items dci ON dci.id=ddi.corpus_item_id WHERE ddi.dataset_id=? AND (dci.id IS NULL OR dci.invalidated_at IS NOT NULL OR dci.$column<>1 OR dci.content_hash<>ddi.content_hash OR dci.provenance_hash<>ddi.provenance_hash)");
    $q->execute([$d['id']]);$invalid=(int)$q->fetchColumn();
    $calc=data_dataset_manifest_hash($pdo,$d);$manifestOk=hash_equals((string)$d['manifest_hash'],$calc);
    return ['usable'=>$invalid===0&&$manifestOk,'reason'=>$manifestOk?($invalid===0?'current':'eligibility_or_content_changed'):'manifest_integrity_failure','invalid_items'=>$invalid,'manifest_ok'=>$manifestOk,'computed_manifest_hash'=>$calc,'stored_manifest_hash'=>$d['manifest_hash']];
}
function data_dataset_retire(PDO $pdo,array $viewer,string $publicId): array {
    data_dataset_require_admin($viewer);$d=data_dataset_get($pdo,$publicId);if(!$d)throw new RuntimeException('Dataset not found.');if($d['status']==='retired')return $d;
    $pdo->prepare("UPDATE data_datasets SET status='retired',retired_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$d['id']]);data_dataset_event($pdo,(int)$d['id'],(int)$viewer['id'],'retired',[]);return data_dataset_get($pdo,$publicId)??[];
}
function data_dataset_events(PDO $pdo,string $publicId,int $limit=50): array {
    $d=data_dataset_get($pdo,$publicId);if(!$d)return [];$limit=max(1,min(200,$limit));$q=$pdo->prepare("SELECT e.*,u.display_name actor_name FROM data_dataset_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.dataset_id=? ORDER BY e.id DESC LIMIT $limit");$q->execute([$d['id']]);return $q->fetchAll();
}
function data_dataset_items(PDO $pdo,string $publicId,int $limit=100,int $offset=0): array {
    $d=data_dataset_get($pdo,$publicId);if(!$d)return [];$limit=max(1,min(500,$limit));$offset=max(0,$offset);$q=$pdo->prepare("SELECT ddi.*,u.display_name contributor_name FROM data_dataset_items ddi LEFT JOIN users u ON u.id=ddi.contributor_user_id WHERE ddi.dataset_id=? ORDER BY ddi.position LIMIT $limit OFFSET $offset");$q->execute([$d['id']]);return $q->fetchAll();
}
function data_dataset_export(PDO $pdo,array $viewer,string $publicId,bool $includeText=false): array {
    data_dataset_require_admin($viewer);$d=data_dataset_get($pdo,$publicId);if(!$d)throw new RuntimeException('Dataset not found.');if(!in_array($d['status'],['frozen','retired'],true))throw new RuntimeException('Only frozen or retired dataset manifests can be exported.');
    $status=data_dataset_current_use_status($pdo,$publicId);if($includeText&&($d['status']!=='frozen'||!$status['usable']))throw new RuntimeException('Dataset data export is blocked because the dataset is retired or current rights, consent, content, provenance, or manifest integrity no longer validates.');
    $payload=data_dataset_manifest_payload($pdo,$d,$includeText);$payload['manifest_hash']=$d['manifest_hash'];$payload['current_use_status']=$status;
    data_dataset_event($pdo,(int)$d['id'],(int)$viewer['id'],$includeText?'exported_data':'exported_manifest',['usable'=>$status['usable']]);
    return $payload;
}
function data_dataset_summary(PDO $pdo): array {
    if(!data_dataset_ready($pdo))return ['ready'=>false];$scalar=fn(string $sql)=>(int)$pdo->query($sql)->fetchColumn();
    return ['ready'=>true,'datasets'=>$scalar('SELECT COUNT(*) FROM data_datasets'),'drafts'=>$scalar("SELECT COUNT(*) FROM data_datasets WHERE status='draft'"),'frozen'=>$scalar("SELECT COUNT(*) FROM data_datasets WHERE status='frozen'"),'retired'=>$scalar("SELECT COUNT(*) FROM data_datasets WHERE status='retired'"),'frozen_items'=>$scalar('SELECT COUNT(*) FROM data_dataset_items')];
}
