<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$dsn=(string)getenv('DB_DSN');
$dbUser=(string)getenv('DB_USER');
$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');

require_once $root.'/app/installer.php';

$pdo=new PDO($dsn,$dbUser,$dbPass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);

$result=installer_run($pdo,$root.'/database/schema.sql',$root.'/database/migrations');
$pending=installer_pending_migrations($pdo,$root.'/database/migrations');
if($pending)throw new RuntimeException('Targeted CI schema preparation left pending migrations: '.implode(', ',$pending));
if(!installer_base_schema_ready($pdo,$root.'/database/schema.sql'))throw new RuntimeException('Targeted CI base schema is incomplete.');

echo 'Targeted CI schema ready; applied '.count($result['migrations']??[])." migration(s).\n";
