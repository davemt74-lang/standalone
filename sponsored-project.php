<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/sponsored-project-detail.php';
$viewer=current_user($pdo);
header($viewer?'Cache-Control: private, no-store':'Cache-Control: public, max-age=60');
$id=(string)($_GET['project']??'');
$detail=sponsored_project_detail_resolve($pdo,$viewer,$id);
if(!$detail){http_response_code(404);exit('Sponsored Project unavailable.');}
$p=$detail['project'];$role=$detail['role'];$sample=$p['sample'];$elig=$detail['eligibility']??null;
$specs=$p['project_specs'];
$title=(string)$p['title'];$currency=(string)$p['currency'];
$fee=sponsored_project_detail_money($p,(int)$p['fee_cents']);
$limited=!empty($p['max_participants']);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?> · Sponsored Project · Annotated</title><meta name="description" content="Sponsored Research project objectives, specifications, eligibility, submission workflow, and participation requirements.">
<link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/sponsored-project-detail.css"></head>
<body class="sponsoredDetailPage">
<main class="sponsoredDetail">
<nav class="sponsoredDetailBreadcrumb" aria-label="Breadcrumb"><a href="/research-projects.php">Research Projects</a><span aria-hidden="true">/</span><span><?=h($title)?></span></nav>
<header class="sponsoredDetailHero">
  <div class="sponsoredDetailHeroBody">
    <div class="sponsoredDetailEyebrow"><?= $sample?'DEMONSTRATION · SAMPLE DATA':'SPONSORED RESEARCH PROJECT' ?> <span class="sponsoredDetailDot" aria-hidden="true"></span> <?=h(sponsored_project_detail_policy_label((string)$p['status']))?></div>
    <h1><?=h($title)?></h1><p class="sponsoredDetailLead"><?=nl2br(h((string)$p['brief']))?></p>
    <div class="sponsoredDetailChips"><span><?=h((string)$p['organization'])?></span><span><?=h(sponsored_project_detail_policy_label((string)$p['access']))?> access</span><span><?=h($sample?'Example specification':'Project specification')?></span></div>
  </div>
  <aside class="sponsoredDetailHeroAside">
    <span class="sponsoredDetailEyebrow"><?= $sample?'ILLUSTRATIVE RESEARCHER FEE':'RESEARCHER FLAT FEE' ?></span>
    <strong class="sponsoredDetailFee"><?=h($fee)?></strong><span class="sponsoredDetailSmall"><?= $sample?'No actual payment or opportunity':'Terms and eligibility apply' ?></span>
    <?php if($sample):?>
      <div class="sponsoredDetailNotice">Preview only. This project cannot accept researchers, Agent assignments, submissions or payments.</div>
    <?php elseif($role==='sponsor'):?>
      <a class="button" href="/sponsored-research.php?campaign=<?=rawurlencode((string)$p['id'])?>">Manage project</a>
      <span class="sponsoredDetailSmall">Edit brief, eligibility, terms, invitations and lifecycle.</span>
    <?php elseif($role==='researcher'&&!empty($elig['eligible'])):?>
      <a class="button" href="/research-sponsored-projects.php#project-<?=rawurlencode((string)$p['id'])?>">Assign Research Agent</a>
      <span class="sponsoredDetailSmall">Assignment establishes the agreed flat fee.</span>
    <?php elseif($role==='researcher'):?>
      <span class="sponsoredDetailNotice">Your account does not currently meet all participation requirements.</span>
      <a class="button secondary" href="/research-account.php">Review Research Account</a>
    <?php elseif($viewer):?>
      <a class="button" href="/research-account.php">Research Account eligibility</a>
    <?php else:?>
      <a class="button" href="/login.php">Sign in to participate</a>
    <?php endif?>
  </aside>
