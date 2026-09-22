<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/research.php');
$model=(string)file_get_contents($root.'/app/research-library.php');
$css=(string)file_get_contents($root.'/assets/css/app.css');

function research_folder_assert(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}

research_folder_assert(str_contains($page,'class="researchLibraryCanvas"'),'Research uses the full-width library canvas');
research_folder_assert(str_contains($page,'ADD RESEARCH'),'Research exposes Add Research as the primary creation action');
research_folder_assert(!str_contains($page,'<aside><div class="card"><h3>New project</h3>'),'Legacy permanent New project sidebar is removed');
research_folder_assert(str_contains($page,'class="researchFolderGrid"'),'Research projects render in a folder grid');
research_folder_assert(str_contains($page,'notification_count'),'Folder cards expose unread Research notifications');
research_folder_assert(str_contains($page,'comment_count'),'Folder cards expose project comment activity');
research_folder_assert(str_contains($page,'like_count'),'Folder cards expose project like activity');
research_folder_assert(str_contains($page,'researchSignalRecent'),'Folder cards expose recent activity');
research_folder_assert(str_contains($model,"annotation_reactions ar"),'Research library aggregates real Annotation likes');
research_folder_assert(str_contains($model,"comments c"),'Research library aggregates real Annotation comments');
research_folder_assert(str_contains($model,'notification_rows($pdo,$viewer,300,true)'),'Research library uses permission-filtered unread notifications');
research_folder_assert(str_contains($model,'EXISTS(')&&str_contains($model,'team_members tm'),'Research library scopes Team projects through membership');
research_folder_assert(str_contains($css,'/* Research folder canvas */'),'Research folder canvas has dedicated responsive styling');
research_folder_assert(str_contains($css,'grid-template-columns:repeat(auto-fill,minmax(330px,1fr))'),'Research folders use a responsive multi-column grid');

echo "Research folder canvas contract passed.\n";
