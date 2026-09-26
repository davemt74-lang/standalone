<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if((string)getenv('PHASE67_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);
    $admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase67-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
    $baseline='20260924_079_admin_platform_configuration_governance.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);
    if(!installer_table_exists($pdo,'admin_platform_features'))throw new RuntimeException('079 fixture did not reach Admin Platform Governance.');
    if(installer_table_exists($pdo,'research_system_reports'))throw new RuntimeException('079 fixture unexpectedly contains Phase 67 System Reports schema.');
    $applied=migration_apply_pending($pdo,$root.'/database/migrations',20);
    if(!in_array('20260925_080_research_agent_knowledge_system_reports',$applied,true))throw new RuntimeException('Upgrade did not apply migration 080.');
    if(!in_array('20260926_081_phase67_system_report_provenance_hardening',$applied,true))throw new RuntimeException('Upgrade did not apply migration 081.');
    foreach(['research_system_reports','research_system_report_events'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('080 upgraded schema missing '.$table.'.');
    $nullable=(string)$pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research_system_reports' AND COLUMN_NAME='requested_by_user_id'")->fetchColumn();
    if($nullable!=='YES')throw new RuntimeException('081 must make requested_by_user_id nullable.');
    $deleteRule=(string)$pdo->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='research_system_reports' AND CONSTRAINT_NAME='fk_system_reports_requester'")->fetchColumn();
    if(strtoupper($deleteRule)!=='SET NULL')throw new RuntimeException('081 must preserve report provenance with ON DELETE SET NULL.');
    $pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException('Upgrade left pending migrations: '.implode(', ',$pending));
    $again=migration_apply_pending($pdo,$root.'/database/migrations',20);if($again)throw new RuntimeException('Phase 67 repeat upgrade is not a no-op: '.implode(', ',$again));
    echo "PASS: Phase 67 upgrade rehearsal advanced an actual migration-079 database through 081 and a repeat pass was a no-op.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
