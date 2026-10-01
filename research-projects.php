<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$viewer=current_user($pdo);header($viewer?'Cache-Control: private, no-store':'Cache-Control: public, max-age=60');
$query=trim((string)($_GET['q']??''));$selectedId=trim((string)($_GET['project']??''));
$projects=sponsored_project_public_list($pdo,$query,100);$sampleProjects=sponsored_project_public_samples($pdo,$query);$selected=$selectedId!==''?sponsored_project_public_get($pdo,$selectedId):null;
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Research Projects · Annotated</title><meta name="description" content="Browse open Sponsored Research Projects and find paid research work for qualified Annotated Research Accounts."><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="panel" style="max-width:1200px;margin:32px auto">
<div class="pageTitle"><span class="eyebrow">OPEN RESEARCH</span><h1>Research Projects</h1><p>Browse public Sponsored Research Projects. Qualified Research Accounts can assign one of their own Research Agents, complete the research in Annotated, and submit Reports and Research Documents for review.</p>
<div class="inlineActions"><?php if($viewer):?><a class="button" href="/research-sponsored-projects.php">My Sponsored Projects</a><?php else:?><a class="button" href="/login.php">Sign in to work on a project</a><?php endif?></div></div>
<form method="get" class="card"><label>Find projects<input name="q" value="<?=h($query)?>" placeholder="Topic, objective, sponsor"></label><button>Search</button><?php if($query!==''):?> <a class="button secondary" href="/research-projects.php">Clear</a><?php endif?></form>
<?php if($selected):?>
<section class="card"><span class="eyebrow">PROJECT DETAIL</span><h2><?=h((string)$selected['title'])?></h2><div class="meta">Sponsored by <?=h((string)$selected['organization_name'])?></div>
<p><?=nl2br(h((string)$selected['brief']))?></p><h3>Objective</h3><p><?=nl2br(h((string)$selected['objective']))?></p>
<div class="card"><strong>Researcher compensation</strong><div><?=h((string)$selected['budget_currency'])?> <?=number_format(((int)$selected['researcher_compensation_cents'])/100,2)?> flat fee</div><div class="meta">The exact fee is frozen when an approved researcher assigns a Research Agent.</div></div>
<?php if(!empty($selected['questions'])):?><h3>Research questions</h3><ul><?php foreach($selected['questions'] as $question):?><li><?=h((string)$question['question'])?></li><?php endforeach?></ul><?php endif?>
<h3>Requirements</h3><div class="meta">Verification: <?=h(ucwords(str_replace('_',' ',(string)($selected['eligibility']['min_verification']??'basic'))))?><?php if(!empty($selected['eligibility']['specialties'])):?> · Specialties: <?=h(implode(', ',(array)$selected['eligibility']['specialties']))?><?php endif?><?php if(!empty($selected['eligibility']['languages'])):?> · Languages: <?=h(implode(', ',(array)$selected['eligibility']['languages']))?><?php endif?></div>
<div class="meta">Submission deadline: <?=h((string)($selected['submission_deadline']??'Not set'))?></div>
<?php if($viewer):?><a class="button" href="/research-sponsored-projects.php#project-<?=rawurlencode((string)$selected['public_id'])?>">Work on this project</a><?php else:?><a class="button" href="/login.php">Sign in to continue</a><?php endif?></section>
<?php endif?>
<section class="card"><div class="inlineActions"><h2>Open projects</h2><span class="badge"><?=count($projects)?></span></div>
<?php if(!$projects):?><p>No public Sponsored Research Projects match your search right now.</p><?php endif?>
<?php foreach($projects as $p):?><article class="card" style="margin-bottom:18px">
<div class="inlineActions"><div><h3 style="margin:0"><?=h((string)$p['title'])?></h3><div class="meta"><?=h((string)$p['organization_name'])?></div></div><strong><?=h((string)$p['budget_currency'])?> <?=number_format(((int)$p['researcher_compensation_cents'])/100,2)?></strong></div>
<p><?=h(mb_substr((string)$p['brief'],0,420))?></p>
<div class="meta">Flat fee · deadline <?=h((string)($p['submission_deadline']??'Not set'))?><?php if($p['spots_remaining']!==null):?> · <?=h((string)$p['spots_remaining'])?> spots remaining<?php endif?></div>
<?php if(!empty($p['eligibility']['specialties'])):?><div class="meta">Specialties: <?=h(implode(', ',(array)$p['eligibility']['specialties']))?></div><?php endif?>
<a class="button secondary" href="/research-projects.php?project=<?=rawurlencode((string)$p['public_id'])?><?= $query!==''?'&q='.rawurlencode($query):'' ?>">View project</a>
<?php if($viewer):?><a class="button" href="/research-sponsored-projects.php#project-<?=rawurlencode((string)$p['public_id'])?>">Work on project</a><?php endif?></article><?php endforeach?></section>
<?php if($sampleProjects):?><section class="card" aria-labelledby="sample-projects-heading"><div class="inlineActions"><div><span class="eyebrow">DEMO CONTENT</span><h2 id="sample-projects-heading">Example Sponsored Projects</h2></div><span class="badge">SAMPLE DATA</span></div>
<p class="meta">These are illustrative projects, not real paid listings. You cannot apply, assign an Agent, submit research, or earn compensation through these examples.</p>
<?php foreach($sampleProjects as $sample):?><article class="card" style="margin-bottom:18px"><div class="inlineActions"><div><h3><?=h((string)$sample['title'])?></h3><div class="meta"><?=h((string)$sample['organization_name'])?> · Demonstration only</div></div><span class="badge">SAMPLE — NOT AVAILABLE</span></div><p><?=h((string)$sample['brief'])?></p><div class="meta">Illustrative researcher flat fee <?=h((string)$sample['budget_currency'])?> <?=number_format(((int)$sample['researcher_compensation_cents'])/100,2)?> · Example deadline <?=h((string)$sample['submission_deadline'])?></div><div class="meta">Example requirements: <?=h(implode(' · ',(array)$sample['requirements']))?></div></article><?php endforeach?></section><?php endif?>
</main></body></html>