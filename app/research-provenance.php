<?php
declare(strict_types=1);

function provenance_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_audit_receipts');}
    catch(Throwable $e){return false;}
}

function provenance_canonicalize(mixed $value): mixed {
    if(!is_array($value))return $value;
    if(array_is_list($value))return array_map('provenance_canonicalize',$value);
    ksort($value,SORT_STRING);foreach($value as $k=>$v)$value[$k]=provenance_canonicalize($v);return $value;
}

function provenance_encode(array $manifest): string {
    $json=json_encode(provenance_canonicalize($manifest),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)throw new RuntimeException('Unable to encode provenance manifest.');return $json;
}
function provenance_hash(array $manifest): string {return hash('sha256',provenance_encode($manifest));}

function provenance_project_source_versions(PDO $pdo,array $viewer,int $projectId): array {
    $rows=[];$add=function(array $r,string $role)use(&$rows){$key=$r['source_public_id'].':'.$r['version_number'];if(!isset($rows[$key]))$rows[$key]=['source_id'=>$r['source_public_id'],'source_title'=>$r['source_title'],'url'=>$r['canonical_url'],'source_status'=>$r['source_status'],'version_number'=>(int)$r['version_number'],'version_public_key'=>$key,'captured_at'=>$r['captured_at'],'recorded_content_hash'=>$r['content_hash'],'recorded_target_content_hash'=>$r['target_content_hash'],'roles'=>[]];if(!in_array($role,$rows[$key]['roles'],true))$rows[$key]['roles'][]=$role;};
    $q=$pdo->prepare("SELECT s.public_id source_public_id,s.title source_title,s.canonical_url,s.status source_status,sv.version_number,sv.captured_at,sv.content_hash,sv.target_content_hash FROM project_sources ps JOIN sources s ON s.id=ps.source_id JOIN source_versions sv ON sv.id=s.current_version_id WHERE ps.project_id=? ORDER BY s.public_id,sv.version_number");$q->execute([$projectId]);foreach($q->fetchAll() as $r)$add($r,'project_source_current');
    $q=$pdo->prepare("SELECT s.public_id source_public_id,s.title source_title,s.canonical_url,s.status source_status,sv.version_number,sv.captured_at,sv.content_hash,sv.target_content_hash FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id JOIN sources s ON s.id=a.source_id JOIN source_versions sv ON sv.id=a.source_version_id WHERE pa.project_id=? ORDER BY s.public_id,sv.version_number");$q->execute([$projectId]);foreach($q->fetchAll() as $r)$add($r,'annotation');
    $q=$pdo->prepare("SELECT s.public_id source_public_id,s.title source_title,s.canonical_url,s.status source_status,sv.version_number,sv.captured_at,sv.content_hash,sv.target_content_hash FROM research_claims rc JOIN claim_evidence ce ON ce.claim_id=rc.id JOIN source_versions sv ON sv.id=ce.source_version_id JOIN sources s ON s.id=sv.source_id WHERE rc.project_id=? ORDER BY s.public_id,sv.version_number");$q->execute([$projectId]);foreach($q->fetchAll() as $r)$add($r,'claim_evidence');
    foreach($rows as $key=>$r)if(source_access($pdo,(string)$r['source_id'],$viewer)===null)unset($rows[$key]);
    ksort($rows,SORT_STRING);foreach($rows as &$r)sort($r['roles'],SORT_STRING);unset($r);return array_values($rows);
}

