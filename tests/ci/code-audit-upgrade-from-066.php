<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';require_once $root.'/app/migrations.php';
if((string)getenv('CODE_AUDIT_067_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $name=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);$a=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$tick=chr(96);$q=$tick.str_replace($tick,$tick.$tick,$name).$tick;$a->exec('DROP DATABASE IF EXISTS '.$q);$a->exec('CREATE DATABASE '.$q.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tmp=sys_get_temp_dir().'/annotated-audit067-'.bin2hex(random_bytes(5));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture.');
try{
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,'20260924_066_code_audit_hardening.sql')<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);if(!installer_table_exists($pdo,'stripe_account_state_watermarks'))throw new RuntimeException('066 fixture did not reach the shipped audit-hardening schema.');if(installer_table_exists($pdo,'ai_usage_reservations')||installer_table_exists($pdo,'stripe_checkout_sessions'))throw new RuntimeException('066 fixture unexpectedly contains 067 quota/Checkout schema.');
    $applied=migration_apply_pending($pdo,$root.'/database/migrations',10);if(!in_array('20260924_067_ai_quota_checkout_idempotency',$applied,true))throw new RuntimeException('Upgrade did not apply migration 067.');
    foreach(['ai_usage_reservations','stripe_checkout_sessions'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('067 upgraded schema missing '.$table.'.');
    if(installer_pending_migrations($pdo,$root.'/database/migrations'))throw new RuntimeException('067 upgrade left pending migrations.');
    if(migration_apply_pending($pdo,$root.'/database/migrations',10))throw new RuntimeException('067 repeat upgrade is not a no-op.');
    echo "PASS: Code audit continuation advanced shipped migration 066 through 067 and repeat pass was a no-op.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
