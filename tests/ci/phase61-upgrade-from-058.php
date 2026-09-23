<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if((string)getenv('PHASE61_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');$dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);$admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$admin->exec('DROP DATABASE IF EXISTS `'.str_replace('`','``',$dbName).'`');$admin->exec('CREATE DATABASE `'.str_replace('`','``',$dbName).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tmp=sys_get_temp_dir().'/annotated-phase61-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,'20260923_058_research_intelligence_portfolios_executive_briefing.sql')<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);if(!installer_table_exists($pdo,'research_intelligence_portfolios'))throw new RuntimeException('058 fixture did not reach Research Intelligence Portfolios.');if(installer_table_exists($pdo,'research_intelligence_portfolio_cycles'))throw new RuntimeException('058 fixture unexpectedly contains Phase 61 operations schema.');
    $applied=migration_apply_pending($pdo,$root.'/database/migrations',10);if(!in_array('20260923_059_portfolio_intelligence_operations_follow_through',$applied,true))throw new RuntimeException('Upgrade did not apply migration 059.');foreach(['research_intelligence_portfolio_cycles','research_intelligence_portfolio_subscriptions','research_intelligence_briefing_receipts','research_intelligence_portfolio_decision_links','research_intelligence_portfolio_feedback'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('059 upgraded schema missing '.$table.'.');$pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException('Upgrade left pending migrations: '.implode(', ',$pending));
    echo "PASS: Phase 61 upgrade rehearsal advanced an actual migration-058 database through 059 with no pending migrations.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
