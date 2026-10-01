<?php
declare(strict_types=1);

function sponsored_research_dataset_ready(PDO $pdo): bool {
    try{return sponsored_research_knowledge_ready($pdo)
      && data_dataset_ready($pdo)
      && installer_table_exists($pdo,'sponsored_research_dataset_builds')
      && installer_table_exists($pdo,'sponsored_research_dataset_build_items')
      && installer_table_exists($pdo,'sponsored_research_dataset_events');}
    catch(Throwable $e){return false;}
}
function sponsored_research_dataset_event(PDO $pdo,int $buildId,?int $actorUserId,string $type,array $payload=[]): void {
    $pdo->prepare('INSERT INTO sponsored_research_dataset_events(public_id,build_id,actor_user_id,event_type,payload_json) VALUES(?,?,?,?,?)')
      ->execute([ulid_like(),$buildId,$actorUserId,mb_substr($type,0,80),$payload?data_attribution_encode($payload):null]);
}
function sponsored_research_dataset_require_admin(array $viewer): void {
    if(($viewer['role']??'')!=='admin')throw new RuntimeException('Administrator Dataset authority is required for Sponsored Research dataset assembly.');
}
function sponsored_research_dataset_purpose(string $purpose): string {
    $purpose=trim($purpose);if(!in_array($purpose,['shared_retrieval','evaluation','training','commercial_training'],true))throw new InvalidArgumentException('Invalid Sponsored Research dataset purpose.');return $purpose;
}
function sponsored_research_dataset_purpose_flag(string $purpose): string {
    return match($purpose){'shared_retrieval'=>'shared_retrieval','evaluation'=>'evaluation','training'=>'training','commercial_training'=>'commercial_training'};
}
function sponsored_research_dataset_release_items(PDO $pdo,array $release): array {
    $rows=sponsored_research_knowledge_release_items($pdo,(int)$release['id']);if(!$rows)throw new RuntimeException('Knowledge release contains no items.');return $rows;
}
function sponsored_research_dataset_consent_snapshot(PDO $pdo,array $release,string $purpose): array {
    $purpose=sponsored_research_dataset_purpose($purpose);$items=[];$all=true;$reasons=[];
    foreach(sponsored_research_dataset_release_items($pdo,$release) as $position=>$ri){
        $item=sponsored_research_knowledge_item_get($pdo,(string)$ri['item_public_id']);if(!$item){$all=false;$reasons[]='knowledge_item_missing';continue;}
        $uid=(int)($item['contributor_user_id']??0);$grant=$uid?data_usage_grant($pdo,'sponsored_knowledge_item',(string)$item['public_id'],$uid):null;$elig=data_training_eligibility($pdo,'sponsored_knowledge_item',(string)$item['public_id']);$flag=sponsored_research_dataset_purpose_flag($purpose);$allowed=!empty($elig[$flag]);
        if(!$allowed){$all=false;$reasons[]=(string)($elig['reason']??'not_eligible');}
        $grantSnapshot=$grant?[
          'public_id'=>$grant['public_id'],'shared_retrieval_allowed'=>(int)$grant['shared_retrieval_allowed'],'evaluation_allowed'=>(int)$grant['evaluation_allowed'],
          'training_allowed'=>(int)$grant['training_allowed'],'commercial_training_allowed'=>(int)$grant['commercial_training_allowed'],
          'attribution_required'=>(int)$grant['attribution_required'],'license_code'=>$grant['license_code'],'granted_at'=>$grant['granted_at']
        ]:null;
        $snap=json_decode((string)$item['snapshot_json'],true)?:[];$sourceRights=[];foreach((array)($snap['evidence_sources']??[]) as $source){$sp=(string)($source['source_public_id']??'');if($sp==='')continue;$rights=data_source_rights($pdo,$sp);$sourceRights[]=['source_public_id'=>$sp,'rights'=>$rights,'rights_hash'=>$rights?data_attribution_hash($rights):null];}
        $items[]=['position'=>$position,'knowledge_item_public_id'=>$item['public_id'],'knowledge_item_snapshot_hash'=>$item['snapshot_hash'],'contributor_user_id'=>$uid?:null,'usage_grant'=>$grantSnapshot,'usage_grant_hash'=>$grantSnapshot?data_attribution_hash($grantSnapshot):null,'source_rights'=>$sourceRights,'eligibility'=>$elig,'allowed'=>$allowed];
    }
    $manifest=['schema'=>'annotated-sponsored-dataset-consent-v1','knowledge_release_public_id'=>$release['public_id'],'knowledge_release_number'=>(int)$release['release_number'],'knowledge_release_manifest_hash'=>$release['manifest_hash'],'purpose'=>$purpose,'all_items_eligible'=>$all,'items'=>$items];
    return ['eligible'=>$all,'reasons'=>array_values(array_unique($reasons)),'manifest'=>$manifest,'manifest_hash'=>data_attribution_hash($manifest)];
}
function sponsored_research_dataset_refresh_item(PDO $pdo,string $itemPublicId): array {
    return data_corpus_refresh_object($pdo,'sponsored_knowledge_item',$itemPublicId);
}
function sponsored_research_dataset_refresh_release(PDO $pdo,string $releasePublicId): array {
    $release=sponsored_research_knowledge_release_get($pdo,$releasePublicId);if(!$release)throw new RuntimeException('Knowledge release not found.');$out=[];foreach(sponsored_research_dataset_release_items($pdo,$release) as $ri)$out[(string)$ri['item_public_id']]=sponsored_research_dataset_refresh_item($pdo,(string)$ri['item_public_id']);return $out;
}
function sponsored_research_dataset_refresh_knowledge_for_source(PDO $pdo,string $sourcePublicId): int {
    if(!sponsored_research_dataset_ready($pdo))return 0;$q=$pdo->prepare("SELECT DISTINCT ki.public_id,ki.snapshot_json FROM sponsored_research_knowledge_items ki WHERE ki.state='active' AND ki.future_use_allowed=1");$q->execute();$count=0;
    foreach($q->fetchAll()?:[] as $row){$snap=json_decode((string)$row['snapshot_json'],true)?:[];$match=false;foreach((array)($snap['evidence_sources']??[]) as $source)if((string)($source['source_public_id']??'')===$sourcePublicId){$match=true;break;}if($match){sponsored_research_dataset_refresh_item($pdo,(string)$row['public_id']);$count++;}}
    return $count;
}
function sponsored_research_dataset_build_get(PDO $pdo,string $publicId): ?array {
    $q=$pdo->prepare("SELECT b.*,kr.public_id knowledge_release_public_id,kr.release_number,kr.manifest_hash knowledge_release_manifest_hash,d.public_id dataset_public_id,d.name dataset_name,d.version_number dataset_version,d.status dataset_status
      FROM sponsored_research_dataset_builds b JOIN sponsored_research_knowledge_releases kr ON kr.id=b.knowledge_release_id JOIN data_datasets d ON d.id=b.dataset_id WHERE b.public_id=? LIMIT 1");$q->execute([trim($publicId)]);return $q->fetch()?:null;
}
function sponsored_research_dataset_build_for_dataset(PDO $pdo,int $datasetId): ?array {
    $q=$pdo->prepare('SELECT public_id FROM sponsored_research_dataset_builds WHERE dataset_id=? LIMIT 1');$q->execute([$datasetId]);$p=$q->fetchColumn();return $p?sponsored_research_dataset_build_get($pdo,(string)$p):null;
}
function sponsored_research_dataset_assemble(PDO $pdo,array $viewer,string $releasePublicId,string $purpose,string $name=''): array {
    sponsored_research_dataset_require_admin($viewer);if(!sponsored_research_dataset_ready($pdo))throw new RuntimeException('Sponsored Research dataset integration requires migration 123.');
    $purpose=sponsored_research_dataset_purpose($purpose);$release=sponsored_research_knowledge_release_get($pdo,$releasePublicId);if(!$release)throw new RuntimeException('Knowledge release not found.');
    $releaseStatus=sponsored_research_knowledge_release_current_use_status($pdo,$releasePublicId);if(empty($releaseStatus['usable']))throw new RuntimeException('Knowledge release is not currently usable: '.(string)$releaseStatus['reason']);
    sponsored_research_dataset_refresh_release($pdo,$releasePublicId);$consent=sponsored_research_dataset_consent_snapshot($pdo,$release,$purpose);if(empty($consent['eligible']))throw new RuntimeException('Every Knowledge item requires explicit consent and current Source rights for '.$purpose.'.');
    $itemIds=[];foreach((array)$consent['manifest']['items'] as $item)$itemIds[]=(string)$item['knowledge_item_public_id'];
    $datasetName=mb_substr(trim($name)?:((string)$release['knowledge_base_name'].' · '.$purpose.' · release '.(int)$release['release_number']),0,180);
    $dataset=data_dataset_create($pdo,$viewer,['name'=>$datasetName,'description'=>'Assembled from immutable Sponsored Research Knowledge release '.$release['public_id'].'.','purpose'=>$purpose,'source_object_public_ids'=>$itemIds,'corpus_types'=>['sponsored_knowledge'],'max_items'=>count($itemIds)]);
    $pdo->beginTransaction();try{
      $public=ulid_like();$manifestJson=data_attribution_encode($consent['manifest']);$pdo->prepare("INSERT INTO sponsored_research_dataset_builds(public_id,knowledge_release_id,dataset_id,purpose,consent_manifest_json,consent_manifest_hash,release_manifest_hash,status,created_by_user_id) VALUES(?,?,?,?,?,?,?,'draft',?)")
        ->execute([$public,(int)$release['id'],(int)$dataset['id'],$purpose,$manifestJson,$consent['manifest_hash'],$release['manifest_hash'],(int)$viewer['id']]);$buildId=(int)$pdo->lastInsertId();
      foreach((array)$consent['manifest']['items'] as $position=>$snap){
        $q=$pdo->prepare("SELECT ki.id knowledge_item_id,dci.id corpus_item_id,dug.id usage_grant_id FROM sponsored_research_knowledge_items ki JOIN data_corpus_items dci ON dci.source_object_type='sponsored_knowledge_item' AND dci.source_object_public_id=ki.public_id AND dci.invalidated_at IS NULL JOIN data_usage_grants dug ON dug.object_type='sponsored_knowledge_item' AND dug.object_public_id=ki.public_id AND dug.grantor_user_id=ki.contributor_user_id AND dug.revoked_at IS NULL WHERE ki.public_id=? LIMIT 1");$q->execute([(string)$snap['knowledge_item_public_id']]);$link=$q->fetch();if(!$link)throw new RuntimeException('Consent/corpus linkage changed during dataset assembly.');
        $rightsHash=data_attribution_hash(array_map(fn($x)=>$x['rights_hash']??null,(array)$snap['source_rights']));
        $pdo->prepare('INSERT INTO sponsored_research_dataset_build_items(build_id,knowledge_item_id,contributor_user_id,usage_grant_id,usage_grant_hash,source_rights_hash,corpus_item_id,position) VALUES(?,?,?,?,?,?,?,?)')
          ->execute([$buildId,(int)$link['knowledge_item_id'],$snap['contributor_user_id'],(int)$link['usage_grant_id'],(string)$snap['usage_grant_hash'],$rightsHash,(int)$link['corpus_item_id'],$position]);
      }
      sponsored_research_dataset_event($pdo,$buildId,(int)$viewer['id'],'dataset_build_created',['dataset_public_id'=>$dataset['public_id'],'purpose'=>$purpose,'consent_manifest_hash'=>$consent['manifest_hash'],'release_manifest_hash'=>$release['manifest_hash']]);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $frozen=data_dataset_freeze($pdo,$viewer,(string)$dataset['public_id']);$build=sponsored_research_dataset_build_get($pdo,$public);if(!$build)throw new RuntimeException('Sponsored dataset build could not be reloaded.');
    $pdo->prepare("UPDATE sponsored_research_dataset_builds SET status='frozen',frozen_at=NOW() WHERE id=?")->execute([(int)$build['id']]);
    data_provenance_edge_record($pdo,'sponsored_knowledge_release',(string)$release['public_id'],(string)$release['release_number'],'assembled_into','dataset',(string)$frozen['public_id'],(string)$frozen['version_number'],(int)$viewer['id'],null,null,date('Y-m-d H:i:s'),['purpose'=>$purpose,'consent_manifest_hash'=>$consent['manifest_hash']]);
    foreach($itemIds as $itemId)data_provenance_edge_record($pdo,'sponsored_knowledge_item',$itemId,null,'included_in_dataset','dataset',(string)$frozen['public_id'],(string)$frozen['version_number'],(int)$viewer['id']);
    sponsored_research_dataset_event($pdo,(int)$build['id'],(int)$viewer['id'],'dataset_frozen',['dataset_public_id'=>$frozen['public_id'],'dataset_version'=>(int)$frozen['version_number'],'dataset_manifest_hash'=>$frozen['manifest_hash']]);
    return sponsored_research_dataset_build_get($pdo,$public)??[];
}
function sponsored_research_dataset_current_use_status(PDO $pdo,string $buildPublicId): array {
    $build=sponsored_research_dataset_build_get($pdo,$buildPublicId);if(!$build)return ['usable'=>false,'reason'=>'build_not_found'];
    $releaseStatus=sponsored_research_knowledge_release_current_use_status($pdo,(string)$build['knowledge_release_public_id']);$datasetStatus=data_dataset_current_use_status($pdo,(string)$build['dataset_public_id']);
    $release=sponsored_research_knowledge_release_get($pdo,(string)$build['knowledge_release_public_id']);$consent=$release?sponsored_research_dataset_consent_snapshot($pdo,$release,(string)$build['purpose']):['eligible'=>false,'manifest_hash'=>''];
    $consentOk=$release&&hash_equals((string)$build['consent_manifest_hash'],(string)$consent['manifest_hash'])&&!empty($consent['eligible']);
    $usable=!empty($releaseStatus['usable'])&&!empty($datasetStatus['usable'])&&$consentOk;
    return ['usable'=>$usable,'reason'=>$usable?'current':(!$releaseStatus['usable']?'knowledge_release_invalid':(!$consentOk?'consent_or_rights_changed':'dataset_invalid')),'knowledge_release'=>$releaseStatus,'dataset'=>$datasetStatus,'consent_current'=>$consentOk,'computed_consent_manifest_hash'=>$consent['manifest_hash']??null,'stored_consent_manifest_hash'=>$build['consent_manifest_hash']];
}
function sponsored_research_dataset_builds(PDO $pdo,int $limit=200): array {
    $q=$pdo->query("SELECT b.*,kr.public_id knowledge_release_public_id,kr.release_number,kb.name knowledge_base_name,c.title campaign_title,d.public_id dataset_public_id,d.name dataset_name,d.version_number dataset_version,d.status dataset_status FROM sponsored_research_dataset_builds b JOIN sponsored_research_knowledge_releases kr ON kr.id=b.knowledge_release_id JOIN sponsored_research_knowledge_bases kb ON kb.id=kr.knowledge_base_id JOIN sponsored_research_campaigns c ON c.id=kb.campaign_id JOIN data_datasets d ON d.id=b.dataset_id ORDER BY b.id DESC LIMIT ".max(1,min(500,$limit)));return $q->fetchAll()?:[];
}
