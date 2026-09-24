<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$admin=require_admin($pdo);$success='';$error='';
if(!subscriptions_ready($pdo)){http_response_code(503);exit('Subscriptions & Packages requires the latest database upgrade.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    try{
        $action=(string)($_POST['action']??'');
        if($action==='assign_package'){
            $id=(int)($_POST['user_id']??0);if($id<1)throw new RuntimeException('User is required.');
            $package=(string)($_POST['package_id']??'');$reason=trim((string)($_POST['reason']??''));
            $updated=subscription_assign_user_package($pdo,$admin,$id,$package,$reason);
            $success='Package updated to '.$updated['package_name'].'.';
        }else throw new RuntimeException('Unknown user action.');
    }catch(Throwable $e){$error=$e->getMessage();}
}
$packages=subscription_packages($pdo,true);
$users=$pdo->query("SELECT u.id,u.public_id,u.username,u.display_name,u.email,u.role,u.plan_tier,u.status,u.created_at,
  a.public_id account_public_id,a.subscription_status,a.period_start,a.period_end,a.trial_ends_at,
  p.public_id package_public_id,p.slug package_slug,p.name package_name,p.monthly_ai_token_allowance,p.member_limit
  FROM users u
  LEFT JOIN accounts a ON a.personal_user_id=u.id
  LEFT JOIN subscription_packages p ON p.id=a.package_id
  ORDER BY u.created_at DESC LIMIT 250")->fetchAll();
foreach($users as &$userRow){$usage=!empty($userRow['account_public_id'])&&ai_usage_ready($pdo)?ai_usage_summary_for_user($pdo,(int)$userRow['id']):null;$userRow['used_tokens']=$usage['used_tokens']??null;$userRow['remaining_tokens']=$usage['remaining_tokens']??null;$userRow['effective_allowance']=$usage['effective_allowance']??null;}unset($userRow);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Users · Annotated Admin</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<header class="topbar"><a class="brand" href="/admin/">Annotated Admin</a><nav><a class="active" href="/admin/users.php">Users</a><a href="/admin/packages.php">Packages</a><a href="/admin/usage.php">AI Usage</a><a href="/admin/ai.php">AI</a><a href="/admin/source-monitor.php">Sources</a><a href="/admin/moderation.php">Moderation</a></nav></header>
<main class="panel adminUsersPage"><div class="pageTitle"><span class="eyebrow">USERS & ACCOUNTS</span><h1>User accounts & packages</h1><p>Each user has a personal account with one active subscription package. Account membership is separate from manually managed Research Teams.</p></div>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<div class="tableWrap"><table><thead><tr><th>User</th><th>Role</th><th>Package</th><th>Period</th><th>Status</th><th>Change package</th></tr></thead><tbody>
<?php foreach($users as $x):?>
<tr><td><strong><?=h((string)$x['display_name'])?></strong><div class="meta">@<?=h((string)$x['username'])?> · <?=h((string)$x['email'])?></div><div class="meta">Joined <?=h((string)$x['created_at'])?></div></td>
<td><?=h((string)$x['role'])?></td>
<td><strong><?=h((string)($x['package_name']?:'Account not initialized'))?></strong><?php if($x['monthly_ai_token_allowance']!==null):?><div class="meta"><?=h(number_format((int)$x['monthly_ai_token_allowance']))?> AI tokens / month</div><?php endif?><?php if($x['used_tokens']!==null):?><div class="meta"><?=h(number_format((int)$x['used_tokens']))?> used · <?=$x['remaining_tokens']===null?'uncapped':h(number_format((int)$x['remaining_tokens'])).' remaining'?></div><?php endif?><?php if((int)($x['member_limit']??1)>1):?><div class="meta">Up to <?=h((string)$x['member_limit'])?> account members</div><?php endif?></td>
<td><?php if($x['period_start']):?><?=h((string)$x['period_start'])?> → <?=h((string)$x['period_end'])?><?php if($x['trial_ends_at']):?><div class="meta">Trial ends <?=h((string)$x['trial_ends_at'])?></div><?php endif?><?php else:?>—<?php endif?></td>
<td><?=h((string)$x['status'])?><?php if($x['subscription_status']):?><div class="meta"><?=h((string)$x['subscription_status'])?></div><?php endif?></td>
<td><form method="post" class="adminPackageAssignForm"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="assign_package"><input type="hidden" name="user_id" value="<?=h((string)$x['id'])?>"><select name="package_id" required><?php foreach($packages as $p):?><option value="<?=h((string)$p['public_id'])?>" <?=$x['package_public_id']===$p['public_id']?'selected':''?>><?=h((string)$p['name'])?></option><?php endforeach?></select><input name="reason" maxlength="500" placeholder="Reason (optional)"><button>Update</button></form></td></tr>
<?php endforeach?></tbody></table></div></main></body></html>