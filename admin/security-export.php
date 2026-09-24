<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$admin=require_admin($pdo);if(!admin_security_ready($pdo)){http_response_code(503);exit('Admin V2.50 Security & Compliance requires migration 078.');}
admin_access_assert_capability($pdo,$admin,'admin.security.export');$format=(string)($_GET['format']??'csv');$format=$format==='json'?'json':'csv';$result=admin_security_export($pdo,$admin,$_GET,$format);$ext=$format==='json'?'json':'csv';header('Cache-Control: no-store, private');header('Content-Type: '.($format==='json'?'application/json':'text/csv').'; charset=utf-8');header('Content-Disposition: attachment; filename="annotated-admin-audit-'.gmdate('Ymd-His').'.'.$ext.'"');header('X-Content-SHA256: '.$result['sha256']);echo $result['content'];
