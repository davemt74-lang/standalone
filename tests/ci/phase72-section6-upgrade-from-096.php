<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');require_once $root.'/app/installer.php';
if((string)getenv('PHASE72_SECTION6_RESET_DB')==='1'){
 if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
 $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
 $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase72-s6-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
 $baseline='20260928_096_research_action_plan_execution_evidence_variance.sql';
 foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
 installer_run($pdo,$root.'/database/schema.sql',$tmp);
 foreach(['research_reviews','research_action_plans','research_action_plan_variances'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('096 fixture is missing '.$table.'.');
 $col=$pdo->query("SHOW COLUMNS FROM research_reviews LIKE 'subject_type'")->fetch();$typeBefore=(string)($col['Type']??'');
 if(str_contains($typeBefore,"'action_plan'"))throw new RuntimeException('096 fixture unexpectedly supports Action Plan Team Review.');

 $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES('p72s6-upgrade-user','p72s6_upgrade_user','P72 S6 Upgrade User','p72s6-upgrade@example.test',NOW(),'active','user','pro','cloaked')")->execute();$userId=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,status) VALUES('p72s6-upgrade-project',?,'P72 S6 Upgrade Project','active')")->execute([$userId]);$projectId=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO research_reviews(public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status) VALUES('p72s6-existing-review',?,?,'claim','p72s6-existing-claim',REPEAT('c',64),'Claim fixture','Existing Review','completed')")->execute([$projectId,$userId]);
 $before=$pdo->query("SELECT public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status FROM research_reviews WHERE public_id='p72s6-existing-review'")->fetch();
 $beforePlans=$pdo->query('SHOW CREATE TABLE research_action_plans')->fetch(PDO::FETCH_NUM)[1]??'';
 $beforeVariances=$pdo->query('SHOW CREATE TABLE research_action_plan_variances')->fetch(PDO::FETCH_NUM)[1]??'';

 $final='20260928_097_research_action_plan_team_command_review.sql';
 foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)>0&&strcmp($base,$final)<=0)copy($file,$tmp.'/'.$base);}
 $applied=migration_apply_pending($pdo,$tmp,20);if(!in_array('20260928_097_research_action_plan_team_command_review',$applied,true))throw new RuntimeException('Upgrade did not apply migration 097.');
 $col=$pdo->query("SHOW COLUMNS FROM research_reviews LIKE 'subject_type'")->fetch();$typeAfter=(string)($col['Type']??'');
 if(!str_contains($typeAfter,"'action_plan'"))throw new RuntimeException('097 did not add Action Plan review subject.');
 $after=$pdo->query("SELECT public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status FROM research_reviews WHERE public_id='p72s6-existing-review'")->fetch();if($after!==$before)throw new RuntimeException('097 rewrote existing Collaborative Review state.');
 if(($pdo->query('SHOW CREATE TABLE research_action_plans')->fetch(PDO::FETCH_NUM)[1]??'')!==$beforePlans)throw new RuntimeException('097 unexpectedly changed Action Plan storage.');
 if(($pdo->query('SHOW CREATE TABLE research_action_plan_variances')->fetch(PDO::FETCH_NUM)[1]??'')!==$beforeVariances)throw new RuntimeException('097 unexpectedly changed variance storage.');
 if((int)$pdo->query("SELECT COUNT(*) FROM research_reviews WHERE subject_type='action_plan'")->fetchColumn()!==0)throw new RuntimeException('097 fabricated Action Plan Team Reviews.');
 $pending=installer_pending_migrations($pdo,$tmp);if($pending)throw new RuntimeException('Section 6 upgrade left pending migrations: '.implode(', ',$pending));
 $again=migration_apply_pending($pdo,$tmp,20);if($again)throw new RuntimeException('Section 6 repeat upgrade is not a no-op: '.implode(', ',$again));
 echo "PASS: Phase 72 Section 6 upgrade expands native Review subjects, preserves existing Action Plan/Review state, creates no synthetic reviews, and is repeat-safe.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
