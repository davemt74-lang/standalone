<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/research.php');
$agents=(string)file_get_contents($root.'/app/research-agents.php');
$home=(string)file_get_contents($root.'/app/research-home-ui.php');
$css=(string)file_get_contents($root.'/assets/css/app.css');

function research_folder_assert(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}

research_folder_assert(str_contains($page,'class="researchHomeCanvas"'),'Research uses the full-width canonical home canvas');
research_folder_assert(str_contains($page,'+ New Research Agent'),'Research exposes New Research Agent as its single primary creation action');
research_folder_assert(substr_count($page,'data-research-agent-add')===1,'Research page has exactly one local New Research Agent control');
research_folder_assert(!str_contains($page,'ADD RESEARCH'),'Legacy Add Research action is removed');
research_folder_assert(!str_contains($page,'No Research projects yet'),'Legacy standalone-project empty state is removed');
research_folder_assert(!str_contains($page,'researchFolderGrid'),'Research landing page no longer renders standalone project folders as the primary surface');
research_folder_assert(str_contains($page,'researchHomeAgentGrid'),'Research landing page renders Research Agents as launcher objects');
research_folder_assert(!str_contains($page,'researchAgentLibraryChatFeed'),'Research home no longer loads per-Agent chat feeds');
research_folder_assert(!str_contains($page,'research_agent_chat_feed('),'Research home avoids per-Agent chat-feed queries');
research_folder_assert(!str_contains($page,'research_task_summary(')&&!str_contains($page,'research_program_summary('),'Research home avoids per-Agent task/program summary queries');
research_folder_assert(str_contains($page,"research_agent_shell_href(\$agent,'chat')"),'Every Research Agent opens its persistent main Agent Chat canvas through the canonical shell helper');
research_folder_assert(str_contains($page,"research_agent_shell_href(\$agent,'knowledge')")&&str_contains($page,"research_agent_shell_href(\$agent,'research')")&&str_contains($page,"research_agent_shell_href(\$agent,'reports')"),'Every Research Agent card exposes canonical Knowledge, Research, and Reports shortcuts');
research_folder_assert(!str_contains($page,'/research-project.php?id='),'Research Agent cards no longer expose Project/Workspace as a competing primary destination');
research_folder_assert(str_contains($home,'function research_home_dashboard'),'Research home uses the read-only canonical launcher composition layer');
research_folder_assert(str_contains($agents,"ra.team_id IS NULL AND ra.owner_user_id"),'Research Agent access separates personal ownership from live Team membership');
research_folder_assert(str_contains($css,'/* Phase 75 Section 1 — Research Home & Agent Launcher */'),'Research home has dedicated launcher styling');

echo "Research Agent launcher canvas contract passed.\n";
