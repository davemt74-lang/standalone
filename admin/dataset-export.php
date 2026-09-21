<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required.');}
require_csrf();
if(!data_dataset_ready($pdo)){http_response_code(503);exit('Dataset Registry requires the Phase 38 database upgrade.');}
$id=trim((string)($_POST['id']??''));$mode=(string)($_POST['mode']??'manifest');$includeText=$mode==='data';
try{
    $dataset=data_dataset_get($pdo,$id);if(!$dataset)throw new RuntimeException('Dataset not found.');
    $payload=data_dataset_export($pdo,$u,$id,$includeText);
    $file=data_dataset_slug((string)$dataset['name']).'-v'.(int)$dataset['version_number'].($includeText?'-data':'-manifest').'.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$file.'"');
    header('Cache-Control: no-store');
    echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
    http_response_code(409);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();
}