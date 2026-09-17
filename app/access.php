<?php
declare(strict_types=1);

function project_can_write(array $project): bool {
    return in_array((string)($project['access_role']??''),['owner','admin','researcher'],true);
}
function users_share_team(PDO $pdo,int $a,int $b): bool {
    if($a<1||$b<1)return false;
    $q=$pdo->prepare('SELECT 1 FROM team_members x JOIN team_members y ON y.team_id=x.team_id WHERE x.user_id=? AND y.user_id=? LIMIT 1');
    $q->execute([$a,$b]);
    return (bool)$q->fetchColumn();
}
function presence_identity_visible(PDO $pdo,int $viewerUserId,int $subjectUserId,string $mode): bool {
    if($mode==='visible')return true;
    if($mode==='team_only')return users_share_team($pdo,$viewerUserId,$subjectUserId);
    return false;
}
function annotation_access(PDO $pdo,string $publicId,?array $viewer): ?array {
    $uid=(int)($viewer['id']??0);$admin=(($viewer['role']??'')==='admin');
    $q=$pdo->prepare('SELECT id,public_id,user_id,source_id,source_version_id,capture_id,visibility,team_id,status FROM annotations WHERE public_id=? LIMIT 1');
    $q->execute([$publicId]);$a=$q->fetch();if(!$a)return null;
    if($a['status']==='published'&&$a['visibility']==='public')return $a;
    if(!$viewer)return null;
    if($admin||(int)$a['user_id']===$uid)return $a;
    if($a['status']!=='published')return null;
    if($a['visibility']==='team'&&!empty($a['team_id'])){
        $q=$pdo->prepare('SELECT 1 FROM team_members WHERE team_id=? AND user_id=?');$q->execute([$a['team_id'],$uid]);
        if($q->fetchColumn())return $a;
    }
    $q=$pdo->prepare('SELECT 1 FROM project_annotations pa JOIN research_projects rp ON rp.id=pa.project_id LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE pa.annotation_id=? AND (rp.owner_user_id=? OR tm.user_id=?) LIMIT 1');
    $q->execute([$uid,$a['id'],$uid,$uid]);
    return $q->fetchColumn()?$a:null;
}
function source_access(PDO $pdo,string $publicId,?array $viewer): ?array {
    $q=$pdo->prepare('SELECT * FROM sources WHERE public_id=?');$q->execute([$publicId]);$s=$q->fetch();if(!$s)return null;
    $q=$pdo->prepare("SELECT 1 FROM annotations WHERE source_id=? AND visibility='public' AND status='published' LIMIT 1");$q->execute([$s['id']]);
    if($q->fetchColumn())return $s;
    if(!$viewer)return null;$uid=(int)$viewer['id'];if(($viewer['role']??'')==='admin')return $s;
    $q=$pdo->prepare("SELECT 1 FROM annotations a LEFT JOIN team_members tm ON tm.team_id=a.team_id AND tm.user_id=? WHERE a.source_id=? AND (a.user_id=? OR (a.visibility='team' AND a.status='published' AND tm.user_id IS NOT NULL)) LIMIT 1");
    $q->execute([$uid,$s['id'],$uid]);if($q->fetchColumn())return $s;
    $q=$pdo->prepare('SELECT 1 FROM project_sources ps JOIN research_projects rp ON rp.id=ps.project_id LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE ps.source_id=? AND (rp.owner_user_id=? OR tm.user_id=?) LIMIT 1');
    $q->execute([$uid,$s['id'],$uid,$uid]);if($q->fetchColumn())return $s;
    return null;
}