</header>
<div class="sponsoredDetailLayout">
<div class="sponsoredDetailMain">
  <section class="sponsoredDetailSection" id="overview"><div class="sponsoredDetailSectionHead"><span>01 / BRIEF</span><h2>Project overview</h2></div>
    <h3>Research objective</h3><p><?=h((string)$p['objective']?:'The sponsor has not published a separate objective.')?></p>
    <?php if($p['questions']):?><h3>Research questions</h3><ol class="sponsoredDetailQuestions"><?php foreach($p['questions'] as $q):?><li><?=h((string)$q)?></li><?php endforeach?></ol><?php endif?>
  </section>
  <section class="sponsoredDetailSection" id="specifications"><div class="sponsoredDetailSectionHead"><span>02 / SPECIFICATIONS</span><h2>Project specifications</h2></div>
    <div class="sponsoredDetailFacts">
      <div><span>Access</span><strong><?=h(sponsored_project_detail_policy_label((string)$p['access']))?></strong></div>
      <div><span>Researcher fee</span><strong><?=h($fee)?><?= $sample?' (example)':'' ?></strong></div>
      <div><span>Participant capacity</span><strong><?= $limited?h((string)$p['max_participants']).' max':'Not specified' ?></strong></div>
      <div><span>Current status</span><strong><?=h(sponsored_project_detail_policy_label((string)$p['status']))?></strong></div>
      <div><span>Project start</span><strong><?=h((string)($p['starts_at']?:'Not specified'))?></strong></div>
      <div><span>Submission deadline</span><strong><?=h((string)($p['submission_deadline']?:'Not specified'))?></strong></div>
      <div><span>Review deadline</span><strong><?=h((string)($p['review_deadline']?:'Not specified'))?></strong></div>
      <?php if($role==='sponsor'||$sample):?><div><span><?= $sample?'Example total budget':'Configured budget' ?></span><strong><?=h(sponsored_project_detail_money($p,(int)$p['budget_cents']))?></strong></div><?php endif?>
    </div>
    <?php if($specs['target_audience']!==''||$specs['geography']!==''||$specs['scope_in']!==''||$specs['scope_out']!==''||$specs['methods']):?>
    <h3>Research scope and methods</h3>
    <div class="sponsoredDetailFacts">
      <?php if($specs['target_audience']!==''):?><div><span>Target audience</span><strong><?=h((string)$specs['target_audience'])?></strong></div><?php endif?>
      <?php if($specs['geography']!==''):?><div><span>Target geography</span><strong><?=h((string)$specs['geography'])?></strong></div><?php endif?>
    </div>
    <?php if($specs['scope_in']!==''):?><h3>In scope</h3><p><?=nl2br(h((string)$specs['scope_in']))?></p><?php endif?>
    <?php if($specs['scope_out']!==''):?><h3>Out of scope</h3><p><?=nl2br(h((string)$specs['scope_out']))?></p><?php endif?>
    <?php if($specs['methods']):?><h3>Research methods</h3><div class="sponsoredDetailChips sponsoredDetailChipsLight"><?php foreach($specs['methods'] as $method):?><span><?=h((string)$method)?></span><?php endforeach?></div><?php endif?>
    <?php endif?>
    <?php if($role==='sponsor'):?><p class="sponsoredDetailHint">Need to change these specifications? <a href="/sponsored-research.php?campaign=<?=rawurlencode((string)$p['id'])?>">Edit the governed campaign revision</a>.</p><?php endif?>
  </section>
  <section class="sponsoredDetailSection" id="requirements"><div class="sponsoredDetailSectionHead"><span>03 / PARTICIPATION</span><h2>Researcher requirements</h2></div>
    <?php if($sample):?><p>These are demonstration requirements, not an application or eligibility determination.</p>
      <ul class="sponsoredDetailList"><?php foreach($p['requirements'] as $requirement):?><li><?=h((string)$requirement)?></li><?php endforeach?></ul>
    <?php else:$rules=$p['eligibility'];?>
      <div class="sponsoredDetailFacts">
        <div><span>Research Account</span><strong>Approved account required</strong></div>
        <div><span>Minimum verification</span><strong><?=h(sponsored_project_detail_policy_label((string)$rules['min_verification']))?></strong></div>
        <div><span>Completed campaigns</span><strong><?=h((string)$rules['min_completed_campaigns'])?> minimum</strong></div>
        <div><span>Specialties</span><strong><?= $rules['specialties']?h(implode(', ',$rules['specialties'])):'None specified' ?></strong></div>
        <div><span>Languages</span><strong><?= $rules['languages']?h(implode(', ',$rules['languages'])):'None specified' ?></strong></div>
      </div>
      <?php if($role==='researcher'):?><div class="sponsoredDetailNotice"><?=!empty($elig['eligible'])?'Your Research Account meets the published eligibility checks.':'Some participation requirements are not met.'?></div><?php endif?>
    <?php endif?>
  </section>
  <section class="sponsoredDetailSection" id="deliverables"><div class="sponsoredDetailSectionHead"><span>04 / OUTPUTS</span><h2>Research and deliverables</h2></div>
    <?php if($specs['deliverables']):?>
      <p><?= $sample?'Demonstration deliverable specifications:':'Sponsor-configured project deliverables and acceptance criteria:' ?></p>
      <div class="sponsoredDetailDeliverableGrid"><?php foreach($specs['deliverables'] as $d):?>
        <article class="sponsoredDetailDeliverable"><div><span class="sponsoredDetailEyebrow"><?=h(ucwords(str_replace('_',' ',(string)$d['format'])))?></span><h3><?=h((string)$d['title'])?></h3></div>
          <p><?=h((string)$d['acceptance_criteria'])?></p>
          <?php if($d['due_date']):?><div class="sponsoredDetailSmall">Target due date: <?=h((string)$d['due_date'])?></div><?php endif?>
        </article><?php endforeach?></div>
      <p class="sponsoredDetailHint">Deliverable specifications are descriptive. Actual participation and acceptance remain governed by the published terms and existing review workflow.</p>
    <?php elseif($sample):?>
      <p>These sample projects demonstrate deliverables but cannot accept real submissions.</p>
    <?php else:?>
      <p>The sponsor has not yet configured structured deliverables. The published brief and participation terms describe the work expected.</p>
    <?php endif?>
    <p>Annotator's existing Research Agent workspace supports ready Agent Reports, attached Research Documents, and published Research Report versions.</p>
    <div class="sponsoredDetailWorkflow">
      <div><span>1</span><strong>Review the brief</strong><p>Understand the objective, questions, eligibility and disclosures.</p></div>
      <div><span>2</span><strong>Assign a Research Agent</strong><p>Approved researchers accept the current terms and assign their own active Agent.</p></div>
      <div><span>3</span><strong>Research and submit</strong><p>The Agent prepares evidence-backed reports and linked documents in its existing workspace.</p></div>
      <div><span>4</span><strong>Review and conclude</strong><p>The sponsor reviews submissions, can request revisions and records final decisions.</p></div>
    </div>
  </section>
  <?php if($specs['milestones']):?>
  <section class="sponsoredDetailSection" id="milestones"><div class="sponsoredDetailSectionHead"><span>05 / TIMELINE</span><h2>Planned research milestones</h2></div>
    <p class="sponsoredDetailHint"><?= $sample?'Illustrative checkpoints only.':'Sponsor-configured checkpoints. Progress tracking and tasks remain in the existing Research project workflow.' ?></p>
    <ol class="sponsoredDetailMilestones"><?php foreach($specs['milestones'] as $m):?><li><strong><?=h((string)$m['title'])?></strong>
      <?php if($m['due_date']):?><span class="meta">Target date: <?=h((string)$m['due_date'])?></span><?php endif?>
      <p><?=h((string)$m['success_criteria'])?></p></li><?php endforeach?></ol>
  </section><?php endif?>
  <section class="sponsoredDetailSection" id="governance"><div class="sponsoredDetailSectionHead"><span><?= $specs['milestones']?'06':'05' ?> / GOVERNANCE</span><h2>Participation and data use</h2></div>
    <?php if($sample):?><p>For demonstration only. A real project requires a published agreement, an approved Research Account and explicit participation actions.</p>
    <?php else:$d=$p['disclosures'];?>
      <ul class="sponsoredDetailList">
        <li>Sponsorship disclosure: <?=!empty($d['sponsorship_disclosure_required'])?'Required':'Not required by this configuration'?></li>
        <li>Conflict disclosure: <?=!empty($d['conflict_disclosure_required'])?'Required':'Not required by this configuration'?></li>
        <li>Non-disclosure agreement: <?=!empty($d['nda_required'])?'Required':'Not required by this configuration'?></li>
        <li>AI assistance: <?=h(sponsored_project_detail_policy_label((string)$d['ai_assistance_policy']))?></li>
        <li>Additional training-data consent: <?=($d['training_use_request']==='optional_separate_consent')?'May be requested separately':'Not requested'?></li>
      </ul>
      <p class="sponsoredDetailHint">Participation is subject to the campaign's current published terms. Agreeing to participate does not grant AI training or evaluation rights.</p>
      <?php if($role==='sponsor'):?><a class="button secondary" href="/sponsored-research.php?campaign=<?=rawurlencode((string)$p['id'])?>">Manage terms and disclosures</a><?php endif?>
    <?php endif?>
  </section>
