<?php
declare(strict_types=1);
$root=dirname(__DIR__);$errors=[];
$read=function(string $file)use($root,&$errors):string{$path=$root.'/'.$file;if(!is_file($path)){$errors[]='Missing '.$file;return '';}return (string)file_get_contents($path);};
$runtime=$read('app/team-research-resources.php');$page=$read('team.php');
foreach([
  "team['access_role']??''"=>'Team owner role must be checked.',
  "team_research_require_owner"=>'Runtime must verify persisted Team ownership.',
  "tm.role='owner'" => 'Owner must have current Team membership.',
  "team['owner_user_id']??0"=>'Team owner identity must match the caller.',
  "agent['owner_user_id']!==(int)\$viewer['id']"=>'The Agent must be personally owned by the Team owner.',
  "agent['project_owner_id']!==(int)\$viewer['id']"=>'Project ownership must match the Team owner.',
  "other.project_id=rp.id" => 'Shared projects with other active Agents must not be offered.',
  "Set the Research Agent visibility to Private" => 'Team sharing must not leak publicly visible Agents.',
  "Project changed during Team assignment." => 'Project ownership must be guarded atomically.',
  "ra.team_id IS NULL AND rp.team_id IS NULL"=>'Only unassigned personal Agents may be selected.',
  "UPDATE research_projects SET team_id=?"=>'Agent project must use canonical Team permissions.',
  "UPDATE research_agents SET team_id=?"=>'Agent must use canonical Team permissions.',
  "team_research_sync_member"=>'Conversation membership must remain synchronized.',
  "DELETE FROM conversation_members"=>'Member removal must revoke conversation access.'
] as $fragment=>$message)if(!str_contains($runtime,$fragment))$errors[]=$message;
foreach(["\$isOwner?team_research_assignable", "if(!\$isOwner)throw new RuntimeException('Only the Team owner may assign", 'confirm_workspace_share', 'team_research_assign(', 'team_research_unassign(', "team_research_sync_member(\$pdo", '/research-reports.php?agent=', '/research-agent-knowledge.php?agent='] as $fragment)if(!str_contains($page,$fragment))$errors[]='Team page missing '.$fragment;
foreach(['/research-agent.php','/research-agent-workspace.php'] as $invalid)if(str_contains($page,$invalid))$errors[]='Invalid legacy link '.$invalid;
if($errors){foreach($errors as $e)fwrite(STDERR,"FAIL: $e\n");exit(1);}
echo "Team owner-controlled Research Agent/Desktop/Library assignment contract passed.\n";
