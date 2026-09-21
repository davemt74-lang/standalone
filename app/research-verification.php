<?php
declare(strict_types=1);

/**
 * Phase 27 — Research Trust, Review & Verification
 *
 * This layer intentionally does not decide whether a Claim is "true".
 * It exposes deterministic evidence/review signals that a person or Agent can inspect:
 * support vs contradiction, source diversity, evidence freshness, recorded-integrity
 * coverage, and append-only human review events.
 */

function research_verification_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_verification_events');}
    catch(Throwable $e){return false;}
}

function research_verification_decisions(): array {
    return [
        'reviewed_current'=>'Reviewed — evidence current',
        'needs_review'=>'Needs review',
        'disputed'=>'Disputed',
        'abstained'=>'Abstained',
    ];
}

function research_verification_encode(array $value): string {
    if(function_exists('provenance_encode'))return provenance_encode($value);
    $sort=function(mixed $v)use(&$sort): mixed {
        if(!is_array($v))return $v;
        if(array_is_list($v))return array_map($sort,$v);
        ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=$sort($x);return $v;
    };
    $json=json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)throw new RuntimeException('Unable to encode verification state.');
    return $json;
}

function research_verification_claim_subject_hash(PDO $pdo,string $claimPublic): string {
    $q=$pdo->prepare('SELECT id,statement,claim_type,status,resolution_note,updated_at FROM research_claims WHERE public_id=? LIMIT 1');
    $q->execute([trim($claimPublic)]);$claim=$q->fetch();if(!$claim)return '';
    $eq=$pdo->prepare('SELECT public_id,evidence_type,annotation_id,source_version_id,relationship,note,created_at FROM claim_evidence WHERE claim_id=? ORDER BY public_id');
    $eq->execute([$claim['id']]);$evidence=$eq->fetchAll();
    return hash('sha256',research_verification_encode([
        'statement'=>$claim['statement'],'claim_type'=>$claim['claim_type'],'status'=>$claim['status'],
        'resolution_note'=>$claim['resolution_note'],'evidence'=>$evidence,
    ]));
}

function research_verification_subject(PDO $pdo,array $viewer,string $type,string $public): ?array {
    $type=strtolower(trim($type));$public=trim($public);
    if(!in_array($type,['claim','finding','report_version'],true)||$public==='')return null;
    if($type==='claim'){
        $claim=research_claim_access($pdo,$viewer,$public);if(!$claim)return null;
        return [
            'type'=>'claim','public_id'=>$public,'project_id'=>(int)$claim['project_id'],
            'project_public_id'=>(string)$claim['project_public_id'],'project_title'=>(string)$claim['project_title'],
            'access_role'=>$claim['access_role']??null,'title'=>'Claim: '.mb_substr((string)$claim['statement'],0,190),
            'url'=>'/research-claim.php?id='.rawurlencode($public),
            'hash'=>research_verification_claim_subject_hash($pdo,$public),
        ];
    }
    $subject=function_exists('research_review_subject')?research_review_subject($pdo,$viewer,$type,$public):null;
    if(!$subject)return null;
    $project=project_access($pdo,(int)$viewer['id'],(string)$subject['project_public_id']);if(!$project)return null;
    $hash=(string)$subject['hash'];
    if($type==='finding'){
        $q=$pdo->prepare('SELECT rc.public_id FROM research_findings rf JOIN finding_claims fc ON fc.finding_id=rf.id JOIN research_claims rc ON rc.id=fc.claim_id WHERE rf.public_id=? ORDER BY fc.position,rc.public_id');
        $q->execute([$public]);$claimHashes=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $claimPublic)$claimHashes[(string)$claimPublic]=research_verification_claim_subject_hash($pdo,(string)$claimPublic);
        $hash=hash('sha256',research_verification_encode(['finding'=>$hash,'claims'=>$claimHashes]));
    }
    return [
        'type'=>$type,'public_id'=>$public,'project_id'=>(int)$subject['project_id'],
        'project_public_id'=>(string)$subject['project_public_id'],'project_title'=>(string)$subject['project_title'],
        'access_role'=>$project['access_role']??null,'title'=>(string)$subject['title'],
        'url'=>(string)$subject['url'],'hash'=>$hash,
    ];
}

