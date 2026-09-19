<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/extension-auth.php';

$pair=strtolower(trim((string)($_GET['pair']??'')));
$extensionId=strtolower(trim((string)($_GET['extension_id']??'')));
if(!preg_match('/^[a-f0-9]{64}$/',$pair)){http_response_code(400);exit('Invalid extension pairing request.');}
if(!extension_id_allowed($extensionId,$config)){http_response_code(400);exit('Invalid or disallowed Chrome extension ID.');}

$u=current_user($pdo);
if(!$u){
    $_SESSION['after_login']=$_SERVER['REQUEST_URI'];
    header('Location:/login.php');
    exit;
}

$hash=hash('sha256',$pair);
$binding='pair:'.$extensionId;
$pdo->prepare('DELETE FROM extension_auth_codes WHERE expires_at<NOW()')->execute();
$q=$pdo->prepare("INSERT IGNORE INTO extension_auth_codes(user_id,code_hash,redirect_uri,expires_at,used_at)
    VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 5 MINUTE),NULL)");
$q->execute([$u['id'],$hash,$binding]);

header('Cache-Control: private, no-store');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connecting Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="panel narrow">
<span class="eyebrow">ANNOTATED CHROME</span>
<h1>Connecting…</h1>
<div class="success"><strong>You’re connected.</strong> Returning to the Annotated sidebar.</div>
<p class="meta">Signed in as <?=h($u['display_name'])?> (@<?=h($u['username'])?>). This tab will close automatically.</p>
</main>
</body></html>