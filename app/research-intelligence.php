<?php
declare(strict_types=1);

function research_claim_relation_rows(PDO $pdo,int $projectId,int $claimId): array {
    $q=$pdo->prepare("SELECT cr.public_id,cr.relation_type,cr.note,cr.created_at,
        sc.public_id source_public_id,sc.statement source_statement,
        tc.public_id target_public_id,tc.statement target_statement,
        CASE WHEN cr.source_claim_id=? THEN 'outgoing' ELSE 'incoming' END direction
        FROM claim_relations cr
        JOIN research_claims sc ON sc.id=cr.source_claim_id
        JOIN research_claims tc ON tc.id=cr.target_claim_id
        WHERE cr.project_id=? AND (cr.source_claim_id=? OR cr.target_claim_id=?)
        ORDER BY cr.created_at DESC");
    $q->execute([$claimId,$projectId,$claimId,$claimId]);return $q->fetchAll();
}

function research_project_claim_target(PDO $pdo,int $projectId,string $publicId): ?array {
    $q=$pdo->prepare('SELECT id,public_id,statement,status FROM research_claims WHERE project_id=? AND public_id=? LIMIT 1');
    $q->execute([$projectId,$publicId]);return $q->fetch()?:null;
}

function research_project_graph_rows(PDO $pdo,int $projectId): array {
    $q=$pdo->prepare("SELECT cr.public_id,cr.relation_type,cr.note,cr.created_at,
        sc.public_id source_public_id,sc.statement source_statement,sc.status source_status,
        tc.public_id target_public_id,tc.statement target_statement,tc.status target_status
        FROM claim_relations cr
        JOIN research_claims sc ON sc.id=cr.source_claim_id
        JOIN research_claims tc ON tc.id=cr.target_claim_id
        WHERE cr.project_id=? ORDER BY cr.created_at DESC");
    $q->execute([$projectId]);return $q->fetchAll();
}

function research_project_timeline(PDO $pdo,int $projectId): array {
    $events=[];$add=function(string $type,string $title,string $body,string $at,?string $href=null)use(&$events): void {$events[]=['type'=>$type,'title'=>$title,'body'=>$body,'occurred_at'=>$at,'href'=>$href];};
    $q=$pdo->prepare('SELECT s.public_id,s.title,s.canonical_url,ps.created_at FROM project_sources ps JOIN sources s ON s.id=ps.source_id WHERE ps.project_id=?');$q->execute([$projectId]);foreach($q->fetchAll() as $r)$add('source','Source added',(string)($r['title']?:$r['canonical_url']),(string)$r['created_at'],'/source.php?id='.rawurlencode((string)$r['public_id']));
    $q=$pdo->prepare('SELECT a.public_id,a.text_commentary,pa.created_at FROM project_annotations pa JOIN annotations a ON a.id=pa.annotation_id WHERE pa.project_id=?');$q->execute([$projectId]);foreach($q->fetchAll() as $r)$add('annotation','Annotation added',mb_substr((string)$r['text_commentary'],0,240),(string)$r['created_at'],'/annotation.php?id='.rawurlencode((string)$r['public_id']));
    $q=$pdo->prepare('SELECT body,created_at FROM research_notes WHERE project_id=?');$q->execute([$projectId]);foreach($q->fetchAll() as $r)$add('note','Research note',mb_substr((string)$r['body'],0,240),(string)$r['created_at']);
    $q=$pdo->prepare('SELECT public_id,title,status,task_type,created_at FROM research_tasks WHERE project_id=?');$q->execute([$projectId]);foreach($q->fetchAll() as $r)$add('task','Task created',(string)$r['title'].' · '.$r['task_type'].' · '.$r['status'],(string)$r['created_at']);
    $q=$pdo->prepare('SELECT public_id,statement,status,created_at FROM research_claims WHERE project_id=?');$q->execute([$projectId]);foreach($q->fetchAll() as $r)$add('claim','Claim created',mb_substr((string)$r['statement'],0,240).' · '.$r['status'],(string)$r['created_at'],'/research-claim.php?id='.rawurlencode((string)$r['public_id']));
    $q=$pdo->prepare("SELECT rc.public_id claim_public_id,ce.relationship,ce.evidence_type,ce.created_at FROM claim_evidence ce JOIN research_claims rc ON rc.id=ce.claim_id WHERE rc.project_id=?");$q->execute([$projectId]);foreach($q->fetchAll() as $r)$add('evidence','Evidence attached',ucfirst((string)$r['relationship']).' '.$r['evidence_type'].' evidence',(string)$r['created_at'],'/research-claim.php?id='.rawurlencode((string)$r['claim_public_id']));
    $q=$pdo->prepare('SELECT public_id,title,status,created_at FROM research_findings WHERE project_id=?');$q->execute([$projectId]);foreach($q->fetchAll() as $r)$add('finding','Finding created',(string)$r['title'].' · '.$r['status'],(string)$r['created_at'],'/research-finding.php?id='.rawurlencode((string)$r['public_id']));
    $q=$pdo->prepare("SELECT cr.relation_type,sc.public_id source_public_id,tc.public_id target_public_id,cr.created_at FROM claim_relations cr JOIN research_claims sc ON sc.id=cr.source_claim_id JOIN research_claims tc ON tc.id=cr.target_claim_id WHERE cr.project_id=?");$q->execute([$projectId]);foreach($q->fetchAll() as $r)$add('claim_relation','Claim relationship',strtoupper((string)$r['relation_type']).' '.$r['source_public_id'].' → '.$r['target_public_id'],(string)$r['created_at'],'/research-graph.php?id='.rawurlencode((string)$projectId));
    $q=$pdo->prepare("SELECT sce.change_type,sce.target_changed,sce.diff_summary,s.public_id source_public_id,s.title,sce.created_at FROM source_change_events sce JOIN project_sources ps ON ps.source_id=sce.source_id JOIN sources s ON s.id=sce.source_id WHERE ps.project_id=?");$q->execute([$projectId]);foreach($q->fetchAll() as $r)$add('source_change','Source '.(string)$r['change_type'],((int)$r['target_changed']?'Captured target changed. ':'').(string)($r['diff_summary']??$r['title']),(string)$r['created_at'],'/source.php?id='.rawurlencode((string)$r['source_public_id']));
    $q=$pdo->prepare('SELECT public_id,generated_by,created_at FROM research_briefs WHERE project_id=?');$q->execute([$projectId]);foreach($q->fetchAll() as $r)$add('brief','Research Brief saved','Generated by '.$r['generated_by'],(string)$r['created_at'],'/research-brief.php?id='.rawurlencode((string)$r['public_id']));
    usort($events,fn(array $a,array $b)=>strcmp($b['occurred_at'],$a['occurred_at']));return $events;
}

function research_project_brief_snapshot(PDO $pdo,int $projectId): array {
    $scalar=function(string $sql,array $params=[])use($pdo): int {$q=$pdo->prepare($sql);$q->execute($params);return (int)$q->fetchColumn();};
    $counts=[
        'sources'=>$scalar('SELECT COUNT(*) FROM project_sources WHERE project_id=?',[$projectId]),
        'annotations'=>$scalar('SELECT COUNT(*) FROM project_annotations WHERE project_id=?',[$projectId]),
        'claims'=>$scalar('SELECT COUNT(*) FROM research_claims WHERE project_id=?',[$projectId]),
        'supported'=>$scalar("SELECT COUNT(*) FROM research_claims WHERE project_id=? AND status='supported'",[$projectId]),
        'disputed'=>$scalar("SELECT COUNT(*) FROM research_claims WHERE project_id=? AND status IN ('disputed','contradicted')",[$projectId]),
        'unverified'=>$scalar("SELECT COUNT(*) FROM research_claims WHERE project_id=? AND status='unverified'",[$projectId]),
        'findings'=>$scalar("SELECT COUNT(*) FROM research_findings WHERE project_id=? AND status<>'archived'",[$projectId]),
        'open_tasks'=>$scalar("SELECT COUNT(*) FROM research_tasks WHERE project_id=? AND status IN ('open','in_progress')",[$projectId]),
        'relations'=>$scalar('SELECT COUNT(*) FROM claim_relations WHERE project_id=?',[$projectId]),
        'recent_source_changes'=>$scalar('SELECT COUNT(*) FROM source_change_events sce JOIN project_sources ps ON ps.source_id=sce.source_id WHERE ps.project_id=? AND sce.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)',[$projectId]),
    ];
    $q=$pdo->prepare("SELECT rc.public_id,rc.statement,rc.status,(SELECT COUNT(*) FROM claim_evidence ce WHERE ce.claim_id=rc.id) evidence_count FROM research_claims rc WHERE rc.project_id=? ORDER BY FIELD(rc.status,'contradicted','disputed','unverified','supported','resolved'),evidence_count DESC,rc.updated_at DESC LIMIT 12");$q->execute([$projectId]);$claims=$q->fetchAll();
    $q=$pdo->prepare("SELECT rc.public_id,rc.statement FROM research_claims rc WHERE rc.project_id=? AND rc.status='unverified' AND NOT EXISTS(SELECT 1 FROM claim_evidence ce WHERE ce.claim_id=rc.id) ORDER BY rc.updated_at DESC LIMIT 10");$q->execute([$projectId]);$gaps=$q->fetchAll();
    $q=$pdo->prepare("SELECT cr.relation_type,sc.public_id source_id,sc.statement source_statement,tc.public_id target_id,tc.statement target_statement FROM claim_relations cr JOIN research_claims sc ON sc.id=cr.source_claim_id JOIN research_claims tc ON tc.id=cr.target_claim_id WHERE cr.project_id=? AND cr.relation_type='contradicts' ORDER BY cr.created_at DESC LIMIT 10");$q->execute([$projectId]);$conflicts=$q->fetchAll();
    $q=$pdo->prepare("SELECT public_id,title,task_type,status FROM research_tasks WHERE project_id=? AND status IN ('open','in_progress') ORDER BY created_at DESC LIMIT 10");$q->execute([$projectId]);$tasks=$q->fetchAll();
    return ['counts'=>$counts,'claims'=>$claims,'gaps'=>$gaps,'conflicts'=>$conflicts,'tasks'=>$tasks,'generated_at'=>date('c')];
}

function research_intelligence_ai_context(PDO $pdo,int $projectId): array {
    $refs=[];$chunks=[];
    foreach(research_project_graph_rows($pdo,$projectId) as $r){$refs[]=['type'=>'claim_relation','id'=>$r['public_id']];$chunks[]='[CLAIM-RELATION '.$r['public_id'].'] '.$r['source_public_id'].' '.$r['relation_type'].' '.$r['target_public_id'].($r['note']?' · '.$r['note']:'');}
    $snap=research_project_brief_snapshot($pdo,$projectId);$c=$snap['counts'];$chunks[]='[PROJECT-SNAPSHOT] sources '.$c['sources'].'; annotations '.$c['annotations'].'; claims '.$c['claims'].'; supported '.$c['supported'].'; disputed/contradicted '.$c['disputed'].'; unverified '.$c['unverified'].'; findings '.$c['findings'].'; open tasks '.$c['open_tasks'].'; recent source changes '.$c['recent_source_changes'].'.';
    return ['refs'=>$refs,'text'=>implode("\n",$chunks),'snapshot'=>$snap];
}
