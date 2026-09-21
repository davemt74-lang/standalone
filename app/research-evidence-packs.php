<?php
declare(strict_types=1);

function research_evidence_packs_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_evidence_packs');}
    catch(Throwable $e){return false;}
}

function research_evidence_pack_scope_types(): array {
    return ['project'=>'Project','claim'=>'Claim','finding'=>'Finding','report_version'=>'Report version'];
}

function research_evidence_pack_canonical(array $value): array {
    return function_exists('provenance_canonicalize')?provenance_canonicalize($value):$value;
}
function research_evidence_pack_encode(array $value): string {
    return function_exists('provenance_encode')?provenance_encode($value):(string)json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
function research_evidence_pack_hash(array $value): string {return hash('sha256',research_evidence_pack_encode($value));}

function research_evidence_pack_claim_summary(PDO $pdo,array $viewer,string $claimPublic): ?array {
    $claim=research_claim_access($pdo,$viewer,$claimPublic);if(!$claim)return null;
    $v=research_verification_claim_state($pdo,$viewer,$claim);
    return [
        'claim_id'=>$claimPublic,
        'subject_hash'=>$v['subject_hash'],
        'evidence_state'=>$v['evidence_state'],
        'corroboration'=>$v['corroboration'],
        'freshness'=>$v['freshness'],
        'integrity'=>$v['integrity'],
        'counts'=>$v['counts'],
        'flags'=>$v['flags'],
        'human_review_state'=>$v['human_review']['state'],
        'agent_use'=>$v['agent_use'],
    ];
}

function research_evidence_pack_project_verification(PDO $pdo,array $viewer,string $projectPublic): array {
    if(!function_exists('research_verification_ready')||!research_verification_ready($pdo))return [];
    $summary=research_verification_project_summary($pdo,$viewer,$projectPublic,500);$out=[];
    foreach((array)($summary['claims']??[]) as $v)$out[]=[
        'claim_id'=>$v['claim']['public_id'],
        'subject_hash'=>$v['subject_hash'],
        'evidence_state'=>$v['evidence_state'],
        'corroboration'=>$v['corroboration'],
        'freshness'=>$v['freshness'],
        'integrity'=>$v['integrity'],
        'counts'=>$v['counts'],
        'flags'=>$v['flags'],
        'human_review_state'=>$v['human_review']['state'],
        'agent_use'=>$v['agent_use'],
    ];
    usort($out,fn($a,$b)=>strcmp($a['claim_id'],$b['claim_id']));return $out;
}

function research_evidence_pack_filter_claim_dependencies(array $prov,array $claimIds): array {
    $claimSet=array_fill_keys($claimIds,true);$claims=[];$sourceKeys=[];$annotationIds=[];
    foreach((array)($prov['claims']??[]) as $c)if(isset($claimSet[$c['id']])){
        $claims[]=$c;
        foreach((array)($c['evidence']??[]) as $e)if(!empty($e['available'])){
            $sourceKeys[(string)$e['source_id'].':'.(int)$e['source_version']]=true;
            if(!empty($e['annotation_id']))$annotationIds[(string)$e['annotation_id']]=true;
        }
    }
    $sources=[];foreach((array)($prov['source_versions']??[]) as $s)if(isset($sourceKeys[(string)$s['source_id'].':'.(int)$s['version_number']]))$sources[]=$s;
    $annotations=[];foreach((array)($prov['annotations']??[]) as $a)if(isset($annotationIds[(string)$a['id']]))$annotations[]=$a;
    return ['claims'=>$claims,'source_versions'=>$sources,'annotations'=>$annotations];
}

function research_evidence_pack_manifest(PDO $pdo,array $viewer,string $projectPublic,string $scopeType='project',string $scopePublic=''): array {
    if(!isset(research_evidence_pack_scope_types()[$scopeType]))throw new InvalidArgumentException('Invalid Evidence Pack scope.');
    $project=project_access($pdo,(int)$viewer['id'],trim($projectPublic));if(!$project)throw new RuntimeException('Research project is unavailable.');
    if(!function_exists('provenance_ready')||!provenance_ready($pdo))throw new RuntimeException('Evidence Packs require Research Provenance.');
    $prov=provenance_project_manifest($pdo,$viewer,$projectPublic);unset($prov['evidence_packs'],$prov['decision_memory']);
    $manifest=['schema'=>'annotated-evidence-pack-v1','scope'=>['type'=>$scopeType,'public_id'=>$scopePublic?:$projectPublic],'project'=>['public_id'=>$project['public_id'],'title'=>$project['title'],'status'=>$project['status']??'active']];

    if($scopeType==='project'){
        $manifest['provenance']=$prov;
        $manifest['verification_claims']=research_evidence_pack_project_verification($pdo,$viewer,$projectPublic);
    } elseif($scopeType==='claim'){
        $claim=research_claim_access($pdo,$viewer,$scopePublic);if(!$claim||(int)$claim['project_id']!==(int)$project['id'])throw new RuntimeException('Claim is unavailable in this project.');
        $deps=research_evidence_pack_filter_claim_dependencies($prov,[$scopePublic]);
        $manifest=array_merge($manifest,$deps);
        $manifest['findings']=array_values(array_filter((array)($prov['findings']??[]),fn($f)=>count(array_filter((array)$f['claims'],fn($l)=>(string)$l['claim_id']===$scopePublic))>0));
        $manifest['reviews']=array_values(array_filter((array)($prov['reviews']??[]),fn($r)=>$r['subject_type']==='claim'&&(string)$r['subject_id']===$scopePublic));
        $manifest['verification_events']=array_values(array_filter((array)($prov['verification_events']??[]),fn($r)=>$r['subject_type']==='claim'&&(string)$r['subject_id']===$scopePublic));
        $manifest['verification']=research_evidence_pack_claim_summary($pdo,$viewer,$scopePublic);
    } elseif($scopeType==='finding'){
        $finding=null;foreach((array)($prov['findings']??[]) as $f)if((string)$f['id']===$scopePublic){$finding=$f;break;}
        if(!$finding)throw new RuntimeException('Finding is unavailable in this project.');
        $claimIds=array_values(array_unique(array_map(fn($l)=>(string)$l['claim_id'],(array)$finding['claims'])));
        $manifest['finding']=$finding;
        $manifest=array_merge($manifest,research_evidence_pack_filter_claim_dependencies($prov,$claimIds));
        $manifest['verification_claims']=array_values(array_filter(research_evidence_pack_project_verification($pdo,$viewer,$projectPublic),fn($v)=>in_array((string)$v['claim_id'],$claimIds,true)));
        $manifest['reviews']=array_values(array_filter((array)($prov['reviews']??[]),fn($r)=>$r['subject_type']==='finding'&&(string)$r['subject_id']===$scopePublic));
        $manifest['verification_events']=array_values(array_filter((array)($prov['verification_events']??[]),fn($r)=>$r['subject_type']==='finding'&&(string)$r['subject_id']===$scopePublic));
    } else {
        $row=null;foreach((array)($prov['report_versions']??[]) as $r)if((string)$r['version_id']===$scopePublic){$row=$r;break;}
        if(!$row)throw new RuntimeException('Report version is unavailable in this project.');
        $q=$pdo->prepare("SELECT rv.snapshot_json FROM research_report_versions rv JOIN research_reports rr ON rr.id=rv.report_id WHERE rv.public_id=? AND rr.project_id=? LIMIT 1");$q->execute([$scopePublic,$project['id']]);$json=$q->fetchColumn();if($json===false)throw new RuntimeException('Report snapshot is unavailable.');
        $snapshot=json_decode((string)$json,true);if(!is_array($snapshot))throw new RuntimeException('Report snapshot is invalid.');
        $manifest['report_version']=$row;$manifest['snapshot']=$snapshot;$manifest['snapshot_hash_valid']=hash_equals((string)$row['stored_snapshot_hash'],hash('sha256',(string)$json));
        $manifest['reviews']=array_values(array_filter((array)($prov['reviews']??[]),fn($r)=>$r['subject_type']==='report_version'&&(string)$r['subject_id']===$scopePublic));
        $manifest['verification_events']=array_values(array_filter((array)($prov['verification_events']??[]),fn($r)=>$r['subject_type']==='report_version'&&(string)$r['subject_id']===$scopePublic));
    }
    return research_evidence_pack_canonical($manifest);
}

function research_evidence_pack_scope_hash(array $manifest): string {
    return hash('sha256',research_evidence_pack_encode(['scope'=>$manifest['scope']??[],'project'=>$manifest['project']??[],'payload'=>array_diff_key($manifest,['schema'=>1,'scope'=>1,'project'=>1])]));
}

function research_evidence_pack_create(PDO $pdo,array $viewer,string $projectPublic,string $scopeType='project',string $scopePublic=''): array {
    if(!research_evidence_packs_ready($pdo))throw new RuntimeException('Evidence Packs require the Phase 28 database upgrade.');
    $project=project_access($pdo,(int)$viewer['id'],$projectPublic);if(!$project)throw new RuntimeException('Research project is unavailable.');
    if(!in_array((string)($project['access_role']??''),['owner','admin','researcher'],true))throw new RuntimeException('You have view-only access to this Research project.');
    if($scopeType==='project')$scopePublic=$projectPublic;
    $manifest=research_evidence_pack_manifest($pdo,$viewer,$projectPublic,$scopeType,$scopePublic);$json=research_evidence_pack_encode($manifest);$hash=hash('sha256',$json);$scopeHash=research_evidence_pack_scope_hash($manifest);$public=ulid_like();
    $pdo->prepare('INSERT INTO research_evidence_packs(public_id,project_id,created_by_user_id,scope_type,scope_public_id,scope_hash,manifest_hash,manifest_json) VALUES(?,?,?,?,?,?,?,?)')->execute([$public,$project['id'],$viewer['id'],$scopeType,$scopePublic,$scopeHash,$hash,$json]);
    return research_evidence_pack_access($pdo,$viewer,$public)??[];
}

function research_evidence_pack_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!research_evidence_packs_ready($pdo))return null;
    $q=$pdo->prepare("SELECT rep.*,rp.public_id project_public_id,rp.title project_title,u.public_id creator_public_id,u.display_name creator_name,u.username creator_username FROM research_evidence_packs rep JOIN research_projects rp ON rp.id=rep.project_id JOIN users u ON u.id=rep.created_by_user_id WHERE rep.public_id=? AND rep.created_by_user_id=? LIMIT 1");$q->execute([trim($publicId),$viewer['id']]);$r=$q->fetch();if(!$r)return null;
    if(!project_access($pdo,(int)$viewer['id'],(string)$r['project_public_id']))return null;
    $manifest=json_decode((string)$r['manifest_json'],true);if(!is_array($manifest))return null;$r['manifest']=$manifest;$r['stored_hash_valid']=hash_equals((string)$r['manifest_hash'],hash('sha256',research_evidence_pack_encode($manifest)));
    try{$current=research_evidence_pack_manifest($pdo,$viewer,(string)$r['project_public_id'],(string)$r['scope_type'],(string)$r['scope_public_id']);$r['current_manifest_hash']=research_evidence_pack_hash($current);$r['current_matches_pack']=hash_equals((string)$r['manifest_hash'],$r['current_manifest_hash']);$r['current_manifest']=$current;}
    catch(Throwable $e){$r['current_manifest_hash']=null;$r['current_matches_pack']=false;$r['current_manifest']=null;$r['current_error']=$e->getMessage();}
    return $r;
}

