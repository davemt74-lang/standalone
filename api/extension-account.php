<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/extension-auth.php';
extension_api_headers($config);

if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED']],405);

$origin=(string)($_SERVER['HTTP_ORIGIN']??'');
if($origin===''||!extension_origin_allowed($origin,$config))json_response(['ok'=>false,'error'=>['code'=>'EXTENSION_ORIGIN_REQUIRED','message'=>'Open this action from the Annotated Chrome extension.']],403);

$raw=json_decode((string)file_get_contents('php://input'),true)?:[];
$action=(string)($raw['action']??'');
$extensionId=strtolower(trim((string)preg_replace('#^chrome-extension://#i','',$origin)));
$version=mb_substr(trim((string)($raw['client_version']??'')),0,32)?:null;
$device='Chrome sidebar';

if($action==='login'){
    $identifier=trim((string)($raw['identifier']??''));
    $password=(string)($raw['password']??'');
    rate_limit_api_or_429($pdo,'extension-login-ip',rate_limit_ip_subject(),20,900);
    rate_limit_api_or_429($pdo,'extension-login-account',rate_limit_subject($identifier),10,900);
    if($identifier===''||$password==='')json_response(['ok'=>false,'error'=>['code'=>'INVALID_CREDENTIALS','message'=>'Enter your email or username and password.']],422);

    $q=$pdo->prepare("SELECT id,public_id,username,display_name,email,password_hash,role FROM users WHERE status='active' AND (LOWER(COALESCE(email,''))=LOWER(?) OR username=?) LIMIT 1");
    $q->execute([$identifier,$identifier]);
    $user=$q->fetch();
    if(!$user||empty($user['password_hash'])||!password_verify($password,(string)$user['password_hash'])){
        json_response(['ok'=>false,'error'=>['code'=>'INVALID_CREDENTIALS','message'=>'Email/username or password is incorrect.']],401);
    }
    if(password_needs_rehash((string)$user['password_hash'],PASSWORD_DEFAULT)){
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$user['id']]);
    }
    $session=extension_session_issue($pdo,(int)$user['id'],$config,$device,$version);
    unset($user['password_hash']);
    json_response(['ok'=>true,'data'=>['token'=>$session['token'],'expires_at'=>$session['expires_at'],'user'=>$user]],201);
}

if($action==='register'){
    if(!users_exist($pdo))json_response(['ok'=>false,'error'=>['code'=>'SETUP_REQUIRED','message'=>'Finish the Annotated website setup and create the first administrator before creating extension accounts.']],409);
    $email=strtolower(trim((string)($raw['email']??'')));
    $username=trim((string)($raw['username']??''));
    $displayName=trim((string)($raw['display_name']??''));
    $password=(string)($raw['password']??'');
    $confirm=(string)($raw['confirm_password']??'');

    rate_limit_api_or_429($pdo,'extension-register-ip',rate_limit_ip_subject(),8,3600);
    rate_limit_api_or_429($pdo,'extension-register-email',rate_limit_subject($email),4,3600);

    if(!filter_var($email,FILTER_VALIDATE_EMAIL))json_response(['ok'=>false,'error'=>['code'=>'INVALID_EMAIL','message'=>'Enter a valid email address.']],422);
    if(!preg_match('/^[A-Za-z0-9_]{3,30}$/',$username))json_response(['ok'=>false,'error'=>['code'=>'INVALID_USERNAME','message'=>'Username must be 3–30 characters using letters, numbers, or underscores.']],422);
    if($displayName===''||mb_strlen($displayName)>100)json_response(['ok'=>false,'error'=>['code'=>'INVALID_DISPLAY_NAME','message'=>'Enter a display name up to 100 characters.']],422);
    if(strlen($password)<12)json_response(['ok'=>false,'error'=>['code'=>'WEAK_PASSWORD','message'=>'Password must be at least 12 characters.']],422);
    if($password!==$confirm)json_response(['ok'=>false,'error'=>['code'=>'PASSWORD_MISMATCH','message'=>'Passwords do not match.']],422);

    try{
        $pdo->beginTransaction();
        $q=$pdo->prepare('INSERT INTO users(public_id,username,display_name,email,password_hash) VALUES(?,?,?,?,?)');
        $q->execute([ulid_like(),$username,$displayName,$email,password_hash($password,PASSWORD_DEFAULT)]);
        $userId=(int)$pdo->lastInsertId();
        onboarding_ensure($pdo,$userId);
        $session=extension_session_issue($pdo,$userId,$config,$device,$version);
        $q=$pdo->prepare('SELECT id,public_id,username,display_name,email,role FROM users WHERE id=?');
        $q->execute([$userId]);$user=$q->fetch();
        $pdo->commit();
        json_response(['ok'=>true,'data'=>['token'=>$session['token'],'expires_at'=>$session['expires_at'],'user'=>$user]],201);
    }catch(PDOException $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if((string)$e->getCode()==='23000')json_response(['ok'=>false,'error'=>['code'=>'ACCOUNT_EXISTS','message'=>'That email or username is already registered.']],409);
        throw $e;
    }
}

if($action==='logout'){
    $token=bearer_token();
    if($token){
        $pdo->prepare('UPDATE extension_sessions SET revoked_at=NOW() WHERE token_hash=? AND revoked_at IS NULL')->execute([hash('sha256',$token)]);
    }
    json_response(['ok'=>true,'data'=>['signed_out'=>true]]);
}

json_response(['ok'=>false,'error'=>['code'=>'INVALID_ACTION']],422);