function research_verification_claim_signal(PDO $pdo,array $viewer,array $claim): array {
    $q=$pdo->prepare("SELECT ce.public_id,ce.evidence_type,ce.relationship,ce.created_at,
        a.public_id annotation_public_id,
        sv.id source_version_id,sv.version_number,sv.captured_at,sv.content_hash,sv.target_content_hash,
        s.id source_id,s.public_id source_public_id,s.title source_title,s.canonical_url,s.domain,s.status source_status,
        s.moderation_status,s.current_version_id,s.last_checked_at,
        EXISTS(SELECT 1 FROM source_change_events sce WHERE sce.source_id=s.id AND sce.created_at>sv.captured_at) changed_after_capture
      FROM claim_evidence ce
      JOIN source_versions sv ON sv.id=ce.source_version_id
      JOIN sources s ON s.id=sv.source_id
      LEFT JOIN annotations a ON a.id=ce.annotation_id
      WHERE ce.claim_id=? ORDER BY ce.public_id");
    $q->execute([$claim['id']]);
    $signals=[];$supportSources=[];$supportDomains=[];$support=0;$contradict=0;$context=0;$stale=0;$available=0;$flags=[];
    foreach($q->fetchAll() as $r){
        $relationship=(string)$r['relationship'];
        if(source_access($pdo,(string)$r['source_public_id'],$viewer)===null){
            $signals[]=['available'=>false,'relationship'=>$relationship,'evidence_id'=>$r['public_id']];continue;
        }
        $available++;
        $isSupport=in_array($relationship,['supports','primary'],true);$isContradict=$relationship==='contradicts';
        if($isSupport){$support++;$supportSources[(string)$r['source_public_id']]=true;$d=strtolower(trim((string)$r['domain']));if($d!=='')$supportDomains[$d]=true;}
        elseif($isContradict)$contradict++;else $context++;
        $isCurrent=(int)$r['current_version_id']===(int)$r['source_version_id'];
        $changed=(bool)$r['changed_after_capture'];$unavailable=(string)$r['source_status']==='unavailable';$restricted=(string)($r['moderation_status']??'visible')==='restricted';
        $fresh=$isCurrent&&!$changed&&!$unavailable&&!$restricted;if(!$fresh)$stale++;
        $localFlags=[];
        if(!$isCurrent)$localFlags[]='evidence_version_superseded';
        if($changed)$localFlags[]='source_changed_after_capture';
        if($unavailable)$localFlags[]='source_unavailable';
        if($restricted)$localFlags[]='source_restricted';
        if(empty($r['content_hash']))$localFlags[]='recorded_hash_missing';
        foreach($localFlags as $f)$flags[$f]=true;
        $signals[]=[
            'available'=>true,'evidence_id'=>$r['public_id'],'relationship'=>$relationship,'evidence_type'=>$r['evidence_type'],
            'annotation_id'=>$r['annotation_public_id'],'source_id'=>$r['source_public_id'],'source_title'=>$r['source_title'],
            'domain'=>$r['domain'],'source_status'=>$r['source_status'],'moderation_status'=>$r['moderation_status']??'visible',
            'source_version'=>(int)$r['version_number'],'captured_at'=>$r['captured_at'],'last_checked_at'=>$r['last_checked_at'],
            'is_current_version'=>$isCurrent,'changed_after_capture'=>$changed,'fresh'=>$fresh,
            'recorded_content_hash_present'=>!empty($r['content_hash']),'flags'=>$localFlags,
        ];
    }
    if($support>0&&$contradict>0)$evidenceState='contested';
    elseif($support>0)$evidenceState='support_only';
    elseif($contradict>0)$evidenceState='contradiction_only';
    elseif($context>0)$evidenceState='context_only';
    else $evidenceState='no_evidence';

    if(count($supportDomains)>=2)$corroboration='independent_domains';
    elseif(count($supportSources)>=2)$corroboration='multiple_sources_same_domain';
    elseif(count($supportSources)===1)$corroboration='single_source';
    else $corroboration='none';

    if($available===0)$freshness='unknown';
    elseif($stale===0)$freshness='current';
    elseif($stale===$available)$freshness='stale';
    else $freshness='mixed';

    $integrity=$available===0?'unknown':(isset($flags['recorded_hash_missing'])?'recorded_hash_gap':'recorded_hashes_present');
    return [
        'evidence_state'=>$evidenceState,'corroboration'=>$corroboration,'freshness'=>$freshness,'integrity'=>$integrity,
        'counts'=>['available'=>$available,'support'=>$support,'contradict'=>$contradict,'context'=>$context,'support_sources'=>count($supportSources),'support_domains'=>count($supportDomains),'stale'=>$stale],
        'flags'=>array_keys($flags),'sources'=>$signals,
    ];
}

function research_verification_human_state(PDO $pdo,array $viewer,array $subject,int $limit=40): array {
    if(!research_verification_ready($pdo))return ['state'=>'unavailable','current'=>[],'stale'=>[],'counts'=>[]];
    $limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT rve.*,u.public_id reviewer_public_id,u.display_name,u.username
      FROM research_verification_events rve JOIN users u ON u.id=rve.reviewer_user_id
      WHERE rve.project_id=? AND rve.subject_type=? AND rve.subject_public_id=?
      ORDER BY rve.id DESC LIMIT ".$limit);
    $q->execute([$subject['project_id'],$subject['type'],$subject['public_id']]);$current=[];$stale=[];$counts=['reviewed_current'=>0,'needs_review'=>0,'disputed'=>0,'abstained'=>0];
    foreach($q->fetchAll() as $r){
        $isCurrent=hash_equals((string)$r['subject_hash'],(string)$subject['hash']);
        if($isCurrent&&function_exists('change_impact_subject_latest_event')){
            $upstream=change_impact_subject_latest_event($pdo,$viewer,(string)$subject['type'],(string)$subject['public_id'],(string)$r['created_at']);
            if($upstream)$isCurrent=false;
        }
        $row=[
            'public_id'=>$r['public_id'],'decision'=>$r['decision'],'note'=>$r['note'],'created_at'=>$r['created_at'],
            'reviewer'=>['public_id'=>$r['reviewer_public_id'],'name'=>$r['display_name']?:$r['username']],
            'evidence_state_hash'=>$r['evidence_state_hash'],'is_current'=>$isCurrent,
        ];
        if($isCurrent){$current[]=$row;$counts[(string)$r['decision']]++;}
        else $stale[]=$row;
    }
    $nonAbstainKinds=0;foreach(['reviewed_current','needs_review','disputed'] as $d)if($counts[$d]>0)$nonAbstainKinds++;
    if($nonAbstainKinds>1)$state='mixed';
    elseif($counts['disputed']>0)$state='disputed';
    elseif($counts['needs_review']>0)$state='needs_review';
    elseif($counts['reviewed_current']>0)$state='reviewed_current';
    elseif($counts['abstained']>0)$state='abstained';
    elseif($stale)$state='stale';
    else $state='not_reviewed';
    return ['state'=>$state,'current'=>$current,'stale'=>$stale,'counts'=>$counts];
}

function research_verification_claim_state(PDO $pdo,array $viewer,array $claim): array {
    $signal=research_verification_claim_signal($pdo,$viewer,$claim);
    $subject=research_verification_subject($pdo,$viewer,'claim',(string)$claim['public_id']);
    $human=$subject?research_verification_human_state($pdo,$viewer,$subject):['state'=>'unavailable','current'=>[],'stale'=>[],'counts'=>[]];
    $reasons=[];$severity='low';
    if(in_array($signal['evidence_state'],['contested','contradiction_only'],true)){$reasons[]='contradictory_evidence';$severity='high';}
    if($signal['evidence_state']==='no_evidence'){$reasons[]='no_evidence';if($severity!=='high')$severity='medium';}
    if(in_array($signal['freshness'],['stale','mixed'],true)){$reasons[]='stale_evidence';$severity='high';}
    if(in_array($human['state'],['disputed','needs_review','mixed'],true)){$reasons[]='human_review_attention';$severity='high';}
    if(($claim['status']??'')==='supported'&&in_array($signal['corroboration'],['none','single_source'],true)){$reasons[]='limited_corroboration';if($severity==='low')$severity='medium';}
    if(in_array('source_restricted',$signal['flags'],true)||in_array('source_unavailable',$signal['flags'],true)){$reasons[]='source_availability_or_moderation';$severity='high';}
    $agentUse='supported_with_attribution';
    if($signal['evidence_state']==='no_evidence')$agentUse='insufficient_evidence';
    elseif(in_array($signal['evidence_state'],['contested','contradiction_only'],true))$agentUse='contested_evidence';
    elseif(in_array($signal['freshness'],['stale','mixed'],true))$agentUse='stale_evidence';
    elseif(in_array($human['state'],['disputed','needs_review','mixed'],true))$agentUse='human_review_caution';
    elseif($human['state']==='reviewed_current')$agentUse='human_reviewed_evidence';
    return array_merge($signal,[
        'claim'=>['public_id'=>$claim['public_id'],'statement'=>$claim['statement'],'claim_type'=>$claim['claim_type'],'status'=>$claim['status'],'updated_at'=>$claim['updated_at']],
        'human_review'=>$human,'attention'=>!empty($reasons),'attention_reasons'=>$reasons,'severity'=>$severity,'agent_use'=>$agentUse,
        'subject_hash'=>$subject['hash']??'',
    ]);
}

function research_verification_project_claims(PDO $pdo,array $viewer,string $projectPublic,int $limit=200): array {
    $project=project_access($pdo,(int)$viewer['id'],trim($projectPublic));if(!$project)return [];
    $limit=max(1,min(500,$limit));$q=$pdo->prepare("SELECT rc.*,rp.public_id project_public_id,rp.title project_title FROM research_claims rc JOIN research_projects rp ON rp.id=rc.project_id WHERE rc.project_id=? ORDER BY rc.updated_at DESC,rc.id DESC LIMIT ".$limit);
    $q->execute([$project['id']]);$out=[];foreach($q->fetchAll() as $claim){$claim['access_role']=$project['access_role']??null;$out[]=research_verification_claim_state($pdo,$viewer,$claim);}return $out;
}

function research_verification_project_summary(PDO $pdo,array $viewer,string $projectPublic,int $limit=200): array {
    $project=project_access($pdo,(int)$viewer['id'],trim($projectPublic));if(!$project)return ['available'=>false,'claims'=>[]];
    $claims=research_verification_project_claims($pdo,$viewer,$projectPublic,$limit);
    $summary=['available'=>true,'project'=>['public_id'=>$project['public_id'],'title'=>$project['title'],'access_role'=>$project['access_role']??null],
      'total_claims'=>count($claims),'independent_domain_support'=>0,'contested'=>0,'stale'=>0,'no_evidence'=>0,'reviewed_current'=>0,'human_attention'=>0,'needs_attention'=>0,'claims'=>$claims,'revision'=>(string)($project['updated_at']??'')];
    foreach($claims as $c){
        if($c['corroboration']==='independent_domains')$summary['independent_domain_support']++;
        if(in_array($c['evidence_state'],['contested','contradiction_only'],true))$summary['contested']++;
        if(in_array($c['freshness'],['stale','mixed'],true))$summary['stale']++;
        if($c['evidence_state']==='no_evidence')$summary['no_evidence']++;
        if($c['human_review']['state']==='reviewed_current')$summary['reviewed_current']++;
        if(in_array($c['human_review']['state'],['disputed','needs_review','mixed'],true))$summary['human_attention']++;
        if($c['attention'])$summary['needs_attention']++;
        if(strcmp((string)$c['claim']['updated_at'],$summary['revision'])>0)$summary['revision']=(string)$c['claim']['updated_at'];
        foreach(array_merge($c['human_review']['current'],$c['human_review']['stale']) as $ev)if(strcmp((string)$ev['created_at'],$summary['revision'])>0)$summary['revision']=(string)$ev['created_at'];
    }
    return $summary;
}

function research_verification_record(PDO $pdo,array $viewer,string $type,string $public,string $decision,string $note=''): array {
    if(!research_verification_ready($pdo))throw new RuntimeException('Research Verification requires the Phase 27 database upgrade.');
    if(!isset(research_verification_decisions()[$decision]))throw new InvalidArgumentException('Invalid verification decision.');
    $subject=research_verification_subject($pdo,$viewer,$type,$public);if(!$subject)throw new RuntimeException('Research subject is unavailable.');
    if(!in_array((string)($subject['access_role']??''),['owner','admin','researcher'],true))throw new RuntimeException('You have view-only access to this Research project.');
    $state=['subject'=>['type'=>$subject['type'],'public_id'=>$subject['public_id'],'hash'=>$subject['hash']]];
    if($subject['type']==='claim'){
        $claim=research_claim_access($pdo,$viewer,$subject['public_id']);$state['evidence']=research_verification_claim_signal($pdo,$viewer,$claim);
    } elseif($subject['type']==='finding'){
        $q=$pdo->prepare('SELECT rc.public_id FROM research_findings rf JOIN finding_claims fc ON fc.finding_id=rf.id JOIN research_claims rc ON rc.id=fc.claim_id WHERE rf.public_id=? ORDER BY fc.position,rc.public_id');$q->execute([$subject['public_id']]);
        $state['claim_states']=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $claimPublic){$claim=research_claim_access($pdo,$viewer,(string)$claimPublic);if($claim){$sig=research_verification_claim_signal($pdo,$viewer,$claim);$state['claim_states'][]=['claim_id'=>$claimPublic,'evidence_state'=>$sig['evidence_state'],'corroboration'=>$sig['corroboration'],'freshness'=>$sig['freshness'],'flags'=>$sig['flags']];}}
    } else {
        $state['report_snapshot_hash']=$subject['hash'];
        if(function_exists('provenance_ready')&&provenance_ready($pdo)){
            $q=$pdo->prepare('SELECT rr.public_id report_public_id,rv.version_number FROM research_report_versions rv JOIN research_reports rr ON rr.id=rv.report_id WHERE rv.public_id=? LIMIT 1');$q->execute([$subject['public_id']]);$rv=$q->fetch();
            if($rv){$report=research_report_access($pdo,(string)$rv['report_public_id'],$viewer);$version=$report?research_report_version($pdo,$report,(int)$rv['version_number']):null;if($version)$state['snapshot_hash_valid']=hash_equals((string)$version['snapshot_hash'],hash('sha256',(string)$version['snapshot_json']));}
        }
    }
    $stateJson=research_verification_encode($state);$stateHash=hash('sha256',$stateJson);$eventPublic=ulid_like();$note=mb_substr(trim($note),0,8000);
    $pdo->prepare('INSERT INTO research_verification_events(public_id,project_id,reviewer_user_id,subject_type,subject_public_id,subject_hash,decision,evidence_state_hash,evidence_state_json,note) VALUES(?,?,?,?,?,?,?,?,?,?)')
      ->execute([$eventPublic,$subject['project_id'],$viewer['id'],$subject['type'],$subject['public_id'],$subject['hash'],$decision,$stateHash,$stateJson,$note?:null]);
    $q=$pdo->prepare('SELECT * FROM research_verification_events WHERE public_id=? LIMIT 1');$q->execute([$eventPublic]);return $q->fetch()?:[];
}

