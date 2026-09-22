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

library_ui_assert(str_contains($research,'researchLibraryCanvas'),'Research uses full-width library canvas');
library_ui_assert(str_contains($research,'ADD RESEARCH'),'Research creation is collapsed behind ADD RESEARCH');
library_ui_assert(str_contains($research,'researchFolderGrid'),'Research uses folder cards');
library_ui_assert(str_contains($research,'Advanced Research tools'),'Research preserves the advanced tools contract');
library_ui_assert(str_contains($research,'/research-citations.php'),'Research preserves project citations entry point');
library_ui_assert(str_contains($research,'/research-provenance.php'),'Research preserves provenance entry point');

library_ui_assert(str_contains($saved,'savedLibraryCanvas'),'Saved uses full-width library canvas');
library_ui_assert(str_contains($saved,'ADD COLLECTION'),'Saved creation is collapsed behind ADD COLLECTION');
library_ui_assert(str_contains($saved,'savedCollectionGrid'),'Saved collections render as library folders');
library_ui_assert(!str_contains($saved,'<header class="topbar">'),'Saved no longer renders a duplicate legacy header');

library_ui_assert(str_contains($teams,'teamsLibraryCanvas'),'Teams uses full-width library canvas');
library_ui_assert(str_contains($teams,'ADD TEAM'),'Team creation is collapsed behind ADD TEAM');
library_ui_assert(str_contains($teams,'teamWorkspaceGrid'),'Teams render as workspace cards');

library_ui_assert(!str_contains($annotation,'<header class="topbar">'),'Annotation detail no longer renders a duplicate legacy header');

library_ui_assert(str_contains($css,'/* Research folder canvas */'),'Research folder styling exists');
library_ui_assert(str_contains($css,'/* Shared Saved + Teams library canvases */'),'Saved and Teams shared library styling exists');

echo "Workspace library canvas contract passed.\n";
