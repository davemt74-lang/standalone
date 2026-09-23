<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime-compat.php';

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    if(PHP_SAPI!=='cli'){header('Location: /install.php');exit;}
    throw new RuntimeException('Annotated is not configured. Run the web installer first.');
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
    $contentLength=(int)($_SERVER['CONTENT_LENGTH']??0);$requestPath=(string)(parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)?:'');$requestLimit=$requestPath==='/api/research-workspace-upload.php'?210*1024*1024:50*1024*1024;if($contentLength>$requestLimit){http_response_code(413);exit('Request is too large.');}
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; media-src 'self' blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self' https://accounts.google.com https://twitter.com");
    header('Permissions-Policy: camera=(), geolocation=(), payment=(), usb=()');
    if($secure)header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$db = $config['db'];
$pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
require_once __DIR__ . '/installer.php';
if(PHP_SAPI!=='cli'&&!installer_table_exists($pdo,'users')){
    header('Location: /install.php');
    exit;
}
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/concurrency.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/shell.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/object-handoff.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/source-integrity.php';
require_once __DIR__ . '/annotation-intelligence.php';
require_once __DIR__ . '/live.php';
require_once __DIR__ . '/conversations.php';
require_once __DIR__ . '/moderation.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/release.php';
require_once __DIR__ . '/release-operations.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/proactive-intelligence.php';
require_once __DIR__ . '/research-automation.php';
require_once __DIR__ . '/research-agents.php';
require_once __DIR__ . '/research-agent-workspace.php';
require_once __DIR__ . '/research-retrieval.php';
require_once __DIR__ . '/research-autonomy.php';
require_once __DIR__ . '/research-monitoring.php';
require_once __DIR__ . '/cross-research.php';
require_once __DIR__ . '/research-outcomes.php';
require_once __DIR__ . '/research-reviews.php';
require_once __DIR__ . '/change-impact.php';
require_once __DIR__ . '/research-portfolio.php';
require_once __DIR__ . '/living-research.php';
require_once __DIR__ . '/research-network.php';
require_once __DIR__ . '/research-provenance.php';
require_once __DIR__ . '/data-attribution.php';
require_once __DIR__ . '/data-datasets.php';
require_once __DIR__ . '/data-evaluations.php';
require_once __DIR__ . '/data-model-registry.php';
require_once __DIR__ . '/data-training.php';
require_once __DIR__ . '/data-post-training.php';
require_once __DIR__ . '/data-model-release.php';
require_once __DIR__ . '/data-model-deployment.php';
require_once __DIR__ . '/data-model-observability.php';
require_once __DIR__ . '/data-model-improvement.php';
require_once __DIR__ . '/data-model-campaigns.php';
require_once __DIR__ . '/intelligence-release-audit.php';
require_once __DIR__ . '/research-verification.php';
require_once __DIR__ . '/research-evidence-packs.php';
require_once __DIR__ . '/research-workflow.php';
