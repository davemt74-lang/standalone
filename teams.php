<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$name=trim((string)($_POST['name']??''));
    if($name==='')$error='Team name is required.';
    else{
        $pdo->beginTransaction();
        try{
            $public=ulid_like();$q=$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)');$q->execute([$public,$u['id'],$name]);$teamId=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner')")->execute([$teamId,$u['id']]);
            $pdo->commit();header('Location:/team.php?id='.rawurlencode($public));exit;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error='Unable to create team.';}
    }
}
$q=$pdo->prepare('SELECT t.id,t.public_id,t.name,t.created_at,tm.role,(SELECT COUNT(*) FROM team_members x WHERE x.team_id=t.id) member_count,(SELECT COUNT(*) FROM research_projects rp WHERE rp.team_id=t.id) project_count FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE tm.user_id=? ORDER BY t.name');
$q->execute([$u['id']]);$teams=$q->fetchAll();
$memberPreviews=[];
foreach($teams as $t){
    $q=$pdo->prepare('SELECT u.username,u.display_name,u.profile_image_url FROM team_members tm JOIN users u ON u.id=tm.user_id WHERE tm.team_id=? ORDER BY FIELD(tm.role,"owner","admin","researcher","viewer"),u.display_name LIMIT 6');
    $q->execute([$t['id']]);$memberPreviews[(int)$t['id']]=$q->fetchAll();
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Teams · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="layout"><section>
<div class="pageTitle"><span class="eyebrow">TEAMS</span><h1>Research together.</h1><p>Private team annotations, shared research, member profiles, and Team Live rooms all use the same Annotated permission model.</p></div>
<?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<?php if(!$teams):?><div class="card empty"><h2>No teams yet</h2><p>Create a team to share annotations, research projects, and private Live rooms with collaborators.</p></div><?php endif?>
<div class="teamOverviewGrid"><?php foreach($teams as $t):?><a class="card sourceCard" href="/team.php?id=<?=h($t['public_id'])?>">
<div class="meta"><?=h(ucfirst((string)$t['role']))?> · <?=h((string)$t['member_count'])?> members · <?=h((string)$t['project_count'])?> projects</div>
<h2><?=h($t['name'])?></h2>
<div class="teamMemberStrip"><?php foreach($memberPreviews[(int)$t['id']] as $m):?><?=app_shell_avatar($m,'teamMiniAvatar')?><?php endforeach?><?php if((int)$t['member_count']>6):?><span class="teamMiniAvatar">+<?=h((string)((int)$t['member_count']-6))?></span><?php endif?></div>
<p>Open the team workspace for members, research, team annotations, and collaboration.</p>
</a><?php endforeach?></div>
</section><aside>
<div class="card"><span class="eyebrow">NEW TEAM</span><h3>Create a team</h3><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><label>Team name<input name="name" required placeholder="Research team"></label><button>Create team</button></form></div>
<div class="card"><h3>Why Teams?</h3><p class="meta">Teams are the private social layer for shared annotations, research projects, and Team Live rooms. Public profiles and follows remain separate.</p></div>
</aside></main></body></html>