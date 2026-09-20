<?php
declare(strict_types=1);

require_once __DIR__.'/research-intelligence.php';
require_once __DIR__.'/research-knowledge.php';
require_once __DIR__.'/research-entities.php';
require_once __DIR__.'/annotation-intelligence.php';
require_once __DIR__.'/ai.php';

function research_workspace_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'research_workspace_intelligence');}
    catch(Throwable $e){return false;}
}

function research_workspace_project_row(PDO $pdo,int $projectId): ?array {
    $q=$pdo->prepare('SELECT rp.* FROM research_projects rp WHERE rp.id=? LIMIT 1');
    $q->execute([$projectId]);return $q->fetch()?:null;
}

function research_workspace_claim_rows(PDO $pdo,int $projectId): array {
    $q=$pdo->prepare("SELECT rc.public_id,rc.statement,rc.claim_type,rc.status,rc.updated_at,
      COUNT(ce.id) evidence_count,
      COUNT(DISTINCT ce.source_version_id) source_version_count,
      COUNT(DISTINCT sv.source_id) source_count,
      SUM(ce.relationship='supports') supports_count,
      SUM(ce.relationship='contradicts') contradicts_count,
      SUM(ce.relationship='primary') primary_count
      FROM research_claims rc
      LEFT JOIN claim_evidence ce ON ce.claim_id=rc.id
      LEFT JOIN source_versions sv ON sv.id=ce.source_version_id
      WHERE rc.project_id=?
      GROUP BY rc.id
      ORDER BY FIELD(rc.status,'contradicted','disputed','unverified','supported','resolved'),rc.updated_at DESC,rc.id DESC
      LIMIT 80");
    $q->execute([$projectId]);$rows=$q->fetchAll();
    foreach($rows as &$r)foreach(['evidence_count','source_version_count','source_count','supports_count','contradicts_count','primary_count'] as $k)$r[$k]=(int)($r[$k]??0);
    unset($r);return $rows;
}

function research_workspace_source_risks(PDO $pdo,int $projectId,int $limit=20): array {
    $limit=max(1,min(50,$limit));
    $q=$pdo->prepare("SELECT s.public_id source_public_id,s.title,s.domain,s.status,
      COUNT(DISTINCT rc.id) affected_claims,
      MAX(sce.created_at) last_change_at,
      MAX(CASE WHEN sce.target_changed=1 THEN 1 ELSE 0 END) target_changed,
      SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(sce.diff_summary,'') ORDER BY sce.created_at DESC SEPARATOR ' || '),' || ',1) latest_diff
      FROM project_sources ps
      JOIN sources s ON s.id=ps.source_id
      LEFT JOIN source_change_events sce ON sce.source_id=s.id
      LEFT JOIN source_versions ev ON ev.source_id=s.id
      LEFT JOIN claim_evidence ce ON ce.source_version_id=ev.id
      LEFT JOIN research_claims rc ON rc.id=ce.claim_id AND rc.project_id=ps.project_id
      WHERE ps.project_id=?
      GROUP BY s.id
      HAVING last_change_at IS NOT NULL OR s.status<>'current'
      ORDER BY target_changed DESC,last_change_at DESC,s.id DESC LIMIT $limit");
    $q->execute([$projectId]);$out=[];
    foreach($q->fetchAll() as $r){$r['affected_claims']=(int)($r['affected_claims']??0);$r['target_changed']=(bool)$r['target_changed'];$out[]=$r;}
    return $out;
}

