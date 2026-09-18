<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    exit('Annotated is not configured. Copy config.example.php to config.php and configure the database.');
}
$config = require $configFile;
date_default_timezone_set('UTC');

if (PHP_SAPI !== 'cli') {
    $baseScheme=strtolower((string)(parse_url((string)($config['app']['base_url']??''),PHP_URL_SCHEME)?:''));
    $secure=$baseScheme==='https';
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.cookie_httponly','1');
    session_name($config['app']['session_name'] ?? 'annotated_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $now=time();if(!empty($_SESSION['user_id'])){if(!empty($_SESSION['last_activity'])&&(int)$_SESSION['last_activity']<$now-43200){$_SESSION=[];session_regenerate_id(true);}elseif(empty($_SESSION['rotated_at'])||(int)$_SESSION['rotated_at']<$now-1800){session_regenerate_id(true);$_SESSION['rotated_at']=$now;}$_SESSION['last_activity']=$now;}
    $contentLength=(int)($_SERVER['CONTENT_LENGTH']??0);if($contentLength>50*1024*1024){http_response_code(413);exit('Request is too large.');}
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; media-src 'self' blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self' https://accounts.google.com https://twitter.com");
    header('Permissions-Policy: camera=(), geolocation=(), payment=(), usb=()');
    if($secure)header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$db = $config['db'];
$pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/concurrency.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/source-integrity.php';
require_once __DIR__ . '/rate-limit.php';
