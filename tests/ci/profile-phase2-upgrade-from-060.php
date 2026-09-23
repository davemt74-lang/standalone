<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';require_once $root.'/app/migrations.php';
if((string)getenv('PROFILE_PHASE2_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');$dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);$admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$tmp=sys_get_temp_dir().'/annotated-profile-phase2-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture.');
try{
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,'20260923_060_vp3_account_connection_research_ingestion.sql')<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);if(!installer_table_exists($pdo,'vp3_connections'))throw new RuntimeException('060 fixture did not reach VP3 connection schema.');if(installer_table_exists($pdo,'profile_pins'))throw new RuntimeException('060 fixture unexpectedly contains Profile Phase 2 schema.');
    $applied=migration_apply_pending($pdo,$root.'/database/migrations',10);if(!in_array('20260923_061_profile_public_identity_showcase',$applied,true))throw new RuntimeException('Upgrade did not apply migration 061.');
    if(!installer_table_exists($pdo,'profile_pins'))throw new RuntimeException('061 upgraded schema missing profile_pins.');
    foreach(['profile_show_research','profile_show_collections','profile_show_about'] as $column){$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='user_preferences' AND column_name=?");$q->execute([$column]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('061 upgraded schema missing '.$column.'.');}
    $pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException('Upgrade left pending migrations: '.implode(', ',$pending));$again=migration_apply_pending($pdo,$root.'/database/migrations',10);if($again)throw new RuntimeException('Profile Phase 2 repeat upgrade is not a no-op: '.implode(', ',$again));
    echo "PASS: Profile Phase 2 upgrade rehearsal advanced migration 060 through 061 and a repeat pass was a no-op.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
