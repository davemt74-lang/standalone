<?php
declare(strict_types=1);

/**
 * Phase 33 — Unified Activity & Context Awareness.
 *
 * Activity is derived from authoritative product state. Nothing here grants
 * access or persists a second activity copy.
 */
function unified_activity_ready(PDO $pdo): bool {
    return installer_table_exists($pdo,'annotations')
        && installer_table_exists($pdo,'conversation_messages')
        && installer_table_exists($pdo,'research_projects');
}

function unified_activity_item(array $data): array {
    $data['type']=(string)($data['type']??'activity');
    $data['surface']=(string)($data['surface']??'workspace');
    $data['created_at']=(string)($data['created_at']??'');
    $data['title']=trim((string)($data['title']??'Annotated activity'));
    $data['body']=trim((string)($data['body']??''));
    $data['href']=(string)($data['href']??'');
    $data['actor']=is_array($data['actor']??null)?$data['actor']:null;
    $data['object']=is_array($data['object']??null)?$data['object']:null;
    $id=(string)($data['object']['public_id']??$data['href']??$data['title']);
    $data['key']=hash('sha256',$data['type'].'|'.$id.'|'.$data['created_at']);
    return $data;
}

function unified_activity_add(array &$items,array $item): void {
    $item=unified_activity_item($item);$items[$item['key']]=$item;
}

function unified_activity_annotations(PDO $pdo,array $viewer,array &$items,int $limit): void {
    $sql="SELECT a.public_id,a.user_id,a.visibility,a.team_id,a.text_commentary,a.published_at,
      u.public_id actor_public_id,u.username,u.display_name,
      s.title source_title,s.domain,t.name team_name
      FROM annotations a
      JOIN users u ON u.id=a.user_id
      JOIN sources s ON s.id=a.source_id
      LEFT JOIN teams t ON t.id=a.team_id
      LEFT JOIN team_members tm ON tm.team_id=a.team_id AND tm.user_id=?
      WHERE a.status='published' AND a.published_at IS NOT NULL
        AND (a.user_id=? OR (a.visibility='team' AND tm.user_id IS NOT NULL))
      ORDER BY a.published_at DESC LIMIT ".$limit;
    $q=$pdo->prepare($sql);$q->execute([$viewer['id'],$viewer['id']]);
    foreach($q->fetchAll() as $row){
        $access=annotation_access($pdo,(string)$row['public_id'],$viewer);if(!$access)continue;
        if((int)$row['user_id']!==(int)$viewer['id']&&function_exists('is_blocked')&&is_blocked($pdo,(int)$viewer['id'],(int)$row['user_id']))continue;
        $own=(int)$row['user_id']===(int)$viewer['id'];$preview=trim((string)$row['text_commentary']);if($preview==='')$preview=(string)($row['source_title']?:$row['domain']?:'Annotation');
        unified_activity_add($items,[
          'type'=>'annotation_published','surface'=>'annotation','created_at'=>$row['published_at'],
          'title'=>$own?'You published an Annotation':($row['display_name']?:$row['username']).' published a Team Annotation',
          'body'=>mb_substr($preview,0,280),
          'href'=>'/annotation.php?id='.rawurlencode((string)$row['public_id']),
          'actor'=>['public_id'=>$row['actor_public_id'],'name'=>$row['display_name']?:$row['username'],'username'=>$row['username']],
          'object'=>['type'=>'annotation','public_id'=>$row['public_id']],
          'meta'=>array_filter(['visibility'=>$row['visibility'],'team'=>$row['team_name']])
        ]);
    }
}

