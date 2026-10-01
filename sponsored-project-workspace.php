<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/sponsored-project-detail.php';
require_once __DIR__.'/app/sponsored-project-workspace.php';
$viewer=current_user($pdo)??[];
header('Cache-Control: private, no-store');
if(!sponsored_workspace_ready($pdo)){http_response_code(503);exit('Sponsored Project Workspace requires migration 129.');}
$public=trim((string)($_GET['project']??$_POST['project']??''));
$access=sponsored_workspace_access($pdo,$viewer,$public);
if(!$access){http_response_code(404);exit('Project workspace unavailable.');}
$campaign=$access['campaign'];$role=$access['role'];$sample=$role==='sample';
$feedback='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    require_csrf();
    try{
        sponsored_workspace_post($pdo,$access,$viewer,$_POST);
        header('Location: /sponsored-project-workspace.php?project='.rawurlencode($public).'&posted=1',true,303);exit;
    }catch(Throwable $e){$feedback=$e->getMessage();}
}
$participants=sponsored_workspace_participants($pdo,$access,$viewer);
$submissions=sponsored_workspace_submission_summaries($pdo,$access,$viewer,50);
$updates=sponsored_workspace_recent($pdo,$access,$viewer,75);
$specs=$sample?sponsored_project_detail_sample_builder_specs((string)$campaign['public_id'],sponsored_project_detail_sample_specs((string)$campaign['public_id']))
    :sponsored_project_builder_normalize($campaign['project_specs']??($campaign['project_specs_json']??null),$campaign['submission_deadline']??null);
$agent=$role==='researcher'?research_agent_access($pdo,$viewer,(string)$access['assignment']['research_agent_public_id']):null;
$canPost=!in_array((string)($campaign['status']??''),['completed','cancelled','archived'],true)
    &&($role==='sponsor'||($role==='researcher'&&
      (string)($access['participation']['status']??'')==='active'
      &&(string)($access['assignment']['status']??'')==='active'));
