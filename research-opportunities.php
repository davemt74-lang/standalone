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
 }catch(Throwable $e){$error=$e->getMessage();}
}
$opportunities=$researchProfile?sponsored_research_campaign_opportunities($pdo,$u,100):[];$selected=null;$eligibility=null;$terms=null;$participation=null;
if($researchProfile&&!empty($_GET['campaign'])){
 $selected=sponsored_research_campaign_by_public($pdo,(string)$_GET['campaign']);
 if($selected&&sponsored_research_campaign_visible_to_researcher($pdo,$u,$selected)){
   $eligibility=sponsored_research_campaign_eligibility_check($pdo,$u,$selected);
   $terms=sponsored_research_campaign_terms_latest($pdo,(int)$selected['id']);
   $participation=sponsored_research_participation_get($pdo,(int)$selected['id'],(int)$u['id']);
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
<?php if($participation&&$participation['status']==='active'):?><div class="success">Active participant · terms v<?=h((string)$participation['terms_version'])?> · campaign revision <?=h((string)$participation['campaign_revision_accepted'])?></div>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="op" value="withdraw"><input type="hidden" name="campaign_id" value="<?=h((string)$selected['public_id'])?>"><label>Withdrawal reason<input name="reason" maxlength="1000"></label><button>Withdraw from campaign</button></form>
<?php elseif($terms&&$eligibility&&!empty($eligibility['eligible'])):?><h3>Participation terms v<?=h((string)$terms['version_number'])?></h3><div class="card"><?=nl2br(h((string)$terms['terms_text']))?></div>
<form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="op" value="join"><input type="hidden" name="campaign_id" value="<?=h((string)$selected['public_id'])?>">
<?php if(!empty($terms['requires_conflict_disclosure'])):?><label>Conflict-of-interest disclosure<textarea name="conflict_disclosure" rows="4" required></textarea></label><?php endif?>
<label><input type="checkbox" name="accept_terms" value="1" required> I accept these campaign participation terms.</label>
<?php if(!empty($selected['disclosures']['sponsorship_disclosure_required'])):?><label><input type="checkbox" name="acknowledge_sponsorship" value="1" required> I acknowledge this work is sponsored research.</label><?php endif?>
<?php if(!empty($terms['requires_nda'])):?><label><input type="checkbox" name="accept_nda" value="1" required> I accept the campaign NDA requirement.</label><?php endif?>
<p class="meta">Joining this campaign does not grant model training or evaluation rights. Those permissions remain governed separately by Data &amp; Attribution.</p><button>Join campaign</button></form>
<?php elseif(!$terms):?><p class="meta">The sponsor has not yet published participation terms for the current campaign revision.</p><?php endif?>
</section><?php endif?>
</main></body></html>