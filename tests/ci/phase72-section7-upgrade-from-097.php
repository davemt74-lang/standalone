<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');require_once $root.'/app/installer.php';
if((string)getenv('PHASE72_SECTION7_RESET_DB')==='1'){
 if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
 $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
 $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase72-s7-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
 $baseline='20260928_097_research_action_plan_team_command_review.sql';
 foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
 installer_run($pdo,$root.'/database/schema.sql',$tmp);
 foreach(['research_action_plans','research_action_plan_execution_baselines','research_decision_outcomes','research_reviews'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('097 fixture is missing '.$table.'.');
 if(installer_table_exists($pdo,'research_action_plan_outcome_links'))throw new RuntimeException('097 fixture unexpectedly contains Section 7 outcome links.');

 $beforePlans=$pdo->query('SHOW CREATE TABLE research_action_plans')->fetch(PDO::FETCH_NUM)[1]??'';
 $beforeOutcomes=$pdo->query('SHOW CREATE TABLE research_decision_outcomes')->fetch(PDO::FETCH_NUM)[1]??'';
 $beforeReviews=$pdo->query('SHOW CREATE TABLE research_reviews')->fetch(PDO::FETCH_NUM)[1]??'';

 $final='20260928_098_research_action_plan_outcome_handoff.sql';
 foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)>0&&strcmp($base,$final)<=0)copy($file,$tmp.'/'.$base);}
 $applied=migration_apply_pending($pdo,$tmp,20);if(!in_array('20260928_098_research_action_plan_outcome_handoff',$applied,true))throw new RuntimeException('Upgrade did not apply migration 098.');
 if(!installer_table_exists($pdo,'research_action_plan_outcome_links'))throw new RuntimeException('098 did not create Action Plan outcome handoff lineage.');
 if(($pdo->query('SHOW CREATE TABLE research_action_plans')->fetch(PDO::FETCH_NUM)[1]??'')!==$beforePlans)throw new RuntimeException('098 unexpectedly changed Action Plan storage.');
 if(($pdo->query('SHOW CREATE TABLE research_decision_outcomes')->fetch(PDO::FETCH_NUM)[1]??'')!==$beforeOutcomes)throw new RuntimeException('098 unexpectedly changed Decision Outcome Memory storage.');
 if(($pdo->query('SHOW CREATE TABLE research_reviews')->fetch(PDO::FETCH_NUM)[1]??'')!==$beforeReviews)throw new RuntimeException('098 unexpectedly changed Collaborative Review storage.');
 if((int)$pdo->query('SELECT COUNT(*) FROM research_action_plan_outcome_links')->fetchColumn()!==0)throw new RuntimeException('098 fabricated Action Plan outcome history.');
 $pending=installer_pending_migrations($pdo,$tmp);if($pending)throw new RuntimeException('Section 7 upgrade left pending migrations: '.implode(', ',$pending));
 $again=migration_apply_pending($pdo,$tmp,20);if($again)throw new RuntimeException('Section 7 repeat upgrade is not a no-op: '.implode(', ',$again));
 echo "PASS: Phase 72 Section 7 upgrade adds only explicit outcome-handoff lineage, preserves existing Plan/Outcome/Review state, fabricates no history, and is repeat-safe.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
