<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');$errors=[];$success='';
$ready=conversation_presence_ready($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    if(!$ready)$errors[]='Chat Status requires the Phase 12A database upgrade.';
    else{
        try{$status=conversation_status_update($pdo,$u,(string)($_POST['status_mode']??'auto'),(string)($_POST['custom_status']??''));$success='Chat status updated.';}
        catch(Throwable $e){$errors[]=$e->getMessage();}
    }
}
$status=$ready?conversation_status_get($pdo,(int)$u['id']):['status_mode'=>'auto','custom_status'=>'','effective_status'=>'offline'];
$labels=['auto'=>'Automatic','available'=>'Available','away'=>'Away','busy'=>'Busy','invisible'=>'Invisible'];
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Chat Status · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="settingsStandalone"><section class="settingsStandaloneMain"><div class="pageTitle"><div class="eyebrow">TEAM CHAT</div><h1>Chat Status</h1><p class="meta">Control how your availability appears to people who share a Team with you.</p></div>
<?php foreach($errors as $e):?><div class="error"><?=h($e)?></div><?php endforeach?><?php if($success):?><div class="success"><?=h($success)?></div><?php endif?>
<?php if(!$ready):?><div class="card"><h2>Database upgrade required</h2><p>Chat presence is not available until migration 019 is applied.</p><?php if(($u['role']??'')==='admin'):?><a class="button" href="/upgrade.php">Run database upgrade</a><?php endif?></div><?php else:?>
<div class="card chatStatusCard">
  <div class="chatStatusCurrent"><span class="chatPresenceDot status-<?=h((string)$status['effective_status'])?>"></span><div><strong><?=h($labels[$status['status_mode']]??'Automatic')?></strong><small>Currently appears <?=h((string)$status['effective_status'])?></small></div></div>
  <form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <fieldset class="chatStatusChoices"><legend>Status</legend>
      <?php foreach([
        'auto'=>['Automatic','Online while Annotated Team Chat is active; offline shortly after you leave.'],
        'available'=>['Available','Show as available whenever this browser is actively connected.'],
        'away'=>['Away','Show an away indicator while connected.'],
        'busy'=>['Busy','Show that you are online but should not be interrupted.'],
        'invisible'=>['Invisible','Use Team Chat normally while appearing offline to teammates.']
      ] as $value=>$copy):?><label class="chatStatusChoice"><input type="radio" name="status_mode" value="<?=h($value)?>" <?=$status['status_mode']===$value?'checked':''?>><span><strong><?=h($copy[0])?></strong><small><?=h($copy[1])?></small></span></label><?php endforeach?>
    </fieldset>
    <label>Custom status<input type="text" name="custom_status" maxlength="120" value="<?=h((string)$status['custom_status'])?>" placeholder="Optional — e.g. Reviewing source notes"></label>
    <p class="meta">Your Team Chat status is visible only to users who currently share a Team with you. Invisible suppresses your online indicator but does not disable messaging.</p>
    <div class="inlineActions"><button>Save chat status</button><a class="button secondary" href="/home.php#team-chat">Back to Team Chat</a></div>
  </form>
</div>
<?php endif?>
</section><aside class="settingsSidebar"><div class="card"><h3>Account settings</h3><p class="meta">Profile, display, privacy, notifications, and security remain in Account Settings.</p><a class="button secondary" href="/settings.php">Open Account Settings</a></div></aside></main>
</body></html>