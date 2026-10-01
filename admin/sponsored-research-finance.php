<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$admin=require_admin($pdo);header('Cache-Control: private, no-store');$success='';$error='';
if(!sponsored_research_finance_ready($pdo)){http_response_code(503);exit('Sponsored Research finance requires migration 121.');}
if(function_exists('admin_access_assert_capability'))admin_access_assert_capability($pdo,$admin,$_SERVER['REQUEST_METHOD']==='POST'?'admin.finance.manage':'admin.finance.view');
if($_SERVER['REQUEST_METHOD']==='POST'){require_csrf();try{
  $op=(string)($_POST['op']??'');
  if($op==='settings'){sponsored_research_finance_settings_update($pdo,$admin,(int)($_POST['platform_fee_bps']??0));$success='Sponsored Research finance settings saved.';}
  elseif($op==='funding'){
    $amount=(int)round(((float)($_POST['amount']??0))*100);sponsored_research_finance_record_funding($pdo,$admin,(string)$_POST['campaign_id'],$amount,'manual_admin',(string)($_POST['reference']??''));$success='Campaign funding recorded.';
  }elseif($op==='reversal'){
    sponsored_research_compensation_reverse($pdo,$admin,(string)$_POST['review_id'],(string)($_POST['reason']??''));$success='Compensation reversal recorded in the immutable ledger.';
  }else throw new RuntimeException('Unknown Sponsored Research finance action.');
}catch(Throwable $e){$error=$e->getMessage();}}
$settings=sponsored_research_finance_settings($pdo);$campaigns=sponsored_research_finance_admin_campaigns($pdo,250);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sponsored Research Finance · Annotated Admin</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><?=admin_ui_sidebar('sponsored_research_finance')?>
<main class="panel"><div class="pageTitle"><span class="eyebrow">PHASE 80 · FINANCE</span><h1>Sponsored Research Finance</h1><p>Campaign funding, reservations, earnings, holds, platform fees and reversals are recorded in a separate immutable ledger. Stripe customer credits are not researcher earnings.</p></div>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<section class="card"><h2>Platform fee policy</h2><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="op" value="settings"><label>Platform fee (basis points)<input type="number" min="0" max="10000" name="platform_fee_bps" value="<?=h((string)$settings['platform_fee_bps'])?>"></label><p class="meta">100 basis points = 1%. The default is 0 until an Administrator explicitly configures a fee.</p><button>Save fee policy</button></form></section>
<section class="card"><h2>Campaign funding</h2><table><thead><tr><th>Campaign</th><th>Budget</th><th>Available</th><th>Reserved</th><th>Platform fees</th><th>Record funding</th></tr></thead><tbody>
<?php foreach($campaigns as $c):$b=$c['finance_balances'];?><tr><td><strong><?=h((string)$c['title'])?></strong><div class="meta"><?=h((string)$c['organization_name'])?> · <?=h((string)$c['account_name'])?></div></td><td><?=h((string)$c['budget_currency'])?> <?=number_format(((int)$c['budget_cents'])/100,2)?></td><td><?=number_format(((int)$b['campaign_available'])/100,2)?></td><td><?=number_format(((int)$b['campaign_reserved'])/100,2)?></td><td><?=number_format(((int)$b['platform_fee'])/100,2)?></td><td><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="op" value="funding"><input type="hidden" name="campaign_id" value="<?=h((string)$c['public_id'])?>"><input type="number" name="amount" min="0.01" step="0.01" required placeholder="Amount"><input name="reference" maxlength="64" placeholder="Payment / invoice reference"><button>Record funding</button></form></td></tr><?php endforeach?>
</tbody></table></section>
<section class="card"><h2>Administrative reversal</h2><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="op" value="reversal"><label>Review case public ID<input name="review_id" required></label><label>Reason<textarea name="reason" rows="4" required></textarea></label><p class="meta">Reversals are ledger entries, never destructive edits. If funds have already left the platform, payout recovery is handled by the future payout section.</p><button>Record reversal</button></form></section>
</main></body></html>