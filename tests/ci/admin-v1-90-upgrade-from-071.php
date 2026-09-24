<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';require_once $root.'/app/migrations.php';
if((string)getenv('ADMIN_V190_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $name=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);$a=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$tick=chr(96);$q=$tick.str_replace($tick,$tick.$tick,$name).$tick;$a->exec('DROP DATABASE IF EXISTS '.$q);$a->exec('CREATE DATABASE '.$q.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tmp=sys_get_temp_dir().'/annotated-v190-'.bin2hex(random_bytes(5));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture.');
try{
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,'20260924_071_coupons_promotions_credits.sql')<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);if(!installer_table_exists($pdo,'commercial_promotions'))throw new RuntimeException('071 fixture did not reach V1.80 promotions schema.');if(installer_table_exists($pdo,'commercial_invoice_evidence'))throw new RuntimeException('071 fixture unexpectedly contains V1.90 invoice evidence schema.');
    $applied=migration_apply_pending($pdo,$root.'/database/migrations',10);if(!in_array('20260924_072_tax_invoices_commercial_billing_hardening',$applied,true))throw new RuntimeException('Upgrade did not apply migration 072.');
    foreach(['commercial_billing_settings','account_billing_profiles','commercial_tax_id_snapshots','commercial_invoice_evidence','commercial_billing_audit_events'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('072 upgraded schema missing '.$table.'.');
    $columns=$pdo->query('SHOW COLUMNS FROM stripe_invoices')->fetchAll();$names=array_column($columns,'Field');foreach(['subtotal_cents','discount_amount_cents','tax_amount_cents','total_cents','automatic_tax_status','customer_tax_ids_json','finalized_at'] as $col)if(!in_array($col,$names,true))throw new RuntimeException('072 missing stripe_invoices column '.$col.'.');
    $q=$pdo->query("SELECT COUNT(*) FROM commercial_billing_settings WHERE automatic_tax_enabled<>0");if((int)$q->fetchColumn()!==0)throw new RuntimeException('072 must preserve automatic tax off by default.');
    if(installer_pending_migrations($pdo,$root.'/database/migrations'))throw new RuntimeException('V1.90 upgrade left pending migrations.');if(migration_apply_pending($pdo,$root.'/database/migrations',10))throw new RuntimeException('V1.90 repeat upgrade is not a no-op.');
    echo "PASS: Admin V1.90 upgrade rehearsal advanced migration 071 through 072 and repeat pass was a no-op.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
