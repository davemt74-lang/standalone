<?php
declare(strict_types=1);

/**
 * Phase 37 — Annotated Data & Attribution Architecture v1
 *
 * Authoritative product objects remain the source of truth. This module stores:
 * - append-only contribution hashes/references
 * - provenance edges
 * - explicit contributor/source reuse permissions
 * - disposable derived corpus items
 * - AI response lineage for the exact Annotated object refs supplied to a model
 *
 * Public visibility never implies training permission.
 */

function data_attribution_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'data_contributions')&&installer_table_exists($pdo,'data_response_lineage');}
    catch(Throwable $e){return false;}
}
function data_attribution_canonicalize(mixed $value): mixed {
    if(!is_array($value))return $value;
    if(array_is_list($value))return array_map('data_attribution_canonicalize',$value);
    ksort($value,SORT_STRING);foreach($value as $k=>$v)$value[$k]=data_attribution_canonicalize($v);return $value;
}
function data_attribution_encode(mixed $value): string {
    $json=json_encode(data_attribution_canonicalize($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)throw new RuntimeException('Unable to encode Data & Attribution state.');
    return $json;
}
function data_attribution_hash(mixed $value): string {return hash('sha256',is_string($value)?$value:data_attribution_encode($value));}
function data_attribution_bool(mixed $value): int {return in_array($value,[1,'1',true,'true','yes','on'],true)?1:0;}

function data_contributor_preferences(PDO $pdo,int $userId): array {
    if(!data_attribution_ready($pdo))return ['allow_shared_retrieval'=>0,'allow_evaluation'=>0,'allow_training'=>0,'allow_commercial_training'=>0,'attribution_required'=>1];
    $pdo->prepare('INSERT IGNORE INTO data_contributor_preferences(user_id) VALUES(?)')->execute([$userId]);
    $q=$pdo->prepare('SELECT * FROM data_contributor_preferences WHERE user_id=?');$q->execute([$userId]);
    return $q->fetch()?:['user_id'=>$userId,'allow_shared_retrieval'=>0,'allow_evaluation'=>0,'allow_training'=>0,'allow_commercial_training'=>0,'attribution_required'=>1];
}
function data_contributor_preferences_update(PDO $pdo,array $viewer,array $input): array {
    if(!data_attribution_ready($pdo))throw new RuntimeException('Data & Attribution requires the Phase 37 database upgrade.');
    $shared=data_attribution_bool($input['allow_shared_retrieval']??0);
    $evaluation=data_attribution_bool($input['allow_evaluation']??0);
    $training=data_attribution_bool($input['allow_training']??0);
    $commercial=data_attribution_bool($input['allow_commercial_training']??0);
    $attribution=array_key_exists('attribution_required',$input)?data_attribution_bool($input['attribution_required']):1;
    if($commercial&&!$training)throw new InvalidArgumentException('Commercial training permission requires training permission.');
    $pdo->prepare('INSERT INTO data_contributor_preferences(user_id,allow_shared_retrieval,allow_evaluation,allow_training,allow_commercial_training,attribution_required) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE allow_shared_retrieval=VALUES(allow_shared_retrieval),allow_evaluation=VALUES(allow_evaluation),allow_training=VALUES(allow_training),allow_commercial_training=VALUES(allow_commercial_training),attribution_required=VALUES(attribution_required)')
        ->execute([$viewer['id'],$shared,$evaluation,$training,$commercial,$attribution]);
    $pdo->prepare("UPDATE data_corpus_items SET invalidated_at=COALESCE(invalidated_at,NOW()),shared_retrieval_eligible=0,evaluation_eligible=0,training_eligible=0,commercial_training_eligible=0,eligibility_reason='contributor_policy_changed',refreshed_at=NOW() WHERE contributor_user_id=? AND invalidated_at IS NULL")->execute([$viewer['id']]);
    if($shared||$evaluation||$training)data_refresh_user_corpus($pdo,$viewer,500);
    return data_contributor_preferences($pdo,(int)$viewer['id']);
}

function data_usage_grant(PDO $pdo,string $objectType,string $objectPublicId,int $grantorUserId): ?array {
    if(!data_attribution_ready($pdo))return null;
    $q=$pdo->prepare('SELECT * FROM data_usage_grants WHERE object_type=? AND object_public_id=? AND grantor_user_id=? AND revoked_at IS NULL LIMIT 1');
    $q->execute([trim($objectType),trim($objectPublicId),$grantorUserId]);return $q->fetch()?:null;
}
function data_usage_grant_set(PDO $pdo,array $viewer,string $objectType,string $objectPublicId,array $input): array {
    if(!data_attribution_ready($pdo))throw new RuntimeException('Data & Attribution requires the Phase 37 database upgrade.');
    $descriptor=data_object_descriptor($pdo,$objectType,$objectPublicId);
    if(!$descriptor)throw new RuntimeException('Contribution object is unavailable.');
    if(($viewer['role']??'')!=='admin'&&(int)($descriptor['contributor_user_id']??0)!==(int)$viewer['id'])throw new RuntimeException('Only the contributor can change reuse permission for this object.');
    if(empty($descriptor['contributor_user_id']))throw new RuntimeException('This object uses source-rights governance instead of contributor grants.');
    $shared=data_attribution_bool($input['shared_retrieval_allowed']??0);
    $evaluation=data_attribution_bool($input['evaluation_allowed']??0);
    $training=data_attribution_bool($input['training_allowed']??0);
    $commercial=data_attribution_bool($input['commercial_training_allowed']??0);
    $attribution=array_key_exists('attribution_required',$input)?data_attribution_bool($input['attribution_required']):1;
    if($commercial&&!$training)throw new InvalidArgumentException('Commercial training permission requires training permission.');
    $license=mb_substr(trim((string)($input['license_code']??'')),0,80)?:null;
    $public=ulid_like();
    $pdo->prepare('INSERT INTO data_usage_grants(public_id,object_type,object_public_id,grantor_user_id,shared_retrieval_allowed,evaluation_allowed,training_allowed,commercial_training_allowed,attribution_required,license_code,revoked_at) VALUES(?,?,?,?,?,?,?,?,?,?,NULL) ON DUPLICATE KEY UPDATE shared_retrieval_allowed=VALUES(shared_retrieval_allowed),evaluation_allowed=VALUES(evaluation_allowed),training_allowed=VALUES(training_allowed),commercial_training_allowed=VALUES(commercial_training_allowed),attribution_required=VALUES(attribution_required),license_code=VALUES(license_code),granted_at=NOW(),revoked_at=NULL,updated_at=NOW()')
        ->execute([$public,$objectType,$objectPublicId,$descriptor['contributor_user_id'],$shared,$evaluation,$training,$commercial,$attribution,$license]);
    data_corpus_refresh_object($pdo,$objectType,$objectPublicId);
    return data_usage_grant($pdo,$objectType,$objectPublicId,(int)$descriptor['contributor_user_id'])??[];
}
function data_usage_grant_revoke(PDO $pdo,array $viewer,string $objectType,string $objectPublicId): bool {
    $descriptor=data_object_descriptor($pdo,$objectType,$objectPublicId);if(!$descriptor)return false;
    if(($viewer['role']??'')!=='admin'&&(int)($descriptor['contributor_user_id']??0)!==(int)$viewer['id'])throw new RuntimeException('Only the contributor can revoke reuse permission for this object.');
    $q=$pdo->prepare('UPDATE data_usage_grants SET revoked_at=NOW(),updated_at=NOW() WHERE object_type=? AND object_public_id=? AND grantor_user_id=? AND revoked_at IS NULL');
    $q->execute([$objectType,$objectPublicId,$descriptor['contributor_user_id']]);data_corpus_refresh_object($pdo,$objectType,$objectPublicId);return $q->rowCount()>0;
}

function data_source_rights(PDO $pdo,string $sourcePublicId): ?array {
    if(!data_attribution_ready($pdo))return null;
    $q=$pdo->prepare('SELECT s.public_id source_public_id,s.title,s.canonical_url,s.domain,sr.* FROM sources s LEFT JOIN source_rights sr ON sr.source_id=s.id WHERE s.public_id=? LIMIT 1');
    $q->execute([trim($sourcePublicId)]);$r=$q->fetch();if(!$r)return null;
    if($r['rights_class']===null)$r=array_merge($r,['rights_class'=>'unknown','retrieval_allowed'=>0,'excerpt_storage_allowed'=>0,'model_context_allowed'=>0,'training_allowed'=>0,'commercial_training_allowed'=>0,'review_status'=>'unreviewed']);
    return $r;
}
function data_source_rights_set(PDO $pdo,array $viewer,string $sourcePublicId,array $input): array {
    if(($viewer['role']??'')!=='admin')throw new RuntimeException('Administrator access is required to classify source rights.');
    $q=$pdo->prepare('SELECT id FROM sources WHERE public_id=? LIMIT 1');$q->execute([trim($sourcePublicId)]);$sourceId=(int)($q->fetchColumn()?:0);if(!$sourceId)throw new RuntimeException('Source not found.');
    $classes=['unknown','user_owned','licensed','public_domain','open_license','permission_granted','restricted'];
    $class=strtolower(trim((string)($input['rights_class']??'unknown')));if(!in_array($class,$classes,true))throw new InvalidArgumentException('Invalid source rights class.');
    $retrieval=data_attribution_bool($input['retrieval_allowed']??0);$storage=data_attribution_bool($input['excerpt_storage_allowed']??0);$context=data_attribution_bool($input['model_context_allowed']??0);$training=data_attribution_bool($input['training_allowed']??0);$commercial=data_attribution_bool($input['commercial_training_allowed']??0);
    if(in_array($class,['unknown','restricted'],true))$retrieval=$storage=$context=$training=$commercial=0;
    if($training&&!$storage)throw new InvalidArgumentException('Training permission requires excerpt storage permission.');
    if($commercial&&!$training)throw new InvalidArgumentException('Commercial training permission requires training permission.');
    $license=mb_substr(trim((string)($input['license_code']??'')),0,80)?:null;$holder=mb_substr(trim((string)($input['rights_holder']??'')),0,255)?:null;$policy=trim((string)($input['policy_url']??''));if($policy!==''&&!filter_var($policy,FILTER_VALIDATE_URL))throw new InvalidArgumentException('Policy URL is invalid.');$policy=$policy?:null;
    $reviewStatus=$class==='unknown'?'unreviewed':'reviewed';
    $pdo->prepare('INSERT INTO source_rights(source_id,rights_class,retrieval_allowed,excerpt_storage_allowed,model_context_allowed,training_allowed,commercial_training_allowed,license_code,rights_holder,policy_url,review_status,reviewed_by_user_id,reviewed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE rights_class=VALUES(rights_class),retrieval_allowed=VALUES(retrieval_allowed),excerpt_storage_allowed=VALUES(excerpt_storage_allowed),model_context_allowed=VALUES(model_context_allowed),training_allowed=VALUES(training_allowed),commercial_training_allowed=VALUES(commercial_training_allowed),license_code=VALUES(license_code),rights_holder=VALUES(rights_holder),policy_url=VALUES(policy_url),review_status=VALUES(review_status),reviewed_by_user_id=VALUES(reviewed_by_user_id),reviewed_at=NOW(),updated_at=NOW()')
        ->execute([$sourceId,$class,$retrieval,$storage,$context,$training,$commercial,$license,$holder,$policy,$reviewStatus,$viewer['id']]);
    data_corpus_refresh_object($pdo,'source',$sourcePublicId);
    return data_source_rights($pdo,$sourcePublicId)??[];
}

function data_contribution_record(PDO $pdo,string $actorType,?int $actorUserId,string $objectType,string $objectPublicId,string $contributionType,string $content,array $state=[],array $metadata=[],?int $aiRunId=null,?string $objectVersionId=null): ?array {
    if(!data_attribution_ready($pdo))return null;
    $actorType=in_array($actorType,['user','agent','system','external'],true)?$actorType:'system';$objectType=trim($objectType);$objectPublicId=trim($objectPublicId);$objectVersionId=trim((string)$objectVersionId);
    if($objectType===''||$objectPublicId===''||$contributionType==='')return null;
    $contentHash=hash('sha256',$content);$stateHash=data_attribution_hash($state);$dedupe=hash('sha256',implode('|',[$actorType,(string)$actorUserId,(string)$aiRunId,$objectType,$objectPublicId,$objectVersionId,$contributionType,$contentHash,$stateHash]));
    $public=ulid_like();$meta=$metadata?data_attribution_encode($metadata):null;
    $pdo->prepare('INSERT IGNORE INTO data_contributions(public_id,actor_type,actor_user_id,ai_run_id,object_type,object_public_id,object_version_id,contribution_type,content_hash,state_hash,metadata_json,dedupe_key) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$public,$actorType,$actorUserId,$aiRunId,$objectType,$objectPublicId,$objectVersionId?:null,$contributionType,$contentHash,$stateHash,$meta,$dedupe]);
    $q=$pdo->prepare('SELECT * FROM data_contributions WHERE dedupe_key=? LIMIT 1');$q->execute([$dedupe]);$row=$q->fetch();if(!$row)return null;
    $pdo->prepare('UPDATE data_contributions SET superseded_at=COALESCE(superseded_at,NOW()) WHERE object_type=? AND object_public_id=? AND contribution_type=? AND id<>? AND superseded_at IS NULL AND revoked_at IS NULL')
        ->execute([$objectType,$objectPublicId,$contributionType,$row['id']]);
    return $row;
}
function data_provenance_edge_record(PDO $pdo,string $fromType,string $fromPublicId,?string $fromVersion,string $relationship,string $toType,string $toPublicId,?string $toVersion=null,?int $createdByUserId=null,?int $aiRunId=null,?float $confidence=null,?string $humanConfirmedAt=null,array $metadata=[]): ?array {
    if(!data_attribution_ready($pdo))return null;
    $fromType=trim($fromType);$fromPublicId=trim($fromPublicId);$relationship=trim($relationship);$toType=trim($toType);$toPublicId=trim($toPublicId);$fromVersion=trim((string)$fromVersion);$toVersion=trim((string)$toVersion);
    if($fromType===''||$fromPublicId===''||$relationship===''||$toType===''||$toPublicId==='')return null;
    $confidence=$confidence===null?null:max(0.0,min(1.0,$confidence));$dedupe=hash('sha256',implode('|',[$fromType,$fromPublicId,$fromVersion,$relationship,$toType,$toPublicId,$toVersion,(string)$createdByUserId,(string)$aiRunId]));
    $public=ulid_like();$pdo->prepare('INSERT IGNORE INTO data_provenance_edges(public_id,from_type,from_public_id,from_version_id,relationship,to_type,to_public_id,to_version_id,created_by_user_id,ai_run_id,confidence,human_confirmed_at,metadata_json,dedupe_key) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$public,$fromType,$fromPublicId,$fromVersion?:null,$relationship,$toType,$toPublicId,$toVersion?:null,$createdByUserId,$aiRunId,$confidence,$humanConfirmedAt,$metadata?data_attribution_encode($metadata):null,$dedupe]);
    $q=$pdo->prepare('SELECT * FROM data_provenance_edges WHERE dedupe_key=? LIMIT 1');$q->execute([$dedupe]);return $q->fetch()?:null;
}

function data_object_descriptor(PDO $pdo,string $objectType,string $publicId): ?array {
    $objectType=strtolower(trim($objectType));$publicId=trim($publicId);if($publicId==='')return null;
    if($objectType==='annotation'){
        $q=$pdo->prepare('SELECT a.public_id,a.user_id contributor_user_id,a.visibility,a.status,a.text_commentary,s.public_id source_public_id,sv.version_number source_version_number FROM annotations a JOIN sources s ON s.id=a.source_id JOIN source_versions sv ON sv.id=a.source_version_id WHERE a.public_id=? LIMIT 1');$q->execute([$publicId]);$r=$q->fetch();if(!$r)return null;
        return ['object_type'=>'annotation','public_id'=>$publicId,'version'=>'','contributor_user_id'=>(int)$r['contributor_user_id'],'visibility'=>$r['visibility'],'published'=>$r['status']==='published','text'=>trim((string)$r['text_commentary']),'corpus_type'=>'annotation_commentary','state'=>['visibility'=>$r['visibility'],'status'=>$r['status'],'source_id'=>$r['source_public_id'],'source_version'=>(int)$r['source_version_number']],'meta'=>['source_public_id'=>$r['source_public_id'],'source_version_number'=>(int)$r['source_version_number']]];
    }
    if($objectType==='claim'){
        $q=$pdo->prepare('SELECT rc.public_id,rc.created_by_user_id contributor_user_id,rc.statement,rc.claim_type,rc.status,rp.public_id project_public_id FROM research_claims rc JOIN research_projects rp ON rp.id=rc.project_id WHERE rc.public_id=? LIMIT 1');$q->execute([$publicId]);$r=$q->fetch();if(!$r)return null;
        return ['object_type'=>'claim','public_id'=>$publicId,'version'=>'','contributor_user_id'=>(int)$r['contributor_user_id'],'visibility'=>'research','published'=>false,'text'=>trim((string)$r['statement']),'corpus_type'=>'claim','state'=>['claim_type'=>$r['claim_type'],'status'=>$r['status'],'project_public_id'=>$r['project_public_id']],'meta'=>['project_public_id'=>$r['project_public_id']]];
    }
    if($objectType==='finding'){
        $q=$pdo->prepare('SELECT rf.public_id,rf.created_by_user_id contributor_user_id,rf.title,rf.summary,rf.status,rp.public_id project_public_id FROM research_findings rf JOIN research_projects rp ON rp.id=rf.project_id WHERE rf.public_id=? LIMIT 1');$q->execute([$publicId]);$r=$q->fetch();if(!$r)return null;
        return ['object_type'=>'finding','public_id'=>$publicId,'version'=>'','contributor_user_id'=>(int)$r['contributor_user_id'],'visibility'=>'research','published'=>false,'text'=>trim((string)$r['title'])."\n\n".trim((string)$r['summary']),'corpus_type'=>'finding','state'=>['status'=>$r['status'],'project_public_id'=>$r['project_public_id']],'meta'=>['project_public_id'=>$r['project_public_id']]];
    }
    if(in_array($objectType,['report_version','research_report_version'],true)){
        $q=$pdo->prepare('SELECT rv.public_id,rv.version_number,rv.published_by_user_id contributor_user_id,rv.visibility,rv.title,rv.summary,rr.public_id report_public_id,rr.status report_status,rp.public_id project_public_id FROM research_report_versions rv JOIN research_reports rr ON rr.id=rv.report_id JOIN research_projects rp ON rp.id=rr.project_id WHERE rv.public_id=? LIMIT 1');$q->execute([$publicId]);$r=$q->fetch();if(!$r)return null;
        return ['object_type'=>'report_version','public_id'=>$publicId,'version'=>(string)$r['version_number'],'contributor_user_id'=>(int)$r['contributor_user_id'],'visibility'=>$r['visibility'],'published'=>$r['report_status']==='published','text'=>trim((string)$r['title']).($r['summary']!==null?"\n\n".trim((string)$r['summary']):''),'corpus_type'=>'report_summary','state'=>['visibility'=>$r['visibility'],'report_status'=>$r['report_status'],'report_public_id'=>$r['report_public_id'],'project_public_id'=>$r['project_public_id'],'version_number'=>(int)$r['version_number']],'meta'=>['report_public_id'=>$r['report_public_id'],'project_public_id'=>$r['project_public_id'],'version_number'=>(int)$r['version_number']]];
    }
    if($objectType==='source'){
        $q=$pdo->prepare('SELECT s.public_id,s.title,s.canonical_url,s.domain,s.status,sv.version_number,sv.extracted_text,sv.content_hash FROM sources s LEFT JOIN source_versions sv ON sv.id=s.current_version_id WHERE s.public_id=? LIMIT 1');$q->execute([$publicId]);$r=$q->fetch();if(!$r)return null;
        return ['object_type'=>'source','public_id'=>$publicId,'version'=>(string)($r['version_number']??''),'contributor_user_id'=>null,'visibility'=>'source_rights','published'=>($r['status']??'current')!=='restricted','text'=>mb_substr(trim((string)($r['extracted_text']??'')),0,50000),'corpus_type'=>'licensed_source_text','state'=>['status'=>$r['status'],'content_hash'=>$r['content_hash'],'version_number'=>$r['version_number']!==null?(int)$r['version_number']:null],'meta'=>['title'=>$r['title'],'url'=>$r['canonical_url'],'domain'=>$r['domain']]];
    }
    return null;
}

function data_object_provenance_hash(PDO $pdo,string $objectType,string $publicId): string {
    if(!data_attribution_ready($pdo))return data_attribution_hash([$objectType,$publicId]);
    $q=$pdo->prepare('SELECT from_type,from_public_id,COALESCE(from_version_id,"") from_version_id,relationship,to_type,to_public_id,COALESCE(to_version_id,"") to_version_id FROM data_provenance_edges WHERE revoked_at IS NULL AND ((from_type=? AND from_public_id=?) OR (to_type=? AND to_public_id=?)) ORDER BY from_type,from_public_id,relationship,to_type,to_public_id,id');
    $q->execute([$objectType,$publicId,$objectType,$publicId]);return data_attribution_hash(['object'=>[$objectType,$publicId],'edges'=>$q->fetchAll()]);
}
function data_training_eligibility(PDO $pdo,string $objectType,string $publicId): array {
    $d=data_object_descriptor($pdo,$objectType,$publicId);$base=['shared_retrieval'=>false,'evaluation'=>false,'training'=>false,'commercial_training'=>false,'attribution_required'=>true,'reason'=>'object_unavailable'];
    if(!$d)return $base;
    if(trim((string)$d['text'])==='')return array_merge($base,['reason'=>'no_reusable_text']);
    if($d['object_type']==='source'){
        $rights=data_source_rights($pdo,$publicId);if(!$rights)return array_merge($base,['reason'=>'source_rights_unknown']);
        $reviewed=(string)($rights['review_status']??'unreviewed')==='reviewed'&&!in_array((string)($rights['rights_class']??'unknown'),['unknown','restricted'],true);
        if(!$reviewed)return array_merge($base,['reason'=>'source_rights_not_approved']);
        $shared=$reviewed&&(bool)$rights['retrieval_allowed']&&(bool)$rights['excerpt_storage_allowed']&&(bool)$rights['model_context_allowed'];$training=$shared&&(bool)$rights['training_allowed'];$commercial=$training&&(bool)$rights['commercial_training_allowed'];
        return ['shared_retrieval'=>$shared,'evaluation'=>$training,'training'=>$training,'commercial_training'=>$commercial,'attribution_required'=>true,'reason'=>'source_rights_approved'];
    }
    if(($d['visibility']??'')!=='public'||empty($d['published']))return array_merge($base,['reason'=>'object_not_public']);
    $uid=(int)($d['contributor_user_id']??0);if(!$uid)return array_merge($base,['reason'=>'contributor_unavailable']);
    $grant=data_usage_grant($pdo,$d['object_type'],$publicId,$uid);$policy=$grant?:data_contributor_preferences($pdo,$uid);
    $shared=(bool)($grant['shared_retrieval_allowed']??$policy['allow_shared_retrieval']??0);$evaluation=(bool)($grant['evaluation_allowed']??$policy['allow_evaluation']??0);$training=(bool)($grant['training_allowed']??$policy['allow_training']??0);$commercial=$training&&(bool)($grant['commercial_training_allowed']??$policy['allow_commercial_training']??0);$attribution=(bool)($grant['attribution_required']??$policy['attribution_required']??1);
    return ['shared_retrieval'=>$shared,'evaluation'=>$evaluation,'training'=>$training,'commercial_training'=>$commercial,'attribution_required'=>$attribution,'reason'=>$grant?'explicit_object_grant':(($shared||$evaluation||$training)?'contributor_opt_in':'no_contributor_consent')];
}
function data_corpus_refresh_object(PDO $pdo,string $objectType,string $publicId): array {
    if(!data_attribution_ready($pdo))return ['active'=>false,'reason'=>'upgrade_required'];
    $d=data_object_descriptor($pdo,$objectType,$publicId);if(!$d)return ['active'=>false,'reason'=>'object_unavailable'];
    $e=data_training_eligibility($pdo,$objectType,$publicId);$active=$e['shared_retrieval']||$e['evaluation']||$e['training'];
    if(!$active||trim((string)$d['text'])===''){
        $pdo->prepare('UPDATE data_corpus_items SET invalidated_at=COALESCE(invalidated_at,NOW()),shared_retrieval_eligible=0,evaluation_eligible=0,training_eligible=0,commercial_training_eligible=0,eligibility_reason=?,refreshed_at=NOW() WHERE source_object_type=? AND source_object_public_id=?')
            ->execute([$e['reason'],$d['object_type'],$publicId]);
        return ['active'=>false,'reason'=>$e['reason'],'eligibility'=>$e];
    }
    $version=(string)($d['version']??'');$text=mb_substr(trim((string)$d['text']),0,50000);$contentHash=hash('sha256',$text);$provHash=data_object_provenance_hash($pdo,$d['object_type'],$publicId);$public=ulid_like();$meta=array_merge((array)($d['meta']??[]),['eligibility_source'=>$e['reason']]);
    $pdo->prepare('INSERT INTO data_corpus_items(public_id,source_object_type,source_object_public_id,source_object_version,contributor_user_id,corpus_type,normalized_text,metadata_json,content_hash,provenance_hash,shared_retrieval_eligible,evaluation_eligible,training_eligible,commercial_training_eligible,attribution_required,eligibility_reason,invalidated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL) ON DUPLICATE KEY UPDATE contributor_user_id=VALUES(contributor_user_id),corpus_type=VALUES(corpus_type),normalized_text=VALUES(normalized_text),metadata_json=VALUES(metadata_json),content_hash=VALUES(content_hash),provenance_hash=VALUES(provenance_hash),shared_retrieval_eligible=VALUES(shared_retrieval_eligible),evaluation_eligible=VALUES(evaluation_eligible),training_eligible=VALUES(training_eligible),commercial_training_eligible=VALUES(commercial_training_eligible),attribution_required=VALUES(attribution_required),eligibility_reason=VALUES(eligibility_reason),refreshed_at=NOW(),invalidated_at=NULL')
        ->execute([$public,$d['object_type'],$publicId,$version,(int)($d['contributor_user_id']??0)?:null,$d['corpus_type'],$text,data_attribution_encode($meta),$contentHash,$provHash,$e['shared_retrieval']?1:0,$e['evaluation']?1:0,$e['training']?1:0,$e['commercial_training']?1:0,$e['attribution_required']?1:0,$e['reason']]);
    return ['active'=>true,'reason'=>$e['reason'],'eligibility'=>$e,'content_hash'=>$contentHash,'provenance_hash'=>$provHash];
}

function data_attribution_capture_object(PDO $pdo,int $actorUserId,string $objectType,string $publicId): ?array {
    if(!data_attribution_ready($pdo))return null;$d=data_object_descriptor($pdo,$objectType,$publicId);if(!$d)return null;
    $type=$d['object_type'];$contributionType=match($type){'annotation'=>'annotation','claim'=>'claim','finding'=>'finding','report_version'=>'publication',default=>'contribution'};
    $row=data_contribution_record($pdo,'user',$actorUserId,$type,$publicId,$contributionType,(string)$d['text'],(array)$d['state'],(array)$d['meta'],null,(string)($d['version']??''));
    if($type==='annotation'&&!empty($d['meta']['source_public_id']))data_provenance_edge_record($pdo,'source',(string)$d['meta']['source_public_id'],(string)($d['meta']['source_version_number']??''),'captured_as','annotation',$publicId,null,$actorUserId);
    if($type==='claim'){
        $q=$pdo->prepare('SELECT ce.relationship,a.public_id annotation_public_id,s.public_id source_public_id,sv.version_number FROM research_claims rc JOIN claim_evidence ce ON ce.claim_id=rc.id JOIN source_versions sv ON sv.id=ce.source_version_id JOIN sources s ON s.id=sv.source_id LEFT JOIN annotations a ON a.id=ce.annotation_id WHERE rc.public_id=? ORDER BY ce.id');$q->execute([$publicId]);
        foreach($q->fetchAll() as $e){if(!empty($e['annotation_public_id']))data_provenance_edge_record($pdo,'annotation',$e['annotation_public_id'],null,(string)$e['relationship'],'claim',$publicId,null,$actorUserId);data_provenance_edge_record($pdo,'source',$e['source_public_id'],(string)$e['version_number'],(string)$e['relationship'],'claim',$publicId,null,$actorUserId);}
    }
    if($type==='finding'){
        $q=$pdo->prepare('SELECT rc.public_id,fc.relationship FROM research_findings rf JOIN finding_claims fc ON fc.finding_id=rf.id JOIN research_claims rc ON rc.id=fc.claim_id WHERE rf.public_id=? ORDER BY fc.position,fc.claim_id');$q->execute([$publicId]);foreach($q->fetchAll() as $e)data_provenance_edge_record($pdo,'claim',$e['public_id'],null,(string)$e['relationship'],'finding',$publicId,null,$actorUserId);
    }
    if($type==='report_version'){
        if(!empty($d['meta']['project_public_id']))data_provenance_edge_record($pdo,'research_project',$d['meta']['project_public_id'],null,'published_as','report_version',$publicId,(string)$d['version'],$actorUserId);
        $q=$pdo->prepare('SELECT snapshot_json FROM research_report_versions WHERE public_id=? LIMIT 1');$q->execute([$publicId]);$snapshot=json_decode((string)($q->fetchColumn()?:''),true);if(is_array($snapshot)){foreach((array)($snapshot['claims']??[]) as $x)if(!empty($x['id']))data_provenance_edge_record($pdo,'claim',(string)$x['id'],null,'included_in','report_version',$publicId,(string)$d['version'],$actorUserId);foreach((array)($snapshot['findings']??[]) as $x)if(!empty($x['id']))data_provenance_edge_record($pdo,'finding',(string)$x['id'],null,'included_in','report_version',$publicId,(string)$d['version'],$actorUserId);}
    }
    if(in_array($type,['annotation','report_version'],true))data_corpus_refresh_object($pdo,$type,$publicId);
    return $row;
}
function data_attribution_capture_verification(PDO $pdo,int $reviewerUserId,string $verificationPublicId): ?array {
    if(!data_attribution_ready($pdo))return null;$q=$pdo->prepare('SELECT public_id,subject_type,subject_public_id,subject_hash,decision,evidence_state_hash,note,created_at FROM research_verification_events WHERE public_id=? AND reviewer_user_id=? LIMIT 1');$q->execute([$verificationPublicId,$reviewerUserId]);$r=$q->fetch();if(!$r)return null;
    $row=data_contribution_record($pdo,'user',$reviewerUserId,'verification',$r['public_id'],'verification',(string)$r['decision']."\n".(string)$r['note'],['subject_hash'=>$r['subject_hash'],'evidence_state_hash'=>$r['evidence_state_hash']],['subject_type'=>$r['subject_type'],'subject_public_id'=>$r['subject_public_id']]);
    data_provenance_edge_record($pdo,(string)$r['subject_type'],(string)$r['subject_public_id'],null,'verified_by','verification',(string)$r['public_id'],null,$reviewerUserId,null,null,(string)$r['created_at'],['decision'=>$r['decision'],'evidence_state_hash'=>$r['evidence_state_hash']]);return $row;
}
function data_attribution_capture_review_response(PDO $pdo,int $reviewerUserId,string $responsePublicId): ?array {
    if(!data_attribution_ready($pdo))return null;$q=$pdo->prepare('SELECT rrr.public_id,rrr.decision,rrr.comment,rrr.subject_hash,rr.subject_type,rr.subject_public_id FROM research_review_responses rrr JOIN research_reviews rr ON rr.id=rrr.review_id WHERE rrr.public_id=? AND rrr.reviewer_user_id=? LIMIT 1');$q->execute([$responsePublicId,$reviewerUserId]);$r=$q->fetch();if(!$r)return null;
    $row=data_contribution_record($pdo,'user',$reviewerUserId,'review_response',$r['public_id'],'review',(string)$r['decision']."\n".(string)$r['comment'],['subject_hash'=>$r['subject_hash']],['subject_type'=>$r['subject_type'],'subject_public_id'=>$r['subject_public_id']]);
    data_provenance_edge_record($pdo,(string)$r['subject_type'],(string)$r['subject_public_id'],null,'reviewed_by','review_response',(string)$r['public_id'],null,$reviewerUserId);return $row;
}

function data_attribution_sync_user(PDO $pdo,array $viewer,int $limit=500): array {
    if(!data_attribution_ready($pdo))return ['ready'=>false,'captured'=>0,'corpus'=>0];$uid=(int)$viewer['id'];$limit=max(1,min(1000,$limit));$captured=0;
    $queries=[
        ['annotation','SELECT public_id FROM annotations WHERE user_id=? ORDER BY id DESC LIMIT '.$limit],
        ['claim','SELECT public_id FROM research_claims WHERE created_by_user_id=? ORDER BY id DESC LIMIT '.$limit],
        ['finding','SELECT public_id FROM research_findings WHERE created_by_user_id=? ORDER BY id DESC LIMIT '.$limit],
        ['report_version','SELECT public_id FROM research_report_versions WHERE published_by_user_id=? ORDER BY id DESC LIMIT '.$limit],
    ];
    foreach($queries as [$type,$sql]){$q=$pdo->prepare($sql);$q->execute([$uid]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)if(data_attribution_capture_object($pdo,$uid,$type,(string)$id))$captured++;}
    $q=$pdo->prepare('SELECT public_id FROM research_verification_events WHERE reviewer_user_id=? ORDER BY id DESC LIMIT '.$limit);$q->execute([$uid]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)if(data_attribution_capture_verification($pdo,$uid,(string)$id))$captured++;
    $q=$pdo->prepare('SELECT public_id FROM research_review_responses WHERE reviewer_user_id=? ORDER BY id DESC LIMIT '.$limit);$q->execute([$uid]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)if(data_attribution_capture_review_response($pdo,$uid,(string)$id))$captured++;
    $q=$pdo->prepare('SELECT COUNT(*) FROM data_corpus_items WHERE contributor_user_id=? AND invalidated_at IS NULL');$q->execute([$uid]);$corpus=(int)$q->fetchColumn();
    return ['ready'=>true,'captured'=>$captured,'corpus'=>$corpus];
}
function data_refresh_user_corpus(PDO $pdo,array $viewer,int $limit=500): int {
    if(!data_attribution_ready($pdo))return 0;$uid=(int)$viewer['id'];$limit=max(1,min(1000,$limit));$count=0;
    $q=$pdo->prepare('SELECT public_id FROM annotations WHERE user_id=? ORDER BY id DESC LIMIT '.$limit);$q->execute([$uid]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){data_corpus_refresh_object($pdo,'annotation',(string)$id);$count++;}
    $q=$pdo->prepare('SELECT public_id FROM research_report_versions WHERE published_by_user_id=? ORDER BY id DESC LIMIT '.$limit);$q->execute([$uid]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){data_corpus_refresh_object($pdo,'report_version',(string)$id);$count++;}
    return $count;
}

function data_ref_normalize(array $ref): ?array {
    $type=strtolower(trim((string)($ref['type']??'')));$id=trim((string)($ref['id']??$ref['public_id']??''));if($id==='')return null;
    if($type==='research')$type='research_project';if($type==='report')$type='report_version';
    if(!preg_match('/^[a-z0-9_\-]{2,64}$/',$type))return null;
    return ['type'=>$type,'id'=>$id,'version'=>trim((string)($ref['version']??''))];
}
function data_ref_contributor(PDO $pdo,string $type,string $id): ?int {
    try{
        $sql=match($type){
            'annotation'=>'SELECT user_id FROM annotations WHERE public_id=?',
            'claim'=>'SELECT created_by_user_id FROM research_claims WHERE public_id=?',
            'finding'=>'SELECT created_by_user_id FROM research_findings WHERE public_id=?',
            'report_version'=>'SELECT published_by_user_id FROM research_report_versions WHERE public_id=?',
            'research_review'=>'SELECT requested_by_user_id FROM research_reviews WHERE public_id=?',
            'verification'=>'SELECT reviewer_user_id FROM research_verification_events WHERE public_id=?',
            default=>null
        };if(!$sql)return null;$q=$pdo->prepare($sql);$q->execute([$id]);$uid=(int)($q->fetchColumn()?:0);return $uid?:null;
    }catch(Throwable $e){return null;}
}
function data_latest_contribution_id(PDO $pdo,string $type,string $id): ?int {
    $q=$pdo->prepare('SELECT id FROM data_contributions WHERE object_type=? AND object_public_id=? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1');$q->execute([$type,$id]);$v=(int)($q->fetchColumn()?:0);return $v?:null;
}
function data_response_record(PDO $pdo,int $aiRunId,string $aiRunPublicId,?array $viewer,string $responseText,array $refs): ?array {
    if(!data_attribution_ready($pdo))return null;$normalized=[];$seen=[];foreach($refs as $raw){if(!is_array($raw))continue;$r=data_ref_normalize($raw);if(!$r)continue;$key=$r['type'].':'.$r['id'].':'.$r['version'];if(isset($seen[$key]))continue;$seen[$key]=true;$normalized[]=$r;}
    $responseHash=hash('sha256',$responseText);$contextHash=data_attribution_hash($normalized);$public=ulid_like();
    $pdo->prepare('INSERT IGNORE INTO data_response_lineage(public_id,ai_run_id,response_hash,context_hash) VALUES(?,?,?,?)')->execute([$public,$aiRunId,$responseHash,$contextHash]);
    $q=$pdo->prepare('SELECT id,public_id FROM data_response_lineage WHERE ai_run_id=? LIMIT 1');$q->execute([$aiRunId]);$lineage=$q->fetch();if(!$lineage)return null;
    data_contribution_record($pdo,'agent',null,'ai_response',$aiRunPublicId,'agent_response',$responseText,['context_hash'=>$contextHash],['initiated_by_user_id'=>$viewer['id']??null],$aiRunId);
    foreach($normalized as $rank=>$r){$contributor=data_ref_contributor($pdo,$r['type'],$r['id']);$contributionId=data_latest_contribution_id($pdo,$r['type'],$r['id']);$reason='Supplied to the model as Annotated context.';$pdo->prepare('INSERT IGNORE INTO data_response_attributions(response_lineage_id,object_type,object_public_id,object_version_id,contributor_user_id,contribution_id,attribution_type,usage_rank,reason) VALUES(?,?,?,?,?,?,\'context\',?,?)')->execute([$lineage['id'],$r['type'],$r['id'],$r['version'],$contributor,$contributionId,$rank,$reason]);data_provenance_edge_record($pdo,$r['type'],$r['id'],$r['version'],'used_in_response','ai_response',$aiRunPublicId,null,$contributor,$aiRunId);}
    return ['public_id'=>$lineage['public_id'],'response_hash'=>$responseHash,'context_hash'=>$contextHash,'attribution_count'=>count($normalized)];
}
function data_response_bind_message(PDO $pdo,string $aiRunPublicId,int $conversationMessageId): void {
    if(!data_attribution_ready($pdo))return;$pdo->prepare('UPDATE data_response_lineage drl JOIN ai_runs ar ON ar.id=drl.ai_run_id SET drl.conversation_message_id=? WHERE ar.public_id=?')->execute([$conversationMessageId,$aiRunPublicId]);
}
function data_response_lineage_access(PDO $pdo,array $viewer,string $aiRunPublicId): ?array {
    if(!data_attribution_ready($pdo))return null;$q=$pdo->prepare('SELECT drl.*,ar.public_id ai_run_public_id,ar.user_id,ar.task_type,ar.scope_type,ar.scope_public_id,ar.created_at ai_created_at FROM data_response_lineage drl JOIN ai_runs ar ON ar.id=drl.ai_run_id WHERE ar.public_id=? LIMIT 1');$q->execute([$aiRunPublicId]);$r=$q->fetch();if(!$r)return null;if(($viewer['role']??'')!=='admin'&&(int)$r['user_id']!==(int)$viewer['id'])return null;
    $q=$pdo->prepare('SELECT dra.object_type,dra.object_public_id,dra.object_version_id,dra.attribution_type,dra.usage_rank,dra.reason,u.public_id contributor_public_id,u.display_name contributor_name,u.username contributor_username FROM data_response_attributions dra LEFT JOIN users u ON u.id=dra.contributor_user_id WHERE dra.response_lineage_id=? ORDER BY dra.usage_rank,dra.id');$q->execute([$r['id']]);$r['attributions']=$q->fetchAll();return $r;
}
function data_response_attribution_map(PDO $pdo,array $messageIds): array {
    $ids=array_values(array_unique(array_filter(array_map('intval',$messageIds),fn($v)=>$v>0)));if(!$ids||!data_attribution_ready($pdo))return [];$placeholders=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT drl.conversation_message_id,ar.public_id ai_run_public_id,drl.public_id lineage_public_id,COUNT(dra.id) attribution_count,COUNT(DISTINCT dra.contributor_user_id) contributor_count FROM data_response_lineage drl JOIN ai_runs ar ON ar.id=drl.ai_run_id LEFT JOIN data_response_attributions dra ON dra.response_lineage_id=drl.id WHERE drl.conversation_message_id IN ($placeholders) GROUP BY drl.conversation_message_id,ar.public_id,drl.public_id");$q->execute($ids);$out=[];foreach($q->fetchAll() as $r)$out[(int)$r['conversation_message_id']]=['run_id'=>$r['ai_run_public_id'],'lineage_id'=>$r['lineage_public_id'],'attribution_count'=>(int)$r['attribution_count'],'contributor_count'=>(int)$r['contributor_count']];return $out;
}

function data_contributor_summary(PDO $pdo,array $viewer): array {
    if(!data_attribution_ready($pdo))return ['ready'=>false];$uid=(int)$viewer['id'];$prefs=data_contributor_preferences($pdo,$uid);
    $q=$pdo->prepare('SELECT contribution_type,COUNT(*) c FROM data_contributions WHERE actor_user_id=? AND revoked_at IS NULL GROUP BY contribution_type ORDER BY c DESC');$q->execute([$uid]);$types=[];$total=0;foreach($q->fetchAll() as $r){$types[$r['contribution_type']]=(int)$r['c'];$total+=(int)$r['c'];}
    $q=$pdo->prepare('SELECT COUNT(*) FROM data_response_attributions WHERE contributor_user_id=?');$q->execute([$uid]);$uses=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT COUNT(*) FROM data_corpus_items WHERE contributor_user_id=? AND invalidated_at IS NULL');$q->execute([$uid]);$corpus=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT COUNT(*) FROM data_corpus_items WHERE contributor_user_id=? AND invalidated_at IS NULL AND training_eligible=1');$q->execute([$uid]);$training=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT public_id,object_type,object_public_id,object_version_id,contribution_type,created_at,superseded_at FROM data_contributions WHERE actor_user_id=? AND revoked_at IS NULL ORDER BY id DESC LIMIT 40');$q->execute([$uid]);$recent=$q->fetchAll();
    return ['ready'=>true,'preferences'=>$prefs,'total_contributions'=>$total,'by_type'=>$types,'response_uses'=>$uses,'corpus_items'=>$corpus,'training_eligible'=>$training,'recent'=>$recent];
}
function data_global_summary(PDO $pdo): array {
    if(!data_attribution_ready($pdo))return ['ready'=>false];$scalar=function(string $sql)use($pdo){return (int)$pdo->query($sql)->fetchColumn();};
    return ['ready'=>true,'contributions'=>$scalar('SELECT COUNT(*) FROM data_contributions'),'edges'=>$scalar('SELECT COUNT(*) FROM data_provenance_edges WHERE revoked_at IS NULL'),'corpus_active'=>$scalar('SELECT COUNT(*) FROM data_corpus_items WHERE invalidated_at IS NULL'),'corpus_training'=>$scalar('SELECT COUNT(*) FROM data_corpus_items WHERE invalidated_at IS NULL AND training_eligible=1'),'response_lineage'=>$scalar('SELECT COUNT(*) FROM data_response_lineage'),'attributions'=>$scalar('SELECT COUNT(*) FROM data_response_attributions'),'source_rights_reviewed'=>$scalar("SELECT COUNT(*) FROM source_rights WHERE review_status='reviewed'")];
}
