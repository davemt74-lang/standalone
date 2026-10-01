<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';require_once $root.'/app/migrations.php';
if((string)getenv('PHASE80_SECTION1_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase80-s1-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture.');
try{
    $baseline='20260930_114_agent_memory_knowledge_management.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);
    if(installer_table_exists($pdo,'research_account_profiles'))throw new RuntimeException('114 fixture unexpectedly contains Research Account governance.');

    $applied=migration_apply_pending($pdo,$root.'/database/migrations',20);
    if(!in_array('20260930_115_phase80_research_sponsor_account_governance',$applied,true))throw new RuntimeException('Upgrade did not apply migration 115.');
    foreach(['research_account_profiles','sponsor_account_profiles','research_account_governance_events'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('115 upgraded schema missing '.$table.'.');

    $cols=[];$q=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research_account_profiles'");
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $column)$cols[(string)$column]=true;
    foreach(['user_id','status','verification_level','marketplace_visible','payout_readiness','approved_by_user_id','decision_reason'] as $column)if(empty($cols[$column]))throw new RuntimeException('115 research_account_profiles missing '.$column.'.');

    $pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException('Upgrade left pending migrations: '.implode(', ',$pending));
    $again=migration_apply_pending($pdo,$root.'/database/migrations',20);if($again)throw new RuntimeException('Phase 80 Section 1 repeat upgrade is not a no-op: '.implode(', ',$again));
    echo "PASS: Phase 80 Section 1 upgrade rehearsal advanced migration 114 through 115 and repeat safety.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
