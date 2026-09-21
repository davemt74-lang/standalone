<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';

$marker=__DIR__.'/admin-password-reset.enable';
$enabled=is_file($marker);
$errors=[];$success='';

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow',true);

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    if(!$enabled){
        http_response_code(403);
        $errors[]='Admin password recovery is not enabled on this server.';
    }else{
        $identifier=trim((string)($_POST['identifier']??''));
        $password=(string)($_POST['password']??'');
        $confirm=(string)($_POST['confirm_password']??'');
        $limit=rate_limit_page_message($pdo,'admin-password-reset-ip',rate_limit_ip_subject(),8,900);
        if($limit)$errors[]=$limit;
        if($identifier==='')$errors[]='Enter the administrator email or username.';
        if(strlen($password)<12)$errors[]='Password must be at least 12 characters.';
        if($password!==$confirm)$errors[]='Passwords do not match.';

        if(!$errors){
            $got=(int)$pdo->query("SELECT GET_LOCK('annotated_admin_password_reset',5)")->fetchColumn();
            if($got!==1)throw new RuntimeException('Admin password recovery is busy. Retry.');
            try{
                $q=$pdo->prepare("SELECT id,username,email FROM users WHERE role='admin' AND status='active' AND (LOWER(COALESCE(email,''))=LOWER(?) OR username=?) LIMIT 1");
                $q->execute([$identifier,$identifier]);
                $admin=$q->fetch();
                if(!$admin){
                    $errors[]='No active administrator matched that email or username.';
                }else{
                    $hash=password_hash($password,PASSWORD_DEFAULT);
                    try{
                        $q=$pdo->prepare('UPDATE users SET password_hash=?,sessions_revoked_before=NOW() WHERE id=? AND role="admin"');
                        $q->execute([$hash,$admin['id']]);
                    }catch(PDOException $e){
                        $q=$pdo->prepare('UPDATE users SET password_hash=? WHERE id=? AND role="admin"');
                        $q->execute([$hash,$admin['id']]);
                    }
                    try{$pdo->prepare('UPDATE extension_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL')->execute([$admin['id']]);}catch(PDOException $e){}
                    $_SESSION=[];
                    if(ini_get('session.use_cookies')){
                        $p=session_get_cookie_params();
                        setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);
                    }
                    @session_destroy();
                    $disabled=@unlink($marker);
                    if($disabled){
                        header('Location: /login.php?admin_reset=1');
                        exit;
                    }
                    $success='Password reset. Delete admin-password-reset.enable from the web root now, then sign in with the new password.';
                    $enabled=is_file($marker);
                }
            }finally{
                $pdo->query("SELECT RELEASE_LOCK('annotated_admin_password_reset')");
            }
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Password Recovery · Annotated</title>
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<main class="panel narrow">
<span class="eyebrow">SERVER OWNER RECOVERY</span>
<h1>Reset an admin password</h1>
<p>This recovery page is controlled by a one-time file on the server. It does not use a recovery key.</p>
<?php if(!$enabled):?>
<div class="card">
<h2>Recovery is disabled</h2>
<p>Using your hosting file manager, FTP, or SSH, create an empty file named:</p>
<p><code>admin-password-reset.enable</code></p>
<p>Place it in the same directory as <code>config.php</code> and this file, then reload this page.</p>
</div>
<p><a href="/login.php">Back to login</a></p>
<?php else:?>
<div class="success">Recovery is enabled for this server. The enable file will be deleted automatically after a successful reset.</div>
<?php foreach($errors as $e):?><div class="error"><?=h($e)?></div><?php endforeach?>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?>
<form method="post" class="stack" autocomplete="off">
<input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
<label>Admin email or username
<input name="identifier" required autocomplete="username" value="<?=h((string)($_POST['identifier']??''))?>">
</label>
<label>New password
<input type="password" name="password" minlength="12" required autocomplete="new-password">
</label>
<label>Confirm new password
<input type="password" name="confirm_password" minlength="12" required autocomplete="new-password">
</label>
<button>Reset administrator password</button>
</form>
<p class="meta">A successful reset also signs out existing browser sessions and revokes Chrome extension sessions for that administrator.</p>
<?php endif?>
</main>
</body>
</html>
