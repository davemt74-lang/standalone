<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';require_once $root.'/app/migrations.php';
if((string)getenv('ADMIN_V160_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $name=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);$a=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$tick=chr(96);$q=$tick.str_replace($tick,$tick.$tick,$name).$tick;$a->exec('DROP DATABASE IF EXISTS '.$q);$a->exec('CREATE DATABASE '.$q.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tmp=sys_get_temp_dir().'/annotated-v160-'.bin2hex(random_bytes(5));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture.');
try{
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,'20260924_068_account_invitations_membership_seats.sql')<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);if(!installer_table_exists($pdo,'account_invitations'))throw new RuntimeException('068 fixture did not reach V1.50 membership schema.');foreach(['billing_operations_settings','billing_daily_snapshots','billing_dunning_cases'] as $table)if(installer_table_exists($pdo,$table))throw new RuntimeException('068 fixture unexpectedly contains V1.60 table '.$table.'.');
    $applied=migration_apply_pending($pdo,$root.'/database/migrations',10);if(!in_array('20260924_069_billing_analytics_dunning',$applied,true))throw new RuntimeException('Upgrade did not apply migration 069.');
    foreach(['billing_operations_settings','billing_daily_snapshots','billing_account_snapshots','billing_dunning_cases','billing_dunning_events','billing_notes','billing_cancellation_feedback'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('069 upgraded schema missing '.$table.'.');
    $settings=$pdo->query('SELECT * FROM billing_operations_settings WHERE id=1')->fetch();if(!$settings||(int)$settings['suspend_after_grace']!==0)throw new RuntimeException('069 must seed conservative dunning policy with auto-suspension disabled.');
    if(installer_pending_migrations($pdo,$root.'/database/migrations'))throw new RuntimeException('V1.60 upgrade left pending migrations.');if(migration_apply_pending($pdo,$root.'/database/migrations',10))throw new RuntimeException('V1.60 repeat upgrade is not a no-op.');
    echo "PASS: Admin V1.60 upgrade rehearsal advanced migration 068 through 069 and repeat pass was a no-op.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
