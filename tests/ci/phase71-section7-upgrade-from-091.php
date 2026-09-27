<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if((string)getenv('PHASE71_SECTION7_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase71-s7-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
    $baseline='20260927_091_research_decision_evolution_reconsideration.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);
    foreach(['research_reviews','research_decisions','research_decision_reconsiderations'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('091 fixture is missing '.$table.'.');
    $col=$pdo->query("SHOW COLUMNS FROM research_reviews LIKE 'subject_type'")->fetch();$typeBefore=(string)($col['Type']??'');
    if(str_contains($typeBefore,"'decision'")||str_contains($typeBefore,"'decision_reconsideration'"))throw new RuntimeException('091 fixture unexpectedly supports Phase 71 Section 7 review subjects.');

    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES('p71s7-upgrade-user','p71s7_upgrade_user','P71 S7 Upgrade User','p71s7-upgrade@example.test',NOW(),'active','user','pro','cloaked')")->execute();$userId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,status) VALUES('p71s7-upgrade-project',?,'P71 S7 Upgrade Project','active')")->execute([$userId]);$projectId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO research_reviews(public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status) VALUES('p71s7-existing-review',?,?,'claim','p71s7-existing-claim',REPEAT('b',64),'Claim fixture','Existing Review','completed')")->execute([$projectId,$userId]);
    $before=$pdo->query("SELECT public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status FROM research_reviews WHERE public_id='p71s7-existing-review'")->fetch();
    $beforeDecision=$pdo->query('SHOW CREATE TABLE research_decisions')->fetch(PDO::FETCH_NUM)[1]??'';
    $beforeReconsideration=$pdo->query('SHOW CREATE TABLE research_decision_reconsiderations')->fetch(PDO::FETCH_NUM)[1]??'';

    $applied=migration_apply_pending($pdo,$root.'/database/migrations',20);
    if(!in_array('20260927_092_research_decision_command_center_team_review',$applied,true))throw new RuntimeException('Upgrade did not apply migration 092.');
    $col=$pdo->query("SHOW COLUMNS FROM research_reviews LIKE 'subject_type'")->fetch();$typeAfter=(string)($col['Type']??'');
    foreach(["'decision'","'decision_reconsideration'"] as $needle)if(!str_contains($typeAfter,$needle))throw new RuntimeException('092 did not add review subject '.$needle.'.');
    $after=$pdo->query("SELECT public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status FROM research_reviews WHERE public_id='p71s7-existing-review'")->fetch();if($after!==$before)throw new RuntimeException('092 rewrote existing Collaborative Review state.');
    if(($pdo->query('SHOW CREATE TABLE research_decisions')->fetch(PDO::FETCH_NUM)[1]??'')!==$beforeDecision)throw new RuntimeException('092 unexpectedly changed Decision storage.');
    if(($pdo->query('SHOW CREATE TABLE research_decision_reconsiderations')->fetch(PDO::FETCH_NUM)[1]??'')!==$beforeReconsideration)throw new RuntimeException('092 unexpectedly changed Reconsideration storage.');
    if((int)$pdo->query("SELECT COUNT(*) FROM research_reviews WHERE subject_type IN ('decision','decision_reconsideration')")->fetchColumn()!==0)throw new RuntimeException('092 fabricated Decision Team Reviews.');
    $pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException('Phase 71 Section 7 upgrade left pending migrations: '.implode(', ',$pending));
    $again=migration_apply_pending($pdo,$root.'/database/migrations',20);if($again)throw new RuntimeException('Phase 71 Section 7 repeat upgrade is not a no-op: '.implode(', ',$again));
    echo "PASS: Phase 71 Section 7 upgrade expanded native Review subjects, preserved existing reviews/Decision state, created no synthetic reviews, and is repeat-safe.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
