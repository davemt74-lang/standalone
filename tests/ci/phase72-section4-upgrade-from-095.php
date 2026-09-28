<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');require_once $root.'/app/installer.php';
if((string)getenv('PHASE72_SECTION4_RESET_DB')==='1'){
 if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
 $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
 $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase72-s4-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
 $baseline='20260927_095_research_action_plan_program_follow_through.sql';
 foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
 installer_run($pdo,$root.'/database/schema.sql',$tmp);
 foreach(['research_action_plans','research_action_plan_milestones','research_action_plan_task_links','research_action_plan_program_links'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('095 fixture is missing '.$table.'.');
 foreach(['research_action_plan_execution_baselines','research_action_plan_execution_observations','research_action_plan_variances'] as $table)if(installer_table_exists($pdo,$table))throw new RuntimeException('095 fixture unexpectedly contains '.$table.'.');

 $beforePlan=$pdo->query('SHOW CREATE TABLE research_action_plans')->fetch(PDO::FETCH_NUM)[1]??'';
 $beforeMilestones=$pdo->query('SHOW CREATE TABLE research_action_plan_milestones')->fetch(PDO::FETCH_NUM)[1]??'';
 $beforeTasks=$pdo->query('SHOW CREATE TABLE research_tasks')->fetch(PDO::FETCH_NUM)[1]??'';
 $beforePrograms=$pdo->query('SHOW CREATE TABLE research_programs')->fetch(PDO::FETCH_NUM)[1]??'';

 $final='20260928_096_research_action_plan_execution_evidence_variance.sql';
 foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)>0&&strcmp($base,$final)<=0)copy($file,$tmp.'/'.$base);}
 $applied=migration_apply_pending($pdo,$tmp,20);if(!in_array('20260928_096_research_action_plan_execution_evidence_variance',$applied,true))throw new RuntimeException('Upgrade did not apply migration 096.');
 foreach(['research_action_plan_execution_baselines','research_action_plan_execution_observations','research_action_plan_variances'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('096 did not create '.$table.'.');
 if(($pdo->query('SHOW CREATE TABLE research_action_plans')->fetch(PDO::FETCH_NUM)[1]??'')!==$beforePlan)throw new RuntimeException('096 rewrote Action Plan storage.');
 if(($pdo->query('SHOW CREATE TABLE research_action_plan_milestones')->fetch(PDO::FETCH_NUM)[1]??'')!==$beforeMilestones)throw new RuntimeException('096 rewrote milestone storage.');
 if(($pdo->query('SHOW CREATE TABLE research_tasks')->fetch(PDO::FETCH_NUM)[1]??'')!==$beforeTasks)throw new RuntimeException('096 rewrote Research Task storage.');
 if(($pdo->query('SHOW CREATE TABLE research_programs')->fetch(PDO::FETCH_NUM)[1]??'')!==$beforePrograms)throw new RuntimeException('096 rewrote Research Program storage.');
 foreach(['research_action_plan_execution_baselines','research_action_plan_execution_observations','research_action_plan_variances'] as $table)if((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException('096 fabricated '.$table.' state.');
 $pending=installer_pending_migrations($pdo,$tmp);if($pending)throw new RuntimeException('Section 4 upgrade left pending migrations: '.implode(', ',$pending));
 $again=migration_apply_pending($pdo,$tmp,20);if($again)throw new RuntimeException('Section 4 repeat upgrade is not a no-op: '.implode(', ',$again));
 echo "PASS: Phase 72 Section 4 upgrade preserves prior execution state, fabricates no evidence/variance history, and is repeat-safe.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
