<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';require_once __DIR__.'/app/annotation-ui.php';require_once __DIR__.'/app/cognitive-feed-ui.php';require_once __DIR__.'/app/research-agent-workspace-ui.php';require_once __DIR__.'/app/action-center.php';require_once __DIR__.'/app/schema-health.php';
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
$requestedResearchAgent=null;
$requestedResearchDocument=null;
$requestedFeedMode=strtolower(trim((string)($_GET['view']??'')));

$conversationReady=false;$chatTeams=[];$preferredTeamContext='';$chatStatus=['status_mode'=>'auto','custom_status'=>'','effective_status'=>'offline'];
$workspaceResearchContext='';$cognitiveReady=false;$cognitiveRuntimeReady=false;$feedMode='latest';
$cognitiveBase=['ready'=>false,'items'=>[],'hidden_count'=>0];
$cognitiveFeed=['ready'=>false,'sections'=>[],'total'=>0,'hidden_count'=>0,'ranking'=>''];
$actionCenter=['ready'=>false,'groups'=>[],'items'=>[],'total'=>0,'high_count'=>0,'counts'=>[]];
$proactiveReady=false;$proactiveBriefing=['ready'=>false,'items'=>[],'count'=>0];
$proactiveAgentHandoff=null;$crossResearchAgentHandoff=null;$reviewAgentHandoff=null;$impactAgentHandoff=null;$portfolioAgentHandoff=null;$directAgentHandoff=null;
$homeRuntimeIncidents=[];

$recordHomeIncident=function(string $component,Throwable $e) use (&$homeRuntimeIncidents): void {
    $homeRuntimeIncidents[$component]=app_schema_runtime_incident($e,'home-'.$component);
};

try{
    $conversationReady=conversation_runtime_ready($pdo);
    if($conversationReady)$chatTeams=conversation_team_list($pdo,$u);
    $preferredTeamContext=$preferredTeam!==''&&in_array($preferredTeam,array_column($chatTeams,'team_public_id'),true)?$preferredTeam:'';
    if(conversation_presence_ready($pdo))$chatStatus=conversation_status_get($pdo,(int)$u['id']);
}catch(Throwable $e){
    $recordHomeIncident('chat',$e);
    $chatTeams=[];$preferredTeamContext='';
}

try{
    $workspaceResearchContext=$workspaceResearchCandidate!==''&&project_access($pdo,(int)$u['id'],$workspaceResearchCandidate)?$workspaceResearchCandidate:'';
}catch(Throwable $e){
    $recordHomeIncident('research-context',$e);
    $workspaceResearchContext='';
}

try{
    if($workspaceAgentContext!==''&&function_exists('research_agent_by_conversation')){
        $requestedResearchAgent=research_agent_by_conversation($pdo,$u,$workspaceAgentContext);
        if($requestedResearchAgent&&!empty($requestedResearchAgent['project_public_id']))$workspaceResearchContext=(string)$requestedResearchAgent['project_public_id'];
    }
}catch(Throwable $e){
    $recordHomeIncident('research-agent-context',$e);
    $requestedResearchAgent=null;
}

try{
    $requestedDocumentPublic=trim((string)($_GET['doc']??''));
    if($requestedResearchAgent&&$requestedDocumentPublic!==''&&function_exists('research_agent_workspace_object')){
        $candidateDocument=research_agent_workspace_object($pdo,$u,$requestedDocumentPublic,false);
        if($candidateDocument&&($candidateDocument['object_type']??'')==='document'
          &&hash_equals((string)$requestedResearchAgent['project_public_id'],(string)$candidateDocument['project_public_id'])){
            $requestedResearchDocument=$candidateDocument;
        }
    }
}catch(Throwable $e){
    $recordHomeIncident('research-agent-document',$e);
    $requestedResearchDocument=null;
}