function provenance_project_annotations(PDO $pdo,array $viewer,int $projectId): array {
    $q=$pdo->prepare("SELECT a.public_id,a.visibility,a.status,a.created_at,a.updated_at,a.text_commentary,c.public_id capture_public_id,c.capture_type,c.selected_text,s.public_id source_public_id,sv.version_number source_version_number
      FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id JOIN captures c ON c.id=a.capture_id JOIN sources s ON s.id=a.source_id JOIN source_versions sv ON sv.id=a.source_version_id
      WHERE pa.project_id=? ORDER BY a.public_id");$q->execute([$projectId]);$out=[];
    foreach($q->fetchAll() as $r){if(annotation_access($pdo,(string)$r['public_id'],$viewer)===null)continue;$out[]=['id'=>$r['public_id'],'visibility'=>$r['visibility'],'status'=>$r['status'],'source_id'=>$r['source_public_id'],'source_version'=>(int)$r['source_version_number'],'capture_id'=>$r['capture_public_id'],'capture_type'=>$r['capture_type'],'selected_text_hash'=>$r['selected_text']!==null?hash('sha256',(string)$r['selected_text']):null,'commentary_hash'=>$r['text_commentary']!==null?hash('sha256',(string)$r['text_commentary']):null,'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']];}
    return $out;
}

function provenance_project_claims(PDO $pdo,array $viewer,int $projectId): array {
    $q=$pdo->prepare("SELECT public_id,statement,claim_type,status,resolution_note,created_at,updated_at,id FROM research_claims WHERE project_id=? ORDER BY public_id");$q->execute([$projectId]);$out=[];
    foreach($q->fetchAll() as $c){$eq=$pdo->prepare("SELECT ce.public_id,ce.evidence_type,ce.relationship,ce.note,a.public_id annotation_public_id,s.public_id source_public_id,sv.version_number source_version_number FROM claim_evidence ce JOIN source_versions sv ON sv.id=ce.source_version_id JOIN sources s ON s.id=sv.source_id LEFT JOIN annotations a ON a.id=ce.annotation_id WHERE ce.claim_id=? ORDER BY ce.public_id");$eq->execute([$c['id']]);$ev=[];foreach($eq->fetchAll() as $e){$sourceOk=source_access($pdo,(string)$e['source_public_id'],$viewer)!==null;$annotationOk=empty($e['annotation_public_id'])||annotation_access($pdo,(string)$e['annotation_public_id'],$viewer)!==null;if(!$sourceOk||!$annotationOk){$ev[]=['id'=>$e['public_id'],'type'=>$e['evidence_type'],'relationship'=>$e['relationship'],'available'=>false];continue;}$ev[]=['id'=>$e['public_id'],'type'=>$e['evidence_type'],'relationship'=>$e['relationship'],'available'=>true,'annotation_id'=>$e['annotation_public_id'],'source_id'=>$e['source_public_id'],'source_version'=>(int)$e['source_version_number'],'note_hash'=>$e['note']!==null?hash('sha256',(string)$e['note']):null];}$out[]=['id'=>$c['public_id'],'statement'=>$c['statement'],'type'=>$c['claim_type'],'status'=>$c['status'],'resolution_note'=>$c['resolution_note'],'evidence'=>$ev,'created_at'=>$c['created_at'],'updated_at'=>$c['updated_at']];}
    return $out;
}

function provenance_project_findings(PDO $pdo,int $projectId): array {
    $q=$pdo->prepare("SELECT id,public_id,title,summary,status,created_at,updated_at FROM research_findings WHERE project_id=? ORDER BY public_id");$q->execute([$projectId]);$out=[];
    foreach($q->fetchAll() as $f){$cq=$pdo->prepare("SELECT rc.public_id,fc.relationship,fc.position FROM finding_claims fc JOIN research_claims rc ON rc.id=fc.claim_id WHERE fc.finding_id=? ORDER BY fc.position,rc.public_id");$cq->execute([$f['id']]);$links=[];foreach($cq->fetchAll() as $l)$links[]=['claim_id'=>$l['public_id'],'relationship'=>$l['relationship'],'position'=>(int)$l['position']];$out[]=['id'=>$f['public_id'],'title'=>$f['title'],'summary'=>$f['summary'],'status'=>$f['status'],'claims'=>$links,'created_at'=>$f['created_at'],'updated_at'=>$f['updated_at']];}
    return $out;
}

