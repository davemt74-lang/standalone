<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';require_once __DIR__.'/app/annotation-ui.php';require_once __DIR__.'/app/cognitive-feed-ui.php';require_once __DIR__.'/app/action-center.php';require_once __DIR__.'/app/schema-health.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');

$schemaStatus=app_schema_runtime_status($pdo,__DIR__.'/database/migrations');
if(empty($schemaStatus['ready'])){
    $migrationPending=array_values(array_filter((array)($schemaStatus['pending']??[]),fn($item)=>$item==='schema_migrations'||(!str_starts_with((string)$item,'table:')&&!str_starts_with((string)$item,'column:'))));
    if(($u['role']??'')==='admin'&&$migrationPending&&!($schemaStatus['changed']??[])&&empty($schemaStatus['error'])){
        header('Location: /upgrade.php?from=home');
        exit;
    }
    $incident=app_schema_runtime_incident((string)($schemaStatus['error']?:('Runtime schema is not current: '.implode(', ',(array)($schemaStatus['pending']??[])).' changed='.implode(',',(array)($schemaStatus['changed']??[])))),'home-schema');
    http_response_code(503);
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Annotated database update required</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="panel narrow"><h1>Database update required</h1><p>Annotated cannot safely load the Home workspace until the database schema matches this release.</p><?php if(($u['role']??'')==='admin'):?><p><a class="button" href="/upgrade.php">Open database upgrade</a></p><?php else:?><p>Please ask an Annotated administrator to complete the database upgrade.</p><?php endif?><p class="meta">Reference: <?=h($incident)?></p></main></body></html><?php
    exit;
}

$preferredTeam=trim((string)($_GET['team']??''));
$workspaceResearchCandidate=trim((string)($_GET['project']??''));
$workspaceAgentContext=trim((string)($_GET['agent']??''));
$requestedFeedMode=strtolower(trim((string)($_GET['view']??'')));

$conversationReady=false;$chatTeams=[];$preferredTeamContext='';$chatStatus=['status_mode'=>'auto','custom_status'=>'','effective_status'=>'offline'];
$workspaceResearchContext='';$cognitiveReady=false;$feedMode='latest';
$cognitiveBase=['ready'=>false,'items'=>[],'hidden_count'=>0];
$cognitiveFeed=['ready'=>false,'sections'=>[],'total'=>0,'hidden_count'=>0,'ranking'=>''];
$actionCenter=['ready'=>false,'groups'=>[],'items'=>[],'total'=>0,'high_count'=>0,'counts'=>[]];
$proactiveReady=false;$proactiveBriefing=['ready'=>false,'items'=>[],'count'=>0];
$proactiveAgentHandoff=null;$crossResearchAgentHandoff=null;$reviewAgentHandoff=null;$impactAgentHandoff=null;$portfolioAgentHandoff=null;$directAgentHandoff=null;
$homeRuntimeIncident='';

