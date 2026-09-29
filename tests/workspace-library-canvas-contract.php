<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$research=(string)file_get_contents($root.'/research.php');
$saved=(string)file_get_contents($root.'/saved.php');
$teams=(string)file_get_contents($root.'/teams.php');
$annotation=(string)file_get_contents($root.'/annotation.php');
$css=(string)file_get_contents($root.'/assets/css/app.css');

function library_ui_assert(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}

library_ui_assert(str_contains($research,'researchHomeCanvas'),'Research uses the full-width canonical Research Home canvas');
library_ui_assert(str_contains($research,'+ New Research Agent'),'Research creation is centered on New Research Agent');
library_ui_assert(!str_contains($research,'researchFolderGrid'),'Research landing page no longer uses standalone project folder cards');
library_ui_assert(str_contains($research,'researchHomeAgentGrid'),'Research canvas exposes the user\'s Research Agents as launcher objects');
library_ui_assert(str_contains($research,'research_agent_ensure_default'),'Research canvas ensures the default Research Agent exists');
library_ui_assert(substr_count($research,'data-research-agent-add')===1,'Research canvas exposes exactly one local New Research Agent modal trigger');
library_ui_assert(!str_contains($research,'<header class="topbar">'),'Research canvas must not render a duplicate legacy header');
library_ui_assert(!str_contains($research,'Advanced Research tools'),'Research Home removes the old advanced-tools inventory from primary navigation');
require_once $root.'/app/research-surface-map.php';
$routeMap=research_route_surface_map();
library_ui_assert(!empty($routeMap['research-citations.php']),'Project citations remain preserved in the canonical compatibility map');
library_ui_assert(!empty($routeMap['research-provenance.php']),'Research provenance remains preserved in the canonical compatibility map');

library_ui_assert(str_contains($saved,'savedLibraryCanvas'),'Saved uses full-width library canvas');
library_ui_assert(str_contains($saved,'ADD COLLECTION'),'Saved creation is collapsed behind ADD COLLECTION');
library_ui_assert(str_contains($saved,'savedCollectionGrid'),'Saved collections render as library folders');
library_ui_assert(!str_contains($saved,'<header class="topbar">'),'Saved no longer renders a duplicate legacy header');

library_ui_assert(str_contains($teams,'teamsLibraryCanvas'),'Teams uses full-width library canvas');
library_ui_assert(str_contains($teams,'ADD TEAM'),'Team creation is collapsed behind ADD TEAM');
library_ui_assert(str_contains($teams,'teamWorkspaceGrid'),'Teams render as workspace cards');

library_ui_assert(!str_contains($annotation,'<header class="topbar">'),'Annotation detail no longer renders a duplicate legacy header');

library_ui_assert(str_contains($css,'/* Phase 75 Section 1 — Research Home & Agent Launcher */'),'Research Agent launcher styling exists');
library_ui_assert(str_contains($css,'/* Shared Saved + Teams library canvases */'),'Saved and Teams shared library styling exists');

echo "Workspace library canvas contract passed.\n";
