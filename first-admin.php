<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';

$schemaFile=__DIR__.'/database/schema.sql';
$migrationDir=__DIR__.'/database/migrations';
if(!installer_base_schema_ready($pdo,$schemaFile)||installer_pending_migrations($pdo,$migrationDir)){
    header('Location: /install.php');
    exit;
}
if(users_exist($pdo)){http_response_code(404);exit('First-admin setup is closed.');}

$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    $email=strtolower(trim((string)($_POST['email']??'')));
    $username=trim((string)($_POST['username']??''));
    $name=trim((string)($_POST['display_name']??''));
    $password=(string)($_POST['password']??'');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))$errors[]='Enter a valid email.';
    if(!preg_match('/^[A-Za-z0-9_]{3,30}$/',$username))$errors[]='Username must be 3–30 letters, numbers, or underscores.';
    if(strlen($password)<12)$errors[]='Password must be at least 12 characters.';
    if($name==='')$errors[]='Display name is required.';
    if(!$errors){
        $got=(int)$pdo->query("SELECT GET_LOCK('annotated_first_admin',5)")->fetchColumn();
        if($got!==1)throw new RuntimeException('First-admin setup is busy. Retry.');
        try{
            if(users_exist($pdo)){http_response_code(409);exit('First admin was already created.');}
            $q=$pdo->prepare('INSERT INTO users(public_id,username,display_name,email,password_hash,role) VALUES(?,?,?,?,?,"admin")');
            $q->execute([ulid_like(),$username,$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
            $uid=(int)$pdo->lastInsertId();
            try{$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);}catch(PDOException $e){}
            if(subscriptions_ready($pdo))subscription_ensure_user_account($pdo,$uid,$uid);
            onboarding_ensure($pdo,$uid);
            session_regenerate_id(true);
            $_SESSION['user_id']=$uid;
            $_SESSION['rotated_at']=time();
            $_SESSION['last_activity']=time();
            $_SESSION['auth_time']=time();
            header('Location: /onboarding.php');
            exit;
        }finally{
            $pdo->query("SELECT RELEASE_LOCK('annotated_first_admin')");
        }
    }
}
header('Cache-Control: private, no-store');
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create First Admin · Annotated</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="panel narrow"><span class="eyebrow">FINAL SETUP</span><h1>Create First Admin</h1><p>Create the first administrator account. This page closes permanently as soon as the account is created.</p><?php foreach($errors as $e):?><div class="error"><?=h($e)?></div><?php endforeach?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><label>Display name<input name="display_name" required autocomplete="name"></label><label>Username<input name="username" required autocomplete="username"></label><label>Email<input type="email" name="email" required autocomplete="email"></label><label>Password<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><button>Create administrator</button></form></main></body></html>
