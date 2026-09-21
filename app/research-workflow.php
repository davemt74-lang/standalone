<?php
declare(strict_types=1);

function research_workflow_stage_definitions(string $projectPublic): array {
    $id=rawurlencode($projectPublic);
    return [
        'capture'=>['label'=>'Capture','description'=>'Collect Sources and Annotations.','url'=>'/research-project.php?id='.$id.'#sources'],
        'investigate'=>['label'=>'Investigate','description'=>'Form Claims and connect exact evidence.','url'=>'/research-knowledge.php?id='.$id],
        'verify'=>['label'=>'Verify','description'=>'Resolve weak, stale, or contested evidence.','url'=>'/research-verification.php?id='.$id],
        'synthesize'=>['label'=>'Synthesize','description'=>'Turn supported Claims into Findings and briefs.','url'=>'/research-knowledge.php?id='.$id.'#findings'],
        'review'=>['label'=>'Review','description'=>'Get human review before relying on important conclusions.','url'=>'/research-reviews.php?scope=all'],
        'publish'=>['label'=>'Publish','description'=>'Create an immutable Research Report version.','url'=>'/research-publish.php?id='.$id],
        'monitor'=>['label'=>'Monitor','description'=>'Watch Sources, impacts, provenance and reproducibility drift.','url'=>'/research-impact.php?project='.$id],
    ];
}

