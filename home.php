<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';require_once __DIR__.'/app/annotation-ui.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');

$feed=feed_annotation_rows($pdo,$u,'following',null,null,30)['annotations'];
$conversationReady=conversation_runtime_ready($pdo);$chatTeams=$conversationReady?conversation_team_list($pdo,$u):[];$preferredTeam=trim((string)($_GET['team']??''));

$q=$pdo->prepare("SELECT u.username,u.display_name,u.profile_image_url,u.bio,(SELECT COUNT(*) FROM annotations a WHERE a.user_id=u.id AND a.visibility='public' AND a.status='published') annotation_count FROM users u LEFT JOIN user_preferences p ON p.user_id=u.id WHERE u.id<>? AND u.status='active' AND COALESCE(p.profile_visibility,'public')='public' AND COALESCE(p.search_visibility,1)=1 AND NOT EXISTS(SELECT 1 FROM follows f WHERE f.follower_user_id=? AND f.followed_user_id=u.id) AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=? AND b.blocked_user_id=u.id) OR (b.blocker_user_id=u.id AND b.blocked_user_id=?)) ORDER BY annotation_count DESC,u.created_at DESC LIMIT 5");
$q->execute([$u['id'],$u['id'],$u['id'],$u['id']]);$people=$q->fetchAll();

$q=$pdo->prepare("SELECT t.public_id,t.name,tm.role,(SELECT COUNT(*) FROM team_members x WHERE x.team_id=t.id) member_count FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE tm.user_id=? ORDER BY t.name LIMIT 5");
$q->execute([$u['id']]);$teams=$q->fetchAll();

$q=$pdo->prepare('SELECT (SELECT COUNT(*) FROM follows WHERE followed_user_id=?) followers,(SELECT COUNT(*) FROM follows WHERE follower_user_id=?) following_count,(SELECT COUNT(*) FROM annotations WHERE user_id=? AND status="published") annotation_count');
$q->execute([$u['id'],$u['id'],$u['id']]);$stats=$q->fetch()?:['followers'=>0,'following_count'=>0,'annotation_count'=>0];
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Home · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body class="homeFeedPage">
<main class="layout"><section>
<?php if(!$feed):?><div class="card empty"><h2>Your feed is ready.</h2><p>Your published annotations and captures from people or sources you follow will appear here.</p><div class="inlineActions"><a class="button" href="/explore.php">Discover people & sources</a><a class="button secondary" href="/chrome-extension.php">Get the Chrome extension</a></div></div><?php endif?>
<?php foreach($feed as $a):?><?=annotation_ui_card($a,$u)?><?php endforeach?>
</section><aside class="homeRightRail <?=$chatTeams?'teamChatRightRail':''?>">
<?php if($chatTeams):?>
<section class="teamChatRail" id="teamChatRail" data-team-chat-rail data-csrf="<?=h(csrf_token())?>" data-preferred-team="<?=h($preferredTeam)?>">
  <header class="teamChatHeader"><div><span class="eyebrow">TEAM CHAT</span><h3>Messages</h3></div><button type="button" class="teamChatClose" data-team-chat-close aria-label="Close team chat">×</button></header>
  <div class="teamChatTeamPicker"><select id="teamChatConversation" aria-label="Choose team"><?php foreach($chatTeams as $chat):?><option value="<?=h($chat['public_id'])?>" data-team="<?=h($chat['team_public_id'])?>" data-members="<?=h((string)$chat['member_count'])?>" data-unread="<?=h((string)$chat['unread_count'])?>" <?=$preferredTeam!==''&&$preferredTeam===$chat['team_public_id']?'selected':''?>><?=h($chat['team_name'])?><?=$chat['unread_count']?' · '.$chat['unread_count'].' new':''?></option><?php endforeach?></select><a id="teamChatOpenTeam" href="/team.php?id=<?=h($chatTeams[0]['team_public_id'])?>">Team</a></div>
  <div class="teamChatStatus"><span id="teamChatMemberCount"></span><span id="teamChatUnread" hidden></span></div>
  <div class="teamChatMessages" id="teamChatMessages" role="log" aria-live="polite" aria-label="Team messages"><div class="teamChatLoading">Loading messages…</div></div>
  <div class="teamChatReply" id="teamChatReply" hidden><span></span><button type="button" aria-label="Cancel reply">×</button></div>
  <form class="teamChatComposer" id="teamChatComposer"><textarea id="teamChatInput" rows="1" maxlength="5000" placeholder="Message your team…" aria-label="Message your team"></textarea><button type="submit" aria-label="Send message">↑</button></form>
