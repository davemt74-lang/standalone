<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');
$error=trim((string)($_GET['error']??''));if($error!==''){unset($_SESSION['vp3_oauth_state']);header('Location: /login.php?vp3_error='.rawurlencode($error));exit;}
try{
    $code=trim((string)($_GET['code']??''));$state=trim((string)($_GET['state']??''));if($code===''||$state==='')throw new RuntimeException('VP3 did not return a complete authorization response.');
    $viewer=current_user($pdo);$result=vp3_connector_callback($pdo,$config,$code,$state,$viewer);$uid=(int)$result['user_id'];
    if($result['mode']==='login'){session_regenerate_id(true);$_SESSION['user_id']=$uid;$_SESSION['rotated_at']=time();$_SESSION['last_activity']=time();$_SESSION['auth_time']=time();header('Location: '.post_auth_destination($pdo,$uid));exit;}
    header('Location: /vp3-library.php?connected=1');exit;
}catch(Throwable $e){http_response_code(400);?><!doctype html><meta charset="utf-8"><title>VP3 connection error · Annotated</title><link rel="stylesheet" href="/assets/css/app.css"><main class="panel narrow"><h1>VP3 connection could not be completed</h1><div class="error"><?=h($e->getMessage())?></div><p><a class="button secondary" href="<?=current_user($pdo)?'/settings.php':'/login.php'?>">Back</a></p></main><?php }
