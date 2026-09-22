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

home_render_assert(!str_contains($agent,'SELECT DISTINCT rp.public_id,rp.title'),'Agent context picker no longer uses DISTINCT with an unselected ORDER BY column');
home_render_assert(str_contains($agent,'EXISTS(SELECT 1 FROM team_members tm'),'Agent Research options use membership EXISTS');
home_render_assert(str_contains($agent,'ORDER BY rp.created_at DESC,rp.id DESC'),'Agent Research options retain deterministic recency ordering');

home_render_assert(str_contains($api,'catch(Throwable $e)'),'Agent API catches unexpected server/database failures');
home_render_assert(str_contains($api,'AGENT_CHAT_INTERNAL'),'Agent API returns a controlled internal-error code');
home_render_assert(str_contains($js,'function renderInlineError'),'Agent UI renders request failures as text nodes');
home_render_assert(!str_contains($js,"contextOptions.innerHTML='<div class=\"error\">'+esc(err.message)"),'Agent context picker does not inject server errors through innerHTML');

echo "Home render integrity contract passed.\n";
