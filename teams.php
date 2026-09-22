<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$name=trim((string)($_POST['name']??''));
    if($name==='')$error='Team name is required.';
    else{
        $pdo->beginTransaction();
        try{$public=ulid_like();$q=$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)');$q->execute([$public,$u['id'],$name]);$teamId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner')")->execute([$teamId,$u['id']]);$pdo->commit();header('Location:/team.php?id='.rawurlencode($public));exit;}
        catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error='Unable to create team.';}
    }
}
$q=$pdo->prepare("SELECT t.id,t.public_id,t.name,t.created_at,tm.role,
 (SELECT COUNT(*) FROM team_members x WHERE x.team_id=t.id) member_count,
 (SELECT COUNT(*) FROM research_projects rp WHERE rp.team_id=t.id AND rp.status<>'archived') project_count,
 COALESCE((SELECT MAX(rp2.updated_at) FROM research_projects rp2 WHERE rp2.team_id=t.id),t.created_at) recent_at
 FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE tm.user_id=? ORDER BY recent_at DESC,t.name");
$q->execute([$u['id']]);$teams=$q->fetchAll();
$memberPreviews=[];foreach($teams as $t){$q=$pdo->prepare('SELECT u.username,u.display_name,u.profile_image_url FROM team_members tm JOIN users u ON u.id=tm.user_id WHERE tm.team_id=? ORDER BY FIELD(tm.role,"owner","admin","researcher","viewer"),u.display_name LIMIT 6');$q->execute([$t['id']]);$memberPreviews[(int)$t['id']]=$q->fetchAll();}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Teams · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="teams">
<main class="teamsLibraryCanvas">
  <section class="libraryCanvasHero"><div><span class="eyebrow">TEAMS</span><h1>Research together.</h1><p>Private shared workspaces for annotations, Research projects, members, and Team Live collaboration.</p></div><details class="libraryAddMenu"<?= $error!==''?' open':'' ?>><summary class="button">ADD TEAM</summary><div class="libraryCreatePopover"><span class="eyebrow">NEW TEAM</span><h2>Create a team</h2><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><label>Team name<input name="name" required placeholder="Research team" value="<?=h((string)($_POST['name']??''))?>"></label><button>Create Team</button></form></div></details></section>
  <section class="libraryCanvasToolbar"><nav><a class="active" href="/teams.php">Your Teams <span><?=h((string)count($teams))?></span></a></nav><span class="libraryToolbarMeta">Private collaboration workspaces</span></section>
  <?php if(!$teams):?><div class="libraryCanvasEmpty"><div class="teamWorkspaceGlyph" aria-hidden="true"></div><h2>No teams yet</h2><p>Create a Team to share annotations, Research projects, and private Live rooms with collaborators.</p></div><?php else:?><section class="teamWorkspaceGrid" aria-label="Teams"><?php foreach($teams as $t):?><a class="teamWorkspaceCard" href="/team.php?id=<?=h($t['public_id'])?>"><header><div class="teamWorkspaceGlyph" aria-hidden="true"></div><div class="teamWorkspaceIdentity"><span><?=h(ucfirst((string)$t['role']))?></span><h2><?=h($t['name'])?></h2></div></header><div class="teamWorkspaceStats"><span><strong><?=h((string)$t['member_count'])?></strong><small>Members</small></span><span><strong><?=h((string)$t['project_count'])?></strong><small>Research projects</small></span></div><div class="teamMemberStrip"><?php foreach($memberPreviews[(int)$t['id']] as $m):?><?=app_shell_avatar($m,'teamMiniAvatar')?><?php endforeach?><?php if((int)$t['member_count']>6):?><span class="teamMiniAvatar">+<?=h((string)((int)$t['member_count']-6))?></span><?php endif?></div><footer><span>Open team workspace</span><span aria-hidden="true">→</span></footer></a><?php endforeach?></section><?php endif?>
</main>
</body></html>