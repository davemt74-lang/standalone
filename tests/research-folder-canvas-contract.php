<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/research.php');
$agents=(string)file_get_contents($root.'/app/research-agents.php');
$css=(string)file_get_contents($root.'/assets/css/app.css');

function research_folder_assert(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}

research_folder_assert(str_contains($page,'class="researchLibraryCanvas"'),'Research uses the full-width library canvas');
research_folder_assert(str_contains($page,'+ New Research Agent'),'Research exposes New Research Agent as its single primary creation action');
research_folder_assert(substr_count($page,'data-research-agent-add')===1,'Research page has exactly one local New Research Agent control');
research_folder_assert(!str_contains($page,'ADD RESEARCH'),'Legacy Add Research action is removed');
research_folder_assert(!str_contains($page,'No Research projects yet'),'Legacy standalone-project empty state is removed');
research_folder_assert(!str_contains($page,'researchFolderGrid'),'Research landing page no longer renders standalone project folders as the primary surface');
research_folder_assert(str_contains($page,'researchAgentLibraryGrid'),'Research landing page renders Research Agents as the primary objects');
research_folder_assert(str_contains($page,'researchAgentLibraryChatFeed'),'Every Research Agent card exposes its own recent chat feed');
research_folder_assert(str_contains($page,"research_agent_shell_href(\$agent,'chat')")&&str_contains($page,'Open Agent'),'Every Research Agent opens its persistent main Agent Chat canvas through the canonical shell helper');
research_folder_assert(str_contains($page,"research_agent_shell_href(\$agent,'knowledge')")&&str_contains($page,"research_agent_shell_href(\$agent,'research')")&&str_contains($page,"research_agent_shell_href(\$agent,'reports')"),'Every Research Agent card exposes the four canonical Agent destinations');
research_folder_assert(!str_contains($page,'/research-project.php?id='),'Research Agent cards no longer expose Project/Workspace as a competing primary destination');
research_folder_assert(str_contains($agents,'function research_agent_chat_feed'),'Research Agent runtime exposes bounded per-Agent conversation history');
research_folder_assert(str_contains($agents,"ra.team_id IS NULL AND ra.owner_user_id"),'Research Agent access separates personal ownership from live Team membership');
research_folder_assert(str_contains($css,'/* Research Agent library — agent-first chat feeds */'),'Agent-first Research landing page has dedicated chat-feed styling');

echo "Research Agent library canvas contract passed.\n";