function research_workflow_counts(PDO $pdo,int $projectId): array {
    $q=$pdo->prepare("SELECT
      (SELECT COUNT(*) FROM project_sources ps WHERE ps.project_id=?) sources,
      (SELECT COUNT(*) FROM project_annotations pa WHERE pa.project_id=?) annotations,
      (SELECT COUNT(*) FROM research_claims rc WHERE rc.project_id=?) claims,
      (SELECT COUNT(DISTINCT rc.id) FROM research_claims rc JOIN claim_evidence ce ON ce.claim_id=rc.id WHERE rc.project_id=?) claims_with_evidence,
      (SELECT COUNT(*) FROM research_findings rf WHERE rf.project_id=? AND rf.status<>'archived') findings,
      (SELECT COUNT(*) FROM research_reviews rr WHERE rr.project_id=? AND rr.status='open' AND rr.subject_type IN ('claim','finding','report_version')) open_reviews,
      (SELECT COUNT(*) FROM research_reviews rr WHERE rr.project_id=? AND rr.status='completed' AND rr.subject_type IN ('claim','finding','report_version')) completed_reviews,
      (SELECT COUNT(*) FROM research_reports rr WHERE rr.project_id=?) reports,
      (SELECT COUNT(*) FROM research_report_versions rv JOIN research_reports rr ON rr.id=rv.report_id WHERE rr.project_id=?) report_versions");
    $q->execute(array_fill(0,9,$projectId));$r=$q->fetch()?:[];
    return array_map('intval',$r);
}

function research_workflow_accessible_review_counts(PDO $pdo,array $viewer,int $projectId): array {
    if(!function_exists('research_reviews_ready')||!research_reviews_ready($pdo))return ['open_reviews'=>0,'completed_reviews'=>0];
    $q=$pdo->prepare("SELECT public_id,status FROM research_reviews WHERE project_id=? AND subject_type IN ('claim','finding','report_version') AND status IN ('open','completed') ORDER BY id DESC");
    $q->execute([$projectId]);$out=['open_reviews'=>0,'completed_reviews'=>0];
    foreach($q->fetchAll() as $row){
        $review=research_review_access($pdo,$viewer,(string)$row['public_id']);if(!$review)continue;
        if($row['status']==='open')$out['open_reviews']++;elseif($row['status']==='completed')$out['completed_reviews']++;
    }
    return $out;
}

function research_workflow_accessible_report_counts(PDO $pdo,array $viewer,int $projectId): array {
    $q=$pdo->prepare("SELECT rr.id,rr.public_id,rp.owner_user_id,rp.team_id
      FROM research_reports rr JOIN research_projects rp ON rp.id=rr.project_id
      WHERE rr.project_id=? AND rr.status='published' ORDER BY rr.id");
    $q->execute([$projectId]);$reports=0;$versions=0;
    foreach($q->fetchAll() as $report){
        $vq=$pdo->prepare("SELECT version_number FROM research_report_versions WHERE report_id=? ORDER BY version_number");
        $vq->execute([$report['id']]);$visibleVersions=0;
        foreach($vq->fetchAll(PDO::FETCH_COLUMN) as $versionNumber)if(research_report_version_access($pdo,$report,(int)$versionNumber,$viewer))$visibleVersions++;
        if($visibleVersions>0){$reports++;$versions+=$visibleVersions;}
    }
    return ['reports'=>$reports,'report_versions'=>$versions];
}

function research_workflow_current_completed_reviews(PDO $pdo,array $viewer,int $projectId): int {
    if(!function_exists('research_reviews_ready')||!research_reviews_ready($pdo))return 0;
    $q=$pdo->prepare("SELECT public_id FROM research_reviews WHERE project_id=? AND status='completed' AND subject_type IN ('claim','finding','report_version') ORDER BY id DESC LIMIT 200");
    $q->execute([$projectId]);$count=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $publicId){$review=research_review_access($pdo,$viewer,(string)$publicId);if($review&&!$review['is_stale'])$count++;}return $count;
}

function research_workflow_state(PDO $pdo,array $viewer,string $projectPublic): array {
    $project=project_access($pdo,(int)$viewer['id'],$projectPublic);if(!$project)return ['available'=>false,'stages'=>[]];
    $counts=research_workflow_counts($pdo,(int)$project['id']);
    $counts=array_merge($counts,research_workflow_accessible_review_counts($pdo,$viewer,(int)$project['id']),research_workflow_accessible_report_counts($pdo,$viewer,(int)$project['id']));
    $counts['current_completed_reviews']=research_workflow_current_completed_reviews($pdo,$viewer,(int)$project['id']);
    $verify=function_exists('research_verification_ready')&&research_verification_ready($pdo)?research_verification_project_summary($pdo,$viewer,$projectPublic,300):['available'=>false,'needs_attention'=>0,'contested'=>0,'stale'=>0,'no_evidence'=>0];
    $impact=function_exists('change_impact_ready')&&change_impact_ready($pdo)?change_impact_project_summary($pdo,$viewer,$projectPublic,20):['events'=>0,'unresolved'=>0,'high'=>0];
    $packs=function_exists('research_evidence_packs_ready')&&research_evidence_packs_ready($pdo)?research_evidence_pack_list($pdo,$viewer,$projectPublic,6):[];
    $driftedPacks=count(array_filter($packs,fn($p)=>empty($p['current_matches_pack'])));
    $defs=research_workflow_stage_definitions($projectPublic);
    $hasCapture=($counts['sources']+$counts['annotations'])>0;
    $hasInvestigated=$counts['claims']>0;
    $verified=$hasInvestigated&&!empty($verify['available'])&&(int)$verify['needs_attention']===0&&(int)($verify['reviewed_current']??0)>=$counts['claims'];
    $hasSynthesis=$counts['findings']>0;
    $reviewed=$counts['current_completed_reviews']>0&&$counts['open_reviews']===0;
    $published=$counts['report_versions']>0;
    $monitorAttention=((int)($impact['unresolved']??0)>0)||$driftedPacks>0;

    $status=[
      'capture'=>$hasCapture?'complete':'current',
      'investigate'=>$hasInvestigated?'complete':($hasCapture?'current':'upcoming'),
      'verify'=>$verified?'complete':($hasInvestigated?'attention':'upcoming'),
      'synthesize'=>$hasSynthesis?'complete':($verified?'current':'upcoming'),
      'review'=>$reviewed?'complete':($hasSynthesis?($counts['open_reviews']>0?'attention':'current'):'upcoming'),
      'publish'=>$published?'complete':($hasSynthesis&&$reviewed?'current':'upcoming'),
      'monitor'=>$published?($monitorAttention?'attention':'active'):'upcoming',
    ];

    if(!$hasCapture)$next=['stage'=>'capture','title'=>'Add your first evidence','detail'=>'Add a Source or Annotation so the project has evidence to investigate.','url'=>$defs['capture']['url'],'agent_prompt'=>'Help me identify the most useful evidence to capture for this Research project. Do not invent Sources.'];
    elseif(!$hasInvestigated)$next=['stage'=>'investigate','title'=>'Form the first Claim','detail'=>'Turn captured evidence into a testable Claim and attach the exact Source Version or Annotation.','url'=>$defs['investigate']['url'],'agent_prompt'=>'Review the captured evidence and propose a testable Claim. Distinguish evidence from inference and do not create anything without confirmation.'];
    elseif(!$verified)$next=['stage'=>'verify','title'=>'Verify the current Claims','detail'=>(int)($verify['needs_attention']??0)>0?(int)$verify['needs_attention'].' Claim(s) have evidence or review signals that need attention.':'Claims need current human verification before synthesis.','url'=>$defs['verify']['url'],'agent_prompt'=>'Explain which Claims need evidence verification or current human review and why. Preserve uncertainty and do not change Claim status automatically.'];
    elseif(!$hasSynthesis)$next=['stage'=>'synthesize','title'=>'Synthesize a Finding','detail'=>'The project has Claims without a Finding that summarizes what the evidence supports.','url'=>$defs['synthesize']['url'],'agent_prompt'=>'Review the verified Claims and propose a Finding only where the evidence supports one. Do not create it without confirmation.'];
    elseif($counts['open_reviews']>0)$next=['stage'=>'review','title'=>'Complete open human review','detail'=>$counts['open_reviews'].' collaborative review(s) are still open.','url'=>$defs['review']['url'],'agent_prompt'=>'Summarize the open Research reviews, disagreements, and evidence questions. Do not resolve reviews on my behalf.'];
    elseif(!$reviewed)$next=['stage'=>'review','title'=>'Request human review','detail'=>'The Research has Findings but no completed collaborative review yet.','url'=>$defs['review']['url'],'agent_prompt'=>'Identify the most important Claim or Finding to send for human review and explain why.'];
    elseif(!$published)$next=['stage'=>'publish','title'=>'Publish an immutable Report version','detail'=>'The Research has synthesized and reviewed material ready for a deliberate publication decision.','url'=>$defs['publish']['url'],'agent_prompt'=>'Outline a Report from the reviewed Findings and Claims. Preserve citations and uncertainty; do not publish without confirmation.'];
    elseif($monitorAttention)$next=['stage'=>'monitor','title'=>'Review changes since publication','detail'=>((int)($impact['unresolved']??0)).' unresolved change impact(s) and '.$driftedPacks.' drifted Evidence Pack(s) need review.','url'=>$defs['monitor']['url'],'agent_prompt'=>'Explain what changed after publication and which Research needs human review. Do not automatically republish or rewrite immutable records.'];
    else $next=['stage'=>'monitor','title'=>'Research loop is healthy','detail'=>'Published Research is being monitored. Capture new evidence or review changes when they appear.','url'=>$defs['monitor']['url'],'agent_prompt'=>'Summarize the current monitored Research state and identify only meaningful new work, if any.'];

    $stages=[];foreach($defs as $key=>$d)$stages[]=['key'=>$key,'label'=>$d['label'],'description'=>$d['description'],'url'=>$d['url'],'status'=>$status[$key]];
    return ['available'=>true,'project'=>['public_id'=>$project['public_id'],'title'=>$project['title'],'access_role'=>$project['access_role']??null],'counts'=>$counts,'verification'=>$verify,'impact'=>$impact,'drifted_packs'=>$driftedPacks,'stages'=>$stages,'next'=>$next];
}

function research_workflow_context(PDO $pdo,array $viewer,string $projectPublic): array {
    $w=research_workflow_state($pdo,$viewer,$projectPublic);if(empty($w['available']))return ['text'=>'','refs'=>[]];
    $done=[];$attention=[];foreach($w['stages'] as $s){if($s['status']==='complete')$done[]=$s['label'];if($s['status']==='attention')$attention[]=$s['label'];}
    $lines=['[UNIFIED RESEARCH WORKFLOW]','Lifecycle: Capture → Investigate → Verify → Synthesize → Review → Publish → Monitor.','Completed stages: '.($done?implode(', ',$done):'none').'.'.($attention?' Attention: '.implode(', ',$attention).'.':''),'Next recommended step: '.$w['next']['title'].'. '.$w['next']['detail'],'Treat this as workflow guidance, not permission to execute changes.'];
    return ['text'=>implode("\n",$lines),'refs'=>[['type'=>'research_project','id'=>$projectPublic]],'workflow'=>$w];
}
