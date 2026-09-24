<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';require_once $root.'/app/migrations.php';
if((string)getenv('ADMIN_V210_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $name=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);$a=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$tick=chr(96);$q=$tick.str_replace($tick,$tick.$tick,$name).$tick;$a->exec('DROP DATABASE IF EXISTS '.$q);$a->exec('CREATE DATABASE '.$q.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tmp=sys_get_temp_dir().'/annotated-v210-'.bin2hex(random_bytes(5));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture.');
try{
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,'20260924_073_admin_v2_operations_command_center.sql')<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);if(!installer_table_exists($pdo,'admin_operations_alerts'))throw new RuntimeException('073 fixture did not reach Admin V2.0 operations schema.');if(installer_table_exists($pdo,'admin_roles'))throw new RuntimeException('073 fixture unexpectedly contains V2.10 access-control schema.');
    $applied=migration_apply_pending($pdo,$root.'/database/migrations',10);if(!in_array('20260924_074_admin_roles_permissions_approvals',$applied,true))throw new RuntimeException('Upgrade did not apply migration 074.');
    foreach(['admin_roles','admin_approval_policies','admin_action_approvals','admin_security_audit_events'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('074 upgraded schema missing '.$table.'.');
    $roles=(int)$pdo->query("SELECT COUNT(*) FROM admin_roles WHERE is_system=1 AND status='active'")->fetchColumn();if($roles<8)throw new RuntimeException('Migration 074 did not seed the system role registry.');
    $linked=(int)$pdo->query("SELECT COUNT(*) FROM admin_operator_profiles WHERE role_id IS NOT NULL")->fetchColumn();$profiles=(int)$pdo->query("SELECT COUNT(*) FROM admin_operator_profiles")->fetchColumn();if($linked!==$profiles)throw new RuntimeException('Migration 074 did not backfill all existing operator profiles to role IDs.');
    if(installer_pending_migrations($pdo,$root.'/database/migrations'))throw new RuntimeException('Admin V2.10 upgrade left pending migrations.');if(migration_apply_pending($pdo,$root.'/database/migrations',10))throw new RuntimeException('Admin V2.10 repeat upgrade is not a no-op.');
    echo "PASS: Admin V2.10 upgrade rehearsal advanced migration 073 through 074 and repeat pass was a no-op.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
