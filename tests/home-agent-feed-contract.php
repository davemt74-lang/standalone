<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$home=(string)file_get_contents($root.'/home.php');
$js=(string)file_get_contents($root.'/assets/js/agent-chat.js');
$css=(string)file_get_contents($root.'/assets/css/app.css');

function home_agent_feed_assert(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}

home_agent_feed_assert(str_contains($home,'$requestedResearchAgent=research_agent_by_conversation'),'Home verifies requested Agent conversations against Research Agents');
home_agent_feed_assert(str_contains($home,'data-research-agent-conversation'),'Home exposes the verified Research Agent conversation to the client');
home_agent_feed_assert(str_contains($home,'data-home-inline-agent'),'Home includes an inline Agent Chat surface inside the feed canvas');
home_agent_feed_assert(str_contains($home,'data-agent-panel-close'),'Research Agent canvas has an explicit return-to-feed control');

home_agent_feed_assert(str_contains($js,'requestedResearchAgentConversation'),'Agent Chat reads the verified Research Agent conversation');
home_agent_feed_assert(str_contains($js,'setModeAgent(research=false)'),'Agent canvas mode requires an explicit Research Agent decision');
home_agent_feed_assert(str_contains($js,'if(!researchAgentMode){'),'Normal Agent Chat does not replace the Home feed');
home_agent_feed_assert(str_contains($js,'messageTarget(){return researchAgentMode?messages:(inlineMessages||messages);}'),'Normal Agent Chat renders to the inline Home thread');
home_agent_feed_assert(str_contains($js,"requestedConversation&&requestedConversation===requestedResearchAgentConversation"),'Only verified Research Agent URLs reopen the full canvas');
home_agent_feed_assert(!str_contains($js,"else if(restoreOpen){setModeAgent()"),'Home no longer restores a stale full Agent canvas from session storage');
home_agent_feed_assert(str_contains($js,"sessionStorage.removeItem('annotated.agentCanvasOpen')"),'Legacy Agent canvas restore state is cleared');
home_agent_feed_assert(str_contains($js,"activeConversation=''"),'Leaving a Research Agent clears its active conversation before normal Home chat resumes');

home_agent_feed_assert(str_contains($css,'.homeInlineAgentThread'),'Inline Home Agent Chat is styled');
home_agent_feed_assert(str_contains($css,'.agentChatPanelClose'),'Research Agent canvas close control is styled');

echo "Home Agent/feed split contract passed.\n";