try{
    $conversationReady=conversation_runtime_ready($pdo);
    $chatTeams=$conversationReady?conversation_team_list($pdo,$u):[];
    $preferredTeamContext=$preferredTeam!==''&&in_array($preferredTeam,array_column($chatTeams,'team_public_id'),true)?$preferredTeam:'';
    $chatStatus=conversation_presence_ready($pdo)?conversation_status_get($pdo,(int)$u['id']):$chatStatus;
    $workspaceResearchContext=$workspaceResearchCandidate!==''&&project_access($pdo,(int)$u['id'],$workspaceResearchCandidate)?$workspaceResearchCandidate:'';

    $cognitiveReady=cognitive_feed_ready($pdo);
    $feedMode=in_array($requestedFeedMode,['cognitive','latest'],true)?$requestedFeedMode:cognitive_feed_mode_get($pdo,$u);
    if(!$cognitiveReady)$feedMode='latest';
    $cognitiveBase=cognitive_feed_items($pdo,$u,$chatTeams,false);
    $cognitiveFeed=$feedMode==='cognitive'?cognitive_feed_compose_from_items($cognitiveBase,4,28):['ready'=>$cognitiveReady,'sections'=>[],'total'=>0,'hidden_count'=>0,'ranking'=>''];
    $actionCenter=action_center_compose($pdo,$u,$chatTeams,60,$cognitiveBase);

    $proactiveReady=proactive_intelligence_ready($pdo);
    if($proactiveReady&&$feedMode==='cognitive')proactive_intelligence_sync($pdo,$u,$cognitiveFeed);
    $proactiveBriefing=$proactiveReady?proactive_briefing($pdo,$u,3):$proactiveBriefing;
    $proactiveAgentKey=trim((string)($_GET['proactive_agent']??''));
    $proactiveAgentHandoff=$proactiveReady&&$proactiveAgentKey!==''?proactive_agent_handoff($pdo,$u,$proactiveAgentKey):null;
    $crossResearchAgentKey=trim((string)($_GET['cross_research_agent']??''));
    $crossResearchAgentHandoff=cross_research_ready($pdo)&&$crossResearchAgentKey!==''?cross_research_agent_handoff($pdo,$u,$crossResearchAgentKey):null;
    $reviewAgentKey=trim((string)($_GET['review_agent']??''));
    $reviewAgentHandoff=research_reviews_ready($pdo)&&$reviewAgentKey!==''?research_review_agent_handoff($pdo,$u,$reviewAgentKey):null;
    $impactAgentId=(int)($_GET['impact_agent']??0);
    $impactAgentProject=trim((string)($_GET['project']??''));
    $impactAgentHandoff=change_impact_ready($pdo)&&$impactAgentId>0?change_impact_agent_handoff($pdo,$u,$impactAgentId,$impactAgentProject):null;
    $portfolioAgent=isset($_GET['portfolio_agent']);
    $portfolioAgentProject=trim((string)($_GET['project']??''));
    $portfolioAgentHandoff=$portfolioAgent&&research_portfolio_ready($pdo)?research_portfolio_agent_handoff($pdo,$u,$portfolioAgentProject!==''?$portfolioAgentProject:null):null;

    $directAgentType=strtolower(trim((string)($_GET['agent_context_type']??'')));
    $directAgentId=trim((string)($_GET['agent_context_id']??''));
    if($directAgentType!==''&&$directAgentId!==''&&function_exists('object_handoff_resolve')){
        $handoffObject=object_handoff_resolve($pdo,$u,$directAgentType,$directAgentId);
        if($handoffObject)$directAgentHandoff=['prompt'=>object_handoff_agent_prompt($directAgentType),'context'=>[['type'=>$directAgentType,'public_id'=>$directAgentId,'label'=>$handoffObject['label']??ucfirst($directAgentType)]],'source'=>'direct_object_handoff'];
    }
}catch(Throwable $e){
    $homeRuntimeIncident=app_schema_runtime_incident($e,'home-optional-runtime');
    $conversationReady=false;$chatTeams=[];$preferredTeamContext='';$chatStatus=['status_mode'=>'auto','custom_status'=>'','effective_status'=>'offline'];
    $cognitiveReady=false;$feedMode='latest';$cognitiveBase=['ready'=>false,'items'=>[],'hidden_count'=>0];
    $cognitiveFeed=['ready'=>false,'sections'=>[],'total'=>0,'hidden_count'=>0,'ranking'=>''];
    $actionCenter=['ready'=>false,'groups'=>[],'items'=>[],'total'=>0,'high_count'=>0,'counts'=>[]];
    $proactiveReady=false;$proactiveBriefing=['ready'=>false,'items'=>[],'count'=>0];
    $proactiveAgentHandoff=$crossResearchAgentHandoff=$reviewAgentHandoff=$impactAgentHandoff=$portfolioAgentHandoff=$directAgentHandoff=null;
}

$feed=[];
try{$feed=$feedMode==='latest'?feed_annotation_rows($pdo,$u,'following',null,null,30)['annotations']:[];}
catch(Throwable $e){if($homeRuntimeIncident==='')$homeRuntimeIncident=app_schema_runtime_incident($e,'home-feed');$feed=[];}