try{
    $cognitiveReady=cognitive_feed_ready($pdo);
    if($cognitiveReady){
        $feedMode=in_array($requestedFeedMode,['cognitive','latest'],true)?$requestedFeedMode:cognitive_feed_mode_get($pdo,$u);
        $cognitiveBase=cognitive_feed_items($pdo,$u,$chatTeams,false);
        $cognitiveFeed=$feedMode==='cognitive'?cognitive_feed_compose_from_items($cognitiveBase,4,28):['ready'=>true,'sections'=>[],'total'=>0,'hidden_count'=>0,'ranking'=>''];
        $actionCenter=action_center_compose($pdo,$u,$chatTeams,60,$cognitiveBase);
        $cognitiveRuntimeReady=true;
    }else{
        $feedMode='latest';
    }
}catch(Throwable $e){
    $recordHomeIncident('cognitive',$e);
    $feedMode='latest';
    $cognitiveBase=['ready'=>$cognitiveReady,'items'=>[],'hidden_count'=>0];
    $cognitiveFeed=['ready'=>$cognitiveReady,'sections'=>[],'total'=>0,'hidden_count'=>0,'ranking'=>''];
    $actionCenter=['ready'=>false,'groups'=>[],'items'=>[],'total'=>0,'high_count'=>0,'counts'=>[]];
}

try{
    $proactiveReady=proactive_intelligence_ready($pdo);
    if($proactiveReady&&$cognitiveRuntimeReady&&$feedMode==='cognitive')proactive_intelligence_sync($pdo,$u,$cognitiveFeed);
    $proactiveBriefing=$proactiveReady?proactive_briefing($pdo,$u,3):$proactiveBriefing;
    $proactiveAgentKey=trim((string)($_GET['proactive_agent']??''));
    $proactiveAgentHandoff=$proactiveReady&&$proactiveAgentKey!==''?proactive_agent_handoff($pdo,$u,$proactiveAgentKey):null;
}catch(Throwable $e){
    $recordHomeIncident('proactive',$e);
    $proactiveReady=false;$proactiveBriefing=['ready'=>false,'items'=>[],'count'=>0];$proactiveAgentHandoff=null;
}

try{
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
    $recordHomeIncident('agent-handoff',$e);
    $crossResearchAgentHandoff=$reviewAgentHandoff=$impactAgentHandoff=$portfolioAgentHandoff=$directAgentHandoff=null;
}

$feed=[];$bookmarkFeed=[];$latestFeed=[];
try{
    if($feedMode==='latest'){
        $feed=feed_annotation_rows($pdo,$u,'following',null,null,30)['annotations'];
        if(function_exists('research_agent_workspace_bookmark_feed'))$bookmarkFeed=research_agent_workspace_bookmark_feed($pdo,$u,30);
        foreach($feed as $row)$latestFeed[]=['kind'=>'annotation','created_at'=>(string)($row['published_at']??$row['created_at']??''),'row'=>$row];
        foreach($bookmarkFeed as $row)$latestFeed[]=['kind'=>'bookmark','created_at'=>(string)($row['created_at']??''),'row'=>$row];
        usort($latestFeed,fn($a,$b)=>(strtotime((string)$b['created_at'])?:0)<=>(strtotime((string)$a['created_at'])?:0));
        $latestFeed=array_slice($latestFeed,0,30);
    }
}catch(Throwable $e){$recordHomeIncident('feed',$e);$feed=[];$bookmarkFeed=[];$latestFeed=[];}

