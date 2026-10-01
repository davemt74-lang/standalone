<?php
declare(strict_types=1);
/* Reuse the canonical Research Agent ↔ Research Project ↔ Desktop/Library boundary. */
function team_research_resources(PDO $pdo,int $teamId): array {
    $q=$pdo->prepare("SELECT ra.id,ra.public_id,ra.name,ra.description,ra.profile_image_url,ra.owner_user_id,ra.conversation_id,c.public_id conversation_public_id,
      rp.public_id project_public_id,rp.title project_title,
      u.display_name owner_name
      FROM research_agents ra JOIN research_projects rp ON rp.id=ra.project_id
      JOIN conversations c ON c.id=ra.conversation_id
      JOIN users u ON u.id=ra.owner_user_id
      WHERE ra.team_id=? AND rp.team_id=? AND ra.status<>'archived'
      ORDER BY ra.updated_at DESC,ra.id DESC");
    $q->execute([$teamId,$teamId]);return $q->fetchAll()?:[];
}
function team_research_assignable(PDO $pdo,int $ownerId): array {
    $q=$pdo->prepare("SELECT ra.public_id,ra.name,rp.title project_title
      FROM research_agents ra JOIN research_projects rp ON rp.id=ra.project_id
      WHERE ra.owner_user_id=? AND rp.owner_user_id=ra.owner_user_id AND ra.team_id IS NULL AND rp.team_id IS NULL AND ra.visibility='private'
        AND ra.status<>'archived' AND rp.status<>'archived'
        AND NOT EXISTS (SELECT 1 FROM research_agents other WHERE other.project_id=rp.id AND other.id<>ra.id AND other.status<>'archived')
        AND NOT EXISTS (SELECT 1 FROM sponsored_research_agent_assignments sponsor WHERE sponsor.research_agent_id=ra.id AND sponsor.status IN ('active','paused','completed'))
      ORDER BY ra.name LIMIT 100");
    $q->execute([$ownerId]);return $q->fetchAll()?:[];
}
/**
 * The picker shows only Teams both owned by this user and carrying an owner
 * membership. UI eligibility never replaces the transactional runtime checks.
 */
function team_research_owned_teams(PDO $pdo,int $ownerId): array {
    $q=$pdo->prepare("SELECT t.id,t.public_id,t.name,t.owner_user_id,tm.role access_role
      FROM teams t JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=? AND tm.role='owner'
      WHERE t.owner_user_id=? ORDER BY t.name,t.id");
    $q->execute([$ownerId,$ownerId]);return $q->fetchAll()?:[];
}
function team_research_owned_team(PDO $pdo,int $ownerId,string $teamPublicId): ?array {
    $q=$pdo->prepare("SELECT t.id,t.public_id,t.name,t.owner_user_id,tm.role access_role
      FROM teams t JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=? AND tm.role='owner'
      WHERE t.owner_user_id=? AND t.public_id=? LIMIT 1");
    $q->execute([$ownerId,$ownerId,trim($teamPublicId)]);return $q->fetch()?:null;
}
function team_research_agent_team(PDO $pdo,int $ownerId,string $agentPublicId): ?array {
    $q=$pdo->prepare("SELECT t.id,t.public_id,t.name,t.owner_user_id,
      EXISTS(SELECT 1 FROM team_members tm WHERE tm.team_id=t.id AND tm.user_id=? AND tm.role='owner') owner_member
      FROM research_agents ra JOIN teams t ON t.id=ra.team_id
      WHERE ra.public_id=? AND ra.owner_user_id=? LIMIT 1");
    $q->execute([$ownerId,trim($agentPublicId),$ownerId]);return $q->fetch()?:null;
}
function team_research_sync_member(PDO $pdo,int $teamId,int $memberId,bool $enabled): void {
    $q=$pdo->prepare("SELECT ra.conversation_id FROM research_agents ra
      JOIN research_projects rp ON rp.id=ra.project_id AND rp.team_id=ra.team_id
      WHERE ra.team_id=? AND ra.status<>'archived'");
    $q->execute([$teamId]);$conversations=$q->fetchAll(PDO::FETCH_COLUMN)?:[];
    foreach($conversations as $conversationId){
        if(!$enabled){
            $pdo->prepare("DELETE FROM conversation_members WHERE conversation_id=? AND user_id=?")
                ->execute([(int)$conversationId,$memberId]);
            continue;
        }
        $role=$pdo->prepare('SELECT role FROM team_members WHERE team_id=? AND user_id=? LIMIT 1');
        $role->execute([$teamId,$memberId]);$teamRole=(string)($role->fetchColumn()?:'');
        if($teamRole==='')continue;
        $memberRole=in_array($teamRole,['owner','admin'],true)?$teamRole:'member';
        $pdo->prepare('INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,?) ON DUPLICATE KEY UPDATE member_role=VALUES(member_role)')
            ->execute([(int)$conversationId,$memberId,$memberRole]);
    }
}
/**
 * Add an existing user without changing their role if already a member.
 * Both owner and admin may invite; only the Team owner may attach Agents.
 */
function team_research_add_member(PDO $pdo,array $team,array $viewer,int $memberId): bool {
    if($memberId<=0)throw new InvalidArgumentException('Select a valid user.');
    $q=$pdo->prepare("SELECT tm.role FROM teams t JOIN team_members tm
      ON tm.team_id=t.id AND tm.user_id=? WHERE t.id=? LIMIT 1");
    $q->execute([(int)$viewer['id'],(int)$team['id']]);
    $role=(string)($q->fetchColumn()?:'');
    if(!in_array($role,['owner','admin'],true))throw new RuntimeException('Team management permission required.');
    $q=$pdo->prepare("SELECT 1 FROM users WHERE id=? AND status='active' LIMIT 1");
    $q->execute([$memberId]);if(!$q->fetchColumn())throw new RuntimeException('Active user not found.');
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("INSERT IGNORE INTO team_members(team_id,user_id,role) VALUES(?,?,'researcher')");
        $q->execute([(int)$team['id'],$memberId]);
        $added=$q->rowCount()===1;
        team_research_sync_member($pdo,(int)$team['id'],$memberId,true);
        $pdo->commit();
        return $added;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function team_research_require_owner(PDO $pdo,array $team,array $viewer): void {
    if((string)($team['access_role']??'')!=='owner'||(int)($team['owner_user_id']??0)!==(int)$viewer['id'])
        throw new RuntimeException('Only the Team owner can manage Team Research Agents.');
    $q=$pdo->prepare("SELECT 1 FROM teams t JOIN team_members tm ON tm.team_id=t.id
        WHERE t.id=? AND t.owner_user_id=? AND tm.user_id=? AND tm.role='owner' LIMIT 1");
    $q->execute([(int)$team['id'],(int)$viewer['id'],(int)$viewer['id']]);
    if(!$q->fetchColumn())throw new RuntimeException('Current Team ownership must be verified.');
}
function team_research_assign(PDO $pdo,array $team,array $viewer,string $agentPublicId): void {
    team_research_require_owner($pdo,$team,$viewer);
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("SELECT ra.id,ra.project_id,ra.conversation_id,ra.owner_user_id,ra.team_id,ra.visibility,
          rp.team_id project_team_id,rp.owner_user_id project_owner_id
          FROM research_agents ra JOIN research_projects rp ON rp.id=ra.project_id
          WHERE ra.public_id=? AND ra.status<>'archived' AND rp.status<>'archived' LIMIT 1 FOR UPDATE");
        $q->execute([$agentPublicId]);$agent=$q->fetch();
        if(!$agent||(int)$agent['owner_user_id']!==(int)$viewer['id']||(int)$agent['project_owner_id']!==(int)$viewer['id'])
            throw new RuntimeException('The Team owner may assign only Research Agents and projects they personally own.');
        if((string)$agent['visibility']!=='private')
            throw new RuntimeException('Set the Research Agent visibility to Private before sharing its workspace with a Team.');
        if($agent['team_id']!==null||$agent['project_team_id']!==null)
            throw new RuntimeException('This Agent or its workspace is already Team-assigned.');
        // Sponsored assignments have separate confidentiality and compensation obligations.
        $sponsor=$pdo->prepare("SELECT 1 FROM sponsored_research_agent_assignments WHERE research_agent_id=? LIMIT 1");
        $sponsor->execute([(int)$agent['id']]);
        if($sponsor->fetchColumn())throw new RuntimeException('Sponsored Research Agents cannot be shared with a Team without an explicit sponsored collaboration agreement. This also applies after withdrawal or removal to preserve historical private conversations.');
        $other=$pdo->prepare("SELECT COUNT(*) FROM research_agents WHERE project_id=? AND id<>? AND status<>'archived'");
        $other->execute([(int)$agent['project_id'],(int)$agent['id']]);
        if((int)$other->fetchColumn()>0)
            throw new RuntimeException('This project contains another Research Agent; sharing it would expose a separate Agent.');
        $update=$pdo->prepare('UPDATE research_projects SET team_id=? WHERE id=? AND owner_user_id=? AND team_id IS NULL');
        $update->execute([(int)$team['id'],(int)$agent['project_id'],(int)$viewer['id']]);
        if($update->rowCount()!==1)throw new RuntimeException('Project changed during Team assignment.');
        $update=$pdo->prepare('UPDATE research_agents SET team_id=? WHERE id=? AND owner_user_id=? AND team_id IS NULL');
        $update->execute([(int)$team['id'],(int)$agent['id'],(int)$viewer['id']]);
        if($update->rowCount()!==1)throw new RuntimeException('Research Agent changed during Team assignment.');
        // Personal conversation invitees cannot retain access outside Team membership.
        $pdo->prepare('DELETE FROM conversation_members WHERE conversation_id=? AND user_id NOT IN (SELECT user_id FROM team_members WHERE team_id=?)')
            ->execute([(int)$agent['conversation_id'],(int)$team['id']]);
        $members=$pdo->prepare('SELECT user_id,role FROM team_members WHERE team_id=?');
        $members->execute([(int)$team['id']]);
        foreach($members->fetchAll()?:[] as $member){
            $memberRole=in_array((string)$member['role'],['owner','admin'],true)?(string)$member['role']:'member';
            $pdo->prepare('INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,?) ON DUPLICATE KEY UPDATE member_role=VALUES(member_role)')
                ->execute([(int)$agent['conversation_id'],(int)$member['user_id'],$memberRole]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function team_research_unassign(PDO $pdo,array $team,array $viewer,string $agentPublicId): void {
    team_research_require_owner($pdo,$team,$viewer);
    $q=$pdo->prepare('SELECT ra.id,ra.project_id,ra.conversation_id,ra.owner_user_id
      FROM research_agents ra JOIN research_projects rp ON rp.id=ra.project_id AND rp.team_id=ra.team_id
      WHERE ra.public_id=? AND ra.team_id=? LIMIT 1');
    $q->execute([$agentPublicId,(int)$team['id']]);$agent=$q->fetch();
    if(!$agent||(int)$agent['owner_user_id']!==(int)$viewer['id'])throw new RuntimeException('Only the Team owner may remove their own assigned Research Agent.');
    $pdo->beginTransaction();
    try{
        $pdo->prepare('UPDATE research_agents SET team_id=NULL WHERE id=? AND team_id=?')
            ->execute([(int)$agent['id'],(int)$team['id']]);
        $pdo->prepare('UPDATE research_projects SET team_id=NULL WHERE id=? AND team_id=?')
            ->execute([(int)$agent['project_id'],(int)$team['id']]);
        $pdo->prepare('DELETE FROM conversation_members WHERE conversation_id=? AND user_id<>?')
            ->execute([(int)$agent['conversation_id'],(int)$agent['owner_user_id']]);
        $pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'owner')
          ON DUPLICATE KEY UPDATE member_role='owner'")->execute([(int)$agent['conversation_id'],(int)$agent['owner_user_id']]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/**
 * Section 1: use the canonical Agent Project and its existing workspace ACL.
 * Never infer Team access from the caller-supplied Team ID alone.
 */
function team_research_collaboration_project(PDO $pdo,array $viewer,int $teamId,string $agentPublicId,bool $write=false): array {
    $q=$pdo->prepare("SELECT rp.id project_id,rp.public_id project_public_id,
      ra.public_id agent_public_id,ra.conversation_id,tm.role member_role
      FROM teams t JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=?
      JOIN research_agents ra ON ra.team_id=t.id AND ra.public_id=? AND ra.status<>'archived'
      JOIN research_projects rp ON rp.id=ra.project_id AND rp.team_id=t.id AND rp.status<>'archived'
      WHERE t.id=? LIMIT 1");
    $q->execute([(int)$viewer['id'],trim($agentPublicId),$teamId]);$scoped=$q->fetch();
    if(!$scoped)throw new RuntimeException('This Research Agent is not assigned to your Team.');
    $project=research_agent_workspace_project($pdo,$viewer,(string)$scoped['agent_public_id']);
    if(!$project||(int)$project['id']!==(int)$scoped['project_id'])
        throw new RuntimeException('The Team Research workspace is unavailable.');
    if($write)research_agent_workspace_require_write($project);
    return $project;
}
function team_research_collaboration_create_document(PDO $pdo,array $viewer,int $teamId,string $agentPublicId,array $input): array {
    $project=team_research_collaboration_project($pdo,$viewer,$teamId,$agentPublicId,true);
    $title=mb_substr(trim((string)($input['title']??'')),0,240);
    if($title==='')throw new InvalidArgumentException('Document title is required.');
    $body=trim((string)($input['body']??''));
    if($body==='')throw new InvalidArgumentException('Add your research notes before saving.');
    if(mb_strlen($body)>50000)throw new InvalidArgumentException('Research notes must be 50,000 characters or fewer.');
    // Preserve an origin tag even if the Agent returns to the owner's personal workspace.
    // Current visibility always follows canonical Project/Team ACL, never this tag.
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $item=research_agent_workspace_create_document($pdo,$viewer,$project,[
            'title'=>$title,'body'=>$body,'document_type'=>'document'
        ]);
        $meta=[
            'contribution_scope'=>'team',
            'origin_team_id'=>$teamId,
            'origin_agent_public_id'=>trim($agentPublicId),
            'contributor_user_id'=>(int)$viewer['id']
        ];
        $q=$pdo->prepare('UPDATE research_workspace_objects SET metadata_json=? WHERE public_id=? AND project_id=? AND created_by_user_id=?');
        $q->execute([json_encode($meta,JSON_UNESCAPED_SLASHES),(string)$item['public_id'],(int)$project['id'],(int)$viewer['id']]);
        if($q->rowCount()!==1)throw new RuntimeException('The contribution could not be tagged.');
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    $item['metadata']=$meta;
    return $item;
}
function team_research_collaboration_recent(PDO $pdo,array $viewer,int $teamId,int $limit=18): array {
    // A removed member must not see contributions through a stale browser session.
    $q=$pdo->prepare('SELECT 1 FROM team_members WHERE team_id=? AND user_id=? LIMIT 1');
    $q->execute([$teamId,(int)$viewer['id']]);
    if(!$q->fetchColumn())return [];
    $limit=max(1,min(40,$limit));
    $q=$pdo->prepare("SELECT rwo.public_id,rwo.object_type,rwo.title,rwo.updated_at,
      u.display_name contributor_name,u.username contributor_username,
      COALESCE(editor.display_name,u.display_name) last_editor_name,
      t.name team_name,t.public_id team_public_id,rwo.metadata_json,
      ra.public_id agent_public_id,ra.name agent_name,c.public_id conversation_public_id,
      rwd.summary document_summary,rwd.revision_number
      FROM research_workspace_objects rwo
      JOIN research_projects rp ON rp.id=rwo.project_id AND rp.team_id=?
      JOIN teams t ON t.id=rp.team_id
      JOIN research_agents ra ON ra.project_id=rp.id AND ra.team_id=? AND ra.status<>'archived'
      JOIN conversations c ON c.id=ra.conversation_id
      JOIN users u ON u.id=rwo.created_by_user_id
      LEFT JOIN research_workspace_documents rwd ON rwd.object_id=rwo.id
      LEFT JOIN users editor ON editor.id=rwd.last_edited_by_user_id
      WHERE rwo.status='active' AND rwo.object_type IN ('document','bookmark','upload','recording')
      ORDER BY rwo.updated_at DESC,rwo.id DESC LIMIT ".$limit);
    $q->execute([$teamId,$teamId]);$items=$q->fetchAll()?:[];
    foreach($items as &$item){
        $metadata=json_decode((string)($item['metadata_json']??''),true);
        $item['contribution_scope']='team';
        $item['tag_label']='Team · '.(string)$item['team_name'];
        $item['origin_team_contribution']=is_array($metadata)&&($metadata['contribution_scope']??'')==='team'
            &&(int)($metadata['origin_team_id']??0)===$teamId;
        unset($item['metadata_json']);
    }
    unset($item);
    return $items;
}
