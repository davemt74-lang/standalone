<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if((string)getenv('PHASE72_SECTION1_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase72-s1-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
    $baseline='20260927_092_research_decision_command_center_team_review.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);
    foreach(['research_decisions','research_decision_versions','research_decision_reconsiderations','research_reviews','research_tasks','research_programs'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('092 fixture is missing '.$table.'.');
    foreach(['research_action_plans','research_action_plan_versions','research_action_plan_events'] as $table)if(installer_table_exists($pdo,$table))throw new RuntimeException('092 fixture unexpectedly contains '.$table.'.');

    $beforeDecision=$pdo->query('SHOW CREATE TABLE research_decisions')->fetch(PDO::FETCH_NUM)[1]??'';
    $beforeTasks=$pdo->query('SHOW CREATE TABLE research_tasks')->fetch(PDO::FETCH_NUM)[1]??'';
    $beforePrograms=$pdo->query('SHOW CREATE TABLE research_programs')->fetch(PDO::FETCH_NUM)[1]??'';

    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES('p72s1-upgrade-user','p72s1_upgrade_user','P72 S1 Upgrade User','p72s1-upgrade@example.test',NOW(),'active','user','pro','cloaked')")->execute();$userId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,status) VALUES('p72s1-upgrade-project',?,'P72 S1 Upgrade Project','active')")->execute([$userId]);$projectId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO research_reviews(public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status) VALUES('p72s1-existing-review',?,?,'claim','p72s1-existing-claim',REPEAT('d',64),'Claim fixture','Existing Review','completed')")->execute([$projectId,$userId]);
    $beforeReview=$pdo->query("SELECT public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status FROM research_reviews WHERE public_id='p72s1-existing-review'")->fetch();

    $section1Final='20260927_093_research_action_plan_ledger_foundation.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)>0&&strcmp($base,$section1Final)<=0)copy($file,$tmp.'/'.$base);}
    $applied=migration_apply_pending($pdo,$tmp,20);
    if(!in_array('20260927_093_research_action_plan_ledger_foundation',$applied,true))throw new RuntimeException('Upgrade did not apply migration 093.');
    foreach(['research_action_plans','research_action_plan_versions','research_action_plan_events'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('093 did not create '.$table.'.');
    if((int)$pdo->query('SELECT COUNT(*) FROM research_action_plans')->fetchColumn()!==0)throw new RuntimeException('093 fabricated Action Plans.');
    if(($pdo->query('SHOW CREATE TABLE research_decisions')->fetch(PDO::FETCH_NUM)[1]??'')!==$beforeDecision)throw new RuntimeException('093 unexpectedly changed Decision storage.');
    if(($pdo->query('SHOW CREATE TABLE research_tasks')->fetch(PDO::FETCH_NUM)[1]??'')!==$beforeTasks)throw new RuntimeException('093 unexpectedly changed Research Task storage.');
    if(($pdo->query('SHOW CREATE TABLE research_programs')->fetch(PDO::FETCH_NUM)[1]??'')!==$beforePrograms)throw new RuntimeException('093 unexpectedly changed Research Program storage.');
    $afterReview=$pdo->query("SELECT public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status FROM research_reviews WHERE public_id='p72s1-existing-review'")->fetch();if($afterReview!==$beforeReview)throw new RuntimeException('093 rewrote existing Collaborative Review state.');

    $pending=installer_pending_migrations($pdo,$tmp);if($pending)throw new RuntimeException('Phase 72 Section 1 upgrade left pending Section 1 migrations: '.implode(', ',$pending));
    $again=migration_apply_pending($pdo,$tmp,20);if($again)throw new RuntimeException('Phase 72 Section 1 repeat upgrade is not a no-op: '.implode(', ',$again));
    echo "PASS: Phase 72 Section 1 upgrade preserved Decision/Task/Program/Review storage, created no synthetic Action Plans, and is repeat-safe.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
