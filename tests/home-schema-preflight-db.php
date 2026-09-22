<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$dsn=(string)getenv('DB_DSN');
$dbUser=(string)getenv('DB_USER');
$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');

require_once $root.'/app/installer.php';
require_once $root.'/app/schema-health.php';

function home_schema_test(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}

$serverDsn=(string)preg_replace('/;dbname=[^;]*/i','',$dsn);
$admin=new PDO($serverDsn,$dbUser,$dbPass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$dbName='annotated_home_schema_'.substr(bin2hex(random_bytes(8)),0,12);
$quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);
$admin->exec("CREATE DATABASE $quoted CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try{
    $pdo=new PDO($serverDsn.';dbname='.$dbName,$dbUser,$dbPass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $migrationDir=$root.'/database/migrations';

    $empty=app_schema_runtime_status($pdo,$migrationDir);
    home_schema_test(empty($empty['ready']),'empty database is rejected by Home schema preflight');
    home_schema_test(in_array('schema_migrations',$empty['pending'],true),'empty database reports missing migration ledger');

    installer_run($pdo,$root.'/database/schema.sql',$migrationDir);
    $current=app_schema_runtime_status($pdo,$migrationDir);
    home_schema_test(!empty($current['ready']),'fully migrated database passes Home schema preflight');
    home_schema_test(empty($current['pending'])&&empty($current['changed']),'current schema has no pending or changed migrations');

    if(migration_column_exists($pdo,'users','profile_image_url'))$pdo->exec('ALTER TABLE users DROP COLUMN profile_image_url');
    $drift=app_schema_runtime_status($pdo,$migrationDir);
    home_schema_test(empty($drift['ready']),'schema drift is rejected even when migration history is current');
    home_schema_test(in_array('column:users.profile_image_url',$drift['pending'],true),'Home preflight identifies missing profile image column');

    echo "Home schema preflight database suite passed.\n";
}finally{
    $admin->exec("DROP DATABASE IF EXISTS $quoted");
}
