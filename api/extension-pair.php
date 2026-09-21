<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/extension-auth.php';
extension_api_headers($config);

if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED']],405);
rate_limit_api_or_429($pdo,'extension-pair-ip',rate_limit_ip_subject(),240,600);

$raw=json_decode(file_get_contents('php://input'),true)?:[];
$pair=strtolower(trim((string)($raw['pair']??'')));
$extensionId=strtolower(trim((string)($raw['extension_id']??'')));
if(!preg_match('/^[a-f0-9]{64}$/',$pair)||!extension_id_allowed($extensionId,$config))json_response(['ok'=>false,'error'=>['code'=>'INVALID_PAIRING_REQUEST']],422);

$origin=(string)($_SERVER['HTTP_ORIGIN']??'');
if($origin!==''&&$origin!=='chrome-extension://'.$extensionId)json_response(['ok'=>false,'error'=>['code'=>'ORIGIN_MISMATCH']],403);

$hash=hash('sha256',$pair);
$binding='pair:'.$extensionId;
$q=$pdo->prepare('SELECT id,user_id FROM extension_auth_codes WHERE code_hash=? AND redirect_uri=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1');
$q->execute([$hash,$binding]);
$row=$q->fetch();
if(!$row)json_response(['ok'=>true,'data'=>['status'=>'pending']]);

$ttl=max(1,min(90,(int)($config['extension']['session_ttl_days']??30)));
$expires=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$ttl.' days')->format('Y-m-d H:i:s');
$pdo->beginTransaction();
try{
    $q=$pdo->prepare('SELECT id,user_id FROM extension_auth_codes WHERE id=? AND code_hash=? AND redirect_uri=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE');
    $q->execute([$row['id'],$hash,$binding]);
    $locked=$q->fetch();
    if(!$locked){$pdo->rollBack();json_response(['ok'=>true,'data'=>['status'=>'pending']]);}
    $token=bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE extension_auth_codes SET used_at=NOW() WHERE id=?')->execute([$locked['id']]);
    $version=mb_substr(trim((string)($raw['client_version']??'')),0,32)?:null;
    $device=mb_substr(trim((string)($raw['device_name']??'Chrome extension')),0,190);
    try{
        $q=$pdo->prepare('INSERT INTO extension_sessions(user_id,token_hash,device_name,client_version,expires_at) VALUES(?,?,?,?,?)');
        $q->execute([$locked['user_id'],hash('sha256',$token),$device,$version,$expires]);
    }catch(PDOException $e){
        if(!str_contains(strtolower($e->getMessage()),'client_version'))throw $e;
        $q=$pdo->prepare('INSERT INTO extension_sessions(user_id,token_hash,device_name,expires_at) VALUES(?,?,?,?)');
        $q->execute([$locked['user_id'],hash('sha256',$token),$device,$expires]);
    }
    $pdo->commit();
    json_response(['ok'=>true,'data'=>['status'=>'connected','token'=>$token,'expires_at'=>$expires]],201);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    json_response(['ok'=>false,'error'=>['code'=>'PAIRING_FAILED','message'=>'Unable to finish extension pairing.']],500);
}