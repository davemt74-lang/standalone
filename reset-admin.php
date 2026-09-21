<?php
declare(strict_types=1);

require __DIR__.'/app/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow', true);

$error='';
$success=false;

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();

    $identifier=trim((string)($_POST['identifier']??''));
    $password=(string)($_POST['password']??'');
    $confirm=(string)($_POST['confirm_password']??'');

    if($identifier==='')$error='Enter the admin email or username.';
    elseif(strlen($password)<12)$error='Password must be at least 12 characters.';
    elseif($password!==$confirm)$error='Passwords do not match.';
    else{
        $q=$pdo->prepare("SELECT id,username,email FROM users WHERE role='admin' AND status='active' AND (LOWER(COALESCE(email,''))=LOWER(?) OR username=?) LIMIT 1");
        $q->execute([$identifier,$identifier]);
        $admin=$q->fetch();

        if(!$admin){
            $error='Admin account not found.';
        }else{
            $hash=password_hash($password,PASSWORD_DEFAULT);
            try{
                $q=$pdo->prepare('UPDATE users SET password_hash=?,sessions_revoked_before=NOW() WHERE id=? AND role="admin"');
                $q->execute([$hash,$admin['id']]);
            }catch(PDOException $e){
                $q=$pdo->prepare('UPDATE users SET password_hash=? WHERE id=? AND role="admin"');
                $q->execute([$hash,$admin['id']]);
            }

            try{
                $pdo->prepare('UPDATE extension_sessions SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL')->execute([$admin['id']]);
            }catch(PDOException $e){}

            $_SESSION=[];
            if(ini_get('session.use_cookies')){
                $p=session_get_cookie_params();
                setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);
            }
            @session_destroy();

            $success=true;
            @unlink(__FILE__);
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Admin Password · Annotated</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
body{background:#f6f6f3}
.resetBox{max-width:520px;margin:70px auto;padding:28px}
.resetBox h1{margin:0 0 8px}
.resetBox p{color:#666}
.resetBox .stack{margin-top:24px}
.resetBox .warning{padding:12px 14px;border:1px solid #e2c96b;background:#fff9dc;border-radius:10px;margin:16px 0}
</style>
</head>
<body>
<main class="panel resetBox">
<span class="eyebrow">ANNOTATED RECOVERY</span>
<h1>Reset Admin Password</h1>
<?php if($success):?>
<div class="success">
<strong>Password reset complete.</strong><br>
<a href="/login.php">Go to login</a>
</div>
<p class="meta">This reset file attempted to delete itself automatically. If it still exists on the server, delete <code>reset-admin.php</code> now.</p>
<?php else:?>
<p>Enter the administrator account and choose a new password.</p>
<div class="warning"><strong>Temporary recovery page.</strong> Delete <code>reset-admin.php</code> after you regain access.</div>
<?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<form method="post" class="stack">
<input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
<label>Admin email or username
<input name="identifier" required autofocus autocomplete="username">
</label>
<label>New password
<input type="password" name="password" minlength="12" required autocomplete="new-password">
</label>
<label>Confirm password
<input type="password" name="confirm_password" minlength="12" required autocomplete="new-password">
</label>
<button>Reset Password</button>
</form>
<?php endif?>
</main>
</body>
</html>