$people=[];
try{
    $q=$pdo->prepare("SELECT u.username,u.display_name,u.profile_image_url,u.bio,(SELECT COUNT(*) FROM annotations a WHERE a.user_id=u.id AND a.visibility='public' AND a.status='published') annotation_count FROM users u LEFT JOIN user_preferences p ON p.user_id=u.id WHERE u.id<>? AND u.status='active' AND COALESCE(p.profile_visibility,'public')='public' AND COALESCE(p.search_visibility,1)=1 AND NOT EXISTS(SELECT 1 FROM follows f WHERE f.follower_user_id=? AND f.followed_user_id=u.id) AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=? AND b.blocked_user_id=u.id) OR (b.blocker_user_id=u.id AND b.blocked_user_id=?)) ORDER BY annotation_count DESC,u.created_at DESC LIMIT 5");
    $q->execute([$u['id'],$u['id'],$u['id'],$u['id']]);$people=$q->fetchAll();
}catch(Throwable $e){if($homeRuntimeIncident==='')$homeRuntimeIncident=app_schema_runtime_incident($e,'home-people');}

$teams=[];
try{
    $q=$pdo->prepare("SELECT t.public_id,t.name,tm.role,(SELECT COUNT(*) FROM team_members x WHERE x.team_id=t.id) member_count FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE tm.user_id=? ORDER BY t.name LIMIT 5");
    $q->execute([$u['id']]);$teams=$q->fetchAll();
}catch(Throwable $e){if($homeRuntimeIncident==='')$homeRuntimeIncident=app_schema_runtime_incident($e,'home-teams');}

$stats=['followers'=>0,'following_count'=>0,'annotation_count'=>0];
try{
    $q=$pdo->prepare('SELECT (SELECT COUNT(*) FROM follows WHERE followed_user_id=?) followers,(SELECT COUNT(*) FROM follows WHERE follower_user_id=?) following_count,(SELECT COUNT(*) FROM annotations WHERE user_id=? AND status="published") annotation_count');
    $q->execute([$u['id'],$u['id'],$u['id']]);$stats=$q->fetch()?:$stats;
}catch(Throwable $e){if($homeRuntimeIncident==='')$homeRuntimeIncident=app_schema_runtime_incident($e,'home-stats');}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Home · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body class="homeFeedPage" data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="home" data-workspace-team="<?=h($preferredTeamContext)?>" data-workspace-research="<?=h($workspaceResearchContext)?>" data-workspace-agent="<?=h($workspaceAgentContext)?>">
<?php if($homeRuntimeIncident!==''&&($u['role']??'')==='admin'):?><div class="panel narrow" style="margin:16px auto"><div class="error"><strong>Some Home modules were temporarily disabled.</strong><p>The core Home feed is still available. Check the PHP error log using reference <code><?=h($homeRuntimeIncident)?></code>.</p></div></div><?php endif?>
<main class="layout"><section id="homeFeedCanvas" data-home-feed-canvas data-feed-mode="<?=h($feedMode)?>">
<div class="homeFeedModeBar" data-cognitive-feed data-csrf="<?=h(csrf_token())?>">
  <nav class="homeFeedModeTabs" aria-label="Home feed view">
    <a href="/home.php?view=cognitive" data-cognitive-mode="cognitive" class="<?=$feedMode==='cognitive'?'active':''?>" <?=$feedMode==='cognitive'?'aria-current="page"':''?>>Now</a>
    <a href="/home.php?view=latest" data-cognitive-mode="latest" class="<?=$feedMode==='latest'?'active':''?>" <?=$feedMode==='latest'?'aria-current="page"':''?>>Latest</a>
  </nav>
  <div class="homeFeedModeActions">
    <?php if($feedMode==='cognitive'&&($cognitiveFeed['hidden_count']??0)>0):?><button type="button" data-cognitive-restore-all>Show hidden (<?=h((string)$cognitiveFeed['hidden_count'])?>)</button><?php endif?>
    <a href="/action-center.php" class="<?=($actionCenter['high_count']??0)>0?'hasAttention':''?>">Actions<?php if(($actionCenter['total']??0)>0):?> (<?=h((string)$actionCenter['total'])?>)<?php endif?></a>
    <a href="/activity.php">Activity</a>
    <button type="button" data-cognitive-refresh>Refresh</button>
  </div>
