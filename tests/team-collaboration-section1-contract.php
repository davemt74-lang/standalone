<?php
declare(strict_types=1);
$base=dirname(__DIR__);
$helper=(string)file_get_contents($base.'/app/team-research-resources.php');
$page=(string)file_get_contents($base.'/team.php');
$existing=(string)file_get_contents($base.'/app/research-agent-workspace.php');
$issues=[];
foreach([
    "JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=?"=>'Persistence-backed current Team membership required.',
    "ra.team_id=t.id AND ra.public_id=?"=>'Selected Agent must belong to Team.',
    "rp.id=ra.project_id AND rp.team_id=t.id"=>'Selected canonical Project must share Team.',
    'research_agent_workspace_project($pdo,$viewer'=>'Reuse canonical Project permissions.',
    'research_agent_workspace_require_write($project)'=>'Viewer write denial required.',
    'research_agent_workspace_create_document($pdo,$viewer,$project'=>'Team contributions must reuse canonical document storage.',
    "rwo.status='active'" =>'Never surface trashed workspace objects.',
    'last_edited_by_user_id'=>'Preserve editor attribution on Team contributions.',
    'function team_research_collaboration_recent'=>'Team contribution feed required.',
    "'contribution_scope'=>'team'" =>'Team-created documents must retain an origin tag.',
    'contributor_user_id'=>'Original contributor must remain associated with research.',
    "t.public_id team_public_id"=>'Team feed must expose current canonical Team identity.'
] as $frag=>$message)if(!str_contains($helper,$frag))$issues[]=$message;
foreach([
    "'team_create_research_document'" =>'Team document post action missing.',
    "['owner','admin','researcher']"=>'Only authorized Team roles may contribute.',
    'team_research_collaboration_create_document($pdo,$u'=>'Team page must use permission-checked document creation.',
    'name="csrf"'=>'Research contribution POST must include valid CSRF.',
    'name="agent_id"'=>'Team contribution must pick an assigned Agent.',
    'name="body"'=>'Team contribution must require research notes.',
    'team_research_collaboration_recent($pdo,$u'=> 'Use only scoped Team contribution feed.',
    'Open Agent Library'=>'Team members must be able to open original Agent Library.',
    'Attaching an Agent is optional'=>'Empty Team remains independently functional.',
    'Open Desktop'=>'Explicit Team Desktop shortcut required.',
    'Open Library'=>'Explicit Team Library shortcut required.',
    'teamResearchContributionLabels'=>'Contribution badge must be visible to Team.',
    'Contributed by '=>'Contributor attribution must be readable.'
] as $frag=>$message)if(!str_contains($page,$frag))$issues[]=$message;
if(!str_contains($existing,"'contribution_tag'")||!str_contains($existing,"'origin_team_contribution'"))$issues[]='Desktop/Library API must expose existing Team contributor origin.';
if(!str_contains($existing,'research_agent_workspace_document_snapshot('))$issues[]='Canonical document versioning is missing.';
if(str_contains($page,'csrf_field()'))$issues[]='Undefined csrf_field() would truncate the Team page.';
if($issues){foreach($issues as $issue)fwrite(STDERR,"FAIL: $issue\n");exit(1);}
echo "Team collaboration Section 1 canonical document and permissions contract passed.\n";
