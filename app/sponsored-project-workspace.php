<?php
declare(strict_types=1);
/**
 * Sponsored Project Workspace S3: collaboration is an append-only progress ledger.
 * Actual tasks, Agent documents, Team ACL, submissions, reviews and compensation
 * remain governed by their canonical systems; this never grants Agent file access.
 */
function sponsored_workspace_ready(PDO $pdo): bool {
    try{return sponsored_projects_ready($pdo)
       && installer_table_exists($pdo,'sponsored_project_updates');}
    catch(Throwable $e){return false;}
}
function sponsored_workspace_access(PDO $pdo,array $viewer,string $publicId): ?array {
    if(!sponsored_workspace_ready($pdo))throw new RuntimeException('Sponsored Project Workspace requires migration 129.');
    $publicId=trim($publicId);
    if($publicId===''||mb_strlen($publicId)>128)return null;
    if(str_starts_with($publicId,'sample-')){
        foreach(sponsored_project_sample_projects($pdo) as $sample)
            if(hash_equals((string)$sample['public_id'],$publicId))
                return ['role'=>'sample','campaign'=>$sample,'assignment'=>null,'participation'=>null];
        return null;
    }
    $campaign=sponsored_research_campaign_by_public($pdo,$publicId);
    if(!$campaign)return null;
    try{
        $managed=sponsored_research_campaign_require_manage($pdo,$viewer,$publicId);
        return ['role'=>'sponsor','campaign'=>$managed,'assignment'=>null,'participation'=>null];
    }catch(Throwable $ignored){/* Fallback is explicit, own accepted participation only. */}
    try{research_account_require_approved($pdo,$viewer);}
    catch(Throwable $ignored){return null;}
    $participation=sponsored_research_participation_get($pdo,(int)$campaign['id'],(int)$viewer['id']);
    $assignment=sponsored_project_assignment($pdo,(int)$campaign['id'],(int)$viewer['id']);
    if(!$participation||!$assignment||
       (int)$assignment['participation_id']!==(int)$participation['id']||
       !in_array((string)$participation['status'],['active','completed'],true)||
       !in_array((string)$assignment['status'],['active','paused','completed'],true))
        return null;
    return ['role'=>'researcher','campaign'=>$campaign,'assignment'=>$assignment,'participation'=>$participation];
}
function sponsored_workspace_participants(PDO $pdo,array $access,array $viewer): array {
    if($access['role']!=='sponsor')return [];
    $campaign=$access['campaign'];
    $q=$pdo->prepare("SELECT p.public_id,p.status,u.display_name,u.username,u.id user_id,
      a.status assignment_status,ra.name agent_name
      FROM sponsored_research_participations p
      JOIN users u ON u.id=p.researcher_user_id
      JOIN sponsored_research_agent_assignments a ON a.participation_id=p.id AND a.campaign_id=p.campaign_id
      JOIN research_agents ra ON ra.id=a.research_agent_id
      WHERE p.campaign_id=? AND p.status IN ('active','completed')
        AND a.status IN ('active','paused','completed')
      ORDER BY p.joined_at DESC,p.id DESC LIMIT 150");
    $q->execute([(int)$campaign['id']]);return $q->fetchAll()?:[];
}
function sponsored_workspace_submission_summaries(PDO $pdo,array $access,array $viewer,int $limit=50): array {
    if($access['role']==='sample')return [];
    $campaign=$access['campaign'];$limit=max(1,min(100,$limit));
    $sql="SELECT s.public_id,s.title,s.status,s.revision_number,s.submitted_at,
      s.review_note,u.display_name contributor_name,ra.name agent_name
      FROM sponsored_research_project_submissions s
      JOIN users u ON u.id=s.researcher_user_id JOIN research_agents ra ON ra.id=s.research_agent_id
      WHERE s.campaign_id=?";
    $params=[(int)$campaign['id']];
    if($access['role']==='researcher'){$sql.=" AND s.researcher_user_id=?";$params[]=(int)$viewer['id'];}
    $sql.=" ORDER BY s.id DESC LIMIT ".$limit;
    $q=$pdo->prepare($sql);$q->execute($params);return $q->fetchAll()?:[];
}
function sponsored_workspace_demo_updates(array $sample): array {
    return [
      ['scope'=>'project','progress_status'=>'started','title'=>'Illustrative research planning',
       'body'=>'Example methodology and source plan organized for review.',
       'actor_name'=>(string)($sample['organization_name']??'Demo Sponsor'),
       'actor_role'=>'Sample sponsor','milestone_position'=>1,'created_at'=>'Demo activity'],
      ['scope'=>'project','progress_status'=>'ready_for_review','title'=>'Example evidence synthesis',
       'body'=>'Sample findings organized into a research outline; no actual work has been submitted.',
       'actor_name'=>'Example Researcher','actor_role'=>'Demo contributor',
       'milestone_position'=>2,'created_at'=>'Demo activity'],
    ];
}
function sponsored_workspace_recent(PDO $pdo,array $access,array $viewer,int $limit=60): array {
    if($access['role']==='sample')return sponsored_workspace_demo_updates($access['campaign']);
    $limit=max(1,min(150,$limit));
    $where="WHERE up.campaign_id=?";$params=[(int)$access['campaign']['id']];
    // A researcher sees sponsor project-wide notices and ONLY their own thread.
    if($access['role']==='researcher'){
        $where.=" AND (up.scope='project' OR (up.scope='participant' AND up.participant_user_id=?))";
        $params[]=(int)$viewer['id'];
    }
    $q=$pdo->prepare("SELECT up.public_id,up.scope,up.participant_user_id,up.milestone_position,
        up.progress_status,up.title,up.body,up.created_at,u.display_name actor_name,
        up.actor_role,
        target.display_name participant_name
      FROM sponsored_project_updates up
      JOIN sponsored_research_campaigns c ON c.id=up.campaign_id
      JOIN users u ON u.id=up.actor_user_id
      LEFT JOIN users target ON target.id=up.participant_user_id
      $where ORDER BY up.id DESC LIMIT $limit");
    $q->execute($params);return $q->fetchAll()?:[];
}
/**
 * Only sponsor project-wide journal entries affect the shared milestone rail.
 * Private researcher updates stay in the private thread, never public progress.
 * This is an informational journal projection, not canonical task completion.
 */
function sponsored_workspace_milestone_states(array $milestones,array $updates): array {
    $states=array_fill(0,count($milestones),'planned');
    $found=[];
    foreach($updates as $entry){
        if(($entry['scope']??'')!=='project'||($entry['actor_role']??'')!=='sponsor')continue;
        $position=(int)($entry['milestone_position']??0);
        if($position<1||$position>count($milestones)||isset($found[$position]))continue;
        $status=(string)($entry['progress_status']??'update');
        if(!in_array($status,['update','started','blocked','ready_for_review','completed'],true))continue;
        $states[$position-1]=$status;
        $found[$position]=true;
    }
    return $states;
}
function sponsored_workspace_validate_update(array $access,array $viewer,array $input,array $participants=[]): array {
    if($access['role']==='sample')throw new RuntimeException('Demonstration projects cannot create updates.');
    if(in_array((string)($access['campaign']['status']??''),['completed','cancelled','archived'],true))
        throw new RuntimeException('A closed Sponsored Project workspace is read-only.');
    $role=(string)$access['role'];$scope=trim((string)($input['scope']??'participant'));
    if(!in_array($scope,['project','participant'],true))
        throw new InvalidArgumentException('Choose project-wide or private participant communication.');
    if($role==='researcher'&&$scope!=='participant')
        throw new RuntimeException('Researchers can post only to their own project thread.');
    if($role==='researcher'&&
       ((string)($access['participation']['status']??'')!=='active'
        ||(string)($access['assignment']['status']??'')!=='active'))
        throw new RuntimeException('Active participation and an active Agent assignment are required to post.');
    $participantId=null;
    if($scope==='participant'){
        if($role==='researcher')$participantId=(int)$viewer['id'];
        else{
            $target=trim((string)($input['participant_public_id']??''));
            foreach($participants as $p)
                if($target!==''&&hash_equals((string)$p['public_id'],$target)
                   &&(string)$p['status']==='active'
                   &&(string)$p['assignment_status']==='active'){
                    $participantId=(int)$p['user_id'];break;
                }
            if(!$participantId)throw new InvalidArgumentException('Select an active assigned researcher.');
        }
    }
    $title=trim((string)($input['title']??''));$body=trim((string)($input['body']??''));
    if($title===''||mb_strlen($title)>180||$body===''||mb_strlen($body)>4000)
        throw new InvalidArgumentException('Updates require a title (180 characters max) and text (4,000 characters max).');
    $status=(string)($input['progress_status']??'update');
    if(!in_array($status,['update','started','blocked','ready_for_review','completed'],true))
        throw new InvalidArgumentException('Unknown progress status.');
    if($role==='researcher'&&$status==='completed')
        throw new RuntimeException('Only the sponsor can mark the shared project checkpoint completed.');
    $milestoneRaw=trim((string)($input['milestone_position']??''));
    $position=null;
    if($milestoneRaw!==''){
        if(!preg_match('/^[1-9][0-9]?$/D',$milestoneRaw))throw new InvalidArgumentException('Invalid milestone index.');
        $position=(int)$milestoneRaw;
        $specs=sponsored_project_builder_normalize($access['campaign']['project_specs']??($access['campaign']['project_specs_json']??null),
            $access['campaign']['submission_deadline']??null);
        if($position>count($specs['milestones']))throw new InvalidArgumentException('Milestone does not belong to this project.');
    }
    return ['scope'=>$scope,'participant_user_id'=>$participantId,'progress_status'=>$status,
        'milestone_position'=>$position,'title'=>$title,'body'=>$body];
}
function sponsored_workspace_post(PDO $pdo,array $access,array $viewer,array $input): array {
    if(!in_array($access['role'],['sponsor','researcher'],true))
        throw new RuntimeException('Posting is unavailable.');
    $campaign=$access['campaign'];
    // Recheck membership and assignments at mutation time; never trust a form's
    // earlier participant list or a previously resolved browser session.
    $fresh=sponsored_workspace_access($pdo,$viewer,(string)$campaign['public_id']);
    if(!$fresh||$fresh['role']!==$access['role'])
        throw new RuntimeException('Your project workspace access has changed.');
    $participants=$fresh['role']==='sponsor'?sponsored_workspace_participants($pdo,$fresh,$viewer):[];
    $valid=sponsored_workspace_validate_update($fresh,$viewer,$input,$participants);
    $public=ulid_like();$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO sponsored_project_updates(public_id,campaign_id,actor_user_id,actor_role,scope,participant_user_id,milestone_position,progress_status,title,body)
            VALUES(?,?,?,?,?,?,?,?,?,?)")
           ->execute([$public,(int)$campaign['id'],(int)$viewer['id'],$fresh['role'],$valid['scope'],
               $valid['participant_user_id'],$valid['milestone_position'],$valid['progress_status'],$valid['title'],$valid['body']]);
        // Existing Agent/project audit event contains metadata only, never
        // participant-thread text. Team/Agent documents are not copied.
        sponsored_project_event($pdo,(int)$campaign['id'],null,null,(int)$viewer['id'],
           'sponsored_workspace_update',['update_public_id'=>$public,'scope'=>$valid['scope'],
             'progress_status'=>$valid['progress_status'],'milestone_position'=>$valid['milestone_position']]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['public_id'=>$public]+$valid;
}