</div>
<?php if(!$cognitiveReady):?><div class="card empty cognitiveUpgradeCard"><h2>Cognitive Feed needs the Phase 16 database upgrade.</h2><p>The chronological Latest feed remains available until migration 023 is installed.</p><?php if(($u['role']??'')==='admin'):?><a class="button secondary" href="/upgrade.php">Run database upgrade</a><?php endif?></div><?php endif?>
<?php if($feedMode==='cognitive'):?>
  <details class="cognitiveRankingNote"><summary>How Now is ranked</summary><p><?=h((string)($cognitiveFeed['ranking']??''))?></p></details>
  <?php if(!($cognitiveFeed['sections']??[])):?><div class="card empty cognitiveCaughtUp"><h2>You’re caught up.</h2><p>No unresolved Research, source, Team, or Agent items need priority right now.</p><a class="button secondary" href="/home.php?view=latest">See latest annotations</a></div><?php else:?><?=cognitive_feed_ui_sections($cognitiveFeed)?><?php endif?>
<?php else:?>
  <?php if(!$feed):?><div class="card empty"><h2>Your feed is ready.</h2><p>Your published annotations and captures from people or sources you follow will appear here.</p><div class="inlineActions"><a class="button" href="/explore.php">Discover people & sources</a><a class="button secondary" href="/chrome-extension.php">Get the Chrome extension</a></div></div><?php endif?>
  <?php foreach($feed as $a):?><?=annotation_ui_card($a,$u)?><?php endforeach?>
<?php endif?>
</section>
<section class="agentChatCanvas" id="homeAgentCanvas" data-agent-chat-canvas data-csrf="<?=h(csrf_token())?>" hidden>
  <header class="agentChatCanvasHeader">
    <div class="agentChatCanvasHeaderLeft"><button type="button" class="button secondary" data-agent-back>← Back to Feed</button><div><span class="eyebrow">AGENT CHAT</span><h2 data-agent-title>New chat</h2></div></div>
    <div class="agentChatCanvasActions"><button type="button" class="button secondary" data-agent-history-toggle>History</button><button type="button" class="button" data-agent-new>New chat</button></div>
  </header>
  <div class="agentChatHistoryPanel" data-agent-history hidden><div class="agentChatHistoryHead"><strong>Recent chats</strong><button type="button" data-agent-history-close aria-label="Close chat history">×</button></div><div data-agent-history-list></div></div>
  <div class="agentChatMessages" data-agent-messages role="log" aria-live="polite"><div class="agentChatWelcome"><span class="eyebrow">ANNOTATED AGENT</span><h2>What are you researching?</h2><p>Ask about your annotations, sources, Research projects, or Team context. Attached context is permission-checked before the Agent can use it.</p><?php if(($proactiveBriefing['count']??0)>0):?><section class="agentProactiveBriefing"><div class="eyebrow">RESEARCH BRIEFING</div><h3><?=h((string)$proactiveBriefing['count'])?> things worth reviewing</h3><?php foreach((array)$proactiveBriefing['items'] as $brief):?><article><strong><?=h((string)($brief['title']??'Research update'))?></strong><p><?=h((string)($brief['why']??''))?></p><?php if(!empty($brief['primary_url'])):?><a href="<?=h((string)$brief['primary_url'])?>">Open context</a><?php endif?></article><?php endforeach?></section><?php endif?></div></div>
  <div class="agentChatContextTray" data-agent-context-tray hidden></div>
  <div class="agentChatContextPicker" data-agent-context-picker hidden><div class="agentChatContextPickerHead"><strong>Add Annotated context</strong><button type="button" data-agent-context-close aria-label="Close context picker">×</button></div><div class="agentChatContextPickerBody" data-agent-context-options><div class="meta">Loading context…</div></div></div>
