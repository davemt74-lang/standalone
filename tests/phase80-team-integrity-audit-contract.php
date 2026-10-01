<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$source=function(string $path)use($root,&$fail):string{
  $file=$root.'/'.$path;if(!is_file($file)){$fail[]='Missing '.$path;return '';}
  return (string)file_get_contents($file);
};
$projects=$source('app/sponsored-research-projects.php');
$finance=$source('app/sponsored-research-project-compensation.php');
$campaigns=$source('app/sponsored-research-campaigns.php');
$teams=$source('app/team-research-resources.php');
$agents=$source('app/research-agents.php');
$public=$source('research-projects.php');
foreach([
  'An existing Sponsored Project assignment cannot change Agents or be reopened.'=>'Agreed Agent identity must be immutable.',
  'sponsored_project_compensation_for_assignment($pdo,(int)$existing'=>'Existing assignments must have an agreement.',
  'The assignment changed. Reload before submitting.'=>'Submission must lock its assignment.',
  'Revision must replace the latest requested submission.'=>'Revision must be linear.',
  'Only the latest submission can receive a review decision.'=>'Review cannot accept older revisions.',
  'This assignment has already been completed or is no longer active.'=>'Reviews cannot reopen completed assignments.',
  'Team-shared Research Agents cannot accept a Sponsored Project'=>'Sponsored confidentiality boundary required.'
] as $fragment=>$label)if(!str_contains($projects,$fragment))$fail[]=$label;
foreach([
  'Researcher compensation was not agreed at assignment.'=>'Never auto-create retroactive compensation on acceptance.',
  'The submitted Agent differs from the frozen compensation agreement.'=>'Accepted Agent must match compensation.',
  'FOR UPDATE'=>'Financial transitions must serialize.',
  "c.starts_at IS NULL OR c.starts_at<=NOW()"=>'Public marketplace must honor start dates.'
] as $fragment=>$label)if(!str_contains($finance,$fragment))$fail[]=$label;
if(str_contains($finance,'function sponsored_project_compensation_backfill_campaign')||str_contains($campaigns,'sponsored_project_compensation_backfill_campaign'))
    $fail[]='Changing campaign fees may not backfill older agreements.';
foreach([
  'function team_research_require_owner'=>'Current Team ownership is verified in the database.',
  'project_owner_id'=>'Sharing requires matching Project ownership.',
  "other.project_id=rp.id"=>'Sharing rejects projects with other active Agents.',
  'Sponsored Research Agents cannot be shared with a Team'=>'Sponsored Agents require explicit collaboration authority.'
] as $fragment=>$label)if(!str_contains($teams,$fragment))$fail[]=$label;
if(!str_contains($agents,"t.owner_user_id=? AND tm.role='owner'"))$fail[]='Direct Team Agent creation must be owner-only.';
if(!str_contains($public,'sponsored_project_public_list'))$fail[]='Public marketplace must reuse canonical discovery.';
if($fail){foreach($fail as $item)fwrite(STDERR,"FAIL: $item\n");exit(1);}
echo "Phase 80/Team integrated integrity audit contract passed.\n";