$people=[];
try{
    $q=$pdo->prepare("SELECT u.username,u.display_name,u.profile_image_url,u.bio,(SELECT COUNT(*) FROM annotations a WHERE a.user_id=u.id AND a.visibility='public' AND a.status='published') annotation_count FROM users u LEFT JOIN user_preferences p ON p.user_id=u.id WHERE u.id<>? AND u.status='active' AND COALESCE(p.profile_visibility,'public')='public' AND COALESCE(p.search_visibility,1)=1 AND NOT EXISTS(SELECT 1 FROM follows f WHERE f.follower_user_id=? AND f.followed_user_id=u.id) AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=? AND b.blocked_user_id=u.id) OR (b.blocker_user_id=u.id AND b.blocked_user_id=?)) ORDER BY annotation_count DESC,u.created_at DESC LIMIT 5");
    $q->execute([$u['id'],$u['id'],$u['id'],$u['id']]);$people=$q->fetchAll();
}catch(Throwable $e){$recordHomeIncident('people',$e);}

$teams=[];
try{
    $q=$pdo->prepare("SELECT t.public_id,t.name,tm.role,(SELECT COUNT(*) FROM team_members x WHERE x.team_id=t.id) member_count FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE tm.user_id=? ORDER BY t.name LIMIT 5");
    $q->execute([$u['id']]);$teams=$q->fetchAll();
}catch(Throwable $e){$recordHomeIncident('teams',$e);}

$stats=['followers'=>0,'following_count'=>0,'annotation_count'=>0];
try{
    $q=$pdo->prepare('SELECT (SELECT COUNT(*) FROM follows WHERE followed_user_id=?) followers,(SELECT COUNT(*) FROM follows WHERE follower_user_id=?) following_count,(SELECT COUNT(*) FROM annotations WHERE user_id=? AND status="published") annotation_count');
    $q->execute([$u['id'],$u['id'],$u['id']]);$stats=$q->fetch()?:$stats;
}catch(Throwable $e){$recordHomeIncident('stats',$e);}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Home · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body class="homeFeedPage" data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="home" data-workspace-team="<?=h($preferredTeamContext)?>" data-workspace-research="<?=h($workspaceResearchContext)?>" data-workspace-agent="<?=h($workspaceAgentContext)?>" data-research-agent-conversation="<?=h((string)($requestedResearchAgent['conversation_public_id']??''))?>" data-research-agent-project="<?=h((string)($requestedResearchAgent['project_public_id']??''))?>" data-research-agent-id="<?=h((string)($requestedResearchAgent['public_id']??''))?>" data-research-agent-name="<?=h((string)($requestedResearchAgent['name']??''))?>" data-research-agent-team="<?=h((string)($requestedResearchAgent['team_public_id']??''))?>">
<main class="layout homeWorkspaceLayout"><section id="homeFeedCanvas" data-home-feed-canvas data-feed-mode="<?=h($feedMode)?>">
<section class="homeInlineAgentThread" data-home-inline-agent hidden aria-label="Agent Chat">
  <div class="homeInlineAgentMessages" data-inline-agent-messages role="log" aria-live="polite"></div>
  <button type="button" class="homeInlineAgentClose" data-inline-agent-close>Close chat</button>
</section>
<?php if($homeRuntimeIncidents&&($u['role']??'')==='admin'):?><div class="homeRuntimeNotice homeFeedRuntimeNotice" role="status"><strong>Home is running in reduced mode.</strong><span><?=h(implode(', ',array_keys($homeRuntimeIncidents)))?> unavailable.</span><details><summary>Diagnostics</summary><?php foreach($homeRuntimeIncidents as $component=>$reference):?><div><code><?=h($component)?></code> · <code><?=h($reference)?></code></div><?php endforeach?></details></div><?php endif?>
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
<?php if(!$cognitiveReady):?><div class="card empty cognitiveUpgradeCard"><h2>Cognitive Feed database update required.</h2><p>The chronological Latest feed remains available until the Cognitive Feed schema is installed.</p><?php if(($u['role']??'')==='admin'):?><a class="button secondary" href="/upgrade.php">Open database upgrade</a><?php endif?></div><?php elseif(!$cognitiveRuntimeReady):?><div class="card empty cognitiveRuntimeFallback"><h2>Now is temporarily unavailable.</h2><p>Latest annotations are still available while the cognitive workspace recovers.</p></div><?php endif?>
<?php if($feedMode==='cognitive'):?>
  <details class="cognitiveRankingNote"><summary>How Now is ranked</summary><p><?=h((string)($cognitiveFeed['ranking']??''))?></p></details>
  <?php if(!($cognitiveFeed['sections']??[])):?><div class="card empty cognitiveCaughtUp"><h2>You’re caught up.</h2><p>No unresolved Research, source, Team, or Agent items need priority right now.</p><a class="button secondary" href="/home.php?view=latest">See latest annotations</a></div><?php else:?><?=cognitive_feed_ui_sections($cognitiveFeed)?><?php endif?>
