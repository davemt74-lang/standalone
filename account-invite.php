<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');}
if(!account_membership_ready($pdo)){http_response_code(503);exit('Account invitations require the latest database upgrade.');}
$error='';$success='';$queryToken=trim((string)($_GET['token']??''));
if($_SERVER['REQUEST_METHOD']==='GET'&&$queryToken!==''){account_membership_remember_invite_token($pdo,$queryToken);header('Location: /account-invite.php',true,303);exit;}
$token=trim((string)($_POST['token']??($_SESSION['pending_account_invite_token']??'')));$invite=$token!==''?account_membership_invitation_by_token($pdo,$token):null;
$user=current_user($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){require_csrf();try{
    if(!$user)throw new RuntimeException('Sign in to respond to this invitation.');
    $action=(string)($_POST['action']??'');
    if($action==='accept'){$result=account_membership_accept_invitation($pdo,$user,$token);$account=$result['account'];header('Location: /account-members.php?account='.rawurlencode((string)$account['public_id']).'&joined=1',true,303);exit;}
    if($action==='decline'){account_membership_decline_invitation($pdo,$user,$token);$success='Invitation declined.';$invite=null;}
    else if($action!=='accept')throw new RuntimeException('Unknown invitation action.');
}catch(Throwable $e){$error=$e->getMessage();}}
$invite=$invite&&$invite['status']==='pending'?$invite:null;
$masked='';if($invite){$email=(string)$invite['invited_email'];$parts=explode('@',$email,2);$local=$parts[0]??'';$masked=(mb_substr($local,0,1)?:'*').str_repeat('•',max(2,min(8,mb_strlen($local)-1))).'@'.($parts[1]??'');}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account Invitation · Annotated</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="panel narrow accountInviteAcceptPage"><span class="eyebrow">ACCOUNT INVITATION</span><h1>Join a commercial account</h1>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<?php if(!$invite):?><div class="error">This invitation is invalid, expired, or no longer available.</div><p><a class="button secondary" href="/home.php">Return to Annotated</a></p>
<?php else:?><section class="card"><h2><?=h((string)$invite['account_name'])?></h2><p>You were invited as <strong><?=h((string)$invite['account_role'])?></strong>.</p><div class="accountInviteFacts"><div><span>Invited email</span><strong><?=h($masked)?></strong></div><div><span>Package</span><strong><?=h((string)$invite['package_name'])?></strong></div><div><span>Seat</span><strong><?=((int)$invite['reserves_seat'])?'Reserved while pending':'Checked at acceptance'?></strong></div><div><span>Expires</span><strong><?=h((string)$invite['expires_at'])?> UTC</strong></div></div><p class="meta">Commercial account membership is separate from Research Teams and does not automatically add you to any Research project or Team.</p></section>
<?php if(!$user):?><div class="card"><h2>Sign in with the invited email</h2><p>Use the account associated with <strong><?=h($masked)?></strong>, or create that Annotated account first.</p><div class="inlineActions"><a class="button" href="/login.php?invite=1">Log in</a><a class="button secondary" href="/register.php?invite=1">Create account</a></div></div>
<?php elseif(strtolower((string)$user['email'])!==strtolower((string)$invite['invited_email'])):?><div class="error">You are signed in with a different email address. Sign out and use the email this invitation was sent to.</div><a class="button secondary" href="/logout.php">Sign out</a>
<?php else:?><div class="inlineActions"><form method="post"><?=csrf_field()?><input type="hidden" name="token" value="<?=h($token)?>"><input type="hidden" name="action" value="accept"><button>Accept invitation</button></form><form method="post"><?=csrf_field()?><input type="hidden" name="token" value="<?=h($token)?>"><input type="hidden" name="action" value="decline"><button class="button secondary">Decline</button></form></div><?php endif?>
<?php endif?>
</main></body></html>