function research_workspace_annotation_links(PDO $pdo,int $projectId,int $limit=24): array {
    if(!annotation_intelligence_ready($pdo))return [];$limit=max(1,min(60,$limit));
    $q=$pdo->prepare("SELECT ar.relation_type,ar.confidence,sa.public_id source_annotation_id,ta.public_id target_annotation_id,
      ss.title source_title,ts.title target_title
      FROM annotation_relationships ar
      JOIN project_annotations spa ON spa.annotation_id=ar.source_annotation_id AND spa.project_id=?
      JOIN project_annotations tpa ON tpa.annotation_id=ar.target_annotation_id AND tpa.project_id=spa.project_id
      JOIN annotations sa ON sa.id=ar.source_annotation_id AND sa.status='published'
      JOIN annotations ta ON ta.id=ar.target_annotation_id AND ta.status='published'
      JOIN sources ss ON ss.id=sa.source_id JOIN sources ts ON ts.id=ta.source_id
      WHERE ar.source_annotation_id<ar.target_annotation_id
      ORDER BY FIELD(ar.relation_type,'conflicts','corroborates','duplicate','related'),ar.confidence DESC,ar.id DESC
      LIMIT $limit");
    $q->execute([$projectId]);$rows=$q->fetchAll();foreach($rows as &$r)$r['confidence']=(float)$r['confidence'];unset($r);return $rows;
}

function research_workspace_entity_rows(PDO $pdo,int $projectId,int $limit=16): array {
    $limit=max(1,min(40,$limit));
    $q=$pdo->prepare("SELECT re.public_id,re.entity_type,re.canonical_name,re.description,re.status,COUNT(rem.id) mention_count
      FROM research_entities re LEFT JOIN research_entity_mentions rem ON rem.entity_id=re.id
      WHERE re.project_id=? AND re.status<>'archived'
      GROUP BY re.id ORDER BY mention_count DESC,re.updated_at DESC,re.id DESC LIMIT $limit");
    $q->execute([$projectId]);$rows=$q->fetchAll();foreach($rows as &$r)$r['mention_count']=(int)$r['mention_count'];unset($r);return $rows;
}

function research_workspace_deterministic_snapshot(PDO $pdo,int $projectId): array {
    $project=research_workspace_project_row($pdo,$projectId);if(!$project)throw new RuntimeException('Research project not found.');
    $brief=research_project_brief_snapshot($pdo,$projectId);$claims=research_workspace_claim_rows($pdo,$projectId);
    $gaps=[];$conflicts=[];$next=[];
    foreach($claims as $c){
        if($c['status']==='unverified'&&$c['evidence_count']===0)$gaps[]=['type'=>'missing_evidence','claim_id'=>$c['public_id'],'title'=>'Claim has no evidence','detail'=>$c['statement'],'priority'=>'high'];
        elseif($c['status']==='unverified'&&$c['source_count']<=1)$gaps[]=['type'=>'single_source','claim_id'=>$c['public_id'],'title'=>'Claim relies on one source','detail'=>$c['statement'],'priority'=>'medium'];
        if($c['status']==='contradicted'||$c['contradicts_count']>0)$conflicts[]=['type'=>'claim_conflict','claim_id'=>$c['public_id'],'title'=>'Conflicting evidence','detail'=>$c['statement'],'priority'=>'high'];
        elseif($c['status']==='disputed')$conflicts[]=['type'=>'disputed_claim','claim_id'=>$c['public_id'],'title'=>'Disputed claim','detail'=>$c['statement'],'priority'=>'medium'];
    }
    foreach(research_project_graph_rows($pdo,$projectId) as $r)if($r['relation_type']==='contradicts')$conflicts[]=['type'=>'claim_relation','claim_id'=>$r['source_public_id'],'target_claim_id'=>$r['target_public_id'],'title'=>'Claims contradict each other','detail'=>mb_substr((string)$r['source_statement'],0,220).' ↔ '.mb_substr((string)$r['target_statement'],0,220),'priority'=>'high'];
    $sourceRisks=research_workspace_source_risks($pdo,$projectId,20);
    foreach($sourceRisks as $r)if($r['target_changed']||$r['affected_claims']>0)$next[]=['type'=>'review_source_change','title'=>'Review changed source evidence','reason'=>($r['title']?:$r['domain']).' changed after evidence was captured.','ref_type'=>'source','ref_id'=>$r['source_public_id'],'priority'=>'high'];
    foreach(array_slice($gaps,0,5) as $g)$next[]=['type'=>'verify_claim','title'=>$g['type']==='missing_evidence'?'Find evidence for this claim':'Find an independent source','reason'=>$g['detail'],'ref_type'=>'claim','ref_id'=>$g['claim_id'],'priority'=>$g['priority']];
    foreach(array_slice($conflicts,0,4) as $g)$next[]=['type'=>'compare_evidence','title'=>'Resolve conflicting evidence','reason'=>$g['detail'],'ref_type'=>'claim','ref_id'=>$g['claim_id']??'','priority'=>$g['priority']];
    if(($brief['counts']['claims']??0)>0&&($brief['counts']['findings']??0)===0)$next[]=['type'=>'create_finding','title'=>'Synthesize a finding','reason'=>'The project has claims but no active findings yet.','ref_type'=>'project','ref_id'=>$project['public_id'],'priority'=>'medium'];
    if(($brief['counts']['open_tasks']??0)>0)$next[]=['type'=>'continue_tasks','title'=>'Continue open research tasks','reason'=>(string)$brief['counts']['open_tasks'].' task(s) are still open or in progress.','ref_type'=>'project','ref_id'=>$project['public_id'],'priority'=>'medium'];
    return [
      'schema'=>'annotated-research-workspace-v1',
      'project'=>['public_id'=>$project['public_id'],'title'=>$project['title'],'description'=>$project['description']],
      'counts'=>$brief['counts'],
      'claims'=>$claims,
      'gaps'=>array_slice($gaps,0,16),
      'conflicts'=>array_slice($conflicts,0,16),
      'source_risks'=>$sourceRisks,
      'next_actions'=>array_slice($next,0,16),
      'annotation_links'=>research_workspace_annotation_links($pdo,$projectId,24),
      'entities'=>research_workspace_entity_rows($pdo,$projectId,16),
      'recent_activity'=>array_slice(research_project_timeline($pdo,$projectId),0,20)
    ];
}

function research_workspace_input_hash(PDO $pdo,int $projectId): string {
    return hash('sha256',json_encode(research_workspace_deterministic_snapshot($pdo,$projectId),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
}

function research_workspace_decode(array $row): array {
    foreach(['highlights_json'=>'highlights','gaps_json'=>'gaps','conflicts_json'=>'conflicts','source_risks_json'=>'source_risks','next_actions_json'=>'next_actions'] as $json=>$key){
        $v=json_decode((string)($row[$json]??''),true);$row[$key]=is_array($v)?$v:[];unset($row[$json]);
    }
    $row['confidence']=$row['confidence']!==null?(float)$row['confidence']:null;return $row;
}

function research_workspace_record(PDO $pdo,int $projectId): ?array {
    if(!research_workspace_ready($pdo))return null;$q=$pdo->prepare('SELECT * FROM research_workspace_intelligence WHERE project_id=? LIMIT 1');$q->execute([$projectId]);$r=$q->fetch();return $r?research_workspace_decode($r):null;
}
