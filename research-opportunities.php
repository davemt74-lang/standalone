<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');$error='';$success='';
if(!sponsored_research_participation_ready($pdo)){http_response_code(503);exit('Sponsored Research participation requires the latest database upgrade.');}
try{$researchProfile=research_account_require_approved($pdo,$u);}catch(Throwable $e){$researchProfile=null;$error=$e->getMessage();}
if($_SERVER['REQUEST_METHOD']==='POST'&&$researchProfile){
 require_csrf();
 try{
  $op=(string)($_POST['op']??'');
  if($op==='join'){sponsored_research_campaign_join($pdo,$u,(string)$_POST['campaign_id'],[
    'accept_terms'=>isset($_POST['accept_terms']),
    'conflict_disclosure'=>(string)($_POST['conflict_disclosure']??''),
    'accept_nda'=>isset($_POST['accept_nda']),
    'acknowledge_sponsorship'=>isset($_POST['acknowledge_sponsorship'])
  ]);$success='You joined the Sponsored Research campaign.';$_GET['campaign']=(string)$_POST['campaign_id'];}
  elseif($op==='withdraw'){sponsored_research_campaign_withdraw($pdo,$u,(string)$_POST['campaign_id'],(string)($_POST['reason']??''));$success='You withdrew from the campaign.';$_GET['campaign']=(string)$_POST['campaign_id'];}
  elseif($op==='dispute_open'){
    $caseId=(string)($_POST['review_id']??'');sponsored_research_dispute_open($pdo,$u,$caseId,(string)($_POST['dispute_reason']??''),[]);
    $success='Dispute opened for review.';$_GET['campaign']=(string)$_POST['campaign_id'];
  }
  elseif(in_array($op,['submission_save','submission_submit'],true)){
    $draft=sponsored_research_submission_draft($pdo,$u,(string)$_POST['campaign_id'],['title'=>(string)($_POST['submission_title']??''),'summary'=>(string)($_POST['submission_summary']??''),'methodology'=>(string)($_POST['methodology']??''),'limitations'=>(string)($_POST['limitations']??'')]);
    if($op==='submission_submit'){$draft=sponsored_research_submission_submit($pdo,$u,(string)$draft['public_id'],(string)($_POST['assets']??''));$success='Sponsored Research submission sent.';}else{$success='Submission draft saved.';}
    $_GET['campaign']=(string)$_POST['campaign_id'];
  }
 }catch(Throwable $e){$error=$e->getMessage();}
}
$opportunities=$researchProfile?sponsored_research_campaign_opportunities($pdo,$u,100):[];$selected=null;$eligibility=null;$terms=null;$participation=null;$submission=null;$participationStale=false;$reviewCase=null;
if($researchProfile&&!empty($_GET['campaign'])){
 $selected=sponsored_research_campaign_by_public($pdo,(string)$_GET['campaign']);
 if($selected&&sponsored_research_campaign_visible_to_researcher($pdo,$u,$selected)){
   $eligibility=sponsored_research_campaign_eligibility_check($pdo,$u,$selected);
   $terms=sponsored_research_campaign_terms_latest($pdo,(int)$selected['id']);
   $participation=sponsored_research_participation_get($pdo,(int)$selected['id'],(int)$u['id']);
   if($participation){$submission=sponsored_research_submission_for_participation($pdo,(int)$participation['id']);$participationStale=(int)$participation['campaign_revision_accepted']!==(int)$selected['current_revision']||($terms&&(int)$participation['terms_id']!==(int)$terms['id']);if($submission)$reviewCase=sponsored_research_review_case_for_submission($pdo,(int)$submission['id']);}
 }else{$selected=null;$error='This Sponsored Research campaign is not available to you.';}
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Research Opportunities · Annotated</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="panel" style="max-width:1200px;margin:32px auto"><div class="pageTitle"><span class="eyebrow">SPONSORED RESEARCH</span><h1>Research Opportunities</h1><p>Paid campaigns are available only to approved Research Accounts that meet each campaign’s eligibility and access rules.</p></div>
<?php if($error):?><div class="error"><?=h($error)?></div><?php endif?><?php if($success):?><div class="success"><?=h($success)?></div><?php endif?>
<section class="card"><h2>Available campaigns</h2>
<?php if(!$opportunities):?><p>No eligible or invited Sponsored Research campaigns are currently available.</p><?php else:?><table><thead><tr><th>Campaign</th><th>Sponsor</th><th>Deadline</th><th>Eligibility</th></tr></thead><tbody><?php foreach($opportunities as $c):?><tr><td><a href="/research-opportunities.php?campaign=<?=rawurlencode((string)$c['public_id'])?>"><strong><?=h((string)$c['title'])?></strong></a><div class="meta"><?=h((string)$c['access_mode'])?> · <?=h((string)$c['budget_currency'])?> <?=number_format(((int)$c['budget_cents'])/100,2)?></div></td><td><?=h((string)($c['organization_name']?:$c['account_name']))?></td><td><?=h((string)($c['submission_deadline']??'Open'))?></td><td><?=!empty($c['eligibility_result']['eligible'])?'Eligible':'Requirements not met'?></td></tr><?php endforeach?></tbody></table><?php endif?></section>
<?php if($selected):?><section class="card"><span class="eyebrow">CAMPAIGN</span><h2><?=h((string)$selected['title'])?></h2><p><?=nl2br(h((string)$selected['brief']))?></p><p><strong>Objective:</strong> <?=h((string)$selected['objective'])?></p>
<h3>Research questions</h3><ol><?php foreach($selected['questions'] as $q):?><li><?=h((string)$q['question'])?></li><?php endforeach?></ol>
<p><strong>Eligibility:</strong> <?=!empty($eligibility['eligible'])?'Eligible':'Not currently eligible'?></p><?php if(!empty($eligibility['reasons'])):?><p class="meta"><?=h(implode(', ',$eligibility['reasons']))?></p><?php endif?>
<?php if($participation&&$participation['status']==='active'&&!$participationStale):?><div class="success">Active participant · terms v<?=h((string)$participation['terms_version'])?> · campaign revision <?=h((string)$participation['campaign_revision_accepted'])?></div>
<?php if(!$submission||in_array((string)$submission['status'],['draft','revision_requested'],true)):?><h3>Research submission</h3><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="campaign_id" value="<?=h((string)$selected['public_id'])?>">
<label>Submission title<input name="submission_title" required maxlength="255" value="<?=h((string)($submission['title']??''))?>"></label>
<label>Summary<textarea name="submission_summary" rows="6" required><?=h((string)($submission['summary']??''))?></textarea></label>
<label>Methodology<textarea name="methodology" rows="5"><?=h((string)($submission['methodology']??''))?></textarea></label>
<label>Limitations<textarea name="limitations" rows="4"><?=h((string)($submission['limitations']??''))?></textarea></label>
<label>Research assets<textarea name="assets" rows="8" placeholder="annotation:PUBLIC_ID&#10;source:PUBLIC_ID&#10;finding:PUBLIC_ID&#10;report_version:PUBLIC_ID&#10;mission:PUBLIC_ID&#10;dataset:PUBLIC_ID"></textarea></label>
<p class="meta">Each submitted asset is snapshotted and hashed. Sources retain separate rights metadata. Only completed Missions and frozen Datasets are accepted.</p>
<button name="op" value="submission_save">Save draft</button><button name="op" value="submission_submit">Submit immutable revision</button></form>
<?php else:?><div class="card"><strong>Submission <?=h((string)$submission['status'])?></strong><div class="meta">Revision <?=h((string)$submission['current_revision'])?> · <?=h(substr((string)($submission['latest_snapshot_hash']??''),0,12))?>…</div>
<?php if($reviewCase):?><div class="meta">Review: <?=h((string)$reviewCase['status'])?><?php if($reviewCase['final_decision']):?> · decision <?=h((string)$reviewCase['final_decision'])?><?php endif?></div><?php if($reviewCase['decision_note']):?><p><?=nl2br(h((string)$reviewCase['decision_note']))?></p><?php endif?>
<?php if(in_array((string)$reviewCase['status'],['accepted','rejected','revision_requested'],true)):?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="op" value="dispute_open"><input type="hidden" name="campaign_id" value="<?=h((string)$selected['public_id'])?>"><input type="hidden" name="review_id" value="<?=h((string)$reviewCase['public_id'])?>"><label>Dispute or appeal reason<textarea name="dispute_reason" rows="4" required></textarea></label><button>Open dispute</button></form><?php endif?><?php endif?></div><?php endif?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="op" value="withdraw"><input type="hidden" name="campaign_id" value="<?=h((string)$selected['public_id'])?>"><label>Withdrawal reason<input name="reason" maxlength="1000"></label><button>Withdraw from campaign</button></form>
<?php elseif($terms&&$eligibility&&!empty($eligibility['eligible'])):?><?php if($participationStale):?><div class="error">The campaign or participation terms changed. Re-accept the current terms before submitting research.</div><?php endif?><h3>Participation terms v<?=h((string)$terms['version_number'])?></h3><div class="card"><?=nl2br(h((string)$terms['terms_text']))?></div>
<form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="op" value="join"><input type="hidden" name="campaign_id" value="<?=h((string)$selected['public_id'])?>">
<?php if(!empty($terms['requires_conflict_disclosure'])):?><label>Conflict-of-interest disclosure<textarea name="conflict_disclosure" rows="4" required></textarea></label><?php endif?>
<label><input type="checkbox" name="accept_terms" value="1" required> I accept these campaign participation terms.</label>
<?php if(!empty($selected['disclosures']['sponsorship_disclosure_required'])):?><label><input type="checkbox" name="acknowledge_sponsorship" value="1" required> I acknowledge this work is sponsored research.</label><?php endif?>
<?php if(!empty($terms['requires_nda'])):?><label><input type="checkbox" name="accept_nda" value="1" required> I accept the campaign NDA requirement.</label><?php endif?>
<p class="meta">Joining this campaign does not grant model training or evaluation rights. Those permissions remain governed separately by Data &amp; Attribution.</p><button>Join campaign</button></form>
<?php elseif(!$terms):?><p class="meta">The sponsor has not yet published participation terms for the current campaign revision.</p><?php endif?>
</section><?php endif?>
</main></body></html>