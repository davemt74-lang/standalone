<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';require_once dirname(__DIR__).'/app/extension-auth.php';
extension_api_headers($config);
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED']],405);
rate_limit_api_or_429($pdo,'extension-token-ip',rate_limit_ip_subject(),30,600);
$raw=json_decode(file_get_contents('php://input'),true)?:[];
$code=(string)($raw['code']??'');$redirect=(string)($raw['redirect_uri']??'');
if($code===''||$redirect===''||!extension_redirect_allowed($redirect,$config))json_response(['ok'=>false,'error'=>['code'=>'INVALID_REQUEST']],422);
rate_limit_api_or_429($pdo,'extension-token-code',hash('sha256',$code),8,600);
$hash=hash('sha256',$code);$ttl=max(1,min(90,(int)($config['extension']['session_ttl_days']??30)));$expires=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$ttl.' days')->format('Y-m-d H:i:s');
$pdo->beginTransaction();
try{
    $q=$pdo->prepare('SELECT id,user_id FROM extension_auth_codes WHERE code_hash=? AND redirect_uri=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE');$q->execute([$hash,$redirect]);$row=$q->fetch();
    if(!$row){$pdo->rollBack();json_response(['ok'=>false,'error'=>['code'=>'INVALID_OR_EXPIRED_CODE']],401);}
    $token=bin2hex(random_bytes(32));$pdo->prepare('UPDATE extension_auth_codes SET used_at=NOW() WHERE id=?')->execute([$row['id']]);
    $version=mb_substr(trim((string)($raw['client_version']??'')),0,32)?:null;$q=$pdo->prepare('INSERT INTO extension_sessions(user_id,token_hash,device_name,client_version,expires_at) VALUES(?,?,?,?,?)');$q->execute([$row['user_id'],hash('sha256',$token),mb_substr(trim((string)($raw['device_name']??'Chrome extension')),0,190),$version,$expires]);
    $pdo->commit();json_response(['ok'=>true,'data'=>['token'=>$token,'expires_at'=>$expires]],201);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'error'=>['code'=>'TOKEN_EXCHANGE_FAILED']],500);}