<?php else:?>
  <?php if(!$latestFeed):?><div class="card empty"><h2>Your feed is ready.</h2><p>Your published annotations, captures, and Research bookmarks will appear here.</p><div class="inlineActions"><a class="button" href="/explore.php">Discover people & sources</a><a class="button secondary" href="/chrome-extension.php">Get the Chrome extension</a></div></div><?php endif?>
  <?php foreach($latestFeed as $item):?>
    <?php if(($item['kind']??'')==='bookmark'):?><?=research_agent_workspace_bookmark_card((array)$item['row'])?>
    <?php else:?><?=annotation_ui_card((array)$item['row'],$u)?>
    <?php endif?>
  <?php endforeach?>
<?php endif?>
</section>
<section class="agentChatCanvas homeAgentCanvas" id="homeAgentCanvas" data-agent-chat-canvas data-csrf="<?=h(csrf_token())?>" data-research-agent-conversation="<?=h((string)($requestedResearchAgent['conversation_public_id']??''))?>" data-research-agent-project="<?=h((string)($requestedResearchAgent['project_public_id']??''))?>" data-research-agent-id="<?=h((string)($requestedResearchAgent['public_id']??''))?>" data-research-agent-team="<?=h((string)($requestedResearchAgent['team_public_id']??''))?>" data-research-document="<?=h((string)($requestedResearchDocument['public_id']??''))?>" hidden>
  <button type="button" class="agentChatPanelClose" data-agent-panel-close aria-label="Close Agent Chat">×</button>
  <?php if($requestedResearchAgent):?><header class="researchAgentCanvasHeader" data-research-workspace-header>
    <div class="researchAgentCanvasIdentity"><span class="eyebrow">RESEARCH AGENT</span><strong><?=h((string)$requestedResearchAgent['name'])?></strong><?php if(!empty($requestedResearchAgent['team_name'])):?><small>Team · <?=h((string)$requestedResearchAgent['team_name'])?></small><?php endif?></div>
    <div class="researchAgentCanvasControls">
      <button type="button" class="researchStickyCreate" data-research-create-sticky>+ Sticky</button>
      <nav class="researchAgentCanvasNav" aria-label="Research Agent workspace">
        <button type="button" class="active" data-research-workspace-view="chat">Chat</button>
        <button type="button" data-research-workspace-view="docs">Docs</button>
        <button type="button" data-research-workspace-view="files">Files</button>
        <button type="button" data-research-workspace-view="trash">Trash</button>
      </nav>
    </div>
  </header>
  <section class="researchAgentWorkspacePanel" data-research-workspace-panel hidden>
    <div class="researchWorkspaceToolbar">
      <div><strong data-research-workspace-title>Workspace</strong><small data-research-workspace-path>All files</small></div>
      <div class="researchWorkspaceToolbarActions"><button type="button" data-research-create-folder>New folder</button><button type="button" data-research-create-document>New doc</button><button type="button" class="primary" data-research-create-bookmark>Add bookmark</button></div>
    </div>
    <div class="researchWorkspaceBreadcrumbs" data-research-workspace-breadcrumbs></div>
    <div class="researchWorkspaceStatus" data-research-workspace-status role="status" aria-live="polite"></div>
    <div class="researchWorkspaceGrid" data-research-workspace-grid></div>
  </section>
  <section class="researchDocumentWorkspace" data-research-document-workspace hidden>
    <div class="researchDocumentPane">
      <header class="researchDocumentHeader">
        <button type="button" data-research-document-close>← Back to chat</button>
        <div class="researchDocumentTitleWrap"><input type="text" maxlength="240" data-research-document-title aria-label="Document title"><span data-research-document-save-state>Saved</span></div>
        <div class="researchDocumentHeaderActions"><button type="button" data-research-document-history>History</button><button type="button" data-research-document-move>Move</button></div>
      </header>
      <div class="researchDocumentToolbar" role="toolbar" aria-label="Document formatting">
        <select data-doc-block aria-label="Text style"><option value="p">Paragraph</option><option value="h1">Heading 1</option><option value="h2">Heading 2</option><option value="h3">Heading 3</option><option value="blockquote">Quote</option><option value="pre">Code block</option></select>
        <button type="button" data-doc-command="bold"><strong>B</strong></button><button type="button" data-doc-command="italic"><em>I</em></button><button type="button" data-doc-command="underline"><u>U</u></button>
        <button type="button" data-doc-command="insertUnorderedList">• List</button><button type="button" data-doc-command="insertOrderedList">1. List</button>
        <button type="button" data-doc-link>Link</button><button type="button" data-doc-table>Table</button><button type="button" data-doc-command="undo">Undo</button><button type="button" data-doc-command="redo">Redo</button>
        <span class="researchDocumentToolbarSpacer"></span><button type="button" data-doc-ask-selection>Ask Agent</button><button type="button" data-doc-sticky-selection>Create sticky</button>
      </div>
      <div class="researchDocumentEditor" data-research-document-editor contenteditable="true" role="textbox" aria-multiline="true" spellcheck="true"></div>
      <aside class="researchDocumentHistory" data-research-document-history-panel hidden><header><strong>Version history</strong><button type="button" data-research-document-history-close>×</button></header><div data-research-document-history-list></div></aside>
    </div>
    <aside class="researchDocumentAgentPane" data-research-document-agent-pane><header><span class="eyebrow">RESEARCH AGENT</span><strong>Work with this document</strong><small>Select text and use Ask Agent, or keep chatting below.</small></header></aside>
  </section>
  <div class="researchStickyLayer" data-research-sticky-layer aria-label="Research sticky notes"></div><?php endif?>
  <div class="agentChatHistoryPanel" data-agent-history hidden><div class="agentChatHistoryHead"><strong>Recent chats</strong><button type="button" data-agent-history-close aria-label="Close chat history">×</button></div><div data-agent-history-list></div></div>
  <div class="agentChatMessages" data-agent-messages role="log" aria-live="polite"><div class="agentChatWelcome"><span class="eyebrow">ANNOTATED AGENT</span><h2>What are you researching?</h2><p>Ask about your annotations, sources, Research projects, or Team context. Attached context is permission-checked before the Agent can use it.</p><?php if(($proactiveBriefing['count']??0)>0):?><section class="agentProactiveBriefing"><div class="eyebrow">RESEARCH BRIEFING</div><h3><?=h((string)$proactiveBriefing['count'])?> things worth reviewing</h3><?php foreach((array)$proactiveBriefing['items'] as $brief):?><article><strong><?=h((string)($brief['title']??'Research update'))?></strong><p><?=h((string)($brief['why']??''))?></p><?php if(!empty($brief['primary_url'])):?><a href="<?=h((string)$brief['primary_url'])?>">Open context</a><?php endif?></article><?php endforeach?></section><?php endif?></div></div>
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
<div class="card"><div class="sectionHeadWeb"><div><span class="eyebrow">TEAMS</span><h3>Your teams</h3></div></div><?php if($teams):?><p class="meta"><?=h((string)count($teams))?> team<?=count($teams)===1?'':'s'?> available.</p><a href="/teams.php">Open Teams</a><?php else:?><p class="meta">Create a team for private collaboration, chat, and shared Research.</p><a href="/teams.php">Create or join a Team</a><?php endif?></div>
<div class="card"><span class="eyebrow">BROWSER SIDEBAR</span><h3>Annotate while you browse</h3><p class="meta">The Chrome extension connects this social website to the live page you are researching.</p><a class="button" href="/chrome-extension.php">Download Chrome Extension</a></div>
<?php endif?>
</aside></main>
<?php if($chatTeams):?><button class="teamChatMobileToggle" type="button" data-team-chat-open aria-controls="team-chat">Team Chat <span data-team-chat-total-unread></span></button><div class="teamChatPopupLayer" data-team-chat-popups aria-live="polite"></div><?php endif?>
<form class="homeAgentDock" id="homeAgentComposer" data-agent-chat-composer>
  <button type="button" class="homeAgentAdd" id="homeAgentAdd" aria-label="Add context">+</button>
  <div class="agentChatContextPicker" data-agent-context-picker hidden><div class="agentChatContextPickerHead"><strong>Add Annotated context</strong><button type="button" data-agent-context-close aria-label="Close context picker">×</button></div><div class="agentChatContextPickerBody" data-agent-context-options><div class="meta">Loading context…</div></div></div>
  <div class="agentChatContextTray" data-agent-context-tray hidden></div>
  <textarea id="homeAgentPrompt" name="prompt" rows="1" placeholder="Ask Annotated…" aria-label="Ask Annotated"></textarea>
  <button type="submit" class="homeAgentSend" aria-label="Send to Agent">↑</button>