$title=(string)$campaign['title'];
?><!doctype html><html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?> · Project Workspace · Annotated</title>
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/sponsored-project-detail.css">
<link rel="stylesheet" href="/assets/css/sponsored-project-workspace.css"></head>
<body class="sponsoredDetailPage">
<main class="sponsoredDetail sponsoredWorkspace">
<nav class="sponsoredDetailBreadcrumb" aria-label="Breadcrumb">
<a href="/research-projects.php">Research Projects</a><span aria-hidden="true">/</span>
<a href="/sponsored-project.php?project=<?=rawurlencode($public)?>"><?=h($title)?></a>
<span aria-hidden="true">/</span><span>Workspace</span></nav>
<header class="sponsoredDetailHero sponsoredWorkspaceHero"><div>
<span class="sponsoredDetailEyebrow"><?= $sample?'DEMONSTRATION · NO REAL ACTIVITY':'SPONSORED PROJECT · '.h(strtoupper($role)) ?></span>
<h1>Project workspace</h1><p class="sponsoredDetailLead"><?=h($title)?></p>
<div class="sponsoredDetailChips"><span><?=h((string)($campaign['organization_name']??'Sponsored Research'))?></span>
<span><?= $sample?'Illustrative timeline':($role==='sponsor'?'Project oversight':'Your assigned research') ?></span></div>
</div><div class="sponsoredWorkspaceHeroActions">
<a class="button" href="/sponsored-project.php?project=<?=rawurlencode($public)?>">Project specifications</a>
<?php if($role==='sponsor'):?><a class="button" href="/sponsored-research.php?campaign=<?=rawurlencode($public)?>">Sponsor management</a>
<?php elseif($role==='researcher'):?><a class="button" href="/research-sponsored-projects.php#project-<?=rawurlencode($public)?>">Researcher workflow</a><?php endif?></div>
</header>
<?php if($sample):?><div class="sponsoredWorkspaceBanner">Sample-data preview: these posts are illustrative. This page cannot create actual research, tasks, conversations, submissions or payments.</div>
<?php elseif($role==='researcher'):?><div class="sponsoredWorkspaceBanner">Only sponsor-wide announcements and your own private participant thread are visible here. Your personal Agent desktop and library are not shared with the sponsor.</div><?php endif?>
<?php if($feedback):?><div class="sponsoredWorkspaceError" role="alert"><?=h($feedback)?></div><?php endif?>
<?php if(!empty($_GET['posted'])):?><div class="sponsoredWorkspaceSuccess" role="status">Project update recorded.</div><?php endif?>
<div class="sponsoredWorkspaceGrid"><div class="sponsoredWorkspacePrimary">
<section class="sponsoredDetailSection" id="activity"><div class="sponsoredDetailSectionHead"><span>PROJECT JOURNAL</span><h2>Progress and collaboration</h2></div>
<p class="sponsoredWorkspaceIntro">An attributed history of project checkpoints and messages. Your Research Agent's source files remain in its existing governed workspace; accepted submissions are summarized below.</p>
<?php if(!$updates):?><p>No project updates yet.</p><?php endif?>
<div class="sponsoredWorkspaceTimeline">
<?php foreach($updates as $entry):?>
<article class="sponsoredWorkspaceUpdate">
<div class="sponsoredWorkspaceUpdateTop"><div>
<span class="sponsoredWorkspaceAuthor"><?=h((string)$entry['actor_name'])?></span>
<span class="sponsoredWorkspaceActor"><?=h(ucfirst((string)$entry['actor_role']))?></span>
<?php if((string)$entry['scope']==='participant'):?><span class="sponsoredWorkspacePrivacy">Private researcher thread</span>
<?php else:?><span class="sponsoredWorkspaceShared">Project-wide</span><?php endif?>
</div><span class="meta"><?=h((string)$entry['created_at'])?></span></div>
<h3><?=h((string)$entry['title'])?></h3>
<p><?=nl2br(h((string)$entry['body']))?></p>
<div class="sponsoredWorkspaceUpdateFoot">
<span><?=h(ucwords(str_replace('_',' ',(string)$entry['progress_status'])))?></span>
<?php if(!empty($entry['milestone_position'])&&isset($specs['milestones'][(int)$entry['milestone_position']-1])):?>
<span>Milestone <?=h((string)$entry['milestone_position'])?>: <?=h((string)$specs['milestones'][(int)$entry['milestone_position']-1]['title'])?></span><?php endif?>
<?php if($role==='sponsor'&&!empty($entry['participant_name'])):?>
<span>For <?=h((string)$entry['participant_name'])?></span><?php endif?>
</div></article>
<?php endforeach?></div></section>
<?php if($canPost):?><section class="sponsoredDetailSection" id="post"><div class="sponsoredDetailSectionHead"><span>CONTRIBUTE</span><h2>Post a project update</h2></div>
<form method="post" class="sponsoredWorkspaceForm"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
<input type="hidden" name="project" value="<?=h($public)?>">
<?php if($role==='sponsor'):?><label>Audience<select name="scope">
<option value="project">Project-wide announcement (all active participants)</option>
<option value="participant">Private message to one assigned researcher</option></select></label>
<label>Researcher for private message<select name="participant_public_id">
<option value="">None — project-wide post</option>
<?php foreach($participants as $p):?><option value="<?=h((string)$p['public_id'])?>">
<?=h((string)$p['display_name'])?> · <?=h((string)$p['agent_name'])?></option><?php endforeach?></select></label>
<?php else:?><input type="hidden" name="scope" value="participant"><?php endif?>
<div class="sponsoredWorkspaceFields"><label>Progress status<select name="progress_status">
<option value="update">General update</option><option value="started">Started</option>
<option value="blocked">Blocked / needs attention</option><option value="ready_for_review">Ready for review</option>
<?php if($role==='sponsor'):?><option value="completed">Checkpoint complete</option><?php endif?>
</select></label>
<label>Milestone<select name="milestone_position"><option value="">General project update</option>
<?php foreach($specs['milestones'] as $index=>$m):?><option value="<?=h((string)($index+1))?>"><?=h((string)$m['title'])?></option><?php endforeach?>
</select></label></div>
<label>Update title<input name="title" maxlength="180" required placeholder="Progress, decision or blocker"></label>
<label>Update text<textarea name="body" maxlength="4000" rows="5" required placeholder="What changed, and what should happen next?"></textarea></label>
<button class="button" type="submit">Record update</button></form>
<p class="sponsoredDetailHint">Posts are append-only and attributed to the author. They do not modify agreed deliverables, acceptance terms, Agent files or compensation.</p></section>
<?php elseif(!$sample):?><div class="sponsoredWorkspaceBanner">This project or your assignment is no longer active. Existing updates remain available for reference, but posting is disabled.</div><?php endif?>
</div><aside class="sponsoredWorkspaceRail">
<section class="sponsoredDetailCard"><strong>Project checkpoints</strong>
<?php if(!$specs['milestones']):?><p>No milestones specified. The sponsor may configure these in the Project Builder.</p><?php else:?>
<?php foreach($specs['milestones'] as $i=>$m):?><div class="sponsoredWorkspaceCheckpoint">
<span class="meta">M<?=h((string)($i+1))?><?=!empty($m['due_date'])?' · '.h((string)$m['due_date']):''?></span>
<strong><?=h((string)$m['title'])?></strong>
<p><?=h((string)$m['success_criteria'])?></p></div><?php endforeach?>
<?php endif?></section>
<?php if($role==='sponsor'):?><section class="sponsoredDetailCard"><strong>Approved, assigned researchers</strong>
<?php if(!$participants):?><p>No active Research Agent assignments.</p><?php endif?>
<?php foreach($participants as $member):?><div class="sponsoredWorkspaceMember">
<strong><?=h((string)$member['display_name'])?></strong>
<span class="meta"><?=h((string)$member['agent_name'])?> · <?=h((string)$member['assignment_status'])?></span>
</div><?php endforeach?>
<a href="/sponsored-research.php?campaign=<?=rawurlencode($public)?>">Invitations and participant management</a></section>
<?php elseif($role==='researcher'):?><section class="sponsoredDetailCard"><strong>Your assigned Agent</strong>
<p><?=h((string)$access['assignment']['research_agent_name'])?></p>
<?php if($agent):?><a href="<?=h(research_agent_shell_href($agent,'desktop'))?>">Open your Agent Desktop</a>
<a href="<?=h(research_agent_shell_href($agent,'library'))?>">Open your Agent Library</a>
<a href="/research-project.php?id=<?=rawurlencode((string)$agent['project_public_id'])?>">Your existing Research Project</a><?php endif?>
<a href="/research-reports.php?agent=<?=rawurlencode((string)$access['assignment']['research_agent_public_id'])?>&view=recent">Open your Agent reports</a>
<a href="/research-sponsored-projects.php#project-<?=rawurlencode($public)?>">Submit through your existing workflow</a></section><?php endif?>
<section class="sponsoredDetailCard"><strong><?= $sample?'Example submissions':'Submission status' ?></strong>
<?php if($sample):?><p>Illustrative research only; this preview never generates deliverables.</p>
<?php elseif(!$submissions):?><p>No submitted work recorded here yet.</p>
<?php else:?><?php foreach($submissions as $submission):?><div class="sponsoredWorkspaceSubmission">
<strong><?=h((string)$submission['title'])?></strong>
<span class="meta"><?=h((string)$submission['contributor_name'])?> · <?=h((string)$submission['status'])?></span>
<?php if(!empty($submission['submitted_at'])):?><span class="meta"><?=h((string)$submission['submitted_at'])?></span><?php endif?>
</div><?php endforeach?>
<?php if($role==='sponsor'):?><a href="/sponsored-research.php?campaign=<?=rawurlencode($public)?>">Review submissions and submitted evidence</a>
<?php else:?><a href="/research-sponsored-projects.php">Open your submitted work</a><?php endif?>
<?php endif?></section>
</aside></div></main></body></html>
