<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$home=(string)file_get_contents($root.'/home.php');
$agent=(string)file_get_contents($root.'/app/agent-chat.php');
$api=(string)file_get_contents($root.'/api/agent-chat.php');
$js=(string)file_get_contents($root.'/assets/js/agent-chat.js');
$css=(string)file_get_contents($root.'/assets/css/app.css');

function home_render_assert(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}

home_render_assert(str_contains($home,'$homeRuntimeIncidents=[]'),'Home tracks component-level runtime failures');
home_render_assert(str_contains($home,'$recordHomeIncident(\'chat\',$e)'),'Home isolates chat failures');
home_render_assert(str_contains($home,'$recordHomeIncident(\'cognitive\',$e)'),'Home isolates cognitive failures');
home_render_assert(str_contains($home,'$cognitiveRuntimeReady'),'Home distinguishes schema readiness from cognitive runtime readiness');
home_render_assert(!str_contains($home,'$homeRuntimeIncident='),'legacy all-or-nothing Home incident state is removed');
home_render_assert(str_contains($home,'homeWorkspaceLayout'),'Home uses dedicated workspace layout hooks');
home_render_assert(str_contains($css,'.homeWorkspaceLayout>#homeFeedCanvas'),'Home feed has explicit grid placement');
home_render_assert(str_contains($css,'.homeWorkspaceLayout>.homeRightRail'),'Home right rail has explicit grid placement');
home_render_assert(str_contains($css,'.homeWorkspaceLayout>.homeRightRail{display:none!important}'),'Home removes the right column');
home_render_assert(str_contains($css,'grid-template-columns:minmax(0,820px)!important'),'Home feed is centered as a single content column');
home_render_assert(str_contains($home,'homeRuntimeNotice homeFeedRuntimeNotice'),'Reduced-mode diagnostics are scoped to the Feed canvas');
$feedPos=strpos($home,'data-home-feed-canvas');$noticePos=strpos($home,'homeFeedRuntimeNotice');
home_render_assert($feedPos!==false&&$noticePos!==false&&$noticePos>$feedPos,'Reduced-mode diagnostics render inside Feed, not above Agent Chat');
home_render_assert(str_contains($css,'.homeFeedPage.agentChatMode .homeRightRail{display:none!important}'),'Agent mode removes the Home right rail');
home_render_assert(str_contains($css,'.homeFeedPage.agentChatMode .appShellFooter{display:none}'),'Agent mode removes the universal footer under the fixed composer');
home_render_assert(str_contains($css,'.homeFeedPage.agentChatMode{overflow-x:clip}'),'Agent mode clips horizontal overflow without creating another scroll container');
home_render_assert(str_contains($js,"if(rightRail)rightRail.hidden=true"),'Agent mode explicitly hides the right rail');
home_render_assert(str_contains($js,"if(rightRail)rightRail.hidden=false"),'Returning to Feed restores the right rail');
home_render_assert(str_contains($home,'data-agent-new>NEW RESEARCH</button>'),'Agent header uses NEW RESEARCH terminology');
home_render_assert(str_contains($home,'<h2 data-agent-title>New Research</h2>'),'Fresh Agent workspace is titled New Research');
$composerPos=strpos($home,'id="homeAgentComposer"');$pickerPos=strpos($home,'data-agent-context-picker');$canvasClosePos=strpos($home,'</section><aside class="homeRightRail');
home_render_assert($composerPos!==false&&$pickerPos!==false&&$pickerPos>$composerPos,'Agent context picker is mounted inside the chat composer');
home_render_assert(str_contains($css,'.homeAgentDock .agentChatContextPicker{')&&str_contains($css,'bottom:calc(100% + 10px)'),'Context picker opens above the composer');
home_render_assert(str_contains($css,'.homeAgentDock .agentChatContextTray{')&&str_contains($css,'left:50%'),'Selected context tray is centered over the chat bar');
home_render_assert(str_contains($css,'.homeAgentDock .agentChatContextTray[hidden]{'),'Empty selected-context tray is forcibly hidden');
home_render_assert(str_contains($js,'contextTray.hidden=!selectedContext.length'),'Selected-context tray hides when there are no selections');
home_render_assert(str_contains($js,"form.querySelector('[data-agent-context-picker]')"),'Chat footer + resolves the context picker from the composer');
home_render_assert(!str_contains($js,"canvas.querySelector('[data-agent-context-picker]')"),'Chat footer + no longer looks for the moved picker in the Agent canvas');

home_render_assert(!str_contains($agent,'SELECT DISTINCT rp.public_id,rp.title'),'Agent context picker no longer uses DISTINCT with an unselected ORDER BY column');
home_render_assert(str_contains($agent,'EXISTS(SELECT 1 FROM team_members tm'),'Agent Research options use membership EXISTS');
home_render_assert(str_contains($agent,'ORDER BY rp.created_at DESC,rp.id DESC'),'Agent Research options retain deterministic recency ordering');

home_render_assert(str_contains($api,'catch(Throwable $e)'),'Agent API catches unexpected server/database failures');
home_render_assert(str_contains($api,'AGENT_CHAT_INTERNAL'),'Agent API returns a controlled internal-error code');
home_render_assert(str_contains($js,'function renderInlineError'),'Agent UI renders request failures as text nodes');
home_render_assert(!str_contains($js,"contextOptions.innerHTML='<div class=\"error\">'+esc(err.message)"),'Agent context picker does not inject server errors through innerHTML');

echo "Home render integrity contract passed.\n";
