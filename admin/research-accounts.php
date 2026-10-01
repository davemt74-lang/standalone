<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$admin=require_admin($pdo);$success='';$error='';
if(!research_accounts_ready($pdo)){http_response_code(503);exit('Research Account governance requires the latest database upgrade.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
 require_csrf();
 try{
  $uid=(int)($_POST['user_id']??0);$authority=(string)($_POST['authority']??'');$status=(string)($_POST['status']??'');$reason=trim((string)($_POST['reason']??''));$verification=(string)($_POST['verification_level']??'basic');
  research_account_admin_decide($pdo,$admin,$uid,$authority,$status,$reason,$verification);$success=ucwords(str_replace('_',' ',$authority)).' updated.';
 }catch(Throwable $e){$error=$e->getMessage();}
}
$rows=$pdo->query("SELECT u.id,u.public_id,u.username,u.display_name,u.email,u.status user_status,
 r.status research_status,r.verification_level research_verification,r.marketplace_visible,r.payout_readiness,r.applied_at research_applied_at,r.decision_reason research_reason,
 s.status sponsor_status,s.verification_level sponsor_verification,s.organization_name,s.applied_at sponsor_applied_at,s.decision_reason sponsor_reason
 FROM users u LEFT JOIN research_account_profiles r ON r.user_id=u.id LEFT JOIN sponsor_account_profiles s ON s.user_id=u.id
 WHERE r.id IS NOT NULL OR s.id IS NOT NULL ORDER BY COALESCE(r.updated_at,s.updated_at) DESC,u.id DESC LIMIT 500")->fetchAll();
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Research Accounts · Annotated Admin</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><?=admin_ui_sidebar('research_accounts')?>
<main class="panel"><div class="pageTitle"><span class="eyebrow">PHASE 80 · GOVERNANCE</span><h1>Research & Sponsor Accounts</h1><p>Paid research and sponsor campaign authority are explicitly approved here. Research approval never grants sponsor authority, and sponsor approval never grants paid-research participation.</p></div>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<div class="tableWrap"><table><thead><tr><th>User</th><th>Research Account</th><th>Sponsor Account</th></tr></thead><tbody>
<?php foreach($rows as $x):?><tr><td><strong><?=h((string)$x['display_name'])?></strong><div class="meta">@<?=h((string)$x['username'])?> · <?=h((string)$x['email'])?></div><div class="meta">User: <?=h((string)$x['user_status'])?></div></td>
<td><?php if($x['research_status']):?><strong><?=h((string)$x['research_status'])?></strong><div class="meta"><?=h((string)$x['research_verification'])?> verification · payout <?=h((string)$x['payout_readiness'])?></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="user_id" value="<?=h((string)$x['id'])?>"><input type="hidden" name="authority" value="research_account"><select name="verification_level"><option>basic</option><option>identity</option><option>qualification</option><option>organization</option><option>payout</option></select><select name="status"><option value="approved">Approve</option><option value="suspended">Suspend</option><option value="revoked">Revoke</option></select><input name="reason" maxlength="500" placeholder="Decision reason"><button>Apply</button></form><?php else:?>—<?php endif?></td>
<td><?php if($x['sponsor_status']):?><strong><?=h((string)$x['sponsor_status'])?></strong><?php if($x['organization_name']):?><div class="meta"><?=h((string)$x['organization_name'])?></div><?php endif?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="user_id" value="<?=h((string)$x['id'])?>"><input type="hidden" name="authority" value="sponsor_account"><select name="verification_level"><option>basic</option><option>identity</option><option>organization</option><option>billing</option></select><select name="status"><option value="approved">Approve</option><option value="suspended">Suspend</option><option value="revoked">Revoke</option></select><input name="reason" maxlength="500" placeholder="Decision reason"><button>Apply</button></form><?php else:?>—<?php endif?></td></tr><?php endforeach?>
</tbody></table></div></main></body></html>
