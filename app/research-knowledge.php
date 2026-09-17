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
