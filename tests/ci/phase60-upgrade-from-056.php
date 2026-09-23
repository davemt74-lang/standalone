<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if((string)getenv('PHASE60_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $admin->exec('DROP DATABASE IF EXISTS `'.str_replace('`','``',$dbName).'`');$admin->exec('CREATE DATABASE `'.str_replace('`','``',$dbName).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase60-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,'20260923_056_research_programs_recurring_intelligence.sql')<=0)copy($file,$tmp.'/'.$base);}
    $result=installer_run($pdo,$root.'/database/schema.sql',$tmp);
    if(!installer_table_exists($pdo,'research_programs'))throw new RuntimeException('056 fixture did not reach Research Programs.');
    if(installer_table_exists($pdo,'research_publication_workflows'))throw new RuntimeException('056 fixture unexpectedly contains Phase 59 publication schema.');
    if(installer_table_exists($pdo,'research_intelligence_portfolios'))throw new RuntimeException('056 fixture unexpectedly contains Phase 60 portfolio schema.');
    $applied=migration_apply_pending($pdo,$root.'/database/migrations',15);
    $expected=['20260923_057_collaborative_review_approval_publishing','20260923_058_research_intelligence_portfolios_executive_briefing'];
    foreach($expected as $version)if(!in_array($version,$applied,true))throw new RuntimeException('Upgrade did not apply '.$version.'.');
    if(!installer_table_exists($pdo,'research_publication_workflows')||!installer_table_exists($pdo,'research_intelligence_portfolios'))throw new RuntimeException('057→058 upgraded schema is incomplete.');
    $pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException('Upgrade left pending migrations: '.implode(', ',$pending));
    echo 'PASS: Phase 60 upgrade rehearsal advanced an actual migration-056 database through 057 and 058 with no pending migrations.'."\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
