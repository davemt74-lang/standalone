<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function p34c(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}

$web=(string)file_get_contents($root.'/assets/js/workspace-state.js');
$chrome=(string)file_get_contents($root.'/extension/sidepanel-workspace.js');
$state=(string)file_get_contents($root.'/extension/sidepanel-state.js');
$agent=(string)file_get_contents($root.'/assets/js/agent-chat.js');
$team=(string)file_get_contents($root.'/assets/js/team-chat.js');
$researchAgent=(string)file_get_contents($root.'/assets/js/research-agent.js');
$html=(string)file_get_contents($root.'/extension/sidepanel.html');

p34c(str_contains($web,'sessionStorage.setItem(KEY')&&!str_contains($web,'localStorage.setItem(KEY'),'website workspace state is sessionStorage-only');
p34c(!str_contains($web,'workspaceContextStrip')&&!str_contains($web,'Working in')&&!str_contains($web,'Clear context'),'website workspace context remains state-only and renders no extra top strip');
p34c(str_contains($chrome,'chrome.storage.session.set')&&!str_contains($chrome,'chrome.storage.local.set({[PHASE34_WORKSPACE_KEY]')&&!str_contains($chrome,'chrome.storage.sync.set({[PHASE34_WORKSPACE_KEY]'),'Chrome workspace state is storage.session-only');
foreach(['team_public_id','research_public_id','object_type','object_public_id','agent_conversation_public_id','surface','updated_at'] as $needle)p34c(str_contains($web,$needle)&&str_contains($chrome,$needle),"both workspace runtimes retain ref-only field: $needle");
foreach(['selected_text','text_commentary','page_title','page_text','prompt_text','response_text'] as $forbidden)p34c(!str_contains($web,$forbidden)&&!str_contains($chrome,$forbidden),"workspace state does not persist content field: $forbidden");
p34c(str_contains($web,"String(value.user_public_id||'')!==user"),'website state is scoped to the current signed-in user');
p34c(str_contains($chrome,"String(stored.user_public_id||'')!==user"),'Chrome state is scoped to the current signed-in user');
p34c(str_contains($web,'history.replaceState')&&str_contains($web,"'ws_team'")&&str_contains($web,"'ws_research'"),'Browser-to-website handoff refs are imported then scrubbed from the URL');
p34c(str_contains($chrome,'phase34WorkspaceWebsiteUrl')&&str_contains($chrome,"url.searchParams.set('ws_research'"),'Chrome carries opaque refs into one-time website handoff parameters');
p34c(str_contains($agent,'agent_conversation_public_id')&&str_contains($agent,'clear_agent'),'Agent Chat updates and clears active Agent workspace refs');
p34c(str_contains($team,'team_public_id:option?.dataset.team'),'Team Chat selection updates active Team workspace ref');
p34c(str_contains($researchAgent,'research_public_id:project')&&str_contains($researchAgent,'agent_conversation_public_id:conversation'),'Research Agent keeps Research and Agent context aligned');
p34c(substr_count($state,'phase34WorkspaceClear')>=2,'Chrome logout and auth-expiry paths clear ephemeral workspace state');
p34c(str_contains((string)file_get_contents($root.'/logout.php'),"sessionStorage.removeItem('annotated.workspaceContext.v1')"),'website explicit logout clears ephemeral workspace state');
p34c(str_contains($html,'workspaceContextMini')&&str_contains($html,'sidepanel-workspace.js'),'Chrome renders the workspace context strip and loads its session runtime');
$home=(string)file_get_contents($root.'/home.php');$researchProject=(string)file_get_contents($root.'/research-project.php');preg_match('/workspace-state\\.js\\?v=([0-9.]+)/',$home,$homeWorkspaceVersion);preg_match('/workspace-state\\.js\\?v=([0-9.]+)/',$researchProject,$researchWorkspaceVersion);
p34c(!empty($homeWorkspaceVersion[1])&&($homeWorkspaceVersion[1]??'')===($researchWorkspaceVersion[1]??'')&&version_compare((string)$homeWorkspaceVersion[1],'34.0','>=')&&str_contains($researchProject,'data-workspace-research'),'website Home and Research surfaces share contextual workspace state at version 34.0 or newer');
p34c(str_contains((string)file_get_contents($root.'/annotation.php'),'data-workspace-object-type="annotation"')&&str_contains((string)file_get_contents($root.'/source.php'),'data-workspace-object-type="source"'),'Annotation and Source surfaces set active object context');
p34c(str_contains((string)file_get_contents($root.'/research-claim.php'),'data-workspace-object-type="claim"')&&str_contains((string)file_get_contents($root.'/research-finding.php'),'data-workspace-object-type="finding"'),'Claim and Finding surfaces set Research/object context');

echo "Phase 34 Contextual Navigation & Workspace State contract suite passed.\n";
