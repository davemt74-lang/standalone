<?php
declare(strict_types=1);

function sponsored_research_submissions_ready(PDO $pdo): bool {
    try{
        return sponsored_research_participation_ready($pdo)
          && data_attribution_ready($pdo)
          && installer_table_exists($pdo,'sponsored_research_submissions')
          && installer_table_exists($pdo,'sponsored_research_submission_versions')
          && installer_table_exists($pdo,'sponsored_research_submission_assets')
          && installer_table_exists($pdo,'sponsored_research_submission_events');
    }catch(Throwable $e){return false;}
}
function sponsored_research_submission_event(PDO $pdo,int $submissionId,?int $versionId,?int $actorUserId,string $eventType,array $payload=[]): void {
    $pdo->prepare('INSERT INTO sponsored_research_submission_events(public_id,submission_id,submission_version_id,actor_user_id,event_type,payload_json) VALUES(?,?,?,?,?,?)')
      ->execute([ulid_like(),$submissionId,$versionId,$actorUserId,mb_substr($eventType,0,80),$payload?data_attribution_encode($payload):null]);
}
function sponsored_research_submission_get(PDO $pdo,string $publicId): ?array {
    if(!sponsored_research_submissions_ready($pdo))return null;
    $q=$pdo->prepare("SELECT s.*,c.public_id campaign_public_id,c.title campaign_title,p.public_id participation_public_id,u.username researcher_username,u.display_name researcher_display_name
      FROM sponsored_research_submissions s JOIN sponsored_research_campaigns c ON c.id=s.campaign_id JOIN sponsored_research_participations p ON p.id=s.participation_id JOIN users u ON u.id=s.researcher_user_id WHERE s.public_id=? LIMIT 1");
    $q->execute([trim($publicId)]);return $q->fetch()?:null;
}
function sponsored_research_submission_for_participation(PDO $pdo,int $participationId): ?array {
    $q=$pdo->prepare('SELECT public_id FROM sponsored_research_submissions WHERE participation_id=? LIMIT 1');$q->execute([$participationId]);$public=$q->fetchColumn();return $public?sponsored_research_submission_get($pdo,(string)$public):null;
}
function sponsored_research_submission_require_researcher(PDO $pdo,array $viewer,string $publicId): array {
    $submission=sponsored_research_submission_get($pdo,$publicId);if(!$submission||(int)$submission['researcher_user_id']!==(int)$viewer['id'])throw new RuntimeException('Sponsored Research submission not found.');
    research_account_require_approved($pdo,$viewer);return $submission;
}
function sponsored_research_submission_versions(PDO $pdo,int $submissionId): array {
    $q=$pdo->prepare('SELECT * FROM sponsored_research_submission_versions WHERE submission_id=? ORDER BY revision_number DESC');$q->execute([$submissionId]);return $q->fetchAll()?:[];
}
function sponsored_research_submission_version_assets(PDO $pdo,int $versionId): array {
    $q=$pdo->prepare('SELECT * FROM sponsored_research_submission_assets WHERE submission_version_id=? ORDER BY position,id');$q->execute([$versionId]);return $q->fetchAll()?:[];
}
function sponsored_research_submission_assets_parse(mixed $input): array {
    if(is_string($input))$input=preg_split('/\r?\n+/',$input)?:[];
    $out=[];foreach((array)$input as $raw){
        if(is_string($raw)){[$type,$id]=array_pad(explode(':',trim($raw),2),2,'');$raw=['type'=>$type,'public_id'=>$id];}
        if(!is_array($raw))continue;$type=strtolower(trim((string)($raw['type']??'')));$id=trim((string)($raw['public_id']??''));
        if(!in_array($type,['annotation','claim','finding','report_version','source','mission','dataset'],true)||$id==='')continue;
        $out[]=['type'=>$type,'public_id'=>mb_substr($id,0,64)];if(count($out)>=200)break;
    }
    return $out;
}
function sponsored_research_submission_asset_snapshot(PDO $pdo,array $viewer,array $asset): array {
    $type=(string)$asset['type'];$public=(string)$asset['public_id'];$uid=(int)$viewer['id'];
    if(in_array($type,['annotation','claim','finding','report_version'],true)){
        if($type==='annotation'&&!annotation_access($pdo,$public,$viewer))throw new RuntimeException('Annotation is unavailable.');
        $d=data_object_descriptor($pdo,$type,$public);if(!$d)throw new RuntimeException(ucfirst(str_replace('_',' ',$type)).' is unavailable.');
        if((int)($d['contributor_user_id']??0)!==$uid)throw new RuntimeException('Only your own contributed research objects can be submitted.');
        $contribution=data_attribution_capture_object($pdo,$uid,$type,$public);
        $snapshot=['schema'=>'annotated-sponsored-asset-v1','type'=>$type,'public_id'=>$public,'version'=>(string)($d['version']??''),'contributor_user_id'=>$uid,'text'=>(string)($d['text']??''),'state'=>(array)($d['state']??[]),'metadata'=>(array)($d['meta']??[])];
        return ['type'=>$type,'public_id'=>$public,'version'=>(string)($d['version']??''),'contributor_user_id'=>$uid,'contribution_id'=>(int)($contribution['id']??0)?:null,'snapshot'=>$snapshot,'rights'=>null];
    }
    if($type==='source'){
        $s=source_access($pdo,$public,$viewer);if(!$s)throw new RuntimeException('Source is unavailable.');
        $q=$pdo->prepare('SELECT sv.version_number,sv.content_hash,sv.captured_at FROM source_versions sv WHERE sv.id=? LIMIT 1');$q->execute([(int)($s['current_version_id']??0)]);$sv=$q->fetch()?:[];
        $rights=data_source_rights($pdo,$public)??['rights_class'=>'unknown','retrieval_allowed'=>0,'excerpt_storage_allowed'=>0,'model_context_allowed'=>0,'training_allowed'=>0,'commercial_training_allowed'=>0,'review_status'=>'unreviewed'];
        $snapshot=['schema'=>'annotated-sponsored-source-v1','type'=>'source','public_id'=>$public,'title'=>$s['title']??null,'canonical_url'=>$s['canonical_url']??null,'domain'=>$s['domain']??null,'status'=>$s['status']??null,'source_version'=>$sv['version_number']??null,'content_hash'=>$sv['content_hash']??null,'captured_at'=>$sv['captured_at']??null];
        return ['type'=>'source','public_id'=>$public,'version'=>(string)($sv['version_number']??''),'contributor_user_id'=>null,'contribution_id'=>null,'snapshot'=>$snapshot,'rights'=>$rights];
    }
    if($type==='mission'){
        $m=research_mission_access($pdo,$viewer,$public);if(!$m)throw new RuntimeException('Research Mission is unavailable.');
        if((string)$m['status']!=='completed')throw new RuntimeException('Only completed Research Missions can be submitted.');
        if((int)($m['created_by_user_id']??0)!==$uid)throw new RuntimeException('Only Research Missions you created can be submitted.');
        $snapshot=['schema'=>'annotated-sponsored-mission-v1','type'=>'mission','public_id'=>$public,'revision'=>(int)$m['current_revision'],'config_hash'=>(string)$m['config_hash'],'status'=>(string)$m['status'],'completed_at'=>$m['completed_at']??null,'config'=>research_mission_config($pdo,$m)];
        return ['type'=>'mission','public_id'=>$public,'version'=>(string)$m['current_revision'],'contributor_user_id'=>$uid,'contribution_id'=>null,'snapshot'=>$snapshot,'rights'=>null];
    }
    if($type==='dataset'){
        $d=data_dataset_get($pdo,$public);if(!$d)throw new RuntimeException('Dataset is unavailable.');
        if((string)$d['status']!=='frozen')throw new RuntimeException('Only frozen Dataset versions can be submitted.');
        if((int)($d['created_by_user_id']??0)!==$uid)throw new RuntimeException('Only Dataset versions you created can be submitted.');
        $snapshot=['schema'=>'annotated-sponsored-dataset-v1','type'=>'dataset','public_id'=>$public,'name'=>$d['name'],'slug'=>$d['slug'],'version_number'=>(int)$d['version_number'],'purpose'=>$d['purpose'],'status'=>$d['status'],'selection_policy_hash'=>$d['selection_policy_hash']??null,'manifest_hash'=>$d['manifest_hash']??null,'item_count'=>(int)($d['item_count']??0),'frozen_at'=>$d['frozen_at']??null];
        return ['type'=>'dataset','public_id'=>$public,'version'=>(string)$d['version_number'],'contributor_user_id'=>$uid,'contribution_id'=>null,'snapshot'=>$snapshot,'rights'=>null];
    }
    throw new InvalidArgumentException('Unsupported submission asset type.');
}
function sponsored_research_submission_draft(PDO $pdo,array $viewer,string $campaignPublicId,array $input): array {
    research_account_require_approved($pdo,$viewer);$campaign=sponsored_research_campaign_by_public($pdo,$campaignPublicId);if(!$campaign)throw new RuntimeException('Campaign not found.');
    $participation=sponsored_research_participation_get($pdo,(int)$campaign['id'],(int)$viewer['id']);if(!$participation||$participation['status']!=='active')throw new RuntimeException('Active campaign participation is required.');
    $title=mb_substr(trim((string)($input['title']??'')),0,255);$summary=trim((string)($input['summary']??''));if($title===''||$summary==='')throw new InvalidArgumentException('Submission title and summary are required.');
    $method=trim((string)($input['methodology']??''));$limitations=trim((string)($input['limitations']??''));
    $existing=sponsored_research_submission_for_participation($pdo,(int)$participation['id']);
    if($existing&&!in_array((string)$existing['status'],['draft','revision_requested'],true))throw new RuntimeException('Submitted research is immutable until a revision is requested.');
    if($existing){
        $pdo->prepare("UPDATE sponsored_research_submissions SET title=?,summary=?,methodology=?,limitations=?,status='draft',updated_at=NOW() WHERE id=?")
          ->execute([$title,$summary,$method?:null,$limitations?:null,(int)$existing['id']]);$public=(string)$existing['public_id'];
    }else{
        $public=ulid_like();$pdo->prepare("INSERT INTO sponsored_research_submissions(public_id,campaign_id,participation_id,researcher_user_id,title,summary,methodology,limitations,status) VALUES(?,?,?,?,?,?,?,?,'draft')")
          ->execute([$public,(int)$campaign['id'],(int)$participation['id'],(int)$viewer['id'],$title,$summary,$method?:null,$limitations?:null]);
        $id=(int)$pdo->lastInsertId();sponsored_research_submission_event($pdo,$id,null,(int)$viewer['id'],'draft_created',['campaign_revision'=>(int)$campaign['current_revision']]);
    }
    return sponsored_research_submission_get($pdo,$public)??[];
}
function sponsored_research_submission_submit(PDO $pdo,array $viewer,string $submissionPublicId,mixed $assetsInput): array {
    $submission=sponsored_research_submission_require_researcher($pdo,$viewer,$submissionPublicId);if(!in_array((string)$submission['status'],['draft','revision_requested'],true))throw new RuntimeException('This submission cannot be submitted in its current state.');
    return app_with_advisory_lock($pdo,'sponsored-research-submission',(int)$submission['id'],function() use($pdo,$viewer,$submission,$assetsInput){
        $fresh=sponsored_research_submission_get($pdo,(string)$submission['public_id']);$campaign=sponsored_research_campaign_by_public($pdo,(string)$fresh['campaign_public_id']);
        $participation=sponsored_research_participation_get($pdo,(int)$campaign['id'],(int)$viewer['id']);if(!$participation||$participation['status']!=='active')throw new RuntimeException('Active campaign participation is required to submit research.');
        if((int)$participation['campaign_revision_accepted']!==(int)$campaign['current_revision'])throw new RuntimeException('You must re-accept the current campaign revision before submitting.');
        $acceptances=sponsored_research_participation_acceptances($pdo,(int)$participation['id'],1);$acceptance=$acceptances[0]??null;if(!$acceptance)throw new RuntimeException('Participation acceptance history is missing.');
        if((int)$acceptance['campaign_revision']!==(int)$campaign['current_revision'])throw new RuntimeException('Current campaign terms must be accepted before submitting.');
        $terms=sponsored_research_campaign_terms_latest($pdo,(int)$campaign['id']);if(!$terms||(int)$terms['id']!==(int)$acceptance['terms_id'])throw new RuntimeException('The latest campaign terms must be accepted before submitting.');
        $assets=sponsored_research_submission_assets_parse($assetsInput);if(!$assets)throw new InvalidArgumentException('At least one research asset is required.');
        $snapshots=[];foreach($assets as $asset)$snapshots[]=sponsored_research_submission_asset_snapshot($pdo,$viewer,$asset);
        $revision=(int)$fresh['current_revision']+1;
        $core=['schema'=>'annotated-sponsored-submission-v1','submission_public_id'=>(string)$fresh['public_id'],'campaign_public_id'=>(string)$campaign['public_id'],'campaign_revision'=>(int)$campaign['current_revision'],'participation_public_id'=>(string)$fresh['participation_public_id'],'participation_acceptance_public_id'=>(string)$acceptance['public_id'],'terms_version'=>(int)$acceptance['terms_version'],'terms_hash'=>(string)$acceptance['terms_hash'],'researcher_user_id'=>(int)$viewer['id'],'title'=>(string)$fresh['title'],'summary'=>(string)$fresh['summary'],'methodology'=>(string)($fresh['methodology']??''),'limitations'=>(string)($fresh['limitations']??''),'assets'=>array_map(fn($a)=>['type'=>$a['type'],'public_id'=>$a['public_id'],'version'=>$a['version'],'snapshot_hash'=>data_attribution_hash($a['snapshot']),'rights_hash'=>$a['rights']?data_attribution_hash($a['rights']):null],$snapshots)];
        $snapshotJson=data_attribution_encode($core);$snapshotHash=hash('sha256',$snapshotJson);
        $pdo->beginTransaction();try{
            $versionPublic=ulid_like();$pdo->prepare('INSERT INTO sponsored_research_submission_versions(public_id,submission_id,revision_number,campaign_revision,participation_acceptance_id,title,summary,methodology,limitations,snapshot_json,snapshot_hash,submitted_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
              ->execute([$versionPublic,(int)$fresh['id'],$revision,(int)$campaign['current_revision'],(int)$acceptance['id'],$fresh['title'],$fresh['summary'],$fresh['methodology'],$fresh['limitations'],$snapshotJson,$snapshotHash,(int)$viewer['id']]);$versionId=(int)$pdo->lastInsertId();
            foreach($snapshots as $position=>$a){
                $assetJson=data_attribution_encode($a['snapshot']);$assetHash=hash('sha256',$assetJson);$rightsJson=$a['rights']?data_attribution_encode($a['rights']):null;$rightsHash=$rightsJson?hash('sha256',$rightsJson):null;
                $pdo->prepare('INSERT INTO sponsored_research_submission_assets(public_id,submission_version_id,asset_type,asset_public_id,asset_version,contributor_user_id,data_contribution_id,snapshot_json,snapshot_hash,source_rights_snapshot_json,source_rights_snapshot_hash,position) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
                  ->execute([ulid_like(),$versionId,$a['type'],$a['public_id'],$a['version']!==''?$a['version']:null,$a['contributor_user_id'],$a['contribution_id'],$assetJson,$assetHash,$rightsJson,$rightsHash,$position]);
                data_provenance_edge_record($pdo,$a['type'],$a['public_id'],$a['version'],'submitted_as','sponsored_submission_version',$versionPublic,(string)$revision,(int)$viewer['id'],null,null,date('Y-m-d H:i:s'),['campaign_public_id'=>$campaign['public_id'],'submission_public_id'=>$fresh['public_id'],'snapshot_hash'=>$assetHash]);
            }
            $contribution=data_contribution_record($pdo,'user',(int)$viewer['id'],'sponsored_submission_version',$versionPublic,'sponsored_research_submission',$snapshotJson,['campaign_revision'=>(int)$campaign['current_revision'],'submission_revision'=>$revision],['campaign_public_id'=>$campaign['public_id'],'submission_public_id'=>$fresh['public_id'],'terms_hash'=>$acceptance['terms_hash']],null,(string)$revision);
            $pdo->prepare("UPDATE sponsored_research_submissions SET status='submitted',current_revision=?,latest_snapshot_hash=?,submitted_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$revision,$snapshotHash,(int)$fresh['id']]);
            sponsored_research_submission_event($pdo,(int)$fresh['id'],$versionId,(int)$viewer['id'],'submission_submitted',['revision'=>$revision,'version_public_id'=>$versionPublic,'snapshot_hash'=>$snapshotHash,'asset_count'=>count($snapshots),'data_contribution_public_id'=>$contribution['public_id']??null]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        return sponsored_research_submission_get($pdo,(string)$fresh['public_id'])??[];
    },5);
}
function sponsored_research_submission_withdraw(PDO $pdo,array $viewer,string $publicId,string $reason=''): array {
    $s=sponsored_research_submission_require_researcher($pdo,$viewer,$publicId);if(!in_array((string)$s['status'],['draft','submitted','revision_requested'],true))throw new RuntimeException('This submission cannot be withdrawn.');
    $pdo->prepare("UPDATE sponsored_research_submissions SET status='withdrawn',withdrawn_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$s['id']]);
    sponsored_research_submission_event($pdo,(int)$s['id'],null,(int)$viewer['id'],'submission_withdrawn',['reason'=>mb_substr(trim($reason),0,1000)]);
    return sponsored_research_submission_get($pdo,$publicId)??[];
}
function sponsored_research_campaign_submissions(PDO $pdo,array $viewer,string $campaignPublicId,int $limit=200): array {
    $campaign=sponsored_research_campaign_require_manage($pdo,$viewer,$campaignPublicId);$q=$pdo->prepare("SELECT s.*,u.username,u.display_name FROM sponsored_research_submissions s JOIN users u ON u.id=s.researcher_user_id WHERE s.campaign_id=? ORDER BY FIELD(s.status,'submitted','revision_requested','draft','accepted','rejected','withdrawn'),s.updated_at DESC LIMIT ".max(1,min(500,$limit)));$q->execute([(int)$campaign['id']]);return $q->fetchAll()?:[];
}
