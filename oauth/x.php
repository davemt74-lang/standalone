<?php
declare(strict_types=1);require dirname(__DIR__).'/app/bootstrap.php';require_once dirname(__DIR__).'/app/oauth.php';
try{$p=oauth_config($config,'x');}catch(Throwable $e){http_response_code(503);exit(h($e->getMessage()));}
$state=bin2hex(random_bytes(24));$verifier=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');$_SESSION['oauth_state']=$state;$_SESSION['oauth_provider']='x';$_SESSION['oauth_verifier']=$verifier;
$params=['response_type'=>'code','client_id'=>$p['client_id'],'redirect_uri'=>$p['redirect_uri'],'scope'=>'users.read tweet.read offline.access','state'=>$state,'code_challenge'=>$challenge,'code_challenge_method'=>'S256'];
header('Location: https://twitter.com/i/oauth2/authorize?'.http_build_query($params));
