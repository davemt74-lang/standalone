<?php
declare(strict_types=1);require __DIR__.'/app/bootstrap.php';
if($_SERVER['REQUEST_METHOD']==='POST'){require_csrf();$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);}session_destroy();header('Location:/login.php');exit;}
$u=current_user($pdo);if(!$u){header('Location:/login.php');exit;}
?><!doctype html><html><head><meta charset="utf-8"><title>Sign out · Annotated</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="panel narrow"><h1>Sign out</h1><form method="post" id="logoutForm"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><button>Sign out</button></form><p><a href="/home.php">Cancel</a></p></main><script>document.querySelector('#logoutForm')?.addEventListener('submit',()=>{try{sessionStorage.removeItem('annotated.workspaceContext.v1');}catch{}});</script></body></html>
