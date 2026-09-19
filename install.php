<?php
declare(strict_types=1);

$root=__DIR__;
$configFile=$root.'/config.php';
require_once $root.'/app/installer.php';

date_default_timezone_set('UTC');
if(session_status()!==PHP_SESSION_ACTIVE){
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.cookie_httponly','1');
    session_name('annotated_install');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
if(empty($_SESSION['install_csrf']))$_SESSION['install_csrf']=bin2hex(random_bytes(32));

$error='';
$pdo=null;
$config=null;
$databaseStatus='Not connected.';
$step=is_file($configFile)?'database':'configuration';
$defaults=[
    'base_url'=>installer_default_base_url(),
    'db_host'=>'127.0.0.1',
    'db_port'=>'3306',
    'db_name'=>'annotated',
    'db_user'=>'',
];

try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $sent=(string)($_POST['csrf']??'');
        if(!hash_equals((string)($_SESSION['install_csrf']??''),$sent))throw new RuntimeException('The installer form expired. Reload the page and try again.');

        $action=(string)($_POST['action']??'');
        if($action==='configure'){
            if(is_file($configFile))throw new RuntimeException('config.php already exists. Reload the installer.');
            $config=installer_build_config($_POST,$root);
            $pdo=installer_connect($config);
            $tableCount=installer_database_table_count($pdo);
            if($tableCount!==0)throw new RuntimeException('The selected database is not empty. Use an empty MariaDB database for a new Annotated installation.');
            installer_write_config($configFile,$config);
            $step='database';
        }elseif($action==='install'){
            if(!is_file($configFile))throw new RuntimeException('Configuration has not been created yet.');
            $config=require $configFile;
            $pdo=installer_connect($config);
            $schemaFile=$root.'/database/schema.sql';
            $migrationDir=$root.'/database/migrations';

            if(installer_base_schema_ready($pdo,$schemaFile)&&installer_table_exists($pdo,'users')){
                $users=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                if($users>0){header('Location: /');exit;}
            }

            $tableCount=installer_database_table_count($pdo);
            $baseReady=installer_base_schema_ready($pdo,$schemaFile);
            if(!$baseReady&&$tableCount>0)throw new RuntimeException('The database contains existing tables but is not a complete Annotated schema. Use an empty database for a fresh install.');

            installer_run($pdo,$schemaFile,$migrationDir);
            unset($_SESSION['install_csrf']);
            header('Location: /first-admin.php');
            exit;
        }
    }

    if(is_file($configFile)){
        $config=require $configFile;
        $pdo=installer_connect($config);
        $schemaFile=$root.'/database/schema.sql';
        $migrationDir=$root.'/database/migrations';
        $baseReady=installer_base_schema_ready($pdo,$schemaFile);
        $tableCount=installer_database_table_count($pdo);
        $databaseStatus=$tableCount===0?'Database connection successful and empty.':($baseReady?'Annotated schema detected.':'Database contains existing tables.');
        if($baseReady){
            if(installer_table_exists($pdo,'users')&&(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()>0){header('Location: /');exit;}
            if(count(installer_pending_migrations($pdo,$migrationDir))===0){header('Location: /first-admin.php');exit;}
        }
    }
}catch(Throwable $e){
    $error=$e->getMessage();
    if(!is_file($configFile))$step='configuration';
}

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install Annotated</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.installShell{max-width:760px;margin:48px auto;padding:24px}.installGrid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.installGrid .full{grid-column:1/-1}.installStatus{margin:18px 0}.installActions{margin-top:18px}@media(max-width:680px){.installGrid{grid-template-columns:1fr}.installGrid .full{grid-column:auto}}
</style>
</head>
<body>
<main class="panel installShell">
<span class="eyebrow">ANNOTATED SETUP</span>
<h1>Install Annotated</h1>
<p>Set the database connection once. Annotated will create <code>config.php</code>, import the base schema, apply every bundled migration, and then create your first administrator.</p>
<p class="meta">No SQL import and no setup key are required.</p>

<?php if($error):?><div class="error"><?=htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></div><?php endif?>

<?php if($step==='configuration'):?>
<form method="post" class="stack">
<input type="hidden" name="csrf" value="<?=htmlspecialchars((string)$_SESSION['install_csrf'],ENT_QUOTES,'UTF-8')?>">
<input type="hidden" name="action" value="configure">
<div class="installGrid">
<label class="full">Site URL
<input name="base_url" required value="<?=htmlspecialchars((string)($_POST['base_url']??$defaults['base_url']),ENT_QUOTES,'UTF-8')?>" placeholder="https://annotated.example.com">
</label>
<label>Database host
<input name="db_host" required value="<?=htmlspecialchars((string)($_POST['db_host']??$defaults['db_host']),ENT_QUOTES,'UTF-8')?>">
</label>
<label>Database port
<input name="db_port" inputmode="numeric" required value="<?=htmlspecialchars((string)($_POST['db_port']??$defaults['db_port']),ENT_QUOTES,'UTF-8')?>">
</label>
<label>Database name
<input name="db_name" required value="<?=htmlspecialchars((string)($_POST['db_name']??$defaults['db_name']),ENT_QUOTES,'UTF-8')?>">
</label>
<label>Database username
<input name="db_user" required autocomplete="username" value="<?=htmlspecialchars((string)($_POST['db_user']??$defaults['db_user']),ENT_QUOTES,'UTF-8')?>">
</label>
<label class="full">Database password
<input type="password" name="db_pass" autocomplete="current-password">
</label>
</div>
<div class="installActions"><button>Save &amp; Test Database</button></div>
</form>
<?php else:?>
<div class="card installStatus">
<strong>Configuration</strong>
<p class="meta">config.php created.</p>
</div>
<div class="card installStatus">
<strong>Database</strong>
<p class="meta"><?=htmlspecialchars($databaseStatus,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></p>
</div>
<form method="post" class="stack">
<input type="hidden" name="csrf" value="<?=htmlspecialchars((string)$_SESSION['install_csrf'],ENT_QUOTES,'UTF-8')?>">
<input type="hidden" name="action" value="install">
<button>Install Annotated</button>
</form>
<?php endif?>
</main>
</body>
</html>