</section><aside class="homeRightRail <?=$chatTeams?'teamChatRightRail':''?>">
<?php if($chatTeams):?>
<section class="teamChatRail" id="team-chat" data-team-chat-rail data-csrf="<?=h(csrf_token())?>" data-preferred-team="<?=h($preferredTeam)?>" data-agent-enabled="<?=(user_is_pro($pdo,$u)||($u['role']??'')==='admin')?'1':'0'?>">
  <header class="teamChatHeader"><div><span class="eyebrow">TEAM CHAT</span><h3>Messages</h3></div><div class="teamChatHeaderActions"><button type="button" class="teamChatPopoutCurrent" data-team-chat-popout aria-label="Pop out current team chat" title="Pop out chat">↗</button><button type="button" class="teamChatClose" data-team-chat-close aria-label="Close team chat">×</button></div></header>
  <div class="teamChatTeamPicker"><select id="teamChatConversation" aria-label="Choose team"><?php foreach($chatTeams as $chat):?><option value="<?=h($chat['public_id'])?>" data-team="<?=h($chat['team_public_id'])?>" data-members="<?=h((string)$chat['member_count'])?>" data-unread="<?=h((string)$chat['unread_count'])?>" <?=$preferredTeam!==''&&$preferredTeam===$chat['team_public_id']?'selected':''?>><?=h($chat['team_name'])?><?=$chat['unread_count']?' · '.$chat['unread_count'].' new':''?></option><?php endforeach?></select><a id="teamChatOpenTeam" href="/team.php?id=<?=h($chatTeams[0]['team_public_id'])?>">Team</a></div>
  <div class="teamChatStatus"><span id="teamChatMemberCount"></span><span id="teamChatUnread" hidden></span></div>
  <div class="teamChatHistoryBar"><button type="button" id="teamChatLoadEarlier" hidden>Load earlier messages</button></div>
  <div class="teamChatMessages" id="teamChatMessages" role="log" aria-live="polite" aria-label="Team messages"><div class="teamChatLoading">Loading messages…</div></div>
  <div class="teamChatReply" id="teamChatReply" hidden><span></span><button type="button" aria-label="Cancel reply">×</button></div>
  <form class="teamChatComposer" id="teamChatComposer"><textarea id="teamChatInput" rows="1" maxlength="5000" placeholder="Message your team…" aria-label="Message your team"></textarea><button type="submit" aria-label="Send message">↑</button></form>
  <footer class="teamChatFooter"><span class="teamChatSelfStatus" data-team-chat-self-status><i class="chatPresenceDot status-<?=h((string)$chatStatus['effective_status'])?>"></i><span><?=h((string)($chatStatus['custom_status']?:ucfirst((string)$chatStatus['status_mode'])))?></span></span><a class="teamChatSettingsButton" href="/chat-settings.php" aria-label="Chat status settings" title="Chat status settings">⚙</a></footer>
