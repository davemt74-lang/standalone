<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Phase 72 upgrade matrix DSN must name a database.');
$dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
$admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);
$baselines=[
 '20260927_092_research_decision_command_center_team_review.sql'=>[
  '20260927_093_research_action_plan_ledger_foundation','20260927_094_research_action_plan_milestones_tasks_dependencies',
  '20260927_095_research_action_plan_program_follow_through','20260928_096_research_action_plan_execution_evidence_variance',
  '20260928_097_research_action_plan_team_command_review','20260928_098_research_action_plan_outcome_handoff'
 ],
 '20260927_093_research_action_plan_ledger_foundation.sql'=>[
  '20260927_094_research_action_plan_milestones_tasks_dependencies','20260927_095_research_action_plan_program_follow_through',
  '20260928_096_research_action_plan_execution_evidence_variance','20260928_097_research_action_plan_team_command_review',
  '20260928_098_research_action_plan_outcome_handoff'
 ],
 '20260927_095_research_action_plan_program_follow_through.sql'=>[
  '20260928_096_research_action_plan_execution_evidence_variance','20260928_097_research_action_plan_team_command_review',
  '20260928_098_research_action_plan_outcome_handoff'
 ],
 '20260928_097_research_action_plan_team_command_review.sql'=>[
  '20260928_098_research_action_plan_outcome_handoff'
 ],
];
$case=0;
foreach($baselines as $baseline=>$expected){
 $case++;$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 $pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $tmp=sys_get_temp_dir().'/annotated-phase72-final-upgrade-'.$case.'-'.bin2hex(random_bytes(5));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create Phase 72 upgrade fixture directory.');
 try{
   foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
   installer_run($pdo,$root.'/database/schema.sql',$tmp);
   $hasPlans=installer_table_exists($pdo,'research_action_plans');
   if($baseline==='20260927_092_research_decision_command_center_team_review.sql'&&$hasPlans)throw new RuntimeException('092 baseline unexpectedly contains Action Plan Ledger.');
   if(strcmp($baseline,'20260927_093_research_action_plan_ledger_foundation.sql')>=0&&!$hasPlans)throw new RuntimeException($baseline.' is missing Action Plan Ledger.');
   if(strcmp($baseline,'20260927_095_research_action_plan_program_follow_through.sql')>=0&&!installer_table_exists($pdo,'research_action_plan_program_links'))throw new RuntimeException($baseline.' is missing Action Plan Program links.');
   if(strcmp($baseline,'20260928_097_research_action_plan_team_command_review.sql')>=0){
     $col=$pdo->query("SHOW COLUMNS FROM research_reviews LIKE 'subject_type'")->fetch();if(!str_contains((string)($col['Type']??''),"'action_plan'"))throw new RuntimeException($baseline.' is missing Action Plan Team Review subject.');
   }

   $tag='p72final'.$case;
   $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
     ->execute([$tag.'-user',$tag.'_user','Phase 72 Upgrade '.$case,$tag.'@example.test']);$userId=(int)$pdo->lastInsertId();
   $pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,status) VALUES(?,?,?,'active')")->execute([$tag.'-project',$userId,'Phase 72 Upgrade Project '.$case]);$projectId=(int)$pdo->lastInsertId();
   $pdo->prepare("INSERT INTO research_reviews(public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status) VALUES(?,?,?,'claim',?,REPEAT('c',64),'Existing claim review','Existing Phase 72 Review','completed')")
     ->execute([$tag.'-review',$projectId,$userId,$tag.'-claim']);
   $before=$pdo->prepare("SELECT public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status FROM research_reviews WHERE public_id=?");$before->execute([$tag.'-review']);$beforeRow=$before->fetch();

   $phase72Final='20260928_098_research_action_plan_outcome_handoff.sql';
   foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)>0&&strcmp($base,$phase72Final)<=0)copy($file,$tmp.'/'.$base);}
   $applied=migration_apply_pending($pdo,$tmp,100);
   foreach($expected as $migration)if(!in_array($migration,$applied,true))throw new RuntimeException($baseline.' did not apply expected migration '.$migration.'.');
   $unexpected=array_values(array_diff($applied,$expected));if($unexpected)throw new RuntimeException($baseline.' applied unexpected migrations: '.implode(', ',$unexpected));

   foreach([
     'research_action_plans','research_action_plan_versions','research_action_plan_events',
     'research_action_plan_milestones','research_action_plan_milestone_dependencies','research_action_plan_task_links',
     'research_action_plan_program_links','research_action_plan_execution_baselines','research_action_plan_execution_observations',
     'research_action_plan_variances','research_action_plan_outcome_links'
   ] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException($baseline.' upgrade is missing '.$table.'.');
   $col=$pdo->query("SHOW COLUMNS FROM research_reviews LIKE 'subject_type'")->fetch();$type=(string)($col['Type']??'');
   if(!str_contains($type,"'action_plan'"))throw new RuntimeException($baseline.' upgrade is missing Action Plan Review subject.');

   $after=$pdo->prepare("SELECT public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status FROM research_reviews WHERE public_id=?");$after->execute([$tag.'-review']);$afterRow=$after->fetch();
   if($afterRow!==$beforeRow)throw new RuntimeException($baseline.' upgrade rewrote existing Collaborative Review state.');

   foreach(['research_action_plans','research_action_plan_program_links','research_action_plan_execution_baselines','research_action_plan_execution_observations','research_action_plan_variances','research_action_plan_outcome_links'] as $table)
     if((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException($baseline.' upgrade fabricated state in '.$table.'.');
   if((int)$pdo->query("SELECT COUNT(*) FROM research_reviews WHERE subject_type='action_plan'")->fetchColumn()!==0)throw new RuntimeException($baseline.' upgrade fabricated Action Plan Team Reviews.');

   $pending=installer_pending_migrations($pdo,$tmp);if($pending)throw new RuntimeException($baseline.' left pending Phase 72 migrations: '.implode(', ',$pending));
   $again=migration_apply_pending($pdo,$tmp,100);if($again)throw new RuntimeException($baseline.' repeat pass is not a no-op: '.implode(', ',$again));
   echo "PASS: Phase 72 supported upgrade ".$baseline." → 098 preserved existing review state, created no synthetic Action Plan state, and is repeat-safe.\n";
 }finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);$pdo=null;}
}
echo "Phase 72 final supported upgrade matrix passed.\n";
