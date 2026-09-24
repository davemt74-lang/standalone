<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';require_once $root.'/app/migrations.php';
if((string)getenv('ADMIN_V170_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $name=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);$a=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$tick=chr(96);$q=$tick.str_replace($tick,$tick.$tick,$name).$tick;$a->exec('DROP DATABASE IF EXISTS '.$q);$a->exec('CREATE DATABASE '.$q.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tmp=sys_get_temp_dir().'/annotated-v170-'.bin2hex(random_bytes(5));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture.');
try{
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,'20260924_069_billing_analytics_dunning.sql')<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);if(!installer_table_exists($pdo,'billing_operations_settings'))throw new RuntimeException('069 fixture did not reach V1.60 billing operations schema.');if(installer_table_exists($pdo,'ai_overage_usage_ledger'))throw new RuntimeException('069 fixture unexpectedly contains V1.70 overage schema.');
    $applied=migration_apply_pending($pdo,$root.'/database/migrations',10);if(!in_array('20260924_070_ai_overage_billing_usage_plans',$applied,true))throw new RuntimeException('Upgrade did not apply migration 070.');
    foreach(['ai_overage_billing_settings','ai_overage_account_settings','ai_overage_period_entitlements','ai_overage_usage_ledger','ai_overage_report_batches','ai_overage_threshold_events','ai_overage_audit_events'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('070 upgraded schema missing '.$table.'.');
    $columns=$pdo->query("SHOW COLUMNS FROM subscription_packages")->fetchAll();$names=array_column($columns,'Field');foreach(['ai_overage_policy','ai_overage_input_micros_per_million','ai_overage_output_micros_per_million','ai_overage_default_cap_cents'] as $col)if(!in_array($col,$names,true))throw new RuntimeException('070 missing package column '.$col.'.');
    $q=$pdo->query("SELECT COUNT(*) FROM subscription_packages WHERE ai_overage_policy<>'hard_limit'");if((int)$q->fetchColumn()!==0)throw new RuntimeException('070 must preserve conservative hard-limit defaults for existing packages.');
    if(installer_pending_migrations($pdo,$root.'/database/migrations'))throw new RuntimeException('V1.70 upgrade left pending migrations.');if(migration_apply_pending($pdo,$root.'/database/migrations',10))throw new RuntimeException('V1.70 repeat upgrade is not a no-op.');
    echo "PASS: Admin V1.70 upgrade rehearsal advanced migration 069 through 070 and repeat pass was a no-op.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