function provenance_project_reports(PDO $pdo,array $viewer,array $project): array {
    $q=$pdo->prepare("SELECT rr.public_id report_public_id,rr.status report_status,rr.current_version_id,rv.id version_id,rv.public_id version_public_id,rv.version_number,rv.visibility,rv.title,rv.snapshot_json,rv.snapshot_hash,rv.created_at FROM research_reports rr JOIN research_report_versions rv ON rv.report_id=rr.id WHERE rr.project_id=? ORDER BY rv.version_number");$q->execute([$project['id']]);$out=[];
    foreach($q->fetchAll() as $r){if(!in_array((string)($project['access_role']??''),['owner','admin'],true)){$accessibleReport=research_report_access($pdo,(string)$r['report_public_id'],$viewer);if(!$accessibleReport||!research_report_version_access($pdo,$accessibleReport,(int)$r['version_number'],$viewer))continue;}$computed=hash('sha256',(string)$r['snapshot_json']);$citations=[];if(function_exists('research_network_ready')&&research_network_ready($pdo)){$cq=$pdo->prepare("SELECT target_version_id,relation_type,note FROM research_report_version_citations WHERE source_version_id=? ORDER BY target_version_id");$cq->execute([$r['version_id']]);foreach($cq->fetchAll() as $edge){$target=research_network_version_meta_by_id($pdo,(int)$edge['target_version_id'],$viewer);$citations[]=$target?['available'=>true,'report_id'=>$target['report_public_id'],'version_id'=>$target['version_public_id'],'version_number'=>$target['version_number'],'relation'=>$edge['relation_type'],'note_hash'=>$edge['note']!==null?hash('sha256',(string)$edge['note']):null]:['available'=>false,'relation'=>$edge['relation_type']];}}
      $out[]=['report_id'=>$r['report_public_id'],'version_id'=>$r['version_public_id'],'version_number'=>(int)$r['version_number'],'visibility'=>$r['visibility'],'title'=>$r['title'],'stored_snapshot_hash'=>$r['snapshot_hash'],'computed_snapshot_hash'=>$computed,'snapshot_hash_valid'=>hash_equals((string)$r['snapshot_hash'],$computed),'is_current'=>(int)$r['current_version_id']===(int)$r['version_id'],'report_status'=>$r['report_status'],'citations'=>$citations,'created_at'=>$r['created_at']];}
    return $out;
}

function provenance_project_reviews(PDO $pdo,array $viewer,int $projectId): array {
    if(!function_exists('research_reviews_ready')||!research_reviews_ready($pdo))return [];
    $q=$pdo->prepare("SELECT id,public_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status,due_at,completed_at,cancelled_at,completion_json,created_at,updated_at FROM research_reviews WHERE project_id=? ORDER BY public_id");
    $q->execute([$projectId]);$out=[];
    foreach($q->fetchAll() as $r){
        if(!research_review_access($pdo,$viewer,(string)$r['public_id']))continue;
        $rq=$pdo->prepare("SELECT public_id,decision,subject_hash,comment,created_at FROM research_review_responses WHERE review_id=? ORDER BY id");$rq->execute([$r['id']]);$responses=[];
        foreach($rq->fetchAll() as $resp)$responses[]=['id'=>$resp['public_id'],'decision'=>$resp['decision'],'subject_hash'=>$resp['subject_hash'],'comment_hash'=>$resp['comment']!==null?hash('sha256',(string)$resp['comment']):null,'created_at'=>$resp['created_at']];
        $out[]=['id'=>$r['public_id'],'subject_type'=>$r['subject_type'],'subject_id'=>$r['subject_public_id'],'subject_hash'=>$r['subject_hash'],'subject_version_label'=>$r['subject_version_label'],'title'=>$r['title'],'status'=>$r['status'],'responses'=>$responses,'completion_hash'=>$r['completion_json']!==null?hash('sha256',(string)$r['completion_json']):null,'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']];
    }
    return $out;
}
function provenance_project_outcomes(PDO $pdo,array $viewer,int $projectId): array {
    if(!function_exists('research_outcomes_ready')||!research_outcomes_ready($pdo))return [];$q=$pdo->prepare("SELECT public_id,event_type,decision_type,source_type,source_public_id,object_type,object_public_id,title,summary,result_type,result_public_id,is_manual,occurred_at FROM research_outcome_events WHERE project_id=? AND user_id=? ORDER BY public_id");$q->execute([$projectId,$viewer['id']]);return $q->fetchAll();
}

