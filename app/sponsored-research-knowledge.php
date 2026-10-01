<?php
declare(strict_types=1);

function sponsored_research_knowledge_ready(PDO $pdo): bool {
    try{return sponsored_research_finance_ready($pdo)
      && installer_table_exists($pdo,'sponsored_research_knowledge_bases')
      && installer_table_exists($pdo,'sponsored_research_knowledge_items')
      && installer_table_exists($pdo,'sponsored_research_knowledge_releases')
      && installer_table_exists($pdo,'sponsored_research_knowledge_release_items')
      && installer_table_exists($pdo,'sponsored_research_knowledge_events');}
    catch(Throwable $e){return false;}
}
function sponsored_research_knowledge_event(PDO $pdo,int $kbId,?int $itemId,?int $releaseId,?int $actorUserId,string $type,array $payload=[]): void {
    $pdo->prepare('INSERT INTO sponsored_research_knowledge_events(public_id,knowledge_base_id,knowledge_item_id,release_id,actor_user_id,event_type,payload_json) VALUES(?,?,?,?,?,?,?)')
      ->execute([ulid_like(),$kbId,$itemId,$releaseId,$actorUserId,mb_substr($type,0,80),$payload?data_attribution_encode($payload):null]);
}
function sponsored_research_knowledge_base_get(PDO $pdo,string $publicId): ?array {
    if(!sponsored_research_knowledge_ready($pdo))return null;
    $q=$pdo->prepare("SELECT kb.*,c.public_id campaign_public_id,c.title campaign_title,a.public_id account_public_id,a.name account_name,ra.public_id research_agent_public_id,ra.name research_agent_name
      FROM sponsored_research_knowledge_bases kb
      JOIN sponsored_research_campaigns c ON c.id=kb.campaign_id
      JOIN accounts a ON a.id=kb.account_id
      LEFT JOIN research_agents ra ON ra.id=kb.research_agent_id
      WHERE kb.public_id=? LIMIT 1");
    $q->execute([trim($publicId)]);return $q->fetch()?:null;
}
function sponsored_research_knowledge_base_require_manage(PDO $pdo,array $viewer,string $publicId): array {
    $kb=sponsored_research_knowledge_base_get($pdo,$publicId);if(!$kb)throw new RuntimeException('Sponsored Research Knowledge Base not found.');
    sponsored_research_campaign_require_manage($pdo,$viewer,(string)$kb['campaign_public_id']);return $kb;
}
function sponsored_research_knowledge_base_for_campaign(PDO $pdo,array $viewer,string $campaignPublicId): ?array {
    $campaign=sponsored_research_campaign_require_manage($pdo,$viewer,$campaignPublicId);$q=$pdo->prepare('SELECT public_id FROM sponsored_research_knowledge_bases WHERE campaign_id=? ORDER BY id LIMIT 1');$q->execute([(int)$campaign['id']]);$p=$q->fetchColumn();return $p?sponsored_research_knowledge_base_get($pdo,(string)$p):null;
}
function sponsored_research_knowledge_base_create(PDO $pdo,array $viewer,string $campaignPublicId,array $input=[]): array {
    $campaign=sponsored_research_campaign_require_manage($pdo,$viewer,$campaignPublicId);
    $existing=sponsored_research_knowledge_base_for_campaign($pdo,$viewer,$campaignPublicId);if($existing)return $existing;
    $name=mb_substr(trim((string)($input['name']??($campaign['title'].' Knowledge Base'))),0,255);if($name==='')throw new InvalidArgumentException('Knowledge Base name is required.');
    $description=trim((string)($input['description']??''));$public=ulid_like();
    $pdo->prepare("INSERT INTO sponsored_research_knowledge_bases(public_id,campaign_id,account_id,research_agent_id,name,description,status,current_release_number,created_by_user_id) VALUES(?,?,?,?,?,?,'draft',0,?)")
      ->execute([$public,(int)$campaign['id'],(int)$campaign['account_id'],$campaign['research_agent_id']!==null?(int)$campaign['research_agent_id']:null,$name,$description!==''?$description:null,(int)$viewer['id']]);
    $id=(int)$pdo->lastInsertId();sponsored_research_knowledge_event($pdo,$id,null,null,(int)$viewer['id'],'knowledge_base_created',['campaign_public_id'=>$campaign['public_id']]);
    return sponsored_research_knowledge_base_get($pdo,$public)??[];
}
function sponsored_research_knowledge_submission_review(PDO $pdo,int $submissionVersionId): array {
    $q=$pdo->prepare("SELECT rc.*,s.public_id submission_public_id,s.researcher_user_id,sv.public_id submission_version_public_id,sv.revision_number,sv.snapshot_hash,c.public_id campaign_public_id
      FROM sponsored_research_review_cases rc
      JOIN sponsored_research_submissions s ON s.id=rc.submission_id
      JOIN sponsored_research_submission_versions sv ON sv.id=rc.submission_version_id
      JOIN sponsored_research_campaigns c ON c.id=rc.campaign_id
      WHERE rc.submission_version_id=? LIMIT 1");
    $q->execute([$submissionVersionId]);$case=$q->fetch();if(!$case)throw new RuntimeException('Accepted review case is required before Knowledge promotion.');
    if((string)$case['final_decision']!=='accepted'||!in_array((string)$case['status'],['accepted','resolved'],true))throw new RuntimeException('Only accepted Sponsored Research can be promoted to Knowledge.');
    return $case;
}
function sponsored_research_knowledge_asset(PDO $pdo,string $assetPublicId): array {
    $q=$pdo->prepare("SELECT a.*,sv.public_id submission_version_public_id,sv.revision_number,sv.submission_id
      FROM sponsored_research_submission_assets a
      JOIN sponsored_research_submission_versions sv ON sv.id=a.submission_version_id
      WHERE a.public_id=? LIMIT 1");
    $q->execute([trim($assetPublicId)]);$asset=$q->fetch();if(!$asset)throw new RuntimeException('Submission asset not found.');return $asset;
}
function sponsored_research_knowledge_item_body(array $asset): array {
    $snap=json_decode((string)$asset['snapshot_json'],true);if(!is_array($snap))$snap=[];
    $type=(string)$asset['asset_type'];$title=ucwords(str_replace('_',' ',$type));$body='';
    if($type==='annotation'){$title='Annotation';$body=(string)($snap['text']??'');}
    elseif($type==='claim'){$title='Claim';$body=(string)($snap['text']??'');}
    elseif($type==='finding'){$title=(string)($snap['metadata']['title']??$snap['state']['title']??'Finding');$body=(string)($snap['text']??$snap['state']['summary']??'');}
    elseif($type==='report_version'){$title=(string)($snap['metadata']['title']??'Report');$body=(string)($snap['text']??'');}
    elseif($type==='mission'){$title='Completed Research Mission';$body=data_attribution_encode($snap['config']??$snap);}
    elseif($type==='dataset'){$title=(string)($snap['name']??'Dataset');$body='Frozen Dataset '.$asset['asset_public_id'].' version '.(string)($asset['asset_version']??'');}
    else throw new RuntimeException('Sources remain evidence lineage and cannot be promoted as standalone Knowledge items.');
    if(trim($body)==='')$body=data_attribution_encode($snap);
    return ['title'=>mb_substr(trim($title),0,255),'body'=>$body,'snapshot'=>$snap];
}
function sponsored_research_knowledge_evidence_sources(PDO $pdo,int $submissionVersionId): array {
    $q=$pdo->prepare("SELECT public_id,asset_public_id,asset_version,snapshot_hash,source_rights_snapshot_hash,source_rights_snapshot_json FROM sponsored_research_submission_assets WHERE submission_version_id=? AND asset_type='source' ORDER BY position,id");$q->execute([$submissionVersionId]);$out=[];
    foreach($q->fetchAll()?:[] as $r)$out[]=['submission_asset_public_id'=>$r['public_id'],'source_public_id'=>$r['asset_public_id'],'source_version'=>$r['asset_version'],'snapshot_hash'=>$r['snapshot_hash'],'source_rights_snapshot_hash'=>$r['source_rights_snapshot_hash'],'source_rights'=>json_decode((string)($r['source_rights_snapshot_json']??''),true)?:null];
    return $out;
}
function sponsored_research_knowledge_item_authority(PDO $pdo,array $viewer,array $item,bool $allowContributor=false): array {
    $kb=sponsored_research_knowledge_base_get($pdo,(string)$item['knowledge_base_public_id']);if(!$kb)throw new RuntimeException('Knowledge Base not found.');
    if($allowContributor&&(int)($item['contributor_user_id']??0)===(int)$viewer['id']){research_account_require_approved($pdo,$viewer);return $kb;}
    sponsored_research_campaign_require_manage($pdo,$viewer,(string)$kb['campaign_public_id']);return $kb;
}
function sponsored_research_knowledge_item_get(PDO $pdo,string $publicId): ?array {
    $q=$pdo->prepare("SELECT ki.*,kb.public_id knowledge_base_public_id FROM sponsored_research_knowledge_items ki JOIN sponsored_research_knowledge_bases kb ON kb.id=ki.knowledge_base_id WHERE ki.public_id=? LIMIT 1");$q->execute([trim($publicId)]);return $q->fetch()?:null;
}
function sponsored_research_knowledge_items(PDO $pdo,string $kbPublicId,bool $includeInactive=true): array {
    $kb=sponsored_research_knowledge_base_get($pdo,$kbPublicId);if(!$kb)return [];$sql='SELECT ki.*,u.display_name contributor_name FROM sponsored_research_knowledge_items ki LEFT JOIN users u ON u.id=ki.contributor_user_id WHERE ki.knowledge_base_id=?';if(!$includeInactive)$sql.=" AND ki.state='active' AND ki.future_use_allowed=1";$sql.=' ORDER BY ki.id';$q=$pdo->prepare($sql);$q->execute([(int)$kb['id']]);return $q->fetchAll()?:[];
}
function sponsored_research_knowledge_promote(PDO $pdo,array $viewer,string $kbPublicId,string $assetPublicId,?string $supersedesItemPublicId=null): array {
    $kb=sponsored_research_knowledge_base_require_manage($pdo,$viewer,$kbPublicId);$asset=sponsored_research_knowledge_asset($pdo,$assetPublicId);
    $case=sponsored_research_knowledge_submission_review($pdo,(int)$asset['submission_version_id']);if((int)$case['campaign_id']!==(int)$kb['campaign_id'])throw new RuntimeException('Knowledge promotion must remain inside the originating Sponsored Research campaign.');
    if((string)$asset['asset_type']==='source')throw new RuntimeException('Sources remain evidence lineage and cannot be promoted as standalone Knowledge items.');
    $content=sponsored_research_knowledge_item_body($asset);$evidence=sponsored_research_knowledge_evidence_sources($pdo,(int)$asset['submission_version_id']);
    $snapshot=['schema'=>'annotated-sponsored-knowledge-item-v1','source_submission_version_public_id'=>$asset['submission_version_public_id'],'submission_revision'=>(int)$asset['revision_number'],'source_asset_public_id'=>$asset['public_id'],'source_object'=>['type'=>$asset['asset_type'],'public_id'=>$asset['asset_public_id'],'version'=>$asset['asset_version'],'snapshot_hash'=>$asset['snapshot_hash']],'contributor_user_id'=>$asset['contributor_user_id']!==null?(int)$asset['contributor_user_id']:null,'content'=>['title'=>$content['title'],'body'=>$content['body']],'evidence_sources'=>$evidence];
    $snapJson=data_attribution_encode($snapshot);$snapHash=hash('sha256',$snapJson);$supersedesId=null;
    if($supersedesItemPublicId){$old=sponsored_research_knowledge_item_get($pdo,$supersedesItemPublicId);if(!$old||(int)$old['knowledge_base_id']!==(int)$kb['id'])throw new RuntimeException('Superseded Knowledge item is not part of this Knowledge Base.');if((string)$old['state']!=='active')throw new RuntimeException('Only an active Knowledge item can be corrected.');$supersedesId=(int)$old['id'];}
    $pdo->beginTransaction();try{
      $public=ulid_like();$pdo->prepare("INSERT INTO sponsored_research_knowledge_items(public_id,knowledge_base_id,source_submission_version_id,source_submission_asset_id,source_object_type,source_object_public_id,source_object_version,contributor_user_id,title,body,state,future_use_allowed,snapshot_json,snapshot_hash,source_rights_snapshot_json,source_rights_snapshot_hash,supersedes_item_id,promoted_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,'active',1,?,?,?,?,?,?)")
        ->execute([$public,(int)$kb['id'],(int)$asset['submission_version_id'],(int)$asset['id'],$asset['asset_type'],$asset['asset_public_id'],$asset['asset_version'],$asset['contributor_user_id'],$content['title'],$content['body'],$snapJson,$snapHash,$asset['source_rights_snapshot_json'],$asset['source_rights_snapshot_hash'],$supersedesId,(int)$viewer['id']]);
      $id=(int)$pdo->lastInsertId();
      if($supersedesId!==null){$pdo->prepare("UPDATE sponsored_research_knowledge_items SET state='corrected',future_use_allowed=0,corrected_at=NOW() WHERE id=?")->execute([$supersedesId]);sponsored_research_knowledge_event($pdo,(int)$kb['id'],$supersedesId,null,(int)$viewer['id'],'knowledge_item_corrected',['replacement_public_id'=>$public]);}
      data_provenance_edge_record($pdo,'sponsored_submission_asset',(string)$asset['public_id'],(string)$asset['asset_version'],'promoted_to','sponsored_knowledge_item',$public,null,(int)$viewer['id'],null,null,date('Y-m-d H:i:s'),['knowledge_base_public_id'=>$kb['public_id'],'review_case_public_id'=>$case['public_id']]);
      data_provenance_edge_record($pdo,'sponsored_submission_version',(string)$asset['submission_version_public_id'],(string)$asset['revision_number'],'promoted_to','sponsored_knowledge_item',$public,null,(int)$viewer['id']);
      if($asset['contributor_user_id']!==null)data_contribution_record($pdo,'user',(int)$asset['contributor_user_id'],'sponsored_knowledge_item',$public,'knowledge_promotion',$content['body'],['snapshot_hash'=>$snapHash],['source_object_type'=>$asset['asset_type'],'source_object_public_id'=>$asset['asset_public_id'],'knowledge_base_public_id'=>$kb['public_id']],null,null);
      sponsored_research_knowledge_event($pdo,(int)$kb['id'],$id,null,(int)$viewer['id'],$supersedesId?'knowledge_item_replacement_promoted':'knowledge_item_promoted',['source_asset_public_id'=>$asset['public_id'],'snapshot_hash'=>$snapHash,'evidence_source_count'=>count($evidence)]);
      $pdo->commit();return sponsored_research_knowledge_item_get($pdo,$public)??[];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sponsored_research_knowledge_item_revoke_rights(PDO $pdo,array $viewer,string $itemPublicId,string $reason): array {
    $item=sponsored_research_knowledge_item_get($pdo,$itemPublicId);if(!$item)throw new RuntimeException('Knowledge item not found.');$kb=sponsored_research_knowledge_item_authority($pdo,$viewer,$item,true);$reason=trim($reason);if($reason==='')throw new InvalidArgumentException('Rights revocation reason is required.');
    if((string)$item['state']==='rights_revoked')return $item;
    $pdo->prepare("UPDATE sponsored_research_knowledge_items SET state='rights_revoked',future_use_allowed=0,rights_revoked_at=NOW() WHERE id=?")->execute([(int)$item['id']]);
    sponsored_research_knowledge_event($pdo,(int)$kb['id'],(int)$item['id'],null,(int)$viewer['id'],'knowledge_item_rights_revoked',['reason'=>$reason,'historical_releases_preserved'=>true]);
    return sponsored_research_knowledge_item_get($pdo,$itemPublicId)??[];
}
function sponsored_research_knowledge_item_withdraw(PDO $pdo,array $viewer,string $itemPublicId,string $reason): array {
    $item=sponsored_research_knowledge_item_get($pdo,$itemPublicId);if(!$item)throw new RuntimeException('Knowledge item not found.');$kb=sponsored_research_knowledge_base_require_manage($pdo,$viewer,(string)$item['knowledge_base_public_id']);$reason=trim($reason);if($reason==='')throw new InvalidArgumentException('Withdrawal reason is required.');
    if((string)$item['state']==='withdrawn')return $item;
    $pdo->prepare("UPDATE sponsored_research_knowledge_items SET state='withdrawn',future_use_allowed=0,withdrawn_at=NOW() WHERE id=?")->execute([(int)$item['id']]);
    sponsored_research_knowledge_event($pdo,(int)$kb['id'],(int)$item['id'],null,(int)$viewer['id'],'knowledge_item_withdrawn',['reason'=>$reason,'historical_releases_preserved'=>true]);
    return sponsored_research_knowledge_item_get($pdo,$itemPublicId)??[];
}
function sponsored_research_knowledge_manifest(PDO $pdo,array $kb): array {
    $items=sponsored_research_knowledge_items($pdo,(string)$kb['public_id'],false);$manifestItems=[];
    foreach($items as $position=>$item){
      $snap=json_decode((string)$item['snapshot_json'],true)?:[];$sources=[];foreach((array)($snap['evidence_sources']??[]) as $source){$current=data_source_rights($pdo,(string)($source['source_public_id']??''));$source['release_rights']=$current;$source['release_rights_hash']=$current?data_attribution_hash($current):null;$sources[]=$source;}$manifestItems[]=['position'=>$position,'item_public_id'=>$item['public_id'],'source_object_type'=>$item['source_object_type'],'source_object_public_id'=>$item['source_object_public_id'],'source_object_version'=>$item['source_object_version'],'contributor_user_id'=>$item['contributor_user_id']!==null?(int)$item['contributor_user_id']:null,'snapshot_hash'=>$item['snapshot_hash'],'source_rights_snapshot_hash'=>$item['source_rights_snapshot_hash'],'item_state'=>$item['state'],'future_use_allowed'=>(bool)$item['future_use_allowed'],'evidence_sources'=>$sources];
    }
    return ['schema'=>'annotated-sponsored-knowledge-release-v1','knowledge_base'=>['public_id'=>$kb['public_id'],'campaign_public_id'=>$kb['campaign_public_id'],'account_public_id'=>$kb['account_public_id'],'research_agent_public_id'=>$kb['research_agent_public_id']??null,'name'=>$kb['name']],'item_count'=>count($manifestItems),'items'=>$manifestItems];
}
function sponsored_research_knowledge_release_create(PDO $pdo,array $viewer,string $kbPublicId,string $note=''): array {
    $kb=sponsored_research_knowledge_base_require_manage($pdo,$viewer,$kbPublicId);
    return app_with_advisory_lock($pdo,'sponsored-knowledge-release',(int)$kb['id'],function() use($pdo,$viewer,$kbPublicId,$note){
      $kb=sponsored_research_knowledge_base_require_manage($pdo,$viewer,$kbPublicId);$manifest=sponsored_research_knowledge_manifest($pdo,$kb);if((int)$manifest['item_count']===0)throw new RuntimeException('Knowledge Base release requires at least one active, future-usable Knowledge item.');
      foreach((array)$manifest['items'] as $item)foreach((array)($item['evidence_sources']??[]) as $source){$rights=$source['release_rights']??null;if(!is_array($rights)||empty($rights['retrieval_allowed']))throw new RuntimeException('Knowledge Base release is blocked until every evidence Source has current retrieval rights.');}
      $next=(int)$kb['current_release_number']+1;$manifest['release_number']=$next;$json=data_attribution_encode($manifest);$hash=hash('sha256',$json);
      $pdo->beginTransaction();try{
        $public=ulid_like();$pdo->prepare('INSERT INTO sponsored_research_knowledge_releases(public_id,knowledge_base_id,release_number,manifest_json,manifest_hash,item_count,release_note,released_by_user_id) VALUES(?,?,?,?,?,?,?,?)')
          ->execute([$public,(int)$kb['id'],$next,$json,$hash,(int)$manifest['item_count'],trim($note)!==''?mb_substr(trim($note),0,1000):null,(int)$viewer['id']]);$releaseId=(int)$pdo->lastInsertId();
        $items=sponsored_research_knowledge_items($pdo,$kbPublicId,false);foreach($items as $position=>$item)$pdo->prepare('INSERT INTO sponsored_research_knowledge_release_items(release_id,knowledge_item_id,position,item_public_id,source_object_type,source_object_public_id,source_object_version,contributor_user_id,snapshot_hash,source_rights_snapshot_hash,item_state,future_use_allowed) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$releaseId,(int)$item['id'],$position,$item['public_id'],$item['source_object_type'],$item['source_object_public_id'],$item['source_object_version'],$item['contributor_user_id'],$item['snapshot_hash'],$item['source_rights_snapshot_hash'],$item['state'],(int)$item['future_use_allowed']]);
        $pdo->prepare("UPDATE sponsored_research_knowledge_bases SET status='active',current_release_number=?,updated_at=NOW() WHERE id=?")->execute([$next,(int)$kb['id']]);
        data_provenance_edge_record($pdo,'sponsored_campaign',(string)$kb['campaign_public_id'],null,'published_as','sponsored_knowledge_release',$public,(string)$next,(int)$viewer['id']);
        foreach($items as $item)data_provenance_edge_record($pdo,'sponsored_knowledge_item',(string)$item['public_id'],null,'included_in','sponsored_knowledge_release',$public,(string)$next,(int)$viewer['id']);
        sponsored_research_knowledge_event($pdo,(int)$kb['id'],null,$releaseId,(int)$viewer['id'],'knowledge_release_created',['release_public_id'=>$public,'release_number'=>$next,'manifest_hash'=>$hash,'item_count'=>count($items)]);
        $pdo->commit();
      }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
      return sponsored_research_knowledge_release_get($pdo,$public)??[];
    },5);
}
function sponsored_research_knowledge_release_get(PDO $pdo,string $publicId): ?array {
    $q=$pdo->prepare("SELECT kr.*,kb.public_id knowledge_base_public_id,kb.name knowledge_base_name,c.public_id campaign_public_id FROM sponsored_research_knowledge_releases kr JOIN sponsored_research_knowledge_bases kb ON kb.id=kr.knowledge_base_id JOIN sponsored_research_campaigns c ON c.id=kb.campaign_id WHERE kr.public_id=? LIMIT 1");$q->execute([trim($publicId)]);return $q->fetch()?:null;
}
function sponsored_research_knowledge_releases(PDO $pdo,string $kbPublicId): array {
    $kb=sponsored_research_knowledge_base_get($pdo,$kbPublicId);if(!$kb)return [];$q=$pdo->prepare('SELECT * FROM sponsored_research_knowledge_releases WHERE knowledge_base_id=? ORDER BY release_number DESC');$q->execute([(int)$kb['id']]);return $q->fetchAll()?:[];
}
function sponsored_research_knowledge_release_items(PDO $pdo,int $releaseId): array {
    $q=$pdo->prepare('SELECT * FROM sponsored_research_knowledge_release_items WHERE release_id=? ORDER BY position,id');$q->execute([$releaseId]);return $q->fetchAll()?:[];
}
function sponsored_research_knowledge_release_current_use_status(PDO $pdo,string $releasePublicId): array {
    $release=sponsored_research_knowledge_release_get($pdo,$releasePublicId);if(!$release)return ['usable'=>false,'reason'=>'release_not_found','invalid_items'=>0];
    $items=sponsored_research_knowledge_release_items($pdo,(int)$release['id']);$manifest=json_decode((string)$release['manifest_json'],true)?:[];$manifestItems=[];foreach((array)($manifest['items']??[]) as $m)$manifestItems[(string)($m['item_public_id']??'')]=$m;$invalid=0;$reasons=[];
    foreach($items as $ri){
      $item=sponsored_research_knowledge_item_get($pdo,(string)$ri['item_public_id']);if(!$item||!in_array((string)$item['state'],['active'],true)||(int)$item['future_use_allowed']!==1){$invalid++;$reasons[]='knowledge_item_no_longer_eligible';continue;}
      if(!hash_equals((string)$ri['snapshot_hash'],(string)$item['snapshot_hash'])){$invalid++;$reasons[]='knowledge_item_snapshot_changed';}
      $m=$manifestItems[(string)$ri['item_public_id']]??[];foreach((array)($m['evidence_sources']??[]) as $source){
        $sourcePublic=(string)($source['source_public_id']??'');if($sourcePublic==='')continue;$rights=data_source_rights($pdo,$sourcePublic);$storedHash=(string)($source['release_rights_hash']??'');$currentHash=$rights?data_attribution_hash($rights):'';
        if(!$rights||empty($rights['retrieval_allowed'])){$invalid++;$reasons[]='source_rights_block_future_use';break;}
        if($storedHash===''||!hash_equals($storedHash,$currentHash)){$invalid++;$reasons[]='source_rights_changed_since_release';break;}
      }
    }
    $calc=hash('sha256',(string)$release['manifest_json']);$manifestOk=hash_equals((string)$release['manifest_hash'],$calc);if(!$manifestOk){$invalid++;$reasons[]='manifest_integrity_failure';}
    return ['usable'=>$invalid===0,'reason'=>$invalid===0?'current':($reasons[0]??'invalid'),'invalid_items'=>$invalid,'manifest_ok'=>$manifestOk,'reasons'=>array_values(array_unique($reasons))];
}
function sponsored_research_knowledge_events(PDO $pdo,string $kbPublicId,int $limit=100): array {
    $kb=sponsored_research_knowledge_base_get($pdo,$kbPublicId);if(!$kb)return [];$q=$pdo->prepare('SELECT e.*,u.display_name actor_name FROM sponsored_research_knowledge_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.knowledge_base_id=? ORDER BY e.id DESC LIMIT '.max(1,min(500,$limit)));$q->execute([(int)$kb['id']]);return $q->fetchAll()?:[];
}

function sponsored_research_knowledge_promotable_assets(PDO $pdo,array $viewer,string $campaignPublicId): array {
    $campaign=sponsored_research_campaign_require_manage($pdo,$viewer,$campaignPublicId);
    $q=$pdo->prepare("SELECT a.*,sv.public_id submission_version_public_id,sv.revision_number,s.public_id submission_public_id,s.title submission_title,u.display_name contributor_name,u.username contributor_username
      FROM sponsored_research_submission_assets a
      JOIN sponsored_research_submission_versions sv ON sv.id=a.submission_version_id
      JOIN sponsored_research_submissions s ON s.id=sv.submission_id
      JOIN sponsored_research_review_cases rc ON rc.submission_version_id=sv.id
      JOIN users u ON u.id=s.researcher_user_id
      WHERE s.campaign_id=? AND rc.final_decision='accepted' AND rc.status IN ('accepted','resolved') AND a.asset_type<>'source'
      ORDER BY sv.id,a.position,a.id");
    $q->execute([(int)$campaign['id']]);return $q->fetchAll()?:[];
}
function sponsored_research_knowledge_items_for_contributor(PDO $pdo,array $viewer,int $limit=200): array {
    research_account_require_approved($pdo,$viewer);$q=$pdo->prepare("SELECT ki.*,kb.public_id knowledge_base_public_id,kb.name knowledge_base_name,c.public_id campaign_public_id,c.title campaign_title FROM sponsored_research_knowledge_items ki JOIN sponsored_research_knowledge_bases kb ON kb.id=ki.knowledge_base_id JOIN sponsored_research_campaigns c ON c.id=kb.campaign_id WHERE ki.contributor_user_id=? ORDER BY ki.id DESC LIMIT ".max(1,min(500,$limit)));$q->execute([(int)$viewer['id']]);return $q->fetchAll()?:[];
}
