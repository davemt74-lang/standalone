<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');
$id=trim((string)($_GET['id']??$_POST['team']??''));$error='';$success='';
$q=$pdo->prepare("SELECT t.id,t.public_id,t.name,t.owner_user_id,t.created_at,tm.role access_role FROM teams t JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=? WHERE t.public_id=? LIMIT 1");
$q->execute([$u['id'],$id]);$team=$q->fetch();
if(!$team){http_response_code(404);exit('Team not found or access denied.');}
$canManage=in_array($team['access_role'],['owner','admin'],true);$isOwner=$team['access_role']==='owner';

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$op=(string)($_POST['op']??'');
    try{
        if($op==='invite'){
            if(!$canManage)throw new RuntimeException('Team admin permission is required.');
            $username=trim((string)($_POST['username']??''));
            $q=$pdo->prepare('SELECT id FROM users WHERE username=? AND status="active"');$q->execute([$username]);$uid=(int)($q->fetchColumn()?:0);
            if(!$uid)throw new RuntimeException('User not found.');
            $pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'researcher') ON DUPLICATE KEY UPDATE user_id=user_id")->execute([$team['id'],$uid]);
            $success='Member added.';
        }elseif($op==='role'){
            if(!$isOwner)throw new RuntimeException('Only the team owner can change member roles.');
            $member=(int)($_POST['member_id']??0);$role=(string)($_POST['role']??'researcher');
            if(!in_array($role,['admin','researcher','viewer'],true))throw new RuntimeException('Invalid team role.');
            if($member===(int)$team['owner_user_id'])throw new RuntimeException('The owner role cannot be changed here.');
            $pdo->prepare('UPDATE team_members SET role=? WHERE team_id=? AND user_id=?')->execute([$role,$team['id'],$member]);
            $success='Member role updated.';
        }elseif($op==='remove'){
            if(!$isOwner)throw new RuntimeException('Only the team owner can remove members.');
            $member=(int)($_POST['member_id']??0);if($member===(int)$team['owner_user_id'])throw new RuntimeException('The team owner cannot be removed.');
            $pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$team['id'],$member]);
            $success='Member removed.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$q=$pdo->prepare('SELECT u.id,u.username,u.display_name,u.profile_image_url,tm.role,tm.created_at FROM team_members tm JOIN users u ON u.id=tm.user_id WHERE tm.team_id=? ORDER BY FIELD(tm.role,"owner","admin","researcher","viewer"),u.display_name');
$q->execute([$team['id']]);$members=$q->fetchAll();
$q=$pdo->prepare('SELECT public_id,title,description,status,updated_at FROM research_projects WHERE team_id=? ORDER BY updated_at DESC LIMIT 20');$q->execute([$team['id']]);$projects=$q->fetchAll();
$q=$pdo->prepare("SELECT a.public_id,a.text_commentary,a.published_at,u.username,u.display_name,u.profile_image_url,s.public_id source_public_id,s.title source_title,s.domain FROM annotations a JOIN users u ON u.id=a.user_id JOIN sources s ON s.id=a.source_id WHERE a.team_id=? AND a.visibility='team' AND a.status='published' ORDER BY a.published_at DESC LIMIT 25");
$q->execute([$team['id']]);$annotations=$q->fetchAll();
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($team['name'])?> · Teams · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="layout"><section>
<div class="pageTitle"><span class="eyebrow">TEAM · <?=h(strtoupper((string)$team['access_role']))?></span><h1><?=h($team['name'])?></h1><p>Shared people, annotations, Research projects, and private Team collaboration.</p></div>
<?php if($error):?><div class="error"><?=h($error)?></div><?php endif?><?php if($success):?><div class="success"><?=h($success)?></div><?php endif?>
<div class="card"><div class="sectionHeadWeb"><div><span class="eyebrow">MEMBERS</span><h2><?=count($members)?> people</h2></div></div>
<?php foreach($members as $m):?><div class="sessionRow"><a class="profileMini" href="<?=h(profile_path((string)$m['username']))?>"><?=app_shell_avatar($m,'teamMiniAvatar')?><span><strong><?=h($m['display_name'])?></strong><small>@<?=h($m['username'])?> · <?=h(ucfirst($m['role']))?></small></span></a>
<?php if($isOwner&&(int)$m['id']!==(int)$team['owner_user_id']):?><div class="inlineActions"><form method="post" class="inlineForm"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="team" value="<?=h($team['public_id'])?>"><input type="hidden" name="op" value="role"><input type="hidden" name="member_id" value="<?=h((string)$m['id'])?>"><select name="role"><option value="admin" <?=$m['role']==='admin'?'selected':''?>>Admin</option><option value="researcher" <?=$m['role']==='researcher'?'selected':''?>>Researcher</option><option value="viewer" <?=$m['role']==='viewer'?'selected':''?>>Viewer</option></select><button class="button secondary">Save</button></form><form method="post" onsubmit="return confirm('Remove this member from the team?')"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="team" value="<?=h($team['public_id'])?>"><input type="hidden" name="op" value="remove"><input type="hidden" name="member_id" value="<?=h((string)$m['id'])?>"><button class="button secondary">Remove</button></form></div><?php endif?></div><?php endforeach?>
</div>

<div class="sectionHeadWeb"><div><span class="eyebrow">TEAM RESEARCH</span><h2>Projects</h2></div><a href="/research.php">All Research</a></div>
<div class="sourceGrid"><?php foreach($projects as $p):?><a class="card sourceCard" href="/research-project.php?id=<?=h($p['public_id'])?>"><span class="meta"><?=h(ucfirst((string)$p['status']))?> · updated <?=h((string)$p['updated_at'])?></span><h3><?=h($p['title'])?></h3><?php if($p['description']):?><p><?=h(mb_substr((string)$p['description'],0,220))?></p><?php endif?></a><?php endforeach?><?php if(!$projects):?><div class="card empty">No team Research projects yet.</div><?php endif?></div>

<div class="sectionHeadWeb"><div><span class="eyebrow">TEAM FEED</span><h2>Recent annotations</h2></div></div>
<?php foreach($annotations as $a):?><article class="card"><div class="annotationIdentity"><a class="profileMini" href="<?=h(profile_path((string)$a['username']))?>"><?=app_shell_avatar($a,'teamMiniAvatar')?><span><strong><?=h($a['display_name'])?></strong><small>@<?=h($a['username'])?> · <?=h($a['published_at'])?></small></span></a><a href="/source.php?id=<?=h($a['source_public_id'])?>"><?=h($a['source_title']?:$a['domain'])?></a></div><?php if($a['text_commentary']):?><p class="commentary"><?=nl2br(h($a['text_commentary']))?></p><?php endif?><a href="/annotation.php?id=<?=h($a['public_id'])?>">Open annotation</a></article><?php endforeach?><?php if(!$annotations):?><div class="card empty">No team-only annotations yet. Team captures from the Chrome sidebar will appear here.</div><?php endif?>
</section>
<aside>
<?php if($canManage):?><div class="card"><h3>Add a member</h3><p class="meta">Add an existing Annotated user by username.</p><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="team" value="<?=h($team['public_id'])?>"><input type="hidden" name="op" value="invite"><label>Username<input name="username" required placeholder="username"></label><button>Add member</button></form></div><?php endif?>
<div class="card"><h3>Team collaboration</h3><p class="meta">Team membership controls private team annotations, Team Live access, team chat, and team-scoped Research projects.</p><a class="button secondary" href="/home.php?team=<?=h($team['public_id'])?>#team-chat">Open Team Chat</a></div>
</aside></main></body></html>