function unified_activity_team(PDO $pdo,array $viewer,array &$items,int $limit): void {
    if(!conversation_runtime_ready($pdo))return;
    $q=$pdo->prepare("SELECT cm.public_id message_public_id,cm.body,cm.created_at,cm.user_id,
      c.public_id conversation_public_id,t.public_id team_public_id,t.name team_name,
      u.public_id actor_public_id,u.username,u.display_name
      FROM conversation_messages cm
      JOIN conversations c ON c.id=cm.conversation_id AND c.conversation_type='team'
      JOIN teams t ON t.id=c.team_id
      JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=?
      LEFT JOIN users u ON u.id=cm.user_id
      WHERE cm.deleted_at IS NULL
      ORDER BY cm.id DESC LIMIT ".$limit);
    $q->execute([$viewer['id']]);
    foreach($q->fetchAll() as $row){
        if(!conversation_access($pdo,$viewer,(string)$row['conversation_public_id']))continue;
        $self=(int)($row['user_id']??0)===(int)$viewer['id'];$actor=(string)($row['display_name']?:$row['username']?:'A teammate');
        unified_activity_add($items,[
          'type'=>'team_message','surface'=>'team','created_at'=>$row['created_at'],
          'title'=>$self?'You messaged '.$row['team_name']:$actor.' messaged '.$row['team_name'],
          'body'=>mb_substr(trim((string)$row['body']),0,280),
          'href'=>'/home.php?team='.rawurlencode((string)$row['team_public_id']).'#team-chat',
          'actor'=>$row['actor_public_id']?['public_id'=>$row['actor_public_id'],'name'=>$actor,'username'=>$row['username']]:null,
          'object'=>['type'=>'conversation','public_id'=>$row['conversation_public_id']],
          'meta'=>['team'=>$row['team_name']]
        ]);
    }
}

function unified_activity_project_annotations(PDO $pdo,array $viewer,array &$items,int $limit): void {
    $q=$pdo->prepare("SELECT pa.created_at,a.public_id annotation_public_id,a.text_commentary,
      rp.public_id project_public_id,rp.title project_title,
      u.public_id actor_public_id,u.username,u.display_name,pa.added_by_user_id
      FROM project_annotations pa
      JOIN research_projects rp ON rp.id=pa.project_id
      JOIN annotations a ON a.id=pa.annotation_id
      JOIN users u ON u.id=pa.added_by_user_id
      LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=?
      WHERE rp.owner_user_id=? OR tm.user_id=?
      ORDER BY pa.created_at DESC LIMIT ".$limit);
    $q->execute([$viewer['id'],$viewer['id'],$viewer['id']]);
    foreach($q->fetchAll() as $row){
        $project=project_access($pdo,(int)$viewer['id'],(string)$row['project_public_id']);if(!$project)continue;
        if(!annotation_access($pdo,(string)$row['annotation_public_id'],$viewer))continue;
        $self=(int)$row['added_by_user_id']===(int)$viewer['id'];$actor=(string)($row['display_name']?:$row['username']);
        unified_activity_add($items,[
          'type'=>'research_evidence_added','surface'=>'research','created_at'=>$row['created_at'],
          'title'=>$self?'You added evidence to '.$row['project_title']:$actor.' added evidence to '.$row['project_title'],
          'body'=>mb_substr(trim((string)$row['text_commentary'])?:'An Annotation was added as project evidence.',0,280),
          'href'=>'/research-project.php?id='.rawurlencode((string)$row['project_public_id']),
          'actor'=>['public_id'=>$row['actor_public_id'],'name'=>$actor,'username'=>$row['username']],
          'object'=>['type'=>'annotation','public_id'=>$row['annotation_public_id']],
          'context'=>[['type'=>'research','public_id'=>$row['project_public_id']],['type'=>'annotation','public_id'=>$row['annotation_public_id']]],
          'meta'=>['project'=>$row['project_title']]
        ]);
    }
}

function unified_activity_research_objects(PDO $pdo,array $viewer,array &$items,int $limit): void {
    $projects=cognitive_feed_projects($pdo,$viewer,20);if(!$projects)return;
    $projectIds=array_map(fn($p)=>(int)$p['id'],$projects);$marks=implode(',',array_fill(0,count($projectIds),'?'));
    $claim=$pdo->prepare("SELECT rc.public_id,rc.statement,rc.status,rc.updated_at,rp.public_id project_public_id,rp.title project_title,u.public_id actor_public_id,u.username,u.display_name
      FROM research_claims rc JOIN research_projects rp ON rp.id=rc.project_id JOIN users u ON u.id=rc.created_by_user_id
      WHERE rc.project_id IN ($marks) ORDER BY rc.updated_at DESC LIMIT ".$limit);
    $claim->execute($projectIds);
    foreach($claim->fetchAll() as $row){
        if(!project_access($pdo,(int)$viewer['id'],(string)$row['project_public_id']))continue;
        unified_activity_add($items,[
          'type'=>'claim_updated','surface'=>'research','created_at'=>$row['updated_at'],
          'title'=>'Claim updated in '.$row['project_title'],'body'=>mb_substr((string)$row['statement'],0,280),
          'href'=>'/research-claim.php?id='.rawurlencode((string)$row['public_id']),
          'actor'=>['public_id'=>$row['actor_public_id'],'name'=>$row['display_name']?:$row['username'],'username'=>$row['username']],
          'object'=>['type'=>'claim','public_id'=>$row['public_id']],
          'context'=>[['type'=>'research','public_id'=>$row['project_public_id']],['type'=>'claim','public_id'=>$row['public_id']]],
          'meta'=>['project'=>$row['project_title'],'status'=>$row['status']]
        ]);
    }
    $finding=$pdo->prepare("SELECT rf.public_id,rf.title,rf.summary,rf.status,rf.updated_at,rp.public_id project_public_id,rp.title project_title,u.public_id actor_public_id,u.username,u.display_name
      FROM research_findings rf JOIN research_projects rp ON rp.id=rf.project_id JOIN users u ON u.id=rf.created_by_user_id
      WHERE rf.project_id IN ($marks) AND rf.status<>'archived' ORDER BY rf.updated_at DESC LIMIT ".$limit);
    $finding->execute($projectIds);
    foreach($finding->fetchAll() as $row){
        if(!project_access($pdo,(int)$viewer['id'],(string)$row['project_public_id']))continue;
        unified_activity_add($items,[
          'type'=>'finding_updated','surface'=>'research','created_at'=>$row['updated_at'],
          'title'=>'Finding updated: '.$row['title'],'body'=>mb_substr((string)$row['summary'],0,280),
          'href'=>'/research-finding.php?id='.rawurlencode((string)$row['public_id']),
          'actor'=>['public_id'=>$row['actor_public_id'],'name'=>$row['display_name']?:$row['username'],'username'=>$row['username']],
          'object'=>['type'=>'finding','public_id'=>$row['public_id']],
          'context'=>[['type'=>'research','public_id'=>$row['project_public_id']],['type'=>'finding','public_id'=>$row['public_id']]],
          'meta'=>['project'=>$row['project_title'],'status'=>$row['status']]
        ]);
    }
}

function unified_activity_reports(PDO $pdo,array $viewer,array &$items,int $limit): void {
    if(!installer_table_exists($pdo,'research_report_versions'))return;
    $q=$pdo->prepare("SELECT rv.public_id version_public_id,rv.version_number,rv.title,rv.summary,rv.created_at,
      rr.id report_id,rr.public_id report_public_id,rp.public_id project_public_id,rp.title project_title,rp.owner_user_id,rp.team_id,
      u.public_id actor_public_id,u.username,u.display_name
      FROM research_report_versions rv
      JOIN research_reports rr ON rr.id=rv.report_id
      JOIN research_projects rp ON rp.id=rr.project_id
      JOIN users u ON u.id=rv.published_by_user_id
      LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=?
      WHERE rp.owner_user_id=? OR tm.user_id=? OR rv.visibility='public'
      ORDER BY rv.id DESC LIMIT ".$limit);
    $q->execute([$viewer['id'],$viewer['id'],$viewer['id']]);
    foreach($q->fetchAll() as $row){
        $reportMeta=['id'=>(int)$row['report_id'],'owner_user_id'=>(int)$row['owner_user_id'],'team_id'=>$row['team_id']];
        if(!research_report_version_access($pdo,$reportMeta,(int)$row['version_number'],$viewer))continue;
        unified_activity_add($items,[
          'type'=>'report_published','surface'=>'publish','created_at'=>$row['created_at'],
          'title'=>'Report published: '.$row['title'],'body'=>mb_substr((string)($row['summary']??''),0,280),
          'href'=>'/research-report.php?id='.rawurlencode((string)$row['report_public_id']).'&v='.(int)$row['version_number'],
          'actor'=>['public_id'=>$row['actor_public_id'],'name'=>$row['display_name']?:$row['username'],'username'=>$row['username']],
          'object'=>['type'=>'report_version','public_id'=>$row['version_public_id']],
          'context'=>[['type'=>'research','public_id'=>$row['project_public_id']]],
          'meta'=>['project'=>$row['project_title'],'version'=>(int)$row['version_number']]
        ]);
    }
}

function unified_activity_agent(PDO $pdo,array $viewer,array &$items,int $limit): void {
    if(!agent_actions_ready($pdo))return;
    $q=$pdo->prepare("SELECT aap.public_id,aap.capability_key,aap.status,aap.executed_at,aap.rejected_at,aap.updated_at,aap.result_json,
      rp.public_id project_public_id,rp.title project_title,c.public_id conversation_public_id
      FROM agent_action_proposals aap JOIN research_projects rp ON rp.id=aap.project_id JOIN conversations c ON c.id=aap.conversation_id
      WHERE aap.proposed_by_user_id=? AND aap.status IN ('executed','rejected','failed','stale')
      ORDER BY aap.updated_at DESC LIMIT ".$limit);
    $q->execute([$viewer['id']]);$caps=agent_action_capabilities();
    foreach($q->fetchAll() as $row){
        if(!project_access($pdo,(int)$viewer['id'],(string)$row['project_public_id']))continue;
        $label=(string)($caps[$row['capability_key']]['label']??'Agent action');$when=(string)($row['executed_at']?:$row['rejected_at']?:$row['updated_at']);
        $result=json_decode((string)($row['result_json']??''),true)?:[];$status=(string)$row['status'];
        unified_activity_add($items,[
          'type'=>'agent_action_'.$status,'surface'=>'agent','created_at'=>$when,
          'title'=>$label.' '.str_replace('_',' ',$status),
          'body'=>$status==='executed'?(string)($result['label']??('Completed in '.$row['project_title'])):'Agent action in '.$row['project_title'].' is '.$status.'.',
          'href'=>'/home.php?agent='.rawurlencode((string)$row['conversation_public_id']),
          'object'=>['type'=>'agent_action','public_id'=>$row['public_id']],
          'context'=>[['type'=>'research','public_id'=>$row['project_public_id']]],
          'meta'=>['project'=>$row['project_title'],'status'=>$status]
        ]);
    }
}

function unified_activity_collect(PDO $pdo,array $viewer,int $limit=60): array {
    $limit=max(5,min(120,$limit));if(!unified_activity_ready($pdo))return [];
    $items=[];$slice=max(8,min(30,$limit));
    unified_activity_annotations($pdo,$viewer,$items,$slice);
    unified_activity_team($pdo,$viewer,$items,$slice);
    unified_activity_project_annotations($pdo,$viewer,$items,$slice);
    unified_activity_research_objects($pdo,$viewer,$items,$slice);
    unified_activity_reports($pdo,$viewer,$items,$slice);
    unified_activity_agent($pdo,$viewer,$items,$slice);
    $rows=array_values($items);usort($rows,fn($a,$b)=>(strtotime((string)$b['created_at'])?:0)<=>(strtotime((string)$a['created_at'])?:0));
    return array_slice($rows,0,$limit);
}

function unified_activity_context_items(array $rows,int $limit=6): array {
    $out=[];foreach($rows as $row){
        if(count($out)>=$limit)break;
        if(in_array((string)$row['type'],['team_message','research_evidence_added','agent_action_executed'],true))continue;
        $out[]=$row;
    }
    return $out;
}
