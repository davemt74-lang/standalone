<?php
declare(strict_types=1);require dirname(__DIR__).'/app/bootstrap.php';require_once dirname(__DIR__).'/app/oauth.php';
try{$p=oauth_config($config,'google');}catch(Throwable $e){http_response_code(503);exit(h($e->getMessage()));}
$intent=(string)($_GET['intent']??'login');if($intent==='link'){$me=current_user($pdo);if(!$me){http_response_code(401);exit('Sign in before linking an account.');}$_SESSION['oauth_intent']='link';$_SESSION['oauth_link_user_id']=(int)$me['id'];}else{$_SESSION['oauth_intent']='login';unset($_SESSION['oauth_link_user_id']);}
$state=bin2hex(random_bytes(24));$_SESSION['oauth_state']=$state;$_SESSION['oauth_provider']='google';
$params=['client_id'=>$p['client_id'],'redirect_uri'=>$p['redirect_uri'],'response_type'=>'code','scope'=>'openid email profile','state'=>$state,'access_type'=>'online','prompt'=>'select_account'];
header('Location: https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params));