function provenance_project_verification(PDO $pdo,array $viewer,int $projectId): array {
    if(!function_exists('research_verification_ready')||!research_verification_ready($pdo))return [];
    $q=$pdo->prepare("SELECT rve.public_id,rve.subject_type,rve.subject_public_id,rve.subject_hash,rve.decision,rve.evidence_state_hash,rve.note,rve.created_at,u.public_id reviewer_public_id,u.display_name,u.username
      FROM research_verification_events rve JOIN users u ON u.id=rve.reviewer_user_id
      WHERE rve.project_id=? ORDER BY rve.id");
    $q->execute([$projectId]);$out=[];
    foreach($q->fetchAll() as $r){
        $subject=research_verification_subject($pdo,$viewer,(string)$r['subject_type'],(string)$r['subject_public_id']);if(!$subject)continue;
        $current=hash_equals((string)$r['subject_hash'],(string)$subject['hash']);
        if($current&&function_exists('change_impact_subject_latest_event')&&change_impact_subject_latest_event($pdo,$viewer,(string)$r['subject_type'],(string)$r['subject_public_id'],(string)$r['created_at']))$current=false;
        $out[]=['id'=>$r['public_id'],'subject_type'=>$r['subject_type'],'subject_id'=>$r['subject_public_id'],'subject_hash'=>$r['subject_hash'],'decision'=>$r['decision'],'evidence_state_hash'=>$r['evidence_state_hash'],'reviewer_id'=>$r['reviewer_public_id'],'reviewer_name'=>$r['display_name']?:$r['username'],'note_hash'=>$r['note']!==null?hash('sha256',(string)$r['note']):null,'is_current'=>$current,'created_at'=>$r['created_at']];
    }
    return $out;
}

function provenance_project_evidence_packs(PDO $pdo,array $viewer,int $projectId): array {
    if(!installer_table_exists($pdo,'research_evidence_packs'))return [];
    $q=$pdo->prepare("SELECT rep.public_id,rep.scope_type,rep.scope_public_id,rep.scope_hash,rep.manifest_hash,rep.manifest_json,rep.created_at,u.public_id creator_public_id
      FROM research_evidence_packs rep JOIN users u ON u.id=rep.created_by_user_id
      WHERE rep.project_id=? AND rep.created_by_user_id=? ORDER BY rep.id");
    $q->execute([$projectId,$viewer['id']]);$out=[];
    foreach($q->fetchAll() as $r){
        $json=(string)$r['manifest_json'];
        $out[]=['id'=>$r['public_id'],'scope_type'=>$r['scope_type'],'scope_id'=>$r['scope_public_id'],'scope_hash'=>$r['scope_hash'],'manifest_hash'=>$r['manifest_hash'],'manifest_hash_valid'=>hash_equals((string)$r['manifest_hash'],hash('sha256',$json)),'creator_id'=>$r['creator_public_id'],'created_at'=>$r['created_at']];
    }
    return $out;
}
function provenance_project_manifest(PDO $pdo,array $viewer,string $projectPublic): array {
    $project=project_access($pdo,(int)$viewer['id'],trim($projectPublic));if(!$project)throw new RuntimeException('Research project is unavailable.');
    $manifest=['schema'=>'annotated-provenance-v1','scope'=>['type'=>'project','project_id'=>$project['public_id']],'project'=>['public_id'=>$project['public_id'],'title'=>$project['title'],'description'=>$project['description'],'status'=>$project['status']??'active'],'source_versions'=>provenance_project_source_versions($pdo,$viewer,(int)$project['id']),'annotations'=>provenance_project_annotations($pdo,$viewer,(int)$project['id']),'claims'=>provenance_project_claims($pdo,$viewer,(int)$project['id']),'findings'=>provenance_project_findings($pdo,(int)$project['id']),'report_versions'=>provenance_project_reports($pdo,$viewer,$project),'reviews'=>provenance_project_reviews($pdo,$viewer,(int)$project['id']),'verification_events'=>provenance_project_verification($pdo,$viewer,(int)$project['id']),'evidence_packs'=>provenance_project_evidence_packs($pdo,$viewer,(int)$project['id']),'decision_memory'=>provenance_project_outcomes($pdo,$viewer,(int)$project['id'])];
    return provenance_canonicalize($manifest);
}

function provenance_manifest_edges(array $manifest): array {
    $edges=[];$seen=[];$add=function(string $from,string $to,string $type,array $meta=[])use(&$edges,&$seen){$key=$from.'|'.$to.'|'.$type;if(isset($seen[$key]))return;$seen[$key]=true;$edges[]=['from'=>$from,'to'=>$to,'type'=>$type,'meta'=>$meta];};
    foreach($manifest['annotations']??[] as $a)$add('source:'.$a['source_id'].':v'.$a['source_version'],'annotation:'.$a['id'],'captured_as');
    foreach($manifest['claims']??[] as $c)foreach($c['evidence']??[] as $e){if(empty($e['available']))continue;$add('source:'.$e['source_id'].':v'.$e['source_version'],'claim:'.$c['id'],'evidence',['relationship'=>$e['relationship']]);if(!empty($e['annotation_id']))$add('annotation:'.$e['annotation_id'],'claim:'.$c['id'],'annotation_evidence',['relationship'=>$e['relationship']]);}
    foreach($manifest['findings']??[] as $f)foreach($f['claims']??[] as $l)$add('claim:'.$l['claim_id'],'finding:'.$f['id'],'synthesized_into',['relationship'=>$l['relationship']]);
    foreach($manifest['report_versions']??[] as $r){$rv='report:'.$r['report_id'].':v'.$r['version_number'];foreach($r['citations']??[] as $c)if(!empty($c['available']))$add($rv,'report:'.$c['report_id'].':v'.$c['version_number'],'cites',['relation'=>$c['relation']]);}
    foreach($manifest['reviews']??[] as $r)$add($r['subject_type'].':'.$r['subject_id'],'review:'.$r['id'],'reviewed_as',['subject_hash'=>$r['subject_hash'],'status'=>$r['status']]);
    foreach($manifest['verification_events']??[] as $r)$add($r['subject_type'].':'.$r['subject_id'],'verification:'.$r['id'],'verification_review',['subject_hash'=>$r['subject_hash'],'decision'=>$r['decision'],'evidence_state_hash'=>$r['evidence_state_hash'],'current'=>$r['is_current']]);
    foreach($manifest['evidence_packs']??[] as $p)$add($p['scope_type'].':'.$p['scope_id'],'evidence_pack:'.$p['id'],'frozen_as',['scope_hash'=>$p['scope_hash'],'manifest_hash'=>$p['manifest_hash'],'manifest_hash_valid'=>$p['manifest_hash_valid']]);
    foreach($manifest['decision_memory']??[] as $o)if(!empty($o['object_type'])&&!empty($o['object_public_id']))$add($o['object_type'].':'.$o['object_public_id'],'decision:'.$o['public_id'],'decision_memory',['decision_type'=>$o['decision_type']]);
    usort($edges,fn($a,$b)=>strcmp($a['from'].'|'.$a['to'].'|'.$a['type'],$b['from'].'|'.$b['to'].'|'.$b['type']));return $edges;
}

function provenance_project_integrity(array $manifest): array {
    $reports=['total'=>0,'valid'=>0,'invalid'=>0,'items'=>[]];foreach($manifest['report_versions']??[] as $r){$reports['total']++;$r['snapshot_hash_valid']?$reports['valid']++:$reports['invalid']++;$reports['items'][]=['report_id'=>$r['report_id'],'version_number'=>$r['version_number'],'valid'=>$r['snapshot_hash_valid'],'stored_hash'=>$r['stored_snapshot_hash'],'computed_hash'=>$r['computed_snapshot_hash']];}
    return ['report_snapshots'=>$reports,'manifest_hash'=>provenance_hash($manifest)];
}

function provenance_receipt_create(PDO $pdo,array $viewer,string $projectPublic): array {
    if(!provenance_ready($pdo))throw new RuntimeException('Provenance Audit requires the Phase 26 database upgrade.');
    $project=project_access($pdo,(int)$viewer['id'],$projectPublic);if(!$project)throw new RuntimeException('Research project is unavailable.');
    $ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $manifest=provenance_project_manifest($pdo,$viewer,$projectPublic);$json=provenance_encode($manifest);$hash=hash('sha256',$json);$public=ulid_like();
        $pdo->prepare("INSERT INTO research_audit_receipts(public_id,created_by_user_id,project_id,scope_type,manifest_hash,manifest_json) VALUES(?,?,?,'project',?,?)")->execute([$public,$viewer['id'],$project['id'],$hash,$json]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return provenance_receipt_access($pdo,$viewer,$public)??[];
}

function provenance_receipt_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!provenance_ready($pdo))return null;$q=$pdo->prepare("SELECT rar.*,rp.public_id project_public_id,rp.title project_title FROM research_audit_receipts rar JOIN research_projects rp ON rp.id=rar.project_id WHERE rar.public_id=? AND rar.created_by_user_id=? LIMIT 1");$q->execute([trim($publicId),$viewer['id']]);$r=$q->fetch();if(!$r||!project_access($pdo,(int)$viewer['id'],(string)$r['project_public_id']))return null;$manifest=json_decode((string)$r['manifest_json'],true);if(!is_array($manifest))return null;$r['manifest']=$manifest;$r['stored_hash_valid']=hash_equals((string)$r['manifest_hash'],hash('sha256',provenance_encode($manifest)));$current=provenance_project_manifest($pdo,$viewer,(string)$r['project_public_id']);$r['current_manifest_hash']=provenance_hash($current);$r['current_matches_receipt']=hash_equals((string)$r['manifest_hash'],$r['current_manifest_hash']);return $r;
}

function provenance_receipts(PDO $pdo,array $viewer,string $projectPublic,int $limit=50): array {
    if(!provenance_ready($pdo))return [];$project=project_access($pdo,(int)$viewer['id'],$projectPublic);if(!$project)return [];$limit=max(1,min(200,$limit));$currentHash=provenance_hash(provenance_project_manifest($pdo,$viewer,$projectPublic));
    $q=$pdo->prepare("SELECT rar.id,rar.public_id,rar.created_by_user_id,rar.project_id,rar.scope_type,rar.report_version_id,rar.manifest_hash,rar.created_at,
        SHA2(rar.manifest_json,256) computed_manifest_hash,rp.public_id project_public_id,rp.title project_title
      FROM research_audit_receipts rar JOIN research_projects rp ON rp.id=rar.project_id
      WHERE rar.project_id=? AND rar.created_by_user_id=? ORDER BY rar.id DESC LIMIT ".$limit);
    $q->execute([$project['id'],$viewer['id']]);$out=[];
    foreach($q->fetchAll() as $r){
        $r['stored_hash_valid']=hash_equals(strtolower((string)$r['manifest_hash']),strtolower((string)$r['computed_manifest_hash']));
        unset($r['computed_manifest_hash']);
        $r['current_manifest_hash']=$currentHash;$r['current_matches_receipt']=hash_equals((string)$r['manifest_hash'],$currentHash);$out[]=$r;
    }
    return $out;
}
function provenance_project_context(PDO $pdo,array $viewer,string $projectPublic): array {
    $manifest=provenance_project_manifest($pdo,$viewer,$projectPublic);$integrity=provenance_project_integrity($manifest);$lines=['[RESEARCH PROVENANCE]','Source versions: '.count($manifest['source_versions']).'; annotations: '.count($manifest['annotations']).'; Claims: '.count($manifest['claims']).'; Findings: '.count($manifest['findings']).'; report versions: '.count($manifest['report_versions']).'; reviews: '.count($manifest['reviews']).'; verification events: '.count($manifest['verification_events']??[]).'; Evidence Packs: '.count($manifest['evidence_packs']??[]).'.','Report snapshot verification: '.$integrity['report_snapshots']['valid'].' valid; '.$integrity['report_snapshots']['invalid'].' mismatch.','Current provenance manifest SHA-256: '.$integrity['manifest_hash'].'.'];return ['text'=>implode("\n",$lines),'refs'=>[['type'=>'research_project','id'=>$projectPublic]],'manifest'=>$manifest,'integrity'=>$integrity];
}

function provenance_project_report_integrity(PDO $pdo,array $viewer,string $projectPublic): array {
    $project=project_access($pdo,(int)$viewer['id'],$projectPublic);if(!$project)return ['total'=>0,'valid'=>0,'invalid'=>0,'items'=>[],'revision'=>''];
    $q=$pdo->prepare("SELECT rr.public_id report_public_id,rv.public_id version_public_id,rv.version_number,rv.snapshot_hash,rv.snapshot_json FROM research_reports rr JOIN research_report_versions rv ON rv.report_id=rr.id WHERE rr.project_id=? ORDER BY rv.version_number");$q->execute([$project['id']]);$out=['total'=>0,'valid'=>0,'invalid'=>0,'items'=>[]];$rev=[];
    foreach($q->fetchAll() as $r){if(!in_array((string)($project['access_role']??''),['owner','admin'],true)){$accessibleReport=research_report_access($pdo,(string)$r['report_public_id'],$viewer);if(!$accessibleReport||!research_report_version_access($pdo,$accessibleReport,(int)$r['version_number'],$viewer))continue;}$computed=hash('sha256',(string)$r['snapshot_json']);$valid=hash_equals((string)$r['snapshot_hash'],$computed);$out['total']++;$valid?$out['valid']++:$out['invalid']++;$out['items'][]=['report_id'=>$r['report_public_id'],'version_id'=>$r['version_public_id'],'version_number'=>(int)$r['version_number'],'valid'=>$valid,'stored_hash'=>$r['snapshot_hash'],'computed_hash'=>$computed];$rev[]=$r['version_public_id'].':'.$r['snapshot_hash'].':'.$computed;}
    $out['revision']=hash('sha256',implode('|',$rev));return $out;
}

function provenance_cognitive_observations(PDO $pdo,array $viewer,array &$items,int $limitProjects=12): void {
    if(!provenance_ready($pdo))return;
    $q=$pdo->prepare("SELECT DISTINCT rp.public_id
        FROM research_projects rp
        LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=?
        WHERE rp.status='active' AND (rp.owner_user_id=? OR tm.user_id=?)
        ORDER BY rp.updated_at DESC
        LIMIT ".$limitProjects);
    $q->execute([$viewer['id'],$viewer['id'],$viewer['id']]);

    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $projectPublic){
        $projectPublic=(string)$projectPublic;
        $integrity=provenance_project_report_integrity($pdo,$viewer,$projectPublic);
        $invalid=(int)$integrity['invalid'];
        if($invalid<=0)continue;

        $actions=[
            cognitive_feed_action_link('Open provenance','/research-provenance.php?id='.rawurlencode($projectPublic)),
            cognitive_feed_action_agent(
                'Ask Agent',
                'Explain the provenance integrity mismatch in this Research project. Do not alter any report or evidence.',
                [['type'=>'research','public_id'=>$projectPublic]]
            ),
        ];

        cognitive_feed_add($items,[
            'key'=>cognitive_feed_key('provenance_integrity','research_project',$projectPublic,(string)$integrity['revision']),
            'type'=>'provenance_integrity',
            'section'=>'needs_attention',
            'priority'=>'high',
            'created_at'=>date('Y-m-d H:i:s'),
            'score_extra'=>25,
            'title'=>'Research provenance integrity mismatch',
            'body'=>$invalid.' stored report snapshot hash'.($invalid===1?' does':'es do').' not match recomputed immutable snapshot content.',
            'meta'=>[
                'project'=>$projectPublic,
                'invalid_report_snapshots'=>$invalid,
            ],
            'actions'=>$actions,
        ]);
    }
}