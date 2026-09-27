<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if((string)getenv('RESEARCH_MISSIONS_FINAL_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-missions-final-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
    $baseline='20260926_084_longitudinal_research_intelligence.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);
    foreach(['research_longitudinal_snapshots','research_reviews','research_agents','research_projects'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('084 fixture is missing '.$table.'.');
    foreach(['research_missions','research_mission_versions','research_mission_criteria','research_mission_subquestions','research_mission_events'] as $table)if(installer_table_exists($pdo,$table))throw new RuntimeException('084 fixture unexpectedly contains '.$table.'.');

    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES('rm7-upgrade-user','rm7_upgrade_user','Mission Upgrade User','rm7-upgrade@example.test',NOW(),'active','user','pro','cloaked')")->execute();$userId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,status) VALUES('rm7-upgrade-project',?,'Mission Upgrade Project','active')")->execute([$userId]);$projectId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO research_reviews(public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status) VALUES('rm7-existing-review',?,?,'document','rm7-existing-doc',REPEAT('a',64),'Document revision 4','Existing governed review','completed')")->execute([$projectId,$userId]);
    $before=$pdo->query("SELECT public_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status FROM research_reviews WHERE public_id='rm7-existing-review'")->fetch();

    $applied=migration_apply_pending($pdo,$root.'/database/migrations',20);
    foreach(['20260926_085_research_missions_v1','20260927_086_research_missions_collaboration_cognition_reporting'] as $migration)
        if(!in_array($migration,$applied,true))throw new RuntimeException('Full upgrade did not apply '.$migration.'.');
    foreach(['research_missions','research_mission_versions','research_mission_criteria','research_mission_subquestions','research_mission_events'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('Upgraded schema missing '.$table.'.');
    $q=$pdo->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research_reviews' AND COLUMN_NAME='subject_type'");$columnType=(string)$q->fetchColumn();
    if(!str_contains($columnType,"'mission'"))throw new RuntimeException('Final upgraded Review Center does not accept Mission subjects.');
    $after=$pdo->query("SELECT public_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status FROM research_reviews WHERE public_id='rm7-existing-review'")->fetch();
    if($after!==$before)throw new RuntimeException('085→086 rewrote existing governed review state.');
    if((int)$pdo->query('SELECT COUNT(*) FROM research_missions')->fetchColumn()!==0)throw new RuntimeException('Mission migrations must not fabricate Missions for existing Research projects.');
    $pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException('Full Mission upgrade left pending migrations: '.implode(', ',$pending));
    $again=migration_apply_pending($pdo,$root.'/database/migrations',20);if($again)throw new RuntimeException('Full Mission upgrade is not repeat-safe: '.implode(', ',$again));
    echo "PASS: Research Missions V1 final 084→086 upgrade preserves existing governed state, creates no synthetic Missions, and is repeat-safe.\n";
}finally{
    foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);
}