</section>
<?php else:?>
<div class="card"><div class="profileMini"><?=app_shell_avatar($u,'avatarImageLg')?><span><strong><?=h($u['display_name'])?></strong><small>@<?=h($u['username'])?></small></span></div><div class="profileStats"><span><strong><?=h((string)$stats['followers'])?></strong> followers</span><span><strong><?=h((string)$stats['following_count'])?></strong> following</span><span><strong><?=h((string)$stats['annotation_count'])?></strong> annotations</span></div><a href="<?=h(profile_path((string)$u['username']))?>">View your profile</a></div>
<div class="card"><div class="sectionHeadWeb"><div><span class="eyebrow">PEOPLE</span><h3>Discover researchers</h3></div></div><?php foreach($people as $p):?><a class="profileMini" style="margin:12px 0" href="<?=h(profile_path((string)$p['username']))?>"><?=app_shell_avatar($p,'avatarSm')?><span><strong><?=h($p['display_name'])?></strong><small>@<?=h($p['username'])?> · <?=h((string)$p['annotation_count'])?> annotations</small></span></a><?php endforeach?><?php if(!$people):?><p class="meta">No new profile suggestions right now.</p><?php endif?><a href="/explore.php">Explore Annotated</a></div>
<div class="card"><div class="sectionHeadWeb"><div><span class="eyebrow">TEAMS</span><h3>Your teams</h3></div></div><?php if(!$conversationReady&&$teams):?><p class="meta">Team Chat requires the Phase 12A database upgrade before it can load.</p><?php if(($u['role']??'')==='admin'):?><a class="button secondary" href="/upgrade.php">Run database upgrade</a><?php else:?><a href="/teams.php">Open Teams</a><?php endif?><?php else:?><p class="meta">Create a team for private collaboration, chat, and shared Research.</p><a href="/teams.php">Create or join a Team</a><?php endif?></div>
<div class="card"><span class="eyebrow">BROWSER SIDEBAR</span><h3>Annotate while you browse</h3><p class="meta">The Chrome extension connects this social website to the live page you are researching.</p><a class="button" href="/chrome-extension.php">Download Chrome Extension</a></div>
<?php endif?>
</aside></main>
<?php if($chatTeams):?><button class="teamChatMobileToggle" type="button" data-team-chat-open aria-controls="teamChatRail">Team Chat <span data-team-chat-total-unread></span></button><?php endif?>
<form class="homeAgentDock" id="homeAgentComposer" data-agent-chat-composer>
  <button type="button" class="homeAgentAdd" id="homeAgentAdd" aria-label="Add context">+</button>
  <textarea id="homeAgentPrompt" name="prompt" rows="1" placeholder="Ask Annotated…" aria-label="Ask Annotated"></textarea>
  <button type="submit" class="homeAgentSend" aria-label="Send to Agent">↑</button>
</form>
<script>
(()=>{const form=document.querySelector('#homeAgentComposer'),input=document.querySelector('#homeAgentPrompt'),add=document.querySelector('#homeAgentAdd');if(!form||!input)return;
const size=()=>{input.style.height='auto';input.style.height=Math.min(input.scrollHeight,132)+'px';};input.addEventListener('input',size);
input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();form.requestSubmit();}});
add?.addEventListener('click',()=>document.dispatchEvent(new CustomEvent('annotated:agent-chat-add-context',{bubbles:true})));
form.addEventListener('submit',e=>{e.preventDefault();const prompt=input.value.trim();if(!prompt)return;window.ANNOTATED_PENDING_AGENT_PROMPT=prompt;try{sessionStorage.setItem('annotated.pendingAgentPrompt',prompt);}catch{}document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:{prompt,source:'home_feed'},bubbles:true,cancelable:true}));});
})();
</script>
<?php if($chatTeams):?><script src="/assets/js/team-chat.js?v=12.0"></script><?php endif?>
<?=annotation_ui_scripts($u)?></body></html>