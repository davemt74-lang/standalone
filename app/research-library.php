<?php
declare(strict_types=1);

function research_library_projects(PDO $pdo,array $viewer,int $limit=100): array {
    $limit=max(1,min(200,$limit));
    $agentExclusion=(function_exists('research_agent_ready')&&research_agent_ready($pdo))?" AND NOT EXISTS(SELECT 1 FROM research_agents rag WHERE rag.project_id=rp.id)":"";
    $sql="SELECT
      rp.id,rp.public_id,rp.title,rp.description,rp.status,rp.created_at,rp.updated_at,
      t.name team_name,
      (SELECT COUNT(*) FROM project_sources ps WHERE ps.project_id=rp.id) source_count,
      (SELECT COUNT(*) FROM project_annotations pa WHERE pa.project_id=rp.id) annotation_count,
      (SELECT COUNT(*) FROM comments c JOIN project_annotations pa2 ON pa2.annotation_id=c.annotation_id WHERE pa2.project_id=rp.id) comment_count,
      (SELECT COUNT(*) FROM annotation_reactions ar JOIN project_annotations pa3 ON pa3.annotation_id=ar.annotation_id WHERE pa3.project_id=rp.id AND ar.reaction='like') like_count,
      (SELECT COUNT(*) FROM research_tasks rt WHERE rt.project_id=rp.id AND rt.status IN ('open','in_progress')) task_count,
      (SELECT COUNT(*) FROM research_claims rc WHERE rc.project_id=rp.id) claim_count,
      (SELECT COUNT(*) FROM research_findings rf WHERE rf.project_id=rp.id AND rf.status<>'archived') finding_count,
      GREATEST(
        COALESCE(rp.updated_at,rp.created_at),
        COALESCE((SELECT MAX(ps2.created_at) FROM project_sources ps2 WHERE ps2.project_id=rp.id),'1970-01-01 00:00:00'),
        COALESCE((SELECT MAX(pa4.created_at) FROM project_annotations pa4 WHERE pa4.project_id=rp.id),'1970-01-01 00:00:00'),
        COALESCE((SELECT MAX(c2.created_at) FROM comments c2 JOIN project_annotations pa5 ON pa5.annotation_id=c2.annotation_id WHERE pa5.project_id=rp.id),'1970-01-01 00:00:00'),
        COALESCE((SELECT MAX(ar2.created_at) FROM annotation_reactions ar2 JOIN project_annotations pa6 ON pa6.annotation_id=ar2.annotation_id WHERE pa6.project_id=rp.id),'1970-01-01 00:00:00'),
        COALESCE((SELECT MAX(rc2.updated_at) FROM research_claims rc2 WHERE rc2.project_id=rp.id),'1970-01-01 00:00:00'),
        COALESCE((SELECT MAX(rf2.updated_at) FROM research_findings rf2 WHERE rf2.project_id=rp.id),'1970-01-01 00:00:00')
      ) recent_at
      FROM research_projects rp
      LEFT JOIN teams t ON t.id=rp.team_id
      WHERE rp.status<>'archived' AND (
        rp.owner_user_id=? OR EXISTS(
          SELECT 1 FROM team_members tm WHERE tm.team_id=rp.team_id AND tm.user_id=?
        )
      )".$agentExclusion."
      ORDER BY recent_at DESC,rp.id DESC
      LIMIT ".$limit;
    $q=$pdo->prepare($sql);
    $q->execute([(int)$viewer['id'],(int)$viewer['id']]);
    $projects=$q->fetchAll()?:[];

    $unreadByProject=[];
    if(function_exists('notification_rows')){
        try{
            foreach(notification_rows($pdo,$viewer,300,true) as $notification){
                $context=(array)($notification['context']??[]);
                $projectPublic=trim((string)($context['project_public_id']??''));
                if($projectPublic===''&&($notification['object_type']??'')==='research_project'){
                    $projectPublic=trim((string)($notification['object_public_id']??''));
                }
                if($projectPublic==='')continue;
                $unreadByProject[$projectPublic]=($unreadByProject[$projectPublic]??0)+max(1,(int)($notification['unread_count']??1));
            }
        }catch(Throwable $e){}
    }
    foreach($projects as &$project){
        $project['notification_count']=(int)($unreadByProject[(string)$project['public_id']]??0);
    }
    unset($project);
    return $projects;
}

function research_library_recent_meta(?string $value): array {
    $value=trim((string)$value);
    if($value==='')return ['label'=>'No activity yet','recent'=>false,'datetime'=>''];
    try{$time=new DateTimeImmutable($value);}catch(Throwable $e){return ['label'=>$value,'recent'=>false,'datetime'=>$value];}
    $now=new DateTimeImmutable('now');
    $seconds=max(0,$now->getTimestamp()-$time->getTimestamp());
    if($seconds<3600)$label=max(1,(int)floor($seconds/60)).'m ago';
    elseif($seconds<86400)$label=max(1,(int)floor($seconds/3600)).'h ago';
    elseif($seconds<604800)$label=max(1,(int)floor($seconds/86400)).'d ago';
    else $label=$time->format('M j, Y');
    return ['label'=>$label,'recent'=>$seconds<604800,'datetime'=>$time->format(DATE_ATOM)];
}