</form>
<script src="/assets/js/workspace-state.js?v=36.0"></script>
<script src="/assets/js/agent-chat.js?v=40.0"></script>
<script src="/assets/js/research-agent-workspace-ui.js?v=51.0"></script>
<script src="/assets/js/cognitive-feed.js?v=17.0"></script>
<?php if($chatTeams):?><script src="/assets/js/team-chat.js?v=36.0"></script><?php endif?>
<?php if($proactiveAgentHandoff):?><script>document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:<?=json_encode(['prompt'=>$proactiveAgentHandoff['prompt'],'context'=>$proactiveAgentHandoff['context'],'source'=>'proactive_notification'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>,bubbles:true,cancelable:true}));</script><?php endif?>
<?php if($crossResearchAgentHandoff):?><script>document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:<?=json_encode(['prompt'=>$crossResearchAgentHandoff['prompt'],'context'=>$crossResearchAgentHandoff['context'],'source'=>'cross_research'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>,bubbles:true,cancelable:true}));</script><?php endif?>
<?php if($reviewAgentHandoff):?><script>document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:<?=json_encode(['prompt'=>$reviewAgentHandoff['prompt'],'context'=>$reviewAgentHandoff['context'],'source'=>'research_review'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>,bubbles:true,cancelable:true}));</script><?php endif?>
<?php if($impactAgentHandoff):?><script>document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:<?=json_encode(['prompt'=>$impactAgentHandoff['prompt'],'context'=>$impactAgentHandoff['context'],'source'=>'change_impact'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>,bubbles:true,cancelable:true}));</script><?php endif?>
<?php if($portfolioAgentHandoff):?><script>document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:<?=json_encode(['prompt'=>$portfolioAgentHandoff['prompt'],'context'=>$portfolioAgentHandoff['context'],'source'=>'research_portfolio'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>,bubbles:true,cancelable:true}));</script><?php endif?>
<?php if($directAgentHandoff):?><script>document.dispatchEvent(new CustomEvent('annotated:agent-chat-request',{detail:<?=json_encode($directAgentHandoff,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>,bubbles:true,cancelable:true}));</script><?php endif?>
<?=annotation_ui_scripts($u)?></body></html>