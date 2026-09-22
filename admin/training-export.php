<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('POST required.');}
require_csrf();
if(!data_training_ready($pdo)){http_response_code(503);exit('Training Registry requires the Phase 41 database upgrade.');}
$id=trim((string)($_POST['job_id']??''));
try{$pkg=data_training_export_package($pdo,$u,$id);$file='annotated-training-'.preg_replace('/[^a-zA-Z0-9_-]+/','-',strtolower($id)).'.jsonl';header('Content-Type: application/x-ndjson; charset=utf-8');header('Content-Disposition: attachment; filename="'.$file.'"');header('X-Content-SHA256: '.$pkg['hash']);header('Cache-Control: no-store');echo $pkg['content'];}
catch(Throwable $e){http_response_code(409);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();}
