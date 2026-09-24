<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';require_once $root.'/app/migrations.php';
if((string)getenv('ADMIN_V220_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $name=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);$a=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$tick=chr(96);$q=$tick.str_replace($tick,$tick.$tick,$name).$tick;$a->exec('DROP DATABASE IF EXISTS '.$q);$a->exec('CREATE DATABASE '.$q.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tmp=sys_get_temp_dir().'/annotated-v220-'.bin2hex(random_bytes(5));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture.');
try{
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,'20260924_074_admin_roles_permissions_approvals.sql')<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);if(!installer_table_exists($pdo,'admin_roles')||!installer_table_exists($pdo,'admin_action_approvals'))throw new RuntimeException('074 fixture did not reach Admin V2.10 access-control schema.');if(installer_table_exists($pdo,'admin_support_cases'))throw new RuntimeException('074 fixture unexpectedly contains V2.20 support schema.');
    $applied=migration_apply_pending($pdo,$root.'/database/migrations',10);if(!in_array('20260924_075_admin_support_operations',$applied,true))throw new RuntimeException('Upgrade did not apply migration 075.');
    foreach(['admin_support_cases','admin_support_case_events','admin_support_case_links','admin_support_saved_views'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('075 upgraded schema missing '.$table.'.');
    $q=$pdo->query("SELECT capabilities_json FROM admin_roles WHERE role_key='support_admin'");$caps=json_decode((string)$q->fetchColumn(),true)?:[];if(!in_array('admin.support.manage',$caps,true))throw new RuntimeException('Migration 075 did not activate Support Admin capabilities.');
    if(installer_pending_migrations($pdo,$root.'/database/migrations'))throw new RuntimeException('Admin V2.20 upgrade left pending migrations.');if(migration_apply_pending($pdo,$root.'/database/migrations',10))throw new RuntimeException('Admin V2.20 repeat upgrade is not a no-op.');
    echo "PASS: Admin V2.20 upgrade rehearsal advanced migration 074 through 075 and repeat pass was a no-op.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
