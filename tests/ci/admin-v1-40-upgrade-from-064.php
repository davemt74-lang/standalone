<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';require_once $root.'/app/migrations.php';
if((string)getenv('ADMIN_V140_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $name=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);$a=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$tick=chr(96);$q=$tick.str_replace($tick,$tick.$tick,$name).$tick;$a->exec('DROP DATABASE IF EXISTS '.$q);$a->exec('CREATE DATABASE '.$q.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tmp=sys_get_temp_dir().'/annotated-v140-'.bin2hex(random_bytes(5));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture.');
try{
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,'20260924_064_account_lifecycle_membership_entitlements.sql')<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);if(!installer_table_exists($pdo,'account_admin_events'))throw new RuntimeException('064 fixture did not reach account administration schema.');if(installer_table_exists($pdo,'stripe_webhook_events'))throw new RuntimeException('064 fixture unexpectedly contains Stripe Billing.');
    $applied=migration_apply_pending($pdo,$root.'/database/migrations',10);if(!in_array('20260924_065_stripe_billing_checkout',$applied,true))throw new RuntimeException('Upgrade did not apply migration 065.');
    foreach(['stripe_billing_settings','stripe_package_prices','stripe_customers','stripe_subscriptions','stripe_invoices','stripe_webhook_events','account_billing_events'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('065 upgraded schema missing '.$table.'.');
    $cols=$pdo->query("SHOW COLUMNS FROM accounts LIKE 'billing_source'")->fetchAll();if(!$cols)throw new RuntimeException('065 upgraded accounts schema missing billing_source.');
    $status=$pdo->query("SHOW COLUMNS FROM accounts LIKE 'subscription_status'")->fetch();if(!$status||!str_contains((string)$status['Type'],"'past_due'"))throw new RuntimeException('065 subscription_status enum does not contain past_due.');
    if(installer_pending_migrations($pdo,$root.'/database/migrations'))throw new RuntimeException('V1.40 upgrade left pending migrations.');
    if(migration_apply_pending($pdo,$root.'/database/migrations',10))throw new RuntimeException('V1.40 repeat upgrade is not a no-op.');
    echo "PASS: Admin V1.40 upgrade rehearsal advanced migration 064 through 065 and repeat pass was a no-op.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
