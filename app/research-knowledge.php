<?php
declare(strict_types=1);

function research_claim_access(PDO $pdo,array $user,string $publicId): ?array {
    $q=$pdo->prepare('SELECT rc.*,rp.public_id project_public_id,rp.title project_title FROM research_claims rc JOIN research_projects rp ON rp.id=rc.project_id WHERE rc.public_id=? LIMIT 1');
    $q->execute([$publicId]);$claim=$q->fetch();if(!$claim)return null;
    $project=project_access($pdo,(int)$user['id'],(string)$claim['project_public_id']);if(!$project)return null;
    $claim['access_role']=$project['access_role'];
    return $claim;
}

function research_finding_access(PDO $pdo,array $user,string $publicId): ?array {
    $q=$pdo->prepare('SELECT rf.*,rp.public_id project_public_id,rp.title project_title FROM research_findings rf JOIN research_projects rp ON rp.id=rf.project_id WHERE rf.public_id=? LIMIT 1');
    $q->execute([$publicId]);$finding=$q->fetch();if(!$finding)return null;
    $project=project_access($pdo,(int)$user['id'],(string)$finding['project_public_id']);if(!$project)return null;
    $finding['access_role']=$project['access_role'];
    return $finding;
}

function research_claim_evidence_rows(PDO $pdo,int $claimId): array {
    $q=$pdo->prepare("SELECT ce.public_id,ce.evidence_type,ce.relationship,ce.note,ce.created_at,
        a.public_id annotation_public_id,a.text_commentary,
        sv.id source_version_id,sv.version_number,sv.captured_at,
        s.public_id source_public_id,s.title source_title,s.canonical_url
        FROM claim_evidence ce
        JOIN source_versions sv ON sv.id=ce.source_version_id
        JOIN sources s ON s.id=sv.source_id
        LEFT JOIN annotations a ON a.id=ce.annotation_id
        WHERE ce.claim_id=? ORDER BY FIELD(ce.relationship,'primary','supports','contradicts','context'),ce.created_at");
    $q->execute([$claimId]);return $q->fetchAll();
}

function research_finding_claim_rows(PDO $pdo,int $findingId): array {
    $q=$pdo->prepare("SELECT rc.public_id,rc.statement,rc.claim_type,rc.status,fc.relationship,fc.position
        FROM finding_claims fc JOIN research_claims rc ON rc.id=fc.claim_id
        WHERE fc.finding_id=? ORDER BY fc.position,fc.created_at");
    $q->execute([$findingId]);return $q->fetchAll();
}

function research_project_annotation_evidence(PDO $pdo,int $projectId,string $annotationPublicId): ?array {
    $q=$pdo->prepare("SELECT a.id annotation_id,a.source_version_id,sv.source_id,sv.version_number,s.public_id source_public_id
        FROM project_annotations pa
        JOIN annotations a ON a.id=pa.annotation_id
        JOIN source_versions sv ON sv.id=a.source_version_id
        JOIN sources s ON s.id=sv.source_id
        WHERE pa.project_id=? AND a.public_id=? AND a.status='published' LIMIT 1");
    $q->execute([$projectId,$annotationPublicId]);return $q->fetch()?:null;
}

function research_project_source_evidence(PDO $pdo,int $projectId,string $sourcePublicId): ?array {
    $q=$pdo->prepare("SELECT s.id source_id,s.current_version_id source_version_id,sv.version_number
        FROM project_sources ps
        JOIN sources s ON s.id=ps.source_id
        JOIN source_versions sv ON sv.id=s.current_version_id
        WHERE ps.project_id=? AND s.public_id=? LIMIT 1");
    $q->execute([$projectId,$sourcePublicId]);return $q->fetch()?:null;
}

function research_knowledge_ai_context(PDO $pdo,int $projectId): array {
    $refs=[];$chunks=[];
    $q=$pdo->prepare('SELECT public_id,statement,claim_type,status,resolution_note FROM research_claims WHERE project_id=? ORDER BY updated_at DESC LIMIT 50');$q->execute([$projectId]);
    foreach($q->fetchAll() as $claim){
        $refs[]=['type'=>'claim','id'=>$claim['public_id']];$lines=[];
        $e=$pdo->prepare("SELECT ce.relationship,ce.evidence_type,a.public_id annotation_public_id,s.public_id source_public_id,sv.version_number FROM claim_evidence ce JOIN source_versions sv ON sv.id=ce.source_version_id JOIN sources s ON s.id=sv.source_id LEFT JOIN annotations a ON a.id=ce.annotation_id JOIN research_claims rc ON rc.id=ce.claim_id WHERE rc.public_id=? AND rc.project_id=? ORDER BY ce.created_at");$e->execute([$claim['public_id'],$projectId]);
        foreach($e->fetchAll() as $row){$target=$row['annotation_public_id']?'[ANNOTATION '.$row['annotation_public_id'].']':'[SOURCE '.$row['source_public_id'].']';$lines[]=$row['relationship'].' '.$target.' source-version '.$row['version_number'];}
        $chunks[]='[CLAIM '.$claim['public_id']."]\nType: ".$claim['claim_type']."\nStatus: ".$claim['status']."\nStatement: ".$claim['statement'].($claim['resolution_note']?"\nResolution: ".$claim['resolution_note']:'').($lines?"\nEvidence: ".implode('; ',$lines):'\nEvidence: none');
    }
    $q=$pdo->prepare('SELECT public_id,title,summary,status FROM research_findings WHERE project_id=? AND status<>\'archived\' ORDER BY updated_at DESC LIMIT 30');$q->execute([$projectId]);
    foreach($q->fetchAll() as $finding){
        $refs[]=['type'=>'finding','id'=>$finding['public_id']];$links=[];$f=$pdo->prepare('SELECT rc.public_id,fc.relationship FROM finding_claims fc JOIN research_claims rc ON rc.id=fc.claim_id JOIN research_findings rf ON rf.id=fc.finding_id WHERE rf.public_id=? AND rf.project_id=? ORDER BY fc.position,fc.created_at');$f->execute([$finding['public_id'],$projectId]);
        foreach($f->fetchAll() as $row)$links[]=$row['relationship'].' [CLAIM '.$row['public_id'].']';
        $chunks[]='[FINDING '.$finding['public_id']."]\nStatus: ".$finding['status']."\nTitle: ".$finding['title']."\nSummary: ".$finding['summary'].($links?"\nClaims: ".implode('; ',$links):'\nClaims: none');
    }
    return ['refs'=>$refs,'text'=>implode("\n\n",$chunks)];
}
