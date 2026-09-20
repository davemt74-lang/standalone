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

function research_workspace_ai_model_configured(PDO $pdo): bool {
    try{return ai_setting_model_id($pdo,'research')>0;}
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


function research_workspace_queue(PDO $pdo,int $projectId,int $priority=5): bool {
    if(!research_workspace_ready($pdo)||!research_workspace_ai_model_configured($pdo))return false;
    $project=research_workspace_project_row($pdo,$projectId);if(!$project)return false;
    $hash=research_workspace_input_hash($pdo,$projectId);
    $q=$pdo->prepare('SELECT status,input_hash FROM research_workspace_intelligence WHERE project_id=?');$q->execute([$projectId]);$existing=$q->fetch();
    if($existing&&$existing['status']==='ready'&&hash_equals((string)$existing['input_hash'],$hash))return false;
    $pdo->prepare("INSERT INTO research_workspace_intelligence(project_id,status,input_hash,queued_at,last_error) VALUES(?,'pending',?,NOW(),NULL)
      ON DUPLICATE KEY UPDATE status=IF(input_hash=VALUES(input_hash) AND status='processing','processing','pending'),input_hash=VALUES(input_hash),queued_at=NOW(),last_error=NULL")->execute([$projectId,$hash]);
    $q=$pdo->prepare("SELECT id,status,input_json FROM ai_jobs WHERE task_type='research_workspace_intelligence' AND object_type='research_project' AND object_public_id=? AND status IN ('queued','processing')");
    $q->execute([$project['public_id']]);$matching=false;$obsolete=[];
    foreach($q->fetchAll() as $job){
        $in=json_decode((string)($job['input_json']??''),true);
        if(is_array($in)&&hash_equals((string)($in['input_hash']??''),$hash)){$matching=true;continue;}
        if(($job['status']??'')==='queued')$obsolete[]=(int)$job['id'];
    }
    if($obsolete){$ph=implode(',',array_fill(0,count($obsolete),'?'));$pdo->prepare("UPDATE ai_jobs SET status='blocked',last_error='Superseded by newer Research workspace state.',completed_at=NOW() WHERE id IN ($ph) AND status='queued'")->execute($obsolete);}
    if($matching)return false;
    ai_queue_job($pdo,null,'research_workspace_intelligence',null,'research_project',(string)$project['public_id'],['input_hash'=>$hash],$priority);
    return true;
}

function research_workspace_allowed_refs(array $snapshot): array {
    $refs=['project'=>[],'claim'=>[],'source'=>[],'entity'=>[],'annotation'=>[],'finding'=>[],'task'=>[]];
    $projectId=(string)($snapshot['project']['public_id']??'');if($projectId!=='')$refs['project'][$projectId]=true;
    foreach((array)($snapshot['claims']??[]) as $x)if(!empty($x['public_id']))$refs['claim'][(string)$x['public_id']]=true;
    foreach((array)($snapshot['source_risks']??[]) as $x)if(!empty($x['source_public_id']))$refs['source'][(string)$x['source_public_id']]=true;
    foreach((array)($snapshot['entities']??[]) as $x)if(!empty($x['public_id']))$refs['entity'][(string)$x['public_id']]=true;
    foreach((array)($snapshot['annotation_links']??[]) as $x){
        if(!empty($x['source_annotation_id']))$refs['annotation'][(string)$x['source_annotation_id']]=true;
        if(!empty($x['target_annotation_id']))$refs['annotation'][(string)$x['target_annotation_id']]=true;
    }
    return $refs;
}

function research_workspace_parse_json(string $text): array {
    $text=trim($text);
    if(str_starts_with($text,chr(96).chr(96).chr(96))){
        $text=preg_replace('/^\x60\x60\x60(?:json)?\s*/i','',$text)??$text;
        $text=preg_replace('/\s*\x60\x60\x60$/','',$text)??$text;
    }
    $d=json_decode($text,true);if(!is_array($d))throw new RuntimeException('Research workspace intelligence returned invalid JSON.');return $d;
}

function research_workspace_normalize_items(array $items,array $allowed,int $limit): array {
    $out=[];
    foreach($items as $item){
        if(!is_array($item))continue;
        $title=mb_substr(trim((string)($item['title']??'')),0,240);
        $detail=mb_substr(trim((string)($item['detail']??$item['reason']??'')),0,1200);
        if($title==='')continue;
        $type=mb_substr(trim((string)($item['type']??'item')),0,80);
        $priority=in_array((string)($item['priority']??''),['high','medium','low'],true)?(string)$item['priority']:'medium';
        $refType=strtolower(trim((string)($item['ref_type']??'')));$refId=trim((string)($item['ref_id']??''));
        if($refId!==''&&!isset($allowed[$refType][$refId]))continue;
        $out[]=['type'=>$type,'title'=>$title,'detail'=>$detail,'priority'=>$priority,'ref_type'=>$refType,'ref_id'=>$refId];
        if(count($out)>=$limit)break;
    }
    return $out;
}

function research_workspace_apply_ai_output(PDO $pdo,string $projectPublicId,string $output,string $runPublicId,int $modelId,?string $expectedInputHash=null): array {
    $q=$pdo->prepare('SELECT id FROM research_projects WHERE public_id=? LIMIT 1');$q->execute([$projectPublicId]);$projectId=(int)($q->fetchColumn()?:0);
    if(!$projectId)throw new RuntimeException('Research project missing.');
    $snapshot=research_workspace_deterministic_snapshot($pdo,$projectId);
    $currentHash=hash('sha256',json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    if($expectedInputHash!==null&&$expectedInputHash!==''&&!hash_equals($expectedInputHash,$currentHash))return ['project_id'=>$projectPublicId,'stale'=>true,'current_input_hash'=>$currentHash];
    $data=research_workspace_parse_json($output);$allowed=research_workspace_allowed_refs($snapshot);
    $summary=mb_substr(trim((string)($data['summary']??'')),0,4000);if($summary==='')throw new RuntimeException('Research workspace summary is empty.');
    $highlights=research_workspace_normalize_items((array)($data['highlights']??[]),$allowed,10);
    $gaps=research_workspace_normalize_items((array)($data['gaps']??[]),$allowed,12);
    $conflicts=research_workspace_normalize_items((array)($data['conflicts']??[]),$allowed,12);
    $risks=research_workspace_normalize_items((array)($data['source_risks']??[]),$allowed,12);
    $actions=research_workspace_normalize_items((array)($data['next_actions']??[]),$allowed,12);
    $confidence=max(0,min(1,(float)($data['confidence']??0.65)));$storedModel=$modelId>0?$modelId:null;
    $pdo->prepare("INSERT INTO research_workspace_intelligence(project_id,status,input_hash,summary,highlights_json,gaps_json,conflicts_json,source_risks_json,next_actions_json,confidence,model_id,ai_run_public_id,prompt_version,last_error,processed_at)
      VALUES(?,'ready',?,?,?,?,?,?,?,?,?,?,?,'phase14-v1',NULL,NOW())
      ON DUPLICATE KEY UPDATE status='ready',input_hash=VALUES(input_hash),summary=VALUES(summary),highlights_json=VALUES(highlights_json),gaps_json=VALUES(gaps_json),conflicts_json=VALUES(conflicts_json),source_risks_json=VALUES(source_risks_json),next_actions_json=VALUES(next_actions_json),confidence=VALUES(confidence),model_id=VALUES(model_id),ai_run_public_id=VALUES(ai_run_public_id),prompt_version='phase14-v1',last_error=NULL,processed_at=NOW()")
      ->execute([$projectId,$currentHash,$summary,json_encode($highlights,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($gaps,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($conflicts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($risks,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($actions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$confidence,$storedModel,$runPublicId]);
    return ['project_id'=>$projectPublicId,'summary'=>$summary,'highlights'=>$highlights,'gaps'=>$gaps,'conflicts'=>$conflicts,'source_risks'=>$risks,'next_actions'=>$actions,'confidence'=>$confidence];
}

function research_workspace_mark_processing(PDO $pdo,string $projectPublicId): void {
    $pdo->prepare("UPDATE research_workspace_intelligence rwi JOIN research_projects rp ON rp.id=rwi.project_id SET rwi.status='processing',rwi.last_error=NULL WHERE rp.public_id=?")->execute([$projectPublicId]);
}

function research_workspace_mark_failed(PDO $pdo,string $projectPublicId,string $message): void {
    $pdo->prepare("UPDATE research_workspace_intelligence rwi JOIN research_projects rp ON rp.id=rwi.project_id SET rwi.status='failed',rwi.last_error=? WHERE rp.public_id=?")->execute([mb_substr($message,0,1000),$projectPublicId]);
}

function research_workspace_context(PDO $pdo,int $projectId): array {
    $snapshot=research_workspace_deterministic_snapshot($pdo,$projectId);$record=research_workspace_record($pdo,$projectId);
    $lines=['[RESEARCH WORKSPACE NOW]'];$c=$snapshot['counts'];
    $lines[]='Sources '.$c['sources'].'; annotations '.$c['annotations'].'; claims '.$c['claims'].'; supported '.$c['supported'].'; disputed/contradicted '.$c['disputed'].'; unverified '.$c['unverified'].'; findings '.$c['findings'].'; open tasks '.$c['open_tasks'].'; recent source changes '.$c['recent_source_changes'].'.';
    foreach(array_slice($snapshot['gaps'],0,8) as $x)$lines[]='Gap: '.$x['detail'].' [CLAIM '.$x['claim_id'].']';
    foreach(array_slice($snapshot['conflicts'],0,8) as $x)$lines[]='Conflict: '.$x['detail'].(!empty($x['claim_id'])?' [CLAIM '.$x['claim_id'].']':'');
    foreach(array_slice($snapshot['source_risks'],0,6) as $x)$lines[]='Source risk: '.($x['title']?:$x['domain']).' [SOURCE '.$x['source_public_id'].']'.($x['latest_diff']?' — '.$x['latest_diff']:'');
    if($record&&($record['status']??'')==='ready'){
        $lines[]='AI-derived live synthesis (verify against project evidence): '.$record['summary'];
        foreach(array_slice($record['next_actions'],0,6) as $x)$lines[]='Suggested action: '.$x['title'].' — '.$x['detail'];
    }
    return ['snapshot'=>$snapshot,'intelligence'=>$record,'text'=>implode("\n",$lines)];
}
