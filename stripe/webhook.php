<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);header('Allow: POST');echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}
try{
    $payload=(string)file_get_contents('php://input');
    $signature=(string)($_SERVER['HTTP_STRIPE_SIGNATURE']??'');
    $result=stripe_billing_process_webhook($pdo,$config,$payload,$signature);
    http_response_code(200);
    echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
    error_log('Annotated Stripe webhook rejected: '.$e->getMessage());
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Webhook rejected.'],JSON_UNESCAPED_SLASHES);
}