function research_evidence_pack_list(PDO $pdo,array $viewer,string $projectPublic,int $limit=100,bool $withCurrent=true): array {
    $project=project_access($pdo,(int)$viewer['id'],$projectPublic);if(!$project||!research_evidence_packs_ready($pdo))return [];$limit=max(1,min(300,$limit));
    $q=$pdo->prepare("SELECT rep.public_id,rep.project_id,rep.created_by_user_id,rep.scope_type,rep.scope_public_id,rep.scope_hash,rep.manifest_hash,rep.created_at,
        CASE WHEN LOWER(rep.manifest_hash)=LOWER(SHA2(rep.manifest_json,256)) THEN 1 ELSE 0 END stored_hash_valid,
        u.public_id creator_public_id,u.display_name creator_name,u.username creator_username
      FROM research_evidence_packs rep JOIN users u ON u.id=rep.created_by_user_id
      WHERE rep.project_id=? AND rep.created_by_user_id=? ORDER BY rep.id DESC LIMIT ".$limit);
    $q->execute([$project['id'],$viewer['id']]);$out=[];
    foreach($q->fetchAll() as $r){
        $r['stored_hash_valid']=(bool)$r['stored_hash_valid'];$r['current_matches_pack']=null;
        if($withCurrent){try{$current=research_evidence_pack_manifest($pdo,$viewer,$projectPublic,(string)$r['scope_type'],(string)$r['scope_public_id']);$r['current_matches_pack']=hash_equals((string)$r['manifest_hash'],research_evidence_pack_hash($current));}catch(Throwable $e){$r['current_matches_pack']=false;}}
        $out[]=$r;
    }
    return $out;
}
function research_evidence_pack_sections(array $manifest): array {
    $skip=['schema','scope','project'];$out=[];foreach($manifest as $k=>$v)if(!in_array($k,$skip,true))$out[$k]=hash('sha256',research_evidence_pack_encode([$k=>$v]));ksort($out,SORT_STRING);return $out;
}
function research_evidence_pack_compare(array $pack): array {
    $before=research_evidence_pack_sections((array)$pack['manifest']);$after=is_array($pack['current_manifest']??null)?research_evidence_pack_sections($pack['current_manifest']):[];$keys=array_values(array_unique(array_merge(array_keys($before),array_keys($after))));sort($keys,SORT_STRING);$changed=[];$added=[];$removed=[];
    foreach($keys as $k){if(!isset($before[$k]))$added[]=$k;elseif(!isset($after[$k]))$removed[]=$k;elseif(!hash_equals($before[$k],$after[$k]))$changed[]=$k;}
    return ['matches'=>(bool)($pack['current_matches_pack']??false),'changed_sections'=>$changed,'added_sections'=>$added,'removed_sections'=>$removed,'pack_hash'=>$pack['manifest_hash'],'current_hash'=>$pack['current_manifest_hash']??null];
}

function research_evidence_pack_project_context(PDO $pdo,array $viewer,string $projectPublic,int $limit=5): array {
    if(!research_evidence_packs_ready($pdo))return ['text'=>'','refs'=>[]];
    $packs=research_evidence_pack_list($pdo,$viewer,$projectPublic,$limit);if(!$packs)return ['text'=>'','refs'=>[]];
    $lines=['[REPRODUCIBILITY / EVIDENCE PACKS]','Evidence Packs are immutable snapshots for replay and comparison; current Research remains authoritative.'];$refs=[];
    foreach($packs as $p){$lines[]='- [EVIDENCE PACK '.$p['public_id'].'] '.$p['scope_type'].' '.$p['scope_public_id'].' · created '.$p['created_at'].' · '.($p['stored_hash_valid']?'manifest hash valid':'MANIFEST HASH MISMATCH').' · '.($p['current_matches_pack']?'matches current Research':'current Research has drifted').'.';$refs[]=['type'=>'evidence_pack','id'=>$p['public_id']];}
    $lines[]='Agent policy: use a pack to explain what evidence was frozen at that point in time. Never substitute a stale pack for current Research without saying that drift exists.';
    return ['text'=>implode("\n",$lines),'refs'=>$refs];
}

function research_evidence_pack_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limitProjects=10): void {
    if(!research_evidence_packs_ready($pdo))return;$q=$pdo->prepare("SELECT DISTINCT rp.public_id FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE rp.owner_user_id=? OR tm.user_id=? ORDER BY rp.updated_at DESC LIMIT ".$limitProjects);$q->execute([$viewer['id'],$viewer['id'],$viewer['id']]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $projectPublic){$packs=research_evidence_pack_list($pdo,$viewer,(string)$projectPublic,3);$drift=array_values(array_filter($packs,fn($p)=>!$p['current_matches_pack']));if(!$drift)continue;$latest=$drift[0];cognitive_feed_add($items,[
        'key'=>cognitive_feed_key('evidence_pack_drift','evidence_pack',(string)$latest['public_id'],(string)($latest['created_at']??'')),
        'type'=>'evidence_pack_drift','section'=>'needs_attention','priority'=>'medium','created_at'=>$latest['created_at'],'score_extra'=>8,
        'title'=>'Evidence Pack differs from current Research','body'=>count($drift).' recent Evidence Pack(s) no longer match the current Research state. The packs remain immutable and replayable.',
        'meta'=>['project'=>$projectPublic,'pack_id'=>$latest['public_id'],'drifted_packs'=>count($drift)],
        'actions'=>[cognitive_feed_action_link('Compare pack','/research-evidence-pack.php?pack='.rawurlencode((string)$latest['public_id'])),cognitive_feed_action_agent('Ask Agent','Explain what an Evidence Pack preserves and why current Research can differ. Do not rewrite the pack.',[['type'=>'research','public_id'=>(string)$projectPublic]])],
    ]);}
}
