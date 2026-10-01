<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$user=require_login($pdo);$success='';$error='';
if(!research_accounts_ready($pdo)){http_response_code(503);exit('Research Account governance requires the latest database upgrade.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
 require_csrf();
 try{
  $action=(string)($_POST['action']??'');
  if($action==='research_apply'){research_account_apply($pdo,$user,$_POST);$success='Research Account application submitted for approval.';}
  elseif($action==='sponsor_apply'){sponsor_account_apply($pdo,$user,$_POST);$success='Sponsor Account application submitted for approval.';}
  else throw new RuntimeException('Unknown account action.');
 }catch(Throwable $e){$error=$e->getMessage();}
}
$research=research_account_profile($pdo,(int)$user['id']);$sponsor=sponsor_account_profile($pdo,(int)$user['id']);$eligibility=research_account_eligibility_snapshot($pdo,$user);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Research Account · Annotated</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="panel" style="max-width:1100px;margin:32px auto"><div class="pageTitle"><span class="eyebrow">SPONSORED RESEARCH</span><h1>Research & Sponsor Account</h1><p>Paid research requires an explicitly approved Research Account. Sponsoring research uses a separate approval authority.</p></div>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<section class="card"><h2>Research Account</h2><p>Status: <strong><?=h((string)($research['status']??'not applied'))?></strong><?php if($research):?> · Verification: <?=h((string)$research['verification_level'])?><?php endif?></p>
<?php if(!$research||in_array((string)$research['status'],['pending','revoked'],true)):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="research_apply"><label>Specialties<input name="specialties" value="<?=h(implode(', ',$research['specialties']??[]))?>" placeholder="Market research, energy, software"></label><label>Languages<input name="languages" value="<?=h(implode(', ',$research['languages']??[]))?>" placeholder="English, Spanish"></label><label>Research biography<textarea name="biography" rows="5"><?=h((string)($research['biography']??''))?></textarea></label><button>Submit Research Account application</button></form><?php else:?><p>Your paid-research authority is managed by Annotated Admin. Ordinary Annotated research features are unaffected.</p><?php if((string)$research['status']==='approved'):?><a class="button" href="/research-opportunities.php">Open Research Opportunities</a><?php endif?><?php endif?></section>
<section class="card"><h2>Sponsor Account</h2><p>Status: <strong><?=h((string)($sponsor['status']??'not applied'))?></strong><?php if($sponsor):?> · Verification: <?=h((string)$sponsor['verification_level'])?><?php endif?></p>
<?php if(!$sponsor||in_array((string)$sponsor['status'],['pending','revoked'],true)):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="sponsor_apply"><label>Organization<input name="organization_name" maxlength="190" value="<?=h((string)($sponsor['organization_name']??''))?>"></label><label>Website<input name="website_url" maxlength="500" value="<?=h((string)($sponsor['website_url']??''))?>" placeholder="https://"></label><button>Submit Sponsor Account application</button></form><?php else:?><p>Sponsor authority is independent from Research Account approval.</p><?php if((string)$sponsor['status']==='approved'):?><a class="button" href="/sponsored-research.php">Open Sponsored Research</a><?php endif?><?php endif?></section>
</main></body></html>
