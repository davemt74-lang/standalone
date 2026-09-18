<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$dsn=(string)getenv('DB_DSN');
$dbUser=(string)getenv('DB_USER');
$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');

require_once $root.'/app/installer.php';

function install_test(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}

$serverDsn=(string)preg_replace('/;dbname=[^;]*/i','',$dsn);
$admin=new PDO($serverDsn,$dbUser,$dbPass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);

$dbName='annotated_install_'.substr(bin2hex(random_bytes(8)),0,12);
$quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);
$admin->exec("CREATE DATABASE $quoted CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try{
    $testDsn=$serverDsn.';dbname='.$dbName;
    $pdo=new PDO($testDsn,$dbUser,$dbPass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    $schema=$root.'/database/schema.sql';
    $migrations=$root.'/database/migrations';

    install_test(installer_database_table_count($pdo)===0,'fresh installer starts from an empty database');

    $result=installer_run($pdo,$schema,$migrations);
    install_test(($result['schema_statements']??0)>0,'installer imports the base schema');
    install_test(installer_base_schema_ready($pdo,$schema),'all base schema tables exist after install');

    $files=glob($migrations.'/*.sql')?:[];
    sort($files,SORT_STRING);
    $expected=count($files);
    $applied=(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    install_test($applied===$expected,"installer applies all $expected bundled migrations");
    install_test(count(installer_pending_migrations($pdo,$migrations))===0,'installer leaves no pending migrations');
    install_test((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'installer does not pre-create an administrator');

    $second=installer_run($pdo,$schema,$migrations);
    install_test(($second['schema_statements']??-1)===0,'base schema install is idempotent once complete');
    install_test(count($second['migrations']??[])===0,'migration install is idempotent once complete');

    $pdo->exec('CREATE TABLE installer_probe(id INT PRIMARY KEY)');
    install_test(installer_table_exists($pdo,'installer_probe'),'installer table detection works after initialization');

    echo "Fresh install MariaDB suite passed.\n";
}finally{
    $admin->exec("DROP DATABASE IF EXISTS $quoted");
}
