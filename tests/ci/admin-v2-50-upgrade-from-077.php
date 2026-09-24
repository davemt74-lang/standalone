<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';require_once $root.'/app/migrations.php';
if((string)getenv('ADMIN_V250_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $name=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);$a=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$tick=chr(96);$q=$tick.str_replace($tick,$tick.$tick,$name).$tick;$a->exec('DROP DATABASE IF EXISTS '.$q);$a->exec('CREATE DATABASE '.$q.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tmp=sys_get_temp_dir().'/annotated-v250-'.bin2hex(random_bytes(5));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture.');
try{
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,'20260924_077_admin_customer_success_health.sql')<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);if(!installer_table_exists($pdo,'admin_customer_health_snapshots')||!installer_table_exists($pdo,'admin_security_audit_events'))throw new RuntimeException('077 fixture did not reach Admin V2.40 schema.');if(installer_table_exists($pdo,'admin_security_cases'))throw new RuntimeException('077 fixture unexpectedly contains V2.50 schema.');
    $applied=migration_apply_pending($pdo,$root.'/database/migrations',10);if(!in_array('20260924_078_admin_security_compliance_audit',$applied,true))throw new RuntimeException('Upgrade did not apply migration 078.');
    foreach(['admin_security_cases','admin_security_case_events','admin_privacy_requests','admin_privacy_request_events','admin_audit_exports'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('078 upgraded schema missing '.$table.'.');
    $cols=$pdo->query("SHOW COLUMNS FROM admin_security_audit_events")->fetchAll();$names=array_column($cols,'Field');foreach(['account_id','source_domain','source_event_public_id','correlation_id','sensitivity','metadata_json','actor_ip_hash','actor_user_agent_hash'] as $col)if(!in_array($col,$names,true))throw new RuntimeException('078 security audit extension missing '.$col.'.');
    $q=$pdo->query("SELECT capabilities_json FROM admin_roles WHERE role_key='security_compliance_admin'");$caps=json_decode((string)$q->fetchColumn(),true)?:[];if(!in_array('admin.security.manage',$caps,true)||!in_array('admin.privacy.manage',$caps,true)||in_array('admin.support.manage',$caps,true))throw new RuntimeException('Migration 078 did not seed bounded Security & Compliance authority.');
    if(installer_pending_migrations($pdo,$root.'/database/migrations'))throw new RuntimeException('Admin V2.50 upgrade left pending migrations.');if(migration_apply_pending($pdo,$root.'/database/migrations',10))throw new RuntimeException('Admin V2.50 repeat upgrade is not a no-op.');
    echo "PASS: Admin V2.50 upgrade rehearsal advanced migration 077 through 078 and repeat pass was a no-op.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
