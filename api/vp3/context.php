<?php
declare(strict_types=1);
$GLOBALS['annotated_shell_disabled']=true;
require dirname(__DIR__,2).'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$body=file_get_contents('php://input')?:'';
try{$identity=vp3_host_verify_request($pdo,$body);$viewer=vp3_host_local_user($identity);$context=vp3_host_context($pdo,$viewer);vp3_host_audit($pdo,(int)$viewer['id'],(string)$identity['host_user_id'],'context_read',['summary'=>$context['summary']],(string)($_SERVER['HTTP_X_VP3_NONCE']??''));echo json_encode(['ok'=>true,'data'=>$context],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){$msg=$e->getMessage();$status=str_contains($msg,'has not launched')?404:403;http_response_code($status);echo json_encode(['ok'=>false,'error'=>['code'=>$status===404?'IDENTITY_NOT_LINKED':'VP3_AUTH_FAILED','message'=>$msg]],JSON_UNESCAPED_SLASHES);}
