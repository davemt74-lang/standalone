<?php
declare(strict_types=1);

$root=__DIR__;
$configFile=$root.'/config.php';
$error='';
$ready=false;
$config=null;
$pdo=null;
$databaseStatus='Not connected.';

if(!is_file($configFile)){
    http_response_code(503);
    $error='Annotated is not configured yet. Copy config.example.php to config.php and enter the MariaDB connection settings.';
}else{
    $config=require $configFile;
    date_default_timezone_set('UTC');
    if(session_status()!==PHP_SESSION_ACTIVE){
        $secure=strtolower((string)(parse_url((string)($config['app']['base_url']??''),PHP_URL_SCHEME)?:''))==='https';
        session_name($config['app']['session_name']??'annotated_session');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
        session_start();
    }
    if(empty($_SESSION['install_csrf']))$_SESSION['install_csrf']=bin2hex(random_bytes(32));
    try{
        $db=$config['db']??[];
        $pdo=new PDO((string)($db['dsn']??''),(string)($db['user']??''),(string)($db['pass']??''),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
        require_once $root.'/app/installer.php';
        $schemaFile=$root.'/database/schema.sql';
        $migrationDir=$root.'/database/migrations';
        $baseReady=installer_base_schema_ready($pdo,$schemaFile);
        if($baseReady&&installer_table_exists($pdo,'users')){
            $users=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if($users>0){header('Location: /');exit;}
        }
        $tableCount=installer_database_table_count($pdo);
        $ready=$baseReady||$tableCount===0;
        $databaseStatus=$tableCount===0?'Database is empty and ready for installation.':($baseReady?'Annotated schema detected.':'Database contains existing tables and cannot be initialized automatically.');
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $sent=(string)($_POST['csrf']??'');
            if(!hash_equals((string)($_SESSION['install_csrf']??''),$sent))throw new RuntimeException('The installer form expired. Reload the page and try again.');
            if(!$ready)throw new RuntimeException('The database is not empty and is not a complete Annotated schema. Use an empty database for a fresh install.');
            installer_run($pdo,$schemaFile,$migrationDir);
            unset($_SESSION['install_csrf']);
            header('Location: /first-admin.php');
            exit;
        }
        if($baseReady){
            $pending=installer_pending_migrations($pdo,$migrationDir);
            if(!$pending){header('Location: /first-admin.php');exit;}
        }
    }catch(Throwable $e){
        $error=$e->getMessage();
        $ready=false;
        $databaseStatus='Database check failed.';
    }
}
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install Annotated</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><main class="panel narrow"><span class="eyebrow">ANNOTATED SETUP</span><h1>Install Annotated</h1>
<p>This installer creates the base MariaDB schema and applies every bundled database migration. No SQL import or setup key is required.</p>
<?php if($error):?><div class="error"><?=htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></div><?php endif?>
<div class="card"><strong>Configuration</strong><p class="meta"><?=is_file($configFile)?'config.php found.':'config.php is missing.'?></p></div>
<?php if($pdo):?><div class="card"><strong>Database</strong><p class="meta">Connection successful. <?=htmlspecialchars($databaseStatus,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></p></div><?php endif?>
<?php if($ready):?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=htmlspecialchars((string)($_SESSION['install_csrf']??''),ENT_QUOTES,'UTF-8')?>"><button>Install Annotated</button></form><?php else:?><p>Correct the configuration or select an empty MariaDB database, then reload this page.</p><?php endif?>
</main></body></html>
