<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');$error='';$success='';
if(!sponsored_research_campaigns_ready($pdo)){http_response_code(503);exit('Sponsored Research campaigns require the latest database upgrade.');}
try{$sponsor=sponsor_account_require_approved($pdo,$u);}catch(Throwable $e){$sponsor=null;$error=$e->getMessage();}
$accounts=$sponsor?account_membership_accounts_for_user($pdo,$u,true):[];$agents=$sponsor&&function_exists('research_agent_list')?research_agent_list($pdo,$u,100):[];
if($_SERVER['REQUEST_METHOD']==='POST'&&$sponsor){
 require_csrf();
 try{
  $op=(string)($_POST['op']??'create');
  if($op==='create'){
   $c=sponsored_research_campaign_create($pdo,$u,[
    'account_id'=>(string)($_POST['account_id']??''),'research_agent_id'=>(string)($_POST['research_agent_id']??''),
    'title'=>(string)($_POST['title']??''),'brief'=>(string)($_POST['brief']??''),'objective'=>(string)($_POST['objective']??''),
    'questions'=>(string)($_POST['questions']??''),'access_mode'=>(string)($_POST['access_mode']??'private'),
    'budget_currency'=>(string)($_POST['budget_currency']??'USD'),'budget_cents'=>(int)round(((float)($_POST['budget_amount']??0))*100),
    'max_participants'=>(int)($_POST['max_participants']??0),'starts_at'=>(string)($_POST['starts_at']??''),'submission_deadline'=>(string)($_POST['submission_deadline']??''),
    'eligibility'=>array_filter(['minimum_verification'=>(string)($_POST['minimum_verification']??''),'required_specialties'=>research_account_json_list($_POST['required_specialties']??'')]),
    'disclosures'=>array_filter(['sponsor_disclosure'=>(string)($_POST['sponsor_disclosure']??''),'conflict_disclosure_required'=>isset($_POST['conflict_disclosure_required'])])
   ]);$success='Sponsored Research campaign created.';$_GET['campaign']=$c['public_id'];
  }elseif($op==='status'){sponsored_research_campaign_set_status($pdo,$u,(string)$_POST['campaign_id'],(string)$_POST['status']);$success='Campaign status updated.';}
 }catch(Throwable $e){$error=$e->getMessage();}
}
$campaigns=$sponsor?sponsored_research_campaign_list($pdo,$u,100):[];$selected=null;$versions=[];
if($sponsor&&!empty($_GET['campaign'])){try{$selected=sponsored_research_campaign_require_manage($pdo,$u,(string)$_GET['campaign']);$versions=sponsored_research_campaign_versions($pdo,$u,(string)$_GET['campaign']);}catch(Throwable $e){$error=$e->getMessage();}}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sponsored Research · Annotated</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="panel" style="max-width:1200px;margin:32px auto"><div class="pageTitle"><span class="eyebrow">PHASE 80 · SPONSORED RESEARCH</span><h1>Sponsored Research Campaigns</h1><p>Create governed research briefs that reuse existing Accounts, Research Agents, Missions, billing, and provenance systems.</p></div>
<?php if($error):?><div class="error"><?=h($error)?></div><?php endif?><?php if($success):?><div class="success"><?=h($success)?></div><?php endif?>
<?php if($sponsor&&$accounts):?>
<section class="card"><h2>New campaign</h2><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="op" value="create">
<label>Commercial account<select name="account_id" required><?php foreach($accounts as $a):?><option value="<?=h((string)$a['public_id'])?>"><?=h((string)$a['name'])?></option><?php endforeach?></select></label>
<label>Research Agent<select name="research_agent_id"><option value="">No Agent yet</option><?php foreach($agents as $a):?><option value="<?=h((string)$a['public_id'])?>"><?=h((string)$a['name'])?></option><?php endforeach?></select></label>
<label>Campaign title<input name="title" maxlength="255" required></label><label>Research brief<textarea name="brief" rows="5" required></textarea></label><label>Objective<textarea name="objective" rows="4" required></textarea></label><label>Research questions<textarea name="questions" rows="6" placeholder="One question per line"></textarea></label>
<div class="researchAgentEditSplit"><label>Access<select name="access_mode"><option value="private">Private</option><option value="invite_only">Invite only</option><option value="public">Public</option></select></label><label>Currency<input name="budget_currency" value="USD" maxlength="3"></label><label>Budget<input type="number" name="budget_amount" min="0" step="0.01" value="0"></label><label>Max participants<input type="number" name="max_participants" min="1"></label></div>
<div class="researchAgentEditSplit"><label>Starts<input type="datetime-local" name="starts_at"></label><label>Submission deadline<input type="datetime-local" name="submission_deadline"></label><label>Minimum verification<select name="minimum_verification"><option value="">Any approved Research Account</option><option value="identity">Identity</option><option value="qualification">Qualification</option><option value="organization">Organization</option><option value="payout">Payout ready</option></select></label></div>
<label>Required specialties<input name="required_specialties" placeholder="Energy, software, market research"></label><label>Sponsor disclosure<textarea name="sponsor_disclosure" rows="3"></textarea></label><label><input type="checkbox" name="conflict_disclosure_required" value="1"> Require conflict-of-interest disclosure</label><button>Create campaign</button></form></section>
<?php endif?>
<section class="card"><h2>Your sponsored campaigns</h2><?php if(!$campaigns):?><p>No sponsored campaigns yet.</p><?php else:?><table><thead><tr><th>Campaign</th><th>Status</th><th>Access</th><th>Budget</th></tr></thead><tbody><?php foreach($campaigns as $c):?><tr><td><a href="/sponsored-research.php?campaign=<?=rawurlencode((string)$c['public_id'])?>"><strong><?=h((string)$c['title'])?></strong></a><div class="meta"><?=h((string)$c['account_name'])?><?php if($c['research_agent_name']):?> · <?=h((string)$c['research_agent_name'])?><?php endif?></div></td><td><?=h((string)$c['status'])?></td><td><?=h((string)$c['access_mode'])?></td><td><?=h((string)$c['budget_currency'])?> <?=number_format(((int)$c['budget_cents'])/100,2)?></td></tr><?php endforeach?></tbody></table><?php endif?></section>
<?php if($selected):?><section class="card"><span class="eyebrow">CAMPAIGN DETAIL</span><h2><?=h((string)$selected['title'])?></h2><p><?=nl2br(h((string)$selected['brief']))?></p><p><strong>Objective:</strong> <?=h((string)$selected['objective'])?></p><h3>Questions</h3><ol><?php foreach($selected['questions'] as $q):?><li><?=h((string)$q['question'])?></li><?php endforeach?></ol><p class="meta">Revision <?=h((string)$selected['current_revision'])?> · Config <?=h(substr((string)$selected['config_hash'],0,12))?>…</p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="op" value="status"><input type="hidden" name="campaign_id" value="<?=h((string)$selected['public_id'])?>"><select name="status"><?php foreach(sponsored_research_campaign_statuses() as $k=>$label):?><option value="<?=h($k)?>" <?=$selected['status']===$k?'selected':''?>><?=h($label)?></option><?php endforeach?></select><button>Update status</button></form><h3>Version history</h3><?php foreach($versions as $v):?><div class="meta">Revision <?=h((string)$v['revision_number'])?> · <?=h((string)$v['created_at'])?> · <?=h(substr((string)$v['config_hash'],0,12))?>…</div><?php endforeach?></section><?php endif?>
</main></body></html>