<?php
declare(strict_types=1);

$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root): void{
    $path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}
    if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;
};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root): void{
    $path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;
};

foreach([
 'database/migrations/20260923_048_research_agent_workspace_core.sql',
 'app/research-agent-workspace.php','app/research-agent-workspace-ui.php',
 'api/research-workspace-objects.php','assets/js/research-agent-workspace-ui.js',
 'tests/phase50-research-agent-workspace-db.php'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 50A file missing: '.$file;

$need('database/migrations/20260923_048_research_agent_workspace_core.sql',"ENUM('folder','document','sticky','upload','recording','bookmark')",'Workspace object model must reserve the planned Research Agent object types.');
$need('database/migrations/20260923_048_research_agent_workspace_core.sql','research_workspace_bookmarks','Website bookmarks must be first-class Research Agent objects.');
$need('app/research-agent-workspace.php','research_agent_workspace_create_folder','Research Agent workspace must create folders.');
$need('app/research-agent-workspace.php','research_agent_workspace_create_bookmark','Research Agent workspace must create canonical website bookmarks.');
$need('app/research-agent-workspace.php','research_agent_workspace_subtree_ids','Folder trash/restore must cover nested descendants.');
$need('app/research-agent-workspace.php',"['owner','admin','researcher']",'Workspace writes must respect Team write roles.');
$need('app/research-agent-workspace.php','research_agent_workspace_bookmark_context','Bookmarks must resolve into Agent context with provenance.');
$need('app/agent-chat.php',"if(\$type==='bookmark'",'Primary Agent Chat must accept bookmark context.');
$need('app/workspace-context.php',"if(\$type==='bookmark'",'Shared workspace state must resolve bookmark objects.');
$need('app/object-handoff.php',"'bookmark'=>'Bookmark'",'Unified object handoff must support Research bookmarks.');
$need('app/unified-activity.php','unified_activity_workspace_bookmarks','Bookmarks must surface in unified activity / Now.');
$need('home.php','research_agent_workspace_bookmark_card','Bookmarks must appear in the main Latest feed.');
$need('home.php','data-research-workspace-view="files"','Each Research Agent canvas must expose its workspace files surface.');
$need('home.php','data-research-create-bookmark','Research Agents must support manual bookmark creation.');
$need('assets/js/research-agent-workspace-ui.js','text/x-annotated-workspace-object','Workspace folders must support drag/drop moves.');
$need('assets/js/research-agent-workspace-ui.js','shareBookmarkToTeam','Team Research bookmarks must support Team Chat handoff.');
$need('extension/sidepanel.html','id="bookmarkPage"','Chrome This Page must expose website Bookmark.');
$need('extension/sidepanel.html','id="bookmarkAgent"','Chrome bookmark flow must choose a Research Agent.');
$need('extension/sidepanel-workspace.js','phase50SaveBookmark','Chrome must persist bookmarks into the Research Agent workspace API.');
$need('extension/sidepanel-workspace.js',"object_type:'bookmark'",'Chrome must make the saved bookmark current workspace context.');
$need('extension/content.js','pagePreviewInfo','Chrome must collect bookmark preview metadata without passive browsing history.');
$need('research.php','research_agent_chat_feed','Research page must show a per-Agent conversation feed.');
$need('research.php','+ New Research Agent','Research page must expose one clear Research Agent creation action.');
$need('research.php','Open Agent Chat','Each Research Agent must open its persistent main chat canvas.');
$avoid('research.php','researchLibraryHero','Legacy Research title/hero must be removed.');
$avoid('research.php','ADD RESEARCH','Legacy Add Research control must be removed.');
$avoid('research.php','No Research projects yet','Legacy empty standalone-project panel must be removed.');
$avoid('research.php','Create Research','Research page must not expose the old standalone project creator.');
$need('app/research-agents.php',"ra.team_id IS NULL AND ra.owner_user_id",'Personal Agent ownership and Team membership access must be separated.');
$need('app/agent-chat.php','SELECT ra.team_id FROM research_agents','Team Research Agent chat must re-check live Team membership.');

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 50A Research Agent Workspace Core contract passed.\n";