</div>
<aside class="sponsoredDetailRail">
  <nav class="sponsoredDetailCard" aria-label="Project sections"><strong>On this page</strong><a href="#overview">Overview</a><a href="#specifications">Specifications</a><a href="#requirements">Requirements</a><a href="#deliverables">Workflow and outputs</a><?php if($specs['milestones']):?><a href="#milestones">Milestones</a><?php endif?><a href="#governance">Governance</a></nav>
  <div class="sponsoredDetailCard"><strong>Project tools</strong>
  <?php if($sample):?><p>Explore this sample project without creating data.</p><a href="/research-projects.php">Browse project listings</a>
  <?php elseif($role==='sponsor'):?>
    <a href="/sponsored-research.php?campaign=<?=rawurlencode((string)$p['id'])?>">Edit brief & project terms</a>
    <a href="/sponsored-research.php?campaign=<?=rawurlencode((string)$p['id'])?>">Participants & invitations</a>
    <a href="/sponsored-research.php?campaign=<?=rawurlencode((string)$p['id'])?>">Review & finance</a>
  <?php elseif($role==='researcher'):?><a href="/research-sponsored-projects.php#project-<?=rawurlencode((string)$p['id'])?>">Open researcher workflow</a><a href="/research-account.php">Research Account</a>
  <?php else:?><a href="/research-projects.php">Browse projects</a><?php if($viewer):?><a href="/research-account.php">Research Account</a><?php endif?>
  <?php endif?>
  </div>
</aside>
</div>
</main>
</body></html>
