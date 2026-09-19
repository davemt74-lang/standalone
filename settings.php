<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');$errors=[];$success='';$currentProfileImage=trim((string)($u['profile_image_url']??''));
$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$u['id']]);
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$action=(string)($_POST['action']??'profile');
    if($action==='profile'){
        $display=trim((string)($_POST['display_name']??''));$bio=trim((string)($_POST['bio']??''));$website=trim((string)($_POST['website_url']??''));$image=$currentProfileImage;$oldImage=$currentProfileImage;$uploaded='';
        if($display==='')$errors[]='Display name is required.';
        if($website!==''&&!filter_var($website,FILTER_VALIDATE_URL))$errors[]='Website URL is invalid.';
        if(isset($_POST['remove_profile_photo']))$image='';
        if(isset($_FILES['profile_photo'])&&(int)($_FILES['profile_photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            try{$uploaded=profile_image_upload($_FILES['profile_photo'],(int)$u['id']);$image=$uploaded;}catch(Throwable $e){$errors[]=$e->getMessage();}
        }
        if(!$errors){
            $pdo->prepare('UPDATE users SET display_name=?,bio=?,website_url=?,profile_image_url=? WHERE id=?')->execute([$display,$bio?:null,$website?:null,$image!==''?$image:null,$u['id']]);
            if($oldImage!==''&&$oldImage!==$image)profile_image_delete_local($oldImage);
            $currentProfileImage=$image;if(isset($GLOBALS['annotated_shell']['user'])){$GLOBALS['annotated_shell']['user']['profile_image_url']=$image;$GLOBALS['annotated_shell']['user']['display_name']=$display;}$success='Profile updated.';
        }elseif($uploaded!=='')profile_image_delete_local($uploaded);
    }
    if($action==='privacy'){
        $profile=in_array($_POST['profile_visibility']??'public',['public','private'],true)?$_POST['profile_visibility']:'public';
        $default=in_array($_POST['default_annotation_visibility']??'public',['public','team','private'],true)?$_POST['default_annotation_visibility']:'public';
        $presence=in_array($_POST['live_presence_mode']??'cloaked',['visible','team_only','cloaked','off'],true)?$_POST['live_presence_mode']:'cloaked';
        $search=isset($_POST['search_visibility'])?1:0;
        $pdo->prepare('UPDATE users SET live_presence_mode=? WHERE id=?')->execute([$presence,$u['id']]);
        $pdo->prepare('UPDATE user_preferences SET profile_visibility=?,search_visibility=?,default_annotation_visibility=? WHERE user_id=?')->execute([$profile,$search,$default,$u['id']]);$success='Privacy settings updated.';
    }
    if($action==='notifications'){
        $cols=['notify_social','notify_comments','notify_follows','notify_research','notify_team','notify_sources','notify_live','notify_claims','notify_moderation'];
        $vals=array_map(fn($c)=>isset($_POST[$c])?1:0,$cols);$vals[]=$u['id'];
        $pdo->prepare('UPDATE user_preferences SET notify_social=?,notify_comments=?,notify_follows=?,notify_research=?,notify_team=?,notify_sources=?,notify_live=?,notify_claims=?,notify_moderation=? WHERE user_id=?')->execute($vals);$success='Notification preferences updated.';
    }
    if($action==='unmute'){
        try{notification_mute_set($pdo,$u,(string)($_POST['scope_type']??''),(string)($_POST['scope_id']??''),(string)($_POST['category']??'all'),false);$success='Notification mute removed.';}catch(Throwable $e){$errors[]=$e->getMessage();}
    }
    if($action==='password'){
        $new=(string)($_POST['new_password']??'');$current=(string)($_POST['current_password']??'');$q=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$u['id']]);$hash=$q->fetchColumn();
        if(strlen($new)<12)$errors[]='New password must be at least 12 characters.';elseif($hash&&!password_verify($current,(string)$hash))$errors[]='Current password is incorrect.';else{$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$u['id']]);$success=$hash?'Password changed.':'Native password added.';}
    }
}
$q=$pdo->prepare("SELECT u.*,p.* FROM users u JOIN user_preferences p ON p.user_id=u.id WHERE u.id=?");$q->execute([$u['id']]);$s=$q->fetch();
$q=$pdo->prepare('SELECT scope_type,scope_public_id,category,created_at FROM notification_mutes WHERE user_id=? ORDER BY created_at DESC LIMIT 100');$q->execute([$u['id']]);$mutes=$q->fetchAll();
$notifyLabels=[
 'notify_social'=>'Social activity','notify_comments'=>'Comments and replies','notify_follows'=>'New followers','notify_research'=>'Research activity',
 'notify_team'=>'Team activity','notify_sources'=>'Source changes','notify_live'=>'Live activity','notify_claims'=>'Claims','notify_moderation'=>'Moderation outcomes'
];
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Settings · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<header class="topbar"><a class="brand" href="/">Annotated</a><nav><a href="/home.php">Home</a><a href="<?=h(profile_path((string)$s['username']))?>">Profile</a><a href="/notifications.php">Notifications</a><a href="/connected-accounts.php">Accounts</a><a href="/logout.php">Sign out</a></nav></header>
<main class="layout"><section><div class="pageTitle"><div class="eyebrow">ACCOUNT</div><h1>Settings</h1></div>
<?php foreach($errors as $e):?><div class="error"><?=h($e)?></div><?php endforeach?><?php if($success):?><div class="success"><?=h($success)?></div><?php endif?>
<div class="card"><h2>Profile</h2><div class="profileMini" style="margin-bottom:18px"><?=app_shell_avatar($s,'avatarImageLg')?><span><strong><?=h($s['display_name'])?></strong><small>@<?=h($s['username'])?></small></span></div><form method="post" enctype="multipart/form-data" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="profile"><label>Display name<input name="display_name" value="<?=h($s['display_name'])?>" required></label><label>Profile photo<input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp"></label><p class="meta">Upload a JPG, PNG, or WebP up to 5 MB. Your photo appears in the website header, profile, annotation cards, Teams, and Chrome extension feeds.</p><?php if(!empty($s['profile_image_url'])):?><label class="profilePhotoRemove"><input type="checkbox" name="remove_profile_photo" value="1"> Remove current profile photo</label><?php endif?><label>Bio<textarea name="bio" rows="5" maxlength="2000"><?=h((string)$s['bio'])?></textarea></label><label>Website<input type="url" name="website_url" value="<?=h((string)$s['website_url'])?>"></label><button>Save profile</button></form></div>
<div class="card"><h2>Privacy & publishing</h2><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="privacy"><label>Profile visibility<select name="profile_visibility"><option value="public" <?=$s['profile_visibility']==='public'?'selected':''?>>Public</option><option value="private" <?=$s['profile_visibility']==='private'?'selected':''?>>Private</option></select></label><label><input type="checkbox" name="search_visibility" value="1" <?=$s['search_visibility']?'checked':''?>> Allow my profile to appear in search</label><label>Default annotation visibility<select name="default_annotation_visibility"><option value="public" <?=$s['default_annotation_visibility']==='public'?'selected':''?>>Public</option><option value="team" <?=$s['default_annotation_visibility']==='team'?'selected':''?>>Team</option><option value="private" <?=$s['default_annotation_visibility']==='private'?'selected':''?>>Private</option></select></label><label>Live presence<select name="live_presence_mode"><option value="visible" <?=$s['live_presence_mode']==='visible'?'selected':''?>>Visible</option><option value="team_only" <?=$s['live_presence_mode']==='team_only'?'selected':''?>>Team only</option><option value="cloaked" <?=$s['live_presence_mode']==='cloaked'?'selected':''?>>Cloaked</option><option value="off" <?=$s['live_presence_mode']==='off'?'selected':''?>>Off</option></select></label><button>Save privacy settings</button></form></div>
<div class="card" id="notifications"><h2>Notifications</h2><p class="meta">These switches are enforced when notifications are created, not merely hidden in the UI.</p><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="notifications"><?php foreach($notifyLabels as $key=>$label):?><label><input type="checkbox" name="<?=h($key)?>" value="1" <?=!empty($s[$key])?'checked':''?>> <?=h($label)?></label><?php endforeach?><button>Save notification settings</button></form>
<h3>Muted scopes</h3><?php if(!$mutes):?><p class="meta">No muted sources, conversations, Live rooms, or users.</p><?php endif?><?php foreach($mutes as $m):?><div class="muteRow"><span><strong><?=h(ucfirst((string)$m['scope_type']))?></strong> · <?=h((string)$m['category'])?><small><?=h((string)$m['scope_public_id'])?></small></span><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="unmute"><input type="hidden" name="scope_type" value="<?=h((string)$m['scope_type'])?>"><input type="hidden" name="scope_id" value="<?=h((string)$m['scope_public_id'])?>"><input type="hidden" name="category" value="<?=h((string)$m['category'])?>"><button class="button secondary">Unmute</button></form></div><?php endforeach?></div>
<div class="card"><h2><?=empty($s['password_hash'])?'Add native password':'Change password'?></h2><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="password"><?php if(!empty($s['password_hash'])):?><label>Current password<input type="password" name="current_password" required></label><?php endif?><label>New password<input type="password" name="new_password" minlength="12" required></label><button><?=empty($s['password_hash'])?'Add password':'Change password'?></button></form></div>
</section><aside><div class="card"><h3>Plan</h3><p><strong><?=h(ucfirst(user_plan($pdo,$u)))?></strong></p><?php if(user_is_pro($pdo,$u)):?><a class="button" href="/ai.php">Ask Annotated</a><?php else:?><p class="meta">Pro adds provenance-aware AI research tools.</p><?php endif?></div><?php if(($u['role']??'')==='admin'):?><div class="card"><h3>System admin</h3><a class="button secondary" href="/admin/">Open admin backend</a></div><?php endif?><div class="card"><h3>Account security</h3><p class="meta">Manage Google/X connections and revoke Chrome extension sessions.</p><a class="button secondary" href="/connected-accounts.php">Connected accounts</a></div></aside></main></body></html>