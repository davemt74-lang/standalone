<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/extension-auth.php';

$pair=(string)($_GET['pair']??$_POST['pair']??'');
$extensionId=strtolower(trim((string)($_GET['extension_id']??$_POST['extension_id']??'')));
if(!preg_match('/^[a-f0-9]{64}$/',$pair)){http_response_code(400);exit('Invalid extension pairing request.');}
if(!extension_id_allowed($extensionId,$config)){http_response_code(400);exit('Invalid or disallowed Chrome extension ID.');}

$u=current_user($pdo);
if(!$u){
    $_SESSION['after_login']=$_SERVER['REQUEST_URI'];
    header('Location:/login.php');
    exit;
}

$approved=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    $hash=hash('sha256',$pair);
    $binding='pair:'.$extensionId;
    $pdo->prepare('DELETE FROM extension_auth_codes WHERE expires_at<NOW() OR used_at IS NOT NULL')->execute();
    $q=$pdo->prepare("INSERT INTO extension_auth_codes(user_id,code_hash,redirect_uri,expires_at,used_at)
        VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 5 MINUTE),NULL)
        ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),redirect_uri=VALUES(redirect_uri),expires_at=VALUES(expires_at),used_at=NULL");
    $q->execute([$u['id'],$hash,$binding]);
    $approved=true;
}
header('Cache-Control: private, no-store');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connect Chrome Extension · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="panel narrow">
<span class="eyebrow">CHROME EXTENSION</span>
<?php if($approved):?>
<h1>Extension approved</h1>
<div class="success"><strong>Connected.</strong> Return to the Annotated sidebar. It will finish signing in automatically.</div>
<p>You can close this tab.</p>
<?php else:?>
<h1>Connect Annotated</h1>
<p>Connect this browser extension to <strong><?=h($u['display_name'])?></strong> (@<?=h($u['username'])?>).</p>
<div class="card"><strong>Chrome extension</strong><p class="meta"><code><?=h($extensionId)?></code></p></div>
<p>The extension will receive a revocable session token. It will not receive your password.</p>
<form method="post" class="stack">
<input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
<input type="hidden" name="pair" value="<?=h($pair)?>">
<input type="hidden" name="extension_id" value="<?=h($extensionId)?>">
<button>Connect Extension</button>
</form>
<?php endif?>
</main>
</body></html>