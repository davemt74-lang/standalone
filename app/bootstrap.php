<?php
declare(strict_types=1);
$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) { http_response_code(503); exit('Annotated is not configured. Copy config.example.php to config.php and set database credentials.'); }
$config = require $configFile;
if (session_status() !== PHP_SESSION_ACTIVE) { session_name($config['app']['session_name'] ?? 'annotated_session'); session_start(); }
try { $pdo = new PDO($config['db']['dsn'],$config['db']['user'],$config['db']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
catch (Throwable $e) { http_response_code(503); exit('Database unavailable.'); }
require_once __DIR__ . '/functions.php';
