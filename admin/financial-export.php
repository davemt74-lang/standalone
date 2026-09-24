<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$admin=require_admin($pdo);if(!admin_finance_ready($pdo)){http_response_code(503);exit('Admin V2.30 Financial Reporting requires migration 076.');}
admin_access_assert_capability($pdo,$admin,'admin.finance.export');$type=trim((string)($_GET['type']??'ledger'));$filters=['from'=>(string)($_GET['from']??''),'to'=>(string)($_GET['to']??''),'status'=>(string)($_GET['status']??'')];
try{$result=admin_finance_export($pdo,$admin,$type,$filters);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.str_replace('"','',(string)$result['filename']).'"');header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store, private');echo $result['content'];}catch(Throwable $e){http_response_code(400);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();}
