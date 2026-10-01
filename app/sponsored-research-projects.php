<?php
declare(strict_types=1);

function sponsored_research_projects_ready(PDO $pdo): bool {
    try{return sponsored_research_participation_ready($pdo)
      && installer_table_exists($pdo,'sponsored_research_projects')
      && installer_table_exists($pdo,'sponsored_research_project_contributors')
      && installer_table_exists($pdo,'sponsored_research_task_assignments')
      && installer_table_exists($pdo,'sponsored_research_task_deliverable_refs')
      && installer_table_exists($pdo,'sponsored_research_project_events');}
    catch(Throwable $e){return false;}
}
function sponsored_research_project_event(PDO $pdo,int $projectId,?int $assignmentId,?int $actorUserId,string $type,array $payload=[]): void {
    $pdo->prepare('INSERT INTO sponsored_research_project_events(public_id,sponsored_project_id,task_assignment_id,actor_user_id,event_type,payload_json) VALUES(?,?,?,?,?,?)')
      ->execute([ulid_like(),$projectId,$assignmentId,$actorUserId,mb_substr($type,0,80),$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}
function sponsored_research_project_get(PDO $pdo,string $publicId): ?array {
    if(!sponsored_research_projects_ready($pdo))return null;
    $q=$pdo->prepare("SELECT sp.*,c.public_id campaign_public_id,c.title campaign_title,c.brief campaign_brief,c.objective campaign_objective,
      rp.public_id research_project_public_id,rp.title research_project_title,rp.description research_project_description,
      t.public_id team_public_id,t.name team_name,ra.public_id research_agent_public_id,ra.name research_agent_name
      FROM sponsored_research_projects sp
      JOIN sponsored_research_campaigns c ON c.id=sp.campaign_id
      JOIN research_projects rp ON rp.id=sp.research_project_id
      JOIN teams t ON t.id=sp.team_id
      LEFT JOIN research_agents ra ON ra.id=sp.research_agent_id
      WHERE sp.public_id=? LIMIT 1");
    $q->execute([trim($publicId)]);return $q->fetch()?:null;
}
function sponsored_research_project_for_campaign(PDO $pdo,string $campaignPublicId): ?array {
    $q=$pdo->prepare("SELECT sp.public_id FROM sponsored_research_projects sp JOIN sponsored_research_campaigns c ON c.id=sp.campaign_id WHERE c.public_id=? LIMIT 1");
    $q->execute([trim($campaignPublicId)]);$p=$q->fetchColumn();return $p?sponsored_research_project_get($pdo,(string)$p):null;
}
function sponsored_research_project_require_manage(PDO $pdo,array $viewer,string $projectPublicId): array {
    $sp=sponsored_research_project_get($pdo,$projectPublicId);if(!$sp)throw new RuntimeException('Sponsored Research project not found.');
    sponsored_research_campaign_require_manage($pdo,$viewer,(string)$sp['campaign_public_id']);return $sp;
}
function sponsored_research_project_ensure_team(PDO $pdo,array $viewer,array $campaign): array {
    if(!empty($campaign['research_agent_id'])){
        $q=$pdo->prepare("SELECT ra.*,rp.team_id project_team_id,rp.public_id project_public_id,c.id conversation_id FROM research_agents ra JOIN research_projects rp ON rp.id=ra.project_id JOIN conversations c ON c.id=ra.conversation_id WHERE ra.id=? LIMIT 1");
        $q->execute([(int)$campaign['research_agent_id']]);$agent=$q->fetch();if(!$agent)throw new RuntimeException('Campaign Research Agent is unavailable.');
        if(!empty($agent['team_id'])){
            $q=$pdo->prepare('SELECT * FROM teams WHERE id=? LIMIT 1');$q->execute([(int)$agent['team_id']]);return ['team'=>$q->fetch(),'managed'=>0,'project_id'=>(int)$agent['project_id'],'agent'=>$agent];
        }
        $team=team_create($pdo,$viewer,mb_substr((string)$campaign['title'].' Research',0,150));
        $pdo->prepare('UPDATE research_projects SET team_id=?,updated_at=NOW() WHERE id=?')->execute([(int)$team['id'],(int)$agent['project_id']]);
        $pdo->prepare('UPDATE research_agents SET team_id=?,updated_at=NOW() WHERE id=?')->execute([(int)$team['id'],(int)$agent['id']]);
        $pdo->prepare("INSERT IGNORE INTO conversation_members(conversation_id,user_id,member_role) SELECT ?,tm.user_id,CASE WHEN tm.role IN ('owner','admin') THEN tm.role ELSE 'member' END FROM team_members tm WHERE tm.team_id=?")
          ->execute([(int)$agent['conversation_id'],(int)$team['id']]);
        $agent['team_id']=$team['id'];return ['team'=>$team,'managed'=>1,'project_id'=>(int)$agent['project_id'],'agent'=>$agent];
    }
    $team=team_create($pdo,$viewer,mb_substr((string)$campaign['title'].' Research',0,150));
    $projectPublic=ulid_like();$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,team_id,title,description,status) VALUES(?,?,?,?,?,'active')")
      ->execute([$projectPublic,(int)$viewer['id'],(int)$team['id'],mb_substr((string)$campaign['title'],0,200),trim((string)$campaign['brief']."\n\nObjective: ".(string)$campaign['objective'])]);
    return ['team'=>$team,'managed'=>1,'project_id'=>(int)$pdo->lastInsertId(),'agent'=>null];
}
function sponsored_research_project_create(PDO $pdo,array $viewer,string $campaignPublicId): array {
    if(!sponsored_research_projects_ready($pdo))throw new RuntimeException('Sponsored Research projects require migration 124.');
    $campaign=sponsored_research_campaign_require_manage($pdo,$viewer,$campaignPublicId);$existing=sponsored_research_project_for_campaign($pdo,$campaignPublicId);if($existing)return $existing;
    $ctx=sponsored_research_project_ensure_team($pdo,$viewer,$campaign);$public=ulid_like();
    $pdo->prepare("INSERT INTO sponsored_research_projects(public_id,campaign_id,research_project_id,team_id,managed_team,research_agent_id,status,created_by_user_id) VALUES(?,?,?,?,?,?,'active',?)")
      ->execute([$public,(int)$campaign['id'],(int)$ctx['project_id'],(int)$ctx['team']['id'],(int)$ctx['managed'],$campaign['research_agent_id']!==null?(int)$campaign['research_agent_id']:null,(int)$viewer['id']]);
    $id=(int)$pdo->lastInsertId();sponsored_research_project_event($pdo,$id,null,(int)$viewer['id'],'project_created',['campaign_public_id'=>$campaign['public_id'],'research_project_id'=>$ctx['project_id'],'team_public_id'=>$ctx['team']['public_id'],'research_agent_public_id'=>$campaign['research_agent_public_id']??null]);
    return sponsored_research_project_get($pdo,$public)??[];
}
function sponsored_research_project_contributors(PDO $pdo,string $projectPublicId): array {
    $sp=sponsored_research_project_get($pdo,$projectPublicId);if(!$sp)return [];
    $q=$pdo->prepare("SELECT pc.*,u.username,u.display_name,u.profile_image_url FROM sponsored_research_project_contributors pc JOIN users u ON u.id=pc.user_id WHERE pc.sponsored_project_id=? ORDER BY FIELD(pc.status,'active','invited','declined','removed'),FIELD(pc.role,'lead','researcher','reviewer','observer'),pc.id");
    $q->execute([(int)$sp['id']]);return $q->fetchAll()?:[];
}
function sponsored_research_project_invite(PDO $pdo,array $viewer,string $projectPublicId,string $username,string $role='researcher'): array {
    $sp=sponsored_research_project_require_manage($pdo,$viewer,$projectPublicId);$role=in_array($role,['lead','researcher','reviewer','observer'],true)?$role:'researcher';
    $q=$pdo->prepare("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1");$q->execute([trim($username)]);$user=$q->fetch();if(!$user)throw new RuntimeException('Contributor not found.');
    research_account_require_approved($pdo,$user);
    $public=ulid_like();$pdo->prepare("INSERT INTO sponsored_research_project_contributors(public_id,sponsored_project_id,user_id,role,status,invited_by_user_id) VALUES(?,?,?,?,'invited',?) ON DUPLICATE KEY UPDATE role=VALUES(role),status=IF(status='active','active','invited'),invited_by_user_id=VALUES(invited_by_user_id),invited_at=NOW(),removed_at=NULL")
      ->execute([$public,(int)$sp['id'],(int)$user['id'],$role,(int)$viewer['id']]);
    $q=$pdo->prepare('SELECT * FROM sponsored_research_project_contributors WHERE sponsored_project_id=? AND user_id=?');$q->execute([(int)$sp['id'],(int)$user['id']]);$row=$q->fetch();
    sponsored_research_project_event($pdo,(int)$sp['id'],null,(int)$viewer['id'],'contributor_invited',['contributor_user_id'=>(int)$user['id'],'role'=>$role]);return $row?:[];
}
function sponsored_research_project_accept(PDO $pdo,array $viewer,string $projectPublicId): array {
    research_account_require_approved($pdo,$viewer);$sp=sponsored_research_project_get($pdo,$projectPublicId);if(!$sp)throw new RuntimeException('Sponsored Research project not found.');
    $q=$pdo->prepare("SELECT * FROM sponsored_research_project_contributors WHERE sponsored_project_id=? AND user_id=? AND status='invited' LIMIT 1");$q->execute([(int)$sp['id'],(int)$viewer['id']]);$pc=$q->fetch();if(!$pc)throw new RuntimeException('Project invitation not found.');
    $pdo->beginTransaction();try{
      $pdo->prepare("UPDATE sponsored_research_project_contributors SET status='active',accepted_at=NOW(),removed_at=NULL WHERE id=?")->execute([(int)$pc['id']]);
      $teamRole=in_array((string)$pc['role'],['lead'],true)?'researcher':((string)$pc['role']==='observer'?'viewer':'researcher');
      $pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,?) ON DUPLICATE KEY UPDATE role=CASE WHEN role IN ('owner','admin') THEN role ELSE VALUES(role) END")->execute([(int)$sp['team_id'],(int)$viewer['id'],$teamRole]);
      if(!empty($sp['research_agent_id'])){
        $q=$pdo->prepare('SELECT conversation_id FROM research_agents WHERE id=?');$q->execute([(int)$sp['research_agent_id']]);$conversationId=(int)$q->fetchColumn();
        if($conversationId)$pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'member') ON DUPLICATE KEY UPDATE member_role=member_role")->execute([$conversationId,(int)$viewer['id']]);
      }
      sponsored_research_project_event($pdo,(int)$sp['id'],null,(int)$viewer['id'],'contributor_joined',['role'=>$pc['role']]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $q=$pdo->prepare('SELECT * FROM sponsored_research_project_contributors WHERE id=?');$q->execute([(int)$pc['id']]);return $q->fetch()?:[];
}
function sponsored_research_project_remove_contributor(PDO $pdo,array $viewer,string $projectPublicId,int $userId): void {
    $sp=sponsored_research_project_require_manage($pdo,$viewer,$projectPublicId);$q=$pdo->prepare("SELECT * FROM sponsored_research_project_contributors WHERE sponsored_project_id=? AND user_id=? AND status='active' LIMIT 1");$q->execute([(int)$sp['id'],$userId]);$pc=$q->fetch();if(!$pc)throw new RuntimeException('Active contributor not found.');
    $pdo->prepare("UPDATE sponsored_research_project_contributors SET status='removed',removed_at=NOW() WHERE id=?")->execute([(int)$pc['id']]);
    if((int)$sp['managed_team']===1){$q=$pdo->prepare('SELECT owner_user_id FROM teams WHERE id=?');$q->execute([(int)$sp['team_id']]);if((int)$q->fetchColumn()!==$userId)$pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([(int)$sp['team_id'],$userId]);}
    sponsored_research_project_event($pdo,(int)$sp['id'],null,(int)$viewer['id'],'contributor_removed',['contributor_user_id'=>$userId]);
}
function sponsored_research_project_task(PDO $pdo,array $viewer,string $projectPublicId,array $input): array {
    $sp=sponsored_research_project_require_manage($pdo,$viewer,$projectPublicId);$project=project_access($pdo,(int)$viewer['id'],(string)$sp['research_project_public_id']);if(!$project)throw new RuntimeException('Canonical Research Project is unavailable.');
    $title=mb_substr(trim((string)($input['title']??'')),0,255);if($title==='')throw new InvalidArgumentException('Task title is required.');$description=mb_substr(trim((string)($input['description']??'')),0,12000);
    $type=(string)($input['task_type']??'general');if(function_exists('research_task_types')&&!isset(research_task_types()[$type]))$type='general';$priority=(string)($input['priority']??'medium');if(function_exists('research_task_priorities')&&!isset(research_task_priorities()[$priority]))$priority='medium';
    if(function_exists('research_tasks_ready')&&research_tasks_ready($pdo)&&research_task_agent_for_project($pdo,$viewer,(string)$project['public_id']))$task=research_task_create_for_project($pdo,$viewer,$project,['title'=>$title,'description'=>$description,'task_type'=>$type,'priority'=>$priority,'due_at'=>$input['due_at']??null],false);
    else{$public=ulid_like();$pdo->prepare("INSERT INTO research_tasks(public_id,project_id,created_by_user_id,title,description,task_type,status,priority,due_at) VALUES(?,?,?,?,?,?,'open',?,?)")->execute([$public,(int)$project['id'],(int)$viewer['id'],$title,$description!==''?$description:null,$type,$priority,research_task_clean_due($input['due_at']??null)]);$q=$pdo->prepare('SELECT * FROM research_tasks WHERE public_id=?');$q->execute([$public]);$task=$q->fetch();}
    sponsored_research_project_event($pdo,(int)$sp['id'],null,(int)$viewer['id'],'task_created',['task_public_id'=>$task['public_id'],'title'=>$title]);return $task;
}
function sponsored_research_project_task_assign(PDO $pdo,array $viewer,string $projectPublicId,string $taskPublicId,int $contributorUserId,string $role='contributor'): array {
    $sp=sponsored_research_project_require_manage($pdo,$viewer,$projectPublicId);$role=in_array($role,['owner','contributor','reviewer'],true)?$role:'contributor';
    $q=$pdo->prepare("SELECT pc.* FROM sponsored_research_project_contributors pc WHERE pc.sponsored_project_id=? AND pc.user_id=? AND pc.status='active' LIMIT 1");$q->execute([(int)$sp['id'],$contributorUserId]);if(!$q->fetch())throw new RuntimeException('Task assignee must be an active project contributor.');
    $q=$pdo->prepare('SELECT * FROM research_tasks WHERE public_id=? AND project_id=? LIMIT 1');$q->execute([trim($taskPublicId),(int)$sp['research_project_id']]);$task=$q->fetch();if(!$task)throw new RuntimeException('Research task is not part of this Sponsored Project.');
    $public=ulid_like();$pdo->prepare("INSERT INTO sponsored_research_task_assignments(public_id,sponsored_project_id,research_task_id,contributor_user_id,assignment_role,status,assigned_by_user_id) VALUES(?,?,?,?,?,'assigned',?) ON DUPLICATE KEY UPDATE status='assigned',assigned_by_user_id=VALUES(assigned_by_user_id),assigned_at=NOW(),completed_at=NULL")
      ->execute([$public,(int)$sp['id'],(int)$task['id'],$contributorUserId,$role,(int)$viewer['id']]);
    $q=$pdo->prepare('SELECT * FROM sponsored_research_task_assignments WHERE research_task_id=? AND contributor_user_id=? AND assignment_role=?');$q->execute([(int)$task['id'],$contributorUserId,$role]);$a=$q->fetch();sponsored_research_project_event($pdo,(int)$sp['id'],(int)$a['id'],(int)$viewer['id'],'task_assigned',['task_public_id'=>$task['public_id'],'contributor_user_id'=>$contributorUserId,'role'=>$role]);return $a?:[];
}
function sponsored_research_project_assignment_update(PDO $pdo,array $viewer,string $assignmentPublicId,string $status): array {
    if(!in_array($status,['accepted','in_progress','submitted','completed'],true))throw new InvalidArgumentException('Invalid assignment status.');
    $q=$pdo->prepare("SELECT a.*,sp.public_id sponsored_project_public_id FROM sponsored_research_task_assignments a JOIN sponsored_research_projects sp ON sp.id=a.sponsored_project_id WHERE a.public_id=? LIMIT 1");$q->execute([trim($assignmentPublicId)]);$a=$q->fetch();if(!$a)throw new RuntimeException('Task assignment not found.');
    $isContributor=(int)$a['contributor_user_id']===(int)$viewer['id'];$canManage=false;try{sponsored_research_project_require_manage($pdo,$viewer,(string)$a['sponsored_project_public_id']);$canManage=true;}catch(Throwable $e){}
    if(!$isContributor&&!$canManage)throw new RuntimeException('You cannot update this assignment.');
    if($status==='completed'&&!$canManage)throw new RuntimeException('Project manager approval is required to complete an assignment.');
    $fields=["status=?"];$params=[$status];if($status==='accepted')$fields[]='accepted_at=NOW()';if($status==='submitted')$fields[]='submitted_at=NOW()';if($status==='completed')$fields[]='completed_at=NOW()';$params[]=(int)$a['id'];
    $pdo->prepare('UPDATE sponsored_research_task_assignments SET '.implode(',',$fields).' WHERE id=?')->execute($params);sponsored_research_project_event($pdo,(int)$a['sponsored_project_id'],(int)$a['id'],(int)$viewer['id'],'task_assignment_'.$status);
    $q=$pdo->prepare('SELECT * FROM sponsored_research_task_assignments WHERE id=?');$q->execute([(int)$a['id']]);return $q->fetch()?:[];
}
function sponsored_research_task_ref_exists(PDO $pdo,array $viewer,string $type,string $publicId): bool {
    if(in_array($type,['annotation','claim','finding','report_version'],true)){$d=data_object_descriptor($pdo,$type,$publicId);return $d!==null&&(int)($d['contributor_user_id']??0)===(int)$viewer['id'];}
    if($type==='source')return source_access($pdo,$publicId,$viewer)!==null;
    if($type==='mission')return research_mission_access($pdo,$viewer,$publicId)!==null;
    if($type==='dataset')return data_dataset_get($pdo,$publicId)!==null;
    if($type==='document'){$q=$pdo->prepare("SELECT 1 FROM research_workspace_objects WHERE public_id=? AND object_type='document' LIMIT 1");$q->execute([$publicId]);return (bool)$q->fetchColumn();}
    return false;
}
function sponsored_research_project_submit_ref(PDO $pdo,array $viewer,string $assignmentPublicId,string $type,string $publicId,string $note=''): array {
    $q=$pdo->prepare("SELECT a.*,sp.public_id sponsored_project_public_id FROM sponsored_research_task_assignments a JOIN sponsored_research_projects sp ON sp.id=a.sponsored_project_id WHERE a.public_id=? LIMIT 1");$q->execute([trim($assignmentPublicId)]);$a=$q->fetch();if(!$a||(int)$a['contributor_user_id']!==(int)$viewer['id'])throw new RuntimeException('Task assignment not found.');
    if(!in_array($type,['annotation','source','claim','finding','report_version','mission','dataset','document'],true)||!sponsored_research_task_ref_exists($pdo,$viewer,$type,$publicId))throw new RuntimeException('Research deliverable reference is unavailable.');
    $public=ulid_like();$pdo->prepare("INSERT INTO sponsored_research_task_deliverable_refs(public_id,assignment_id,research_task_id,contributor_user_id,ref_type,ref_public_id,note) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE note=VALUES(note),submitted_at=NOW()")
      ->execute([$public,(int)$a['id'],(int)$a['research_task_id'],(int)$viewer['id'],$type,trim($publicId),mb_substr(trim($note),0,1000)?:null]);
    sponsored_research_project_assignment_update($pdo,$viewer,$assignmentPublicId,'submitted');$q=$pdo->prepare('SELECT * FROM sponsored_research_task_deliverable_refs WHERE assignment_id=? AND ref_type=? AND ref_public_id=?');$q->execute([(int)$a['id'],$type,trim($publicId)]);return $q->fetch()?:[];
}
function sponsored_research_project_tasks(PDO $pdo,string $projectPublicId): array {
    $sp=sponsored_research_project_get($pdo,$projectPublicId);if(!$sp)return [];$q=$pdo->prepare("SELECT rt.*,COUNT(a.id) assignment_count,SUM(CASE WHEN a.status='completed' THEN 1 ELSE 0 END) completed_assignments FROM research_tasks rt LEFT JOIN sponsored_research_task_assignments a ON a.research_task_id=rt.id AND a.sponsored_project_id=? WHERE rt.project_id=? GROUP BY rt.id ORDER BY FIELD(rt.status,'review','failed','waiting','researching','ready','queued','open','in_progress','complete','done','archived'),rt.created_at DESC");$q->execute([(int)$sp['id'],(int)$sp['research_project_id']]);return $q->fetchAll()?:[];
}
function sponsored_research_project_assignments(PDO $pdo,string $projectPublicId,?int $userId=null): array {
    $sp=sponsored_research_project_get($pdo,$projectPublicId);if(!$sp)return [];$params=[(int)$sp['id']];$where='a.sponsored_project_id=?';if($userId){$where.=' AND a.contributor_user_id=?';$params[]=$userId;}
    $q=$pdo->prepare("SELECT a.*,rt.public_id task_public_id,rt.title task_title,rt.description task_description,rt.status task_status,rt.priority,rt.due_at,u.username,u.display_name FROM sponsored_research_task_assignments a JOIN research_tasks rt ON rt.id=a.research_task_id JOIN users u ON u.id=a.contributor_user_id WHERE $where ORDER BY FIELD(a.status,'assigned','accepted','in_progress','submitted','completed','reassigned','removed'),rt.due_at IS NULL,rt.due_at,a.id");$q->execute($params);return $q->fetchAll()?:[];
}
function sponsored_research_project_events(PDO $pdo,string $projectPublicId,int $limit=100): array {
    $sp=sponsored_research_project_get($pdo,$projectPublicId);if(!$sp)return [];$q=$pdo->prepare('SELECT e.*,u.display_name actor_name FROM sponsored_research_project_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.sponsored_project_id=? ORDER BY e.id DESC LIMIT '.max(1,min(500,$limit)));$q->execute([(int)$sp['id']]);return $q->fetchAll()?:[];
}
