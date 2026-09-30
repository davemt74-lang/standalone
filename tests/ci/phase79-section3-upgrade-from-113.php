<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';require_once $root.'/app/migrations.php';
if((string)getenv('PHASE79_SECTION3_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase79-s3-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture.');
try{
    $baseline='20260930_113_profile_content_social_polish.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);
    if(installer_table_exists($pdo,'research_memory_controls'))throw new RuntimeException('113 fixture unexpectedly contains Agent Memory controls.');

    $applied=migration_apply_pending($pdo,$root.'/database/migrations',20);
    if(!in_array('20261001_114_agent_memory_knowledge_management',$applied,true))throw new RuntimeException('Upgrade did not apply migration 114.');
    foreach(['research_memory_controls','research_memory_events','research_memory_usage'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('114 upgraded schema missing '.$table.'.');

    $cols=[];
    $q=$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research_memory_controls'");
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $column)$cols[(string)$column]=true;
    foreach(['project_id','object_type','object_public_id','retrieval_state','correction_text','correction_hash','corrected_by_user_id','corrected_at'] as $column)if(empty($cols[$column]))throw new RuntimeException('114 research_memory_controls missing '.$column.'.');

    $pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException('Upgrade left pending migrations: '.implode(', ',$pending));
    $again=migration_apply_pending($pdo,$root.'/database/migrations',20);if($again)throw new RuntimeException('Phase 79 Section 3 repeat upgrade is not a no-op: '.implode(', ',$again));
    echo "PASS: Phase 79 Section 3 upgrade rehearsal advanced migration 113 through 114 and repeat safety.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
