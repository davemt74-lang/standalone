<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';$u=require_user($pdo);$id=trim((string)($_GET['id']??$_POST['id']??''));$success='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){require_csrf();try{
    $event=research_verification_record($pdo,$u,(string)($_POST['subject_type']??'claim'),(string)($_POST['subject_id']??''),(string)($_POST['decision']??''),(string)($_POST['note']??''));
    $success='Verification review recorded. Prior review events remain immutable.';
}catch(Throwable $e){$error=$e->getMessage();}}
$project=$id!==''?project_access($pdo,(int)$u['id'],$id):null;
if($id!==''&&!$project){http_response_code(404);exit('Research project not found.');}
if($project){
    $summary=research_verification_ready($pdo)?research_verification_project_summary($pdo,$u,$id,250):['available'=>false,'claims'=>[]];
    $events=research_verification_ready($pdo)?research_verification_project_events($pdo,$u,$id,80):[];
    $canWrite=in_array((string)($project['access_role']??''),['owner','admin','researcher'],true);
}else{
    $q=$pdo->prepare("SELECT DISTINCT rp.public_id,rp.title,rp.updated_at FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE rp.owner_user_id=? OR tm.user_id=? ORDER BY rp.updated_at DESC LIMIT 80");$q->execute([$u['id'],$u['id'],$u['id']]);$projects=$q->fetchAll();
}
function rv_label(string $v): string {return ucwords(str_replace('_',' ',$v));}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Research Verification · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<header class="topbar"><a class="brand" href="/home.php">Annotated</a><nav><?php if($project):?><a href="/research-project.php?id=<?=h($project['public_id'])?>">Project</a><a href="/research-provenance.php?id=<?=h($project['public_id'])?>">Provenance</a><?php endif?><a href="/research-reviews.php">Reviews</a><a href="/research.php">Research</a></nav></header>
<?php if(!$project):?>
<main class="verificationHub"><section><div class="pageTitle"><span class="eyebrow">PHASE 27 · RESEARCH VERIFICATION</span><h1>Evidence review center</h1><p>Inspect transparent evidence signals across Research projects. Annotated reports support, contradiction, source diversity, freshness, recorded-integrity coverage, and human review history. These signals are not a truth score.</p></div>
<?php if(!research_verification_ready($pdo)):?><div class="error">Database upgrade 034 is required.</div><?php endif?>
<div class="verificationProjectList"><?php foreach($projects as $p):?><?php $s=research_verification_ready($pdo)?research_verification_project_summary($pdo,$u,(string)$p['public_id'],120):null;?><article class="card verificationProjectCard"><div><div class="meta">UPDATED <?=h((string)$p['updated_at'])?></div><h2><?=h((string)$p['title'])?></h2><?php if($s):?><p class="meta"><?=h((string)$s['total_claims'])?> Claims · <?=h((string)$s['needs_attention'])?> need attention · <?=h((string)$s['contested'])?> contested · <?=h((string)$s['stale'])?> stale/mixed · <?=h((string)$s['reviewed_current'])?> human-reviewed current</p><?php endif?></div><a class="button" href="/research-verification.php?id=<?=h((string)$p['public_id'])?>">Open verification</a></article><?php endforeach?></div>
<?php if(!$projects):?><div class="card empty">No accessible Research projects.</div><?php endif?></section><aside class="stickyVerificationRail"><div class="card"><h3>Verification boundary</h3><p class="meta">Corroboration means multiple evidence sources/domains are present. Human review means a person reviewed a specific evidence state. Neither means Annotated has proven the Claim true.</p></div></aside></main>
<?php else:?>
<main class="verificationLayout"><section><div class="pageTitle"><span class="eyebrow">PHASE 27 · EVIDENCE REVIEW</span><h1><?=h((string)$project['title'])?></h1><p>Review Claim-level evidence signals without collapsing them into a single opaque rating. Source quality here means inspectable structural signals—availability, moderation state, version currency, change history, and recorded hash coverage—not a judgment about a publisher.</p><div class="inlineActions"><a class="button secondary" href="/research-provenance.php?id=<?=h((string)$project['public_id'])?>">Open provenance</a><a class="button secondary" href="/research-reviews.php">Collaborative reviews</a></div></div>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<?php if(empty($summary['available'])):?><div class="error">Database upgrade 034 is required.</div><?php else:?>
<div class="verificationSummary">
<div class="card"><strong><?=h((string)$summary['total_claims'])?></strong><span>Claims</span></div>
<div class="card"><strong><?=h((string)$summary['needs_attention'])?></strong><span>Need attention</span></div>
<div class="card"><strong><?=h((string)$summary['distinct_domain_support'])?></strong><span>Distinct-domain support</span></div>
<div class="card"><strong><?=h((string)$summary['contested'])?></strong><span>Contested</span></div>
<div class="card"><strong><?=h((string)$summary['stale'])?></strong><span>Stale / mixed evidence</span></div>
<div class="card"><strong><?=h((string)$summary['reviewed_current'])?></strong><span>Human-reviewed current</span></div>
</div>
<div class="verificationBoundary card"><strong>Interpretation boundary</strong><p>These are evidence-state signals, not truth certification. “Distinct domains” is a source-diversity signal, not proof that sources are editorially independent. “Reviewed current” records a human review at a specific evidence state.</p></div>
<section class="verificationClaims"><?php if(!$summary['claims']):?><div class="card empty">No Claims yet.</div><?php endif?>
<?php foreach($summary['claims'] as $c):?><?php $cl=$c['claim'];?>
<article class="card verificationClaim <?=$c['attention']?'verificationAttention':''?>">
<div class="verificationClaimHead"><div><div class="meta"><?=h(strtoupper((string)$cl['claim_type']))?> · CLAIM <?=h((string)$cl['public_id'])?></div><h2><a href="/research-claim.php?id=<?=h((string)$cl['public_id'])?>"><?=h((string)$cl['statement'])?></a></h2></div><?php if($c['attention']):?><span class="badge">Needs attention</span><?php endif?></div>
<div class="verificationSignals">
<div><span>Evidence</span><strong><?=h(rv_label((string)$c['evidence_state']))?></strong></div>
<div><span>Corroboration</span><strong><?=h(rv_label((string)$c['corroboration']))?></strong></div>
<div><span>Freshness</span><strong><?=h(rv_label((string)$c['freshness']))?></strong></div>
<div><span>Human review</span><strong><?=h(rv_label((string)$c['human_review']['state']))?></strong></div>
<div><span>Recorded integrity</span><strong><?=h(rv_label((string)$c['integrity']))?></strong></div>
<div><span>Agent use</span><strong><?=h(rv_label((string)$c['agent_use']))?></strong></div>
</div>
<?php if($c['attention_reasons']):?><p class="meta">Attention: <?=h(implode(' · ',array_map('rv_label',$c['attention_reasons'])))?></p><?php endif?>
<details class="verificationSources"><summary>Source & evidence signals (<?=h((string)$c['counts']['available'])?> accessible)</summary>
<?php foreach($c['sources'] as $s):?><?php if(empty($s['available'])):?><div class="verificationSource"><span class="meta">Evidence exists but its Source is not available to your current access scope.</span></div><?php else:?><div class="verificationSource"><div><strong><?=h((string)($s['source_title']?:$s['domain']))?></strong><span><?=h(strtoupper((string)$s['relationship']))?> · Source v<?=h((string)$s['source_version'])?> · <?=h((string)$s['domain'])?></span></div><div class="meta"><?= $s['fresh']?'CURRENT EVIDENCE':'REVIEW FRESHNESS' ?><?php if($s['flags']):?> · <?=h(implode(' · ',array_map('rv_label',$s['flags'])))?><?php endif?></div></div><?php endif?><?php endforeach?>
</details>
<?php if($c['human_review']['current']||$c['human_review']['stale']):?><details><summary>Human review history</summary><?php foreach($c['human_review']['current'] as $ev):?><p class="<?=empty($ev['is_effective'])?'meta':''?>"><strong><?=h((string)$ev['reviewer']['name'])?></strong> · <?=h(rv_label((string)$ev['decision']))?> · <?=h((string)$ev['created_at'])?><?=empty($ev['is_effective'])?' · SUPERSEDED BY A NEWER DECISION':''?><?php if($ev['note']):?><br><?=nl2br(h((string)$ev['note']))?><?php endif?></p><?php endforeach?><?php foreach($c['human_review']['stale'] as $ev):?><p class="meta"><strong><?=h((string)$ev['reviewer']['name'])?></strong> · prior <?=h(rv_label((string)$ev['decision']))?> · stale after Research/evidence changed · <?=h((string)$ev['created_at'])?></p><?php endforeach?></details><?php endif?>
<?php if($canWrite):?><form method="post" class="verificationReviewForm"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="id" value="<?=h((string)$project['public_id'])?>"><input type="hidden" name="subject_type" value="claim"><input type="hidden" name="subject_id" value="<?=h((string)$cl['public_id'])?>"><label>Human review decision<select name="decision"><?php foreach(research_verification_decisions() as $v=>$label):?><option value="<?=h($v)?>"><?=h($label)?></option><?php endforeach?></select></label><label>Review note<textarea name="note" rows="2" placeholder="What did you inspect? What remains uncertain?"></textarea></label><button>Record immutable review event</button></form><?php endif?>
</article><?php endforeach?></section>
<?php endif?></section>
<aside class="stickyVerificationRail"><div class="card"><h3>What Agent sees</h3><p class="meta">Agent Chat receives the same permission-filtered evidence states and an explicit instruction not to present unsupported, contested, stale, restricted, or human-disputed Research as established fact.</p></div><div class="card"><h3>Recent verification events</h3><?php foreach(array_slice($events,0,12) as $ev):?><p><strong><?=h(rv_label((string)$ev['decision']))?></strong><br><span class="meta"><?=h((string)$ev['subject_type'])?> <?=h((string)$ev['subject_public_id'])?> · <?=h((string)$ev['reviewer_name'])?> · <?=h((string)$ev['created_at'])?><?=$ev['is_current']?'':' · STALE'?></span></p><?php endforeach?><?php if(!$events):?><p class="meta">No human verification events yet.</p><?php endif?></div></aside></main>
<?php endif?>
</body></html>