function research_verification_project_events(PDO $pdo,array $viewer,string $projectPublic,int $limit=100): array {
    $project=project_access($pdo,(int)$viewer['id'],trim($projectPublic));if(!$project||!research_verification_ready($pdo))return [];
    $limit=max(1,min(500,$limit));$q=$pdo->prepare("SELECT rve.public_id,rve.subject_type,rve.subject_public_id,rve.subject_hash,rve.decision,rve.evidence_state_hash,rve.note,rve.created_at,u.public_id reviewer_public_id,u.display_name,u.username
      FROM research_verification_events rve JOIN users u ON u.id=rve.reviewer_user_id WHERE rve.project_id=? ORDER BY rve.id DESC LIMIT ".$limit);
    $q->execute([$project['id']]);$out=[];foreach($q->fetchAll() as $r){$subject=research_verification_subject($pdo,$viewer,(string)$r['subject_type'],(string)$r['subject_public_id']);if(!$subject)continue;$current=hash_equals((string)$r['subject_hash'],(string)$subject['hash']);if($current&&function_exists('change_impact_subject_latest_event')&&change_impact_subject_latest_event($pdo,$viewer,(string)$r['subject_type'],(string)$r['subject_public_id'],(string)$r['created_at']))$current=false;$r['is_current']=$current;$r['reviewer_name']=$r['display_name']?:$r['username'];unset($r['display_name'],$r['username']);$out[]=$r;}return $out;
}

function research_verification_context(PDO $pdo,array $viewer,string $projectPublic,int $limit=12): array {
    $summary=research_verification_project_summary($pdo,$viewer,$projectPublic,120);if(empty($summary['available']))return ['text'=>'','refs'=>[],'summary'=>$summary];
    $lines=['[RESEARCH EVIDENCE VERIFICATION]','This is evidence/review metadata, not a truth score. Never convert a human review or corroboration signal into a claim that something is certainly true.'];
    $lines[]='Claims: '.$summary['total_claims'].'; need attention: '.$summary['needs_attention'].'; contested: '.$summary['contested'].'; stale/mixed evidence: '.$summary['stale'].'; independent-domain support: '.$summary['independent_domain_support'].'; human-reviewed current: '.$summary['reviewed_current'].'.';
    $refs=[];$shown=0;foreach($summary['claims'] as $c){if(!$c['attention']&&$shown>=max(2,(int)floor($limit/3)))continue;if($shown>=$limit)break;$cl=$c['claim'];$lines[]='- [CLAIM '.$cl['public_id'].'] '.$cl['statement'].' | evidence='.$c['evidence_state'].'; corroboration='.$c['corroboration'].'; freshness='.$c['freshness'].'; human_review='.$c['human_review']['state'].'; agent_use='.$c['agent_use'].'.';$refs[]=['type'=>'claim','id'=>$cl['public_id']];$shown++;}
    $lines[]='Agent policy: unsupported, contested, stale, restricted, or human-disputed Research must be explicitly qualified and attributed. Do not silently promote it to established fact. "Reviewed current" means a person reviewed the evidence state at a specific time; it is not truth certification.';
    return ['text'=>implode("\n",$lines),'refs'=>$refs,'summary'=>$summary];
}

function research_verification_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limitProjects=12): void {
    if(!research_verification_ready($pdo))return;
    $q=$pdo->prepare("SELECT DISTINCT rp.public_id FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE rp.owner_user_id=? OR tm.user_id=? ORDER BY rp.updated_at DESC LIMIT ".$limitProjects);
    $q->execute([$viewer['id'],$viewer['id'],$viewer['id']]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $projectPublic){
        $s=research_verification_project_summary($pdo,$viewer,(string)$projectPublic,100);if(empty($s['available'])||$s['needs_attention']<=0)continue;
        $high=$s['contested']>0||$s['stale']>0||$s['human_attention']>0;$priority=$high?'high':'medium';
        cognitive_feed_add($items,[
            'key'=>cognitive_feed_key('research_verification','project',(string)$projectPublic,(string)$s['revision']),
            'type'=>'research_verification','section'=>'needs_attention','priority'=>$priority,'created_at'=>$s['revision']?:date('Y-m-d H:i:s'),'score_extra'=>$high?16:7,
            'title'=>'Research evidence needs verification',
            'body'=>$s['project']['title'].': '.$s['needs_attention'].' Claim(s) have evidence, freshness, corroboration, or human-review signals that need attention.',
            'meta'=>['project'=>$projectPublic,'contested'=>$s['contested'],'stale'=>$s['stale'],'human_attention'=>$s['human_attention']],
            'actions'=>[
                cognitive_feed_action_link('Review evidence','/research-verification.php?id='.rawurlencode((string)$projectPublic)),
                cognitive_feed_action_agent('Ask Agent','Explain which Research evidence needs review and why. Preserve uncertainty; do not treat verification metadata as truth certification.',[['type'=>'research','public_id'=>(string)$projectPublic]])
            ],
        ]);
    }
}

