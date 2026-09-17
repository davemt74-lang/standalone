<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
if (users_exist($pdo)) { http_response_code(404); exit('First-admin setup is closed.'); }
$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    $email=strtolower(trim($_POST['email']??'')); $username=trim($_POST['username']??''); $name=trim($_POST['display_name']??''); $password=$_POST['password']??'';
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))$errors[]='Enter a valid email.';
    if(!preg_match('/^[A-Za-z0-9_]{3,30}$/',$username))$errors[]='Username must be 3–30 letters, numbers, or underscores.';
    if(strlen($password)<12)$errors[]='Password must be at least 12 characters.';
    if($name==='')$errors[]='Display name is required.';
    if(!$errors){ $s=$pdo->prepare('INSERT INTO users(public_id,username,display_name,email,password_hash,role) VALUES(?,?,?,?,?,"admin")'); $s->execute([ulid_like(),$username,$name,$email,password_hash($password,PASSWORD_DEFAULT)]); $_SESSION['user_id']=(int)$pdo->lastInsertId(); header('Location: /'); exit; }
}
?><!doctype html><html><head><meta charset="utf-8"><title>Create First Admin · Annotated</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="panel narrow"><h1>Create First Admin</h1><p>This one-time screen closes permanently after the first account is created.</p><?php foreach($errors as $e):?><div class="error"><?=h($e)?></div><?php endforeach?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><label>Display name<input name="display_name" required></label><label>Username<input name="username" required></label><label>Email<input type="email" name="email" required></label><label>Password<input type="password" name="password" minlength="12" required></label><button>Create administrator</button></form></main></body></html>
