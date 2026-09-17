<?php
declare(strict_types=1);require dirname(__DIR__).'/app/bootstrap.php';require_once dirname(__DIR__).'/app/oauth.php';
$state=(string)($_GET['state']??'');$code=(string)($_GET['code']??'');$provider=(string)($_SESSION['oauth_provider']??'');
try{rate_limit_enforce($pdo,$config,'oauth_callback_ip',rate_limit_subject_ip(),60,600);}catch(RateLimitExceeded $e){header('Retry-After: '.$e->retryAfter);http_response_code(429);exit('Too many sign-in callbacks. Try again later.');}
if(!$provider||!hash_equals((string)($_SESSION['oauth_state']??''),$state)||$code===''){http_response_code(400);exit('Invalid OAuth callback.');}
try{
 $p=oauth_config($config,$provider);
 if($provider==='google'){
  $token=oauth_http('https://oauth2.googleapis.com/token',['post'=>['code'=>$code,'client_id'=>$p['client_id'],'client_secret'=>$p['client_secret'],'redirect_uri'=>$p['redirect_uri'],'grant_type'=>'authorization_code']]);
  $info=oauth_http('https://openidconnect.googleapis.com/v1/userinfo',['headers'=>['Authorization: Bearer '.$token['access_token']]]);
  $userId=oauth_finish_login($pdo,'google',(string)$info['sub'],$info['email']??null,$info['name']??'Google User',$info['email']?strstr($info['email'],'@',true):'google_user',(bool)($info['email_verified']??false));
 } elseif($provider==='x'){
  $verifier=(string)($_SESSION['oauth_verifier']??'');
  $auth=base64_encode($p['client_id'].':'.$p['client_secret']);
  $token=oauth_http('https://api.x.com/2/oauth2/token',['headers'=>['Authorization: Basic '.$auth,'Content-Type: application/x-www-form-urlencoded'],'post'=>['code'=>$code,'grant_type'=>'authorization_code','redirect_uri'=>$p['redirect_uri'],'code_verifier'=>$verifier]]);
  $me=oauth_http('https://api.x.com/2/users/me?user.fields=profile_image_url,name,username',['headers'=>['Authorization: Bearer '.$token['access_token']]]);$info=$me['data']??[];
  $userId=oauth_finish_login($pdo,'x',(string)($info['id']??''),null,$info['name']??'X User',$info['username']??'x_user',false);
 } else throw new RuntimeException('Unsupported OAuth provider.');
 session_regenerate_id(true);$_SESSION['user_id']=$userId;unset($_SESSION['oauth_state'],$_SESSION['oauth_provider'],$_SESSION['oauth_verifier']);header('Location:'.post_login_destination());
}catch(Throwable $e){http_response_code(502);echo 'OAuth sign-in failed. '.h($e->getMessage());}
