<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required.');}
require_csrf();
if(!data_evaluation_ready($pdo)){http_response_code(503);exit('Evaluation Harness requires the Phase 39 database upgrade.');}
$id=trim((string)($_POST['run_id']??''));
try{
    $payload=data_evaluation_export_run($pdo,$u,$id);$file='annotated-evaluation-'.preg_replace('/[^a-zA-Z0-9_-]+/','-',strtolower($id)).'.json';
    header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="'.$file.'"');header('Cache-Control: no-store');
    echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(409);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();}
