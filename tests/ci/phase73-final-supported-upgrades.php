<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Phase 73 upgrade matrix DSN must name a database.');
$dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
$admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);
$baselines=[
 '20260928_098_research_action_plan_outcome_handoff.sql'=>[
  '20260928_099_portfolio_native_decision_handoff','20260928_100_cross_decision_pattern_memory','20260928_101_strategic_dependency_conflict_graph',
  '20260928_102_recurring_strategic_review','20260928_103_executive_strategic_briefings_team_review'
 ],
 '20260928_099_portfolio_native_decision_handoff.sql'=>[
  '20260928_100_cross_decision_pattern_memory','20260928_101_strategic_dependency_conflict_graph','20260928_102_recurring_strategic_review','20260928_103_executive_strategic_briefings_team_review'
 ],
 '20260928_100_cross_decision_pattern_memory.sql'=>[
  '20260928_101_strategic_dependency_conflict_graph','20260928_102_recurring_strategic_review','20260928_103_executive_strategic_briefings_team_review'
 ],
 '20260928_101_strategic_dependency_conflict_graph.sql'=>[
  '20260928_102_recurring_strategic_review','20260928_103_executive_strategic_briefings_team_review'
 ],
 '20260928_102_recurring_strategic_review.sql'=>[
  '20260928_103_executive_strategic_briefings_team_review'
 ],
];
$case=0;
foreach($baselines as $baseline=>$expected){
 $case++;$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 $pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $tmp=sys_get_temp_dir().'/annotated-phase73-final-upgrade-'.$case.'-'.bin2hex(random_bytes(5));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create Phase 73 upgrade fixture directory.');
 try{
   foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
   installer_run($pdo,$root.'/database/schema.sql',$tmp);
   $tag='p73final'.$case;
   $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
     ->execute([$tag.'-user',$tag.'_user','Phase 73 Upgrade '.$case,$tag.'@example.test']);$userId=(int)$pdo->lastInsertId();
   $pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,status) VALUES(?,?,?,'active')")->execute([$tag.'-project',$userId,'Phase 73 Upgrade Project '.$case]);$projectId=(int)$pdo->lastInsertId();
   $pdo->prepare("INSERT INTO research_reviews(public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status) VALUES(?,?,?,'claim',?,REPEAT('d',64),'Existing claim review','Existing Phase 73 Review','completed')")
     ->execute([$tag.'-review',$projectId,$userId,$tag.'-claim']);
   $q=$pdo->prepare("SELECT public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status FROM research_reviews WHERE public_id=?");$q->execute([$tag.'-review']);$beforeReview=$q->fetch();

   $final='20260928_103_executive_strategic_briefings_team_review.sql';
   foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)>0&&strcmp($base,$final)<=0)copy($file,$tmp.'/'.$base);}
   $applied=migration_apply_pending($pdo,$tmp,100);
   foreach($expected as $migration)if(!in_array($migration,$applied,true))throw new RuntimeException($baseline.' did not apply expected migration '.$migration.'.');
   $unexpected=array_values(array_diff($applied,$expected));if($unexpected)throw new RuntimeException($baseline.' applied unexpected migrations: '.implode(', ',$unexpected));

   foreach([
     'research_intelligence_decision_pattern_runs','research_intelligence_decision_patterns','research_intelligence_decision_pattern_members',
     'research_intelligence_strategic_edges','research_intelligence_strategic_edge_events',
     'research_intelligence_strategic_reviews','research_intelligence_strategic_review_settings','research_intelligence_strategic_briefings'
   ] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException($baseline.' upgrade is missing '.$table.'.');
   $col=$pdo->query("SHOW COLUMNS FROM research_intelligence_portfolio_decision_links LIKE 'decision_id'")->fetch();if(!$col)throw new RuntimeException($baseline.' upgrade is missing native Portfolio Decision lineage.');
   $col=$pdo->query("SHOW COLUMNS FROM research_reviews LIKE 'subject_type'")->fetch();if(!$col||!str_contains((string)($col['Type']??''),"'strategic_review'"))throw new RuntimeException($baseline.' upgrade is missing Strategic Review subject support.');

   foreach(['research_intelligence_decision_pattern_runs','research_intelligence_decision_patterns','research_intelligence_decision_pattern_members','research_intelligence_strategic_edges','research_intelligence_strategic_edge_events','research_intelligence_strategic_reviews','research_intelligence_strategic_review_settings','research_intelligence_strategic_briefings'] as $table)
     if((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException($baseline.' upgrade fabricated Phase 73 state in '.$table.'.');
   $q=$pdo->prepare("SELECT public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status FROM research_reviews WHERE public_id=?");$q->execute([$tag.'-review']);$afterReview=$q->fetch();
   if($afterReview!==$beforeReview)throw new RuntimeException($baseline.' upgrade rewrote existing Collaborative Review state.');

   $pending=installer_pending_migrations($pdo,$tmp);if($pending)throw new RuntimeException($baseline.' left pending Phase 73 migrations: '.implode(', ',$pending));
   $again=migration_apply_pending($pdo,$tmp,100);if($again)throw new RuntimeException($baseline.' repeat pass is not a no-op: '.implode(', ',$again));
   if(glob($root.'/database/migrations/*_104_*.sql'))throw new RuntimeException('Phase 73 final release unexpectedly contains migration 104.');
   echo "PASS: Phase 73 supported upgrade ".$baseline." → 103 preserved existing review state, fabricated no Phase 73 strategic state, and is repeat-safe.\n";
 }finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);$pdo=null;}
}
echo "Phase 73 final supported upgrade matrix passed.\n";
