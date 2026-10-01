<?php
declare(strict_types=1);
$root=dirname(__DIR__);$issues=[];
$get=function(string $name)use($root,&$issues):string{$file=$root.'/'.$name;if(!is_file($file)){$issues[]='Missing '.$name;return '';}return (string)file_get_contents($file);};
$team=$get('team.php');$edit=$get('research-agent-edit.php');$api=$get('app/team-research-resources.php');
foreach([
  'class="teamWorkspaceFullWidth"'=>'Team page must occupy a single full-width canvas.',
  'Attach Research Agent</h2>'=>'Team assignment must be visible in the main Team content.',
  'name="agent_id" required'=>'Team owner must have a Research Agent dropdown.',
  'foreach($assignable as $candidate)'=>'Team dropdown must enumerate eligible owned Agents.',
  "if($isOwner):"=>'Team assignment must be owner-only.',
  'confirm_workspace_share'=>'Sharing must require informed confirmation.',
  "team_research_assign(\$pdo,\$team,\$u"=>'Attach actions must use canonical ownership-checked runtime.',
  "team_research_unassign(\$pdo,\$team,\$u"=>'Team owner must be able to remove shared Agents.',
  'Open Team Chat'=>'Team chat must remain accessible after removing sidebar.'
] as $phrase=>$message)if(!str_contains($team,$phrase))$issues[]=$message;
if(str_contains($team,'<aside>'))$issues[]='Legacy Team right sidebar must be removed.';
if(str_contains($team,'<main class="layout"'))$issues[]='Team page must not use old sidebar layout.';
$attachPos=strpos($team,'id="team-agent-attachment"');$resourcesPos=strpos($team,'Assigned Research Agents, Desktops &amp; Libraries');
if($attachPos===false||$resourcesPos===false||$attachPos>$resourcesPos)$issues[]='Attachment panel must precede assigned resource cards.';
foreach([
  'id="team-assignment"'=>'Research Agent edit must display Team assignment near top.',
  "team_research_owned_teams(\$pdo,(int)\$u['id'])"=>'Agent edit picker must show only owned Teams.',
  "team_research_owned_team(\$pdo,(int)\$u['id']"=>'Posted Team selection must resolve verified owner-only Team.',
  "team_research_assign(\$pdo,\$team,\$u,\$agentId)"=>'Agent edit must reuse canonical secure attach runtime.',
  "team_research_unassign(\$pdo,\$team,\$u,\$agentId)"=>'Agent edit must reuse canonical secure remove runtime.',
  "name=\"team_id\" required"=>'Agent edit must offer Team dropdown.',
  "name=\"confirm_workspace_share\""=>'Agent edit must require explicit share acknowledgement.',
  "Current"=>'Agent edit should expose current assignment.',
  'Remove from Team'=>'Agent edit must allow removal.',
  'Select a Team'=>'Agent edit Team picker needs an explicit empty default.',
] as $phrase=>$message)if(!str_contains($edit,$phrase))$issues[]=$message;
$editPanel=strpos($edit,'id="team-assignment"');$agentFields=strpos($edit,'class="researchAgentEditGrid"');
if($editPanel===false||$agentFields===false||$editPanel>$agentFields)$issues[]='Team assignment panel must be prominently positioned above Agent settings.';
foreach([
  'function team_research_owned_teams'=>'Owner-only Team lookup missing.',
  "tm.role='owner'" =>'Team ownership must include current owner membership.',
  't.owner_user_id=?' =>'Team ownership must include persisted Teams owner ID.',
  'function team_research_owned_team'=>'Secure selection resolver missing.',
  'function team_research_agent_team'=>'Current assignment lookup missing.',
] as $phrase=>$message)if(!str_contains($api,$phrase))$issues[]=$message;
if($issues){foreach($issues as $issue)fwrite(STDERR,"FAIL: $issue\n");exit(1);}
echo "Team assignment two-way UI & owner-only API contract passed.\n";
