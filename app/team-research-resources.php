<?php
declare(strict_types=1);
/* Reuse the canonical Research Agent ↔ Research Project ↔ Desktop/Library boundary. */
function team_research_resources(PDO $pdo,int $teamId): array {
    $q=$pdo->prepare("SELECT ra.id,ra.public_id,ra.name,ra.description,ra.profile_image_url,ra.owner_user_id,ra.conversation_id,
      rp.public_id project_public_id,rp.title project_title,
      u.display_name owner_name
      FROM research_agents ra JOIN research_projects rp ON rp.id=ra.project_id
      JOIN users u ON u.id=ra.owner_user_id
      WHERE ra.team_id=? AND rp.team_id=? AND ra.status<>'archived'
      ORDER BY ra.updated_at DESC,ra.id DESC");
    $q->execute([$teamId,$teamId]);return $q->fetchAll()?:[];
}
function team_research_assignable(PDO $pdo,int $ownerId): array {
    $q=$pdo->prepare("SELECT ra.public_id,ra.name,rp.title project_title
      FROM research_agents ra JOIN research_projects rp ON rp.id=ra.project_id
      WHERE ra.owner_user_id=? AND ra.team_id IS NULL AND rp.team_id IS NULL
        AND ra.status<>'archived' AND rp.status<>'archived'
      ORDER BY ra.name LIMIT 100");
    $q->execute([$ownerId]);return $q->fetchAll()?:[];
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
function team_research_assign(PDO $pdo,array $team,array $viewer,string $agentPublicId): void {
    if((string)$team['access_role']!=='owner'||(int)$team['owner_user_id']!==(int)$viewer['id'])throw new RuntimeException('Only the Team owner can manage Team Research Agents.');
    $q=$pdo->prepare('SELECT ra.id,ra.project_id,ra.conversation_id,ra.owner_user_id,ra.team_id,rp.team_id project_team_id
      FROM research_agents ra JOIN research_projects rp ON rp.id=ra.project_id
      WHERE ra.public_id=? AND ra.status<>\'archived\' AND rp.status<>\'archived\' LIMIT 1');
    $q->execute([$agentPublicId]);$agent=$q->fetch();
    if(!$agent||(int)$agent['owner_user_id']!==(int)$viewer['id'])throw new RuntimeException('The Team owner may assign only Research Agents they personally own.');
    if($agent['team_id']!==null||$agent['project_team_id']!==null)throw new RuntimeException('This Agent or its workspace is already Team-assigned.');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE research_projects SET team_id=? WHERE id=? AND team_id IS NULL')
            ->execute([(int)$team['id'],(int)$agent['project_id']]);
        $pdo->prepare('UPDATE research_agents SET team_id=? WHERE id=? AND team_id IS NULL')
            ->execute([(int)$team['id'],(int)$agent['id']]);
        // Existing personal conversation invites cannot retain access outside Team membership.
        $pdo->prepare('DELETE FROM conversation_members WHERE conversation_id=? AND user_id NOT IN (SELECT user_id FROM team_members WHERE team_id=?)')
            ->execute([(int)$agent['conversation_id'],(int)$team['id']]);
        $members=$pdo->prepare('SELECT user_id FROM team_members WHERE team_id=?');$members->execute([(int)$team['id']]);
        foreach($members->fetchAll(PDO::FETCH_COLUMN)?:[] as $userId){
            $role=$pdo->prepare('SELECT role FROM team_members WHERE team_id=? AND user_id=?');
            $role->execute([(int)$team['id'],(int)$userId]);$teamRole=(string)$role->fetchColumn();
            $memberRole=in_array($teamRole,['owner','admin'],true)?$teamRole:'member';
            $pdo->prepare('INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,?) ON DUPLICATE KEY UPDATE member_role=VALUES(member_role)')
                ->execute([(int)$agent['conversation_id'],(int)$userId,$memberRole]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function team_research_unassign(PDO $pdo,array $team,array $viewer,string $agentPublicId): void {
    if((string)$team['access_role']!=='owner'||(int)$team['owner_user_id']!==(int)$viewer['id'])throw new RuntimeException('Only the Team owner can manage Team Research Agents.');
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