</section>
<?php else:?>
<div class="card"><div class="profileMini"><?=app_shell_avatar($u,'avatarImageLg')?><span><strong><?=h($u['display_name'])?></strong><small>@<?=h($u['username'])?></small></span></div><div class="profileStats"><span><strong><?=h((string)$stats['followers'])?></strong> followers</span><span><strong><?=h((string)$stats['following_count'])?></strong> following</span><span><strong><?=h((string)$stats['annotation_count'])?></strong> annotations</span></div><a href="<?=h(profile_path((string)$u['username']))?>">View your profile</a></div>
<div class="card"><div class="sectionHeadWeb"><div><span class="eyebrow">PEOPLE</span><h3>Discover researchers</h3></div></div><?php foreach($people as $p):?><a class="profileMini" style="margin:12px 0" href="<?=h(profile_path((string)$p['username']))?>"><?=app_shell_avatar($p,'avatarSm')?><span><strong><?=h($p['display_name'])?></strong><small>@<?=h($p['username'])?> · <?=h((string)$p['annotation_count'])?> annotations</small></span></a><?php endforeach?><?php if(!$people):?><p class="meta">No new profile suggestions right now.</p><?php endif?><a href="/explore.php">Explore Annotated</a></div>
<div class="card"><div class="sectionHeadWeb"><div><span class="eyebrow">TEAMS</span><h3>Your teams</h3></div></div><?php if(!$conversationReady&&$teams):?><p class="meta">Team Chat requires the Phase 12A database upgrade before it can load.</p><?php if(($u['role']??'')==='admin'):?><a class="button secondary" href="/upgrade.php">Run database upgrade</a><?php else:?><a href="/teams.php">Open Teams</a><?php endif?><?php else:?><p class="meta">Create a team for private collaboration, chat, and shared Research.</p><a href="/teams.php">Create or join a Team</a><?php endif?></div>
<div class="card"><span class="eyebrow">BROWSER SIDEBAR</span><h3>Annotate while you browse</h3><p class="meta">The Chrome extension connects this social website to the live page you are researching.</p><a class="button" href="/chrome-extension.php">Download Chrome Extension</a></div>
<?php endif?>
</aside></main>
<?php if($chatTeams):?><button class="teamChatMobileToggle" type="button" data-team-chat-open aria-controls="team-chat">Team Chat <span data-team-chat-total-unread></span></button><div class="teamChatPopupLayer" data-team-chat-popups aria-live="polite"></div><?php endif?>
<form class="homeAgentDock" id="homeAgentComposer" data-agent-chat-composer>
  <button type="button" class="homeAgentAdd" id="homeAgentAdd" aria-label="Add context">+</button>
  <textarea id="homeAgentPrompt" name="prompt" rows="1" placeholder="Ask Annotated…" aria-label="Ask Annotated"></textarea>
  <button type="submit" class="homeAgentSend" aria-label="Send to Agent">↑</button>
</form>
<script src="/assets/js/workspace-state.js?v=36.0"></script>
<script src="/assets/js/agent-chat.js?v=37.0"></script>
<script src="/assets/js/cognitive-feed.js?v=17.0"></script>
<?php if($chatTeams):?><script src="/assets/js/team-chat.js?v=36.0"></script><?php endif?>
<?php if($proactiveAgentHandoff):?><script>document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:<?=json_encode(['prompt'=>$proactiveAgentHandoff['prompt'],'context'=>$proactiveAgentHandoff['context'],'source'=>'proactive_notification'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>,bubbles:true,cancelable:true}));</script><?php endif?>
<?php if($crossResearchAgentHandoff):?><script>document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:<?=json_encode(['prompt'=>$crossResearchAgentHandoff['prompt'],'context'=>$crossResearchAgentHandoff['context'],'source'=>'cross_research'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>,bubbles:true,cancelable:true}));</script><?php endif?>
<?php if($reviewAgentHandoff):?><script>document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:<?=json_encode(['prompt'=>$reviewAgentHandoff['prompt'],'context'=>$reviewAgentHandoff['context'],'source'=>'research_review'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>,bubbles:true,cancelable:true}));</script><?php endif?>
<?php if($impactAgentHandoff):?><script>document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:<?=json_encode(['prompt'=>$impactAgentHandoff['prompt'],'context'=>$impactAgentHandoff['context'],'source'=>'change_impact'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>,bubbles:true,cancelable:true}));</script><?php endif?>
<?php if($portfolioAgentHandoff):?><script>document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:<?=json_encode(['prompt'=>$portfolioAgentHandoff['prompt'],'context'=>$portfolioAgentHandoff['context'],'source'=>'research_portfolio'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>,bubbles:true,cancelable:true}));</script><?php endif?>
<?php if($directAgentHandoff):?><script>document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:<?=json_encode($directAgentHandoff,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>,bubbles:true,cancelable:true}));</script><?php endif?>
<?=annotation_ui_scripts($u)?></body></html>