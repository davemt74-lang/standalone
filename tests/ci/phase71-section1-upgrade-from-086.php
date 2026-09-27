<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if((string)getenv('PHASE71_SECTION1_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase71-s1-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
    $baseline='20260927_086_research_missions_collaboration_cognition_reporting.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);
    foreach(['research_missions','research_outcome_events','research_reviews'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('086 fixture is missing '.$table.'.');
    foreach(['research_decisions','research_decision_versions','research_decision_refs','research_decision_events'] as $table)if(installer_table_exists($pdo,$table))throw new RuntimeException('086 fixture unexpectedly contains '.$table.'.');

    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES('p71s1-upgrade-user','p71s1_upgrade_user','P71 S1 Upgrade User','p71s1-upgrade@example.test',NOW(),'active','user','pro','cloaked')")->execute();$userId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,status) VALUES('p71s1-upgrade-project',?,'P71 S1 Upgrade Project','active')")->execute([$userId]);$projectId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO research_outcome_events(public_id,user_id,project_id,event_type,decision_type,source_type,title,summary,dedupe_key,is_manual,occurred_at) VALUES('p71s1-existing-outcome',?,?,'manual_decision','recorded','manual','Existing Outcome','Must remain byte-stable through migration 087',REPEAT('c',64),1,NOW())")->execute([$userId,$projectId]);
    $before=$pdo->query("SELECT public_id,user_id,project_id,event_type,decision_type,source_type,title,summary,dedupe_key,is_manual FROM research_outcome_events WHERE public_id='p71s1-existing-outcome'")->fetch();

    $applied=migration_apply_pending($pdo,$root.'/database/migrations',20);
    if(!in_array('20260927_087_research_decisions_conclusions_foundation',$applied,true))throw new RuntimeException('Upgrade did not apply migration 087.');
    foreach(['research_decisions','research_decision_versions','research_decision_refs','research_decision_events'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('087 did not create '.$table.'.');
    if((int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn()!==0)throw new RuntimeException('087 fabricated Decision records from existing history.');
    $after=$pdo->query("SELECT public_id,user_id,project_id,event_type,decision_type,source_type,title,summary,dedupe_key,is_manual FROM research_outcome_events WHERE public_id='p71s1-existing-outcome'")->fetch();
    if($after!==$before)throw new RuntimeException('087 rewrote existing Outcome Learning history.');

    $pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException('Phase 71 Section 1 upgrade left pending migrations: '.implode(', ',$pending));
    $again=migration_apply_pending($pdo,$root.'/database/migrations',20);if($again)throw new RuntimeException('Phase 71 Section 1 repeat upgrade is not a no-op: '.implode(', ',$again));
    echo "PASS: Phase 71 Section 1 upgrade rehearsal preserved Outcome Learning history, created no synthetic Decisions, and is repeat-safe.\n";
}finally{
    foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);
}