function research_verification_snapshot_claim_signal(array $claim,array $snapshotSources=[]): array {
    $sourceDomain=[];foreach($snapshotSources as $s)if(!empty($s['id']))$sourceDomain[(string)$s['id']]=strtolower(trim((string)($s['domain']??'')));
    $support=0;$contradict=0;$context=0;$sourceIds=[];$domains=[];
    foreach((array)($claim['evidence']??[]) as $e){$rel=(string)($e['relationship']??'');if(in_array($rel,['supports','primary'],true)){$support++;if(!empty($e['source_id'])){$sid=(string)$e['source_id'];$sourceIds[$sid]=true;$d=$sourceDomain[$sid]??'';if($d!=='')$domains[$d]=true;}}elseif($rel==='contradicts')$contradict++;else $context++;}
    $evidenceState=$support&&$contradict?'contested':($support?'support_only':($contradict?'contradiction_only':($context?'context_only':'no_evidence')));
    $corroboration=count($domains)>=2?'independent_domains':(count($sourceIds)>=2?'multiple_sources_same_domain':(count($sourceIds)===1?'single_source':'none'));
    return ['evidence_state'=>$evidenceState,'corroboration'=>$corroboration,'freshness'=>'snapshot_pinned','counts'=>['support'=>$support,'contradict'=>$contradict,'context'=>$context,'support_sources'=>count($sourceIds),'support_domains'=>count($domains)]];
}
