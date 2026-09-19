<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';require_once __DIR__.'/app/annotation-ui.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');

$feed=feed_annotation_rows($pdo,$u,'following',null,null,30)['annotations'];

$q=$pdo->prepare("SELECT u.username,u.display_name,u.profile_image_url,u.bio,(SELECT COUNT(*) FROM annotations a WHERE a.user_id=u.id AND a.visibility='public' AND a.status='published') annotation_count FROM users u LEFT JOIN user_preferences p ON p.user_id=u.id WHERE u.id<>? AND u.status='active' AND COALESCE(p.profile_visibility,'public')='public' AND COALESCE(p.search_visibility,1)=1 AND NOT EXISTS(SELECT 1 FROM follows f WHERE f.follower_user_id=? AND f.followed_user_id=u.id) AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=? AND b.blocked_user_id=u.id) OR (b.blocker_user_id=u.id AND b.blocked_user_id=?)) ORDER BY annotation_count DESC,u.created_at DESC LIMIT 5");
$q->execute([$u['id'],$u['id'],$u['id'],$u['id']]);$people=$q->fetchAll();

$q=$pdo->prepare("SELECT t.public_id,t.name,tm.role,(SELECT COUNT(*) FROM team_members x WHERE x.team_id=t.id) member_count FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE tm.user_id=? ORDER BY t.name LIMIT 5");
$q->execute([$u['id']]);$teams=$q->fetchAll();

$q=$pdo->prepare('SELECT (SELECT COUNT(*) FROM follows WHERE followed_user_id=?) followers,(SELECT COUNT(*) FROM follows WHERE follower_user_id=?) following_count,(SELECT COUNT(*) FROM annotations WHERE user_id=? AND status="published") annotation_count');
$q->execute([$u['id'],$u['id'],$u['id']]);$stats=$q->fetch()?:['followers'=>0,'following_count'=>0,'annotation_count'=>0];
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Home · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="layout"><section>
<div class="pageTitle"><span class="eyebrow">YOUR FEED</span><h1>Your Annotated feed</h1><p>Your annotations plus activity from the people and sources you follow.</p></div>
<?php if(!$feed):?><div class="card empty"><h2>Your feed is ready.</h2><p>Your published annotations and captures from people or sources you follow will appear here.</p><div class="inlineActions"><a class="button" href="/explore.php">Discover people & sources</a><a class="button secondary" href="/chrome-extension.php">Get the Chrome extension</a></div></div><?php endif?>
<?php foreach($feed as $a):?><?=annotation_ui_card($a,$u)?><?php endforeach?>
</section><aside>
<div class="card"><div class="profileMini"><?=app_shell_avatar($u,'avatarImageLg')?><span><strong><?=h($u['display_name'])?></strong><small>@<?=h($u['username'])?></small></span></div><div class="profileStats"><span><strong><?=h((string)$stats['followers'])?></strong> followers</span><span><strong><?=h((string)$stats['following_count'])?></strong> following</span><span><strong><?=h((string)$stats['annotation_count'])?></strong> annotations</span></div><a href="/profile.php?u=<?=h($u['username'])?>">View your profile</a></div>
<div class="card"><div class="sectionHeadWeb"><div><span class="eyebrow">PEOPLE</span><h3>Discover researchers</h3></div></div><?php foreach($people as $p):?><a class="profileMini" style="margin:12px 0" href="/profile.php?u=<?=h($p['username'])?>"><?=app_shell_avatar($p,'avatarSm')?><span><strong><?=h($p['display_name'])?></strong><small>@<?=h($p['username'])?> · <?=h((string)$p['annotation_count'])?> annotations</small></span></a><?php endforeach?><?php if(!$people):?><p class="meta">No new profile suggestions right now.</p><?php endif?><a href="/explore.php">Explore Annotated</a></div>
<div class="card"><div class="sectionHeadWeb"><div><span class="eyebrow">TEAMS</span><h3>Your teams</h3></div></div><?php foreach($teams as $t):?><a class="savedSearchRow" href="/team.php?id=<?=h($t['public_id'])?>"><strong><?=h($t['name'])?></strong><small><?=h(ucfirst($t['role']))?> · <?=h((string)$t['member_count'])?> members</small></a><?php endforeach?><?php if(!$teams):?><p class="meta">Create a team for private collaboration and shared Research.</p><?php endif?><a href="/teams.php">Open Teams</a></div>
<div class="card"><span class="eyebrow">BROWSER SIDEBAR</span><h3>Annotate while you browse</h3><p class="meta">The Chrome extension connects this social website to the live page you are researching.</p><a class="button" href="/chrome-extension.php">Download Chrome Extension</a></div>
</aside></main><?=annotation_ui_scripts($u)?></body></html>