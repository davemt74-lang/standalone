<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');require_once $root.'/app/installer.php';
if((string)getenv('PHASE73_SECTION3_RESET_DB')==='1'){
 if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
 $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
 $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase73-s3-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
 $baseline='20260928_099_portfolio_native_decision_handoff.sql';
 foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
 installer_run($pdo,$root.'/database/schema.sql',$tmp);
 foreach(['research_decisions','research_action_plans','research_decision_outcomes','research_intelligence_portfolio_decision_links'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('099 fixture is missing '.$table.'.');
 foreach(['research_intelligence_decision_pattern_runs','research_intelligence_decision_patterns','research_intelligence_decision_pattern_members'] as $table)if(installer_table_exists($pdo,$table))throw new RuntimeException('099 fixture unexpectedly contains '.$table.'.');

 foreach(['storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-missions','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-outcomes'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
 $run='p73s3up'.substr(bin2hex(random_bytes(4)),0,8);$pub=fn(string $p)=>$p.'-'.$run;
 $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),'p73s3up_'.$run,'P73 S3 Upgrade User',$run.'@example.test']);$uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$user=$q->fetch();
 $agent=research_agent_create($pdo,$user,['name'=>'P73 S3 Upgrade Agent','description'=>'Migration 100 preservation fixture','cadence'=>'manual','timezone_name'=>'UTC']);
 $program=research_program_create($pdo,$user,['agent_id'=>$agent['public_id'],'title'=>'P73 S3 Upgrade Program','objective'=>'Preserve authoritative records.','cadence'=>'manual','tasks'=>[['title'=>'Monitor','task_type'=>'general']]]);
 $portfolio=research_intelligence_portfolio_create($pdo,$user,['title'=>'P73 S3 Upgrade Portfolio','objective'=>'Preserve source state during migration 100.','briefing_cadence'=>'manual','timezone_name'=>'UTC']);$portfolio=research_intelligence_portfolio_add_program($pdo,$user,(string)$portfolio['public_id'],(string)$program['public_id'],'primary');
 $native=research_intelligence_portfolio_create_native_decision($pdo,$user,(string)$portfolio['public_id'],['idempotency_key'=>'p73-s3-upgrade','title'=>'Existing Decision','statement'=>'Existing authoritative Decision before migration 100.','rationale'=>'Existing governed rationale before migration 100.','assumptions'=>['Existing assumption.']]);
 $decision=research_decision_set_status($pdo,$user,(string)$native['decision']['public_id'],'accepted');
 $plan=research_action_plan_from_decision($pdo,$user,(string)$decision['public_id'],['idempotency_key'=>'p73-s3-upgrade-plan','title'=>'Existing Plan','objective'=>'Preserve plan.','expected_result'=>'Preserved.','success_measures'=>[['label'=>'Preserved','target'=>'Yes']]]);
 $outcome=research_decision_record_outcome($pdo,$user,(string)$decision['public_id'],['assessment'=>'success','actual_summary'=>'Existing Decision outcome before migration 100.','lessons'=>'Existing lesson.','idempotency_key'=>'p73-s3-upgrade-outcome'],false);
 $before=[
  'decisions'=>(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn(),
  'plans'=>(int)$pdo->query('SELECT COUNT(*) FROM research_action_plans')->fetchColumn(),
  'outcomes'=>(int)$pdo->query('SELECT COUNT(*) FROM research_decision_outcomes')->fetchColumn(),
  'links'=>(int)$pdo->query('SELECT COUNT(*) FROM research_intelligence_portfolio_decision_links')->fetchColumn()
 ];

 $final='20260928_100_cross_decision_pattern_memory.sql';foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)>0&&strcmp($base,$final)<=0)copy($file,$tmp.'/'.$base);}
 $applied=migration_apply_pending($pdo,$tmp,20);if(!in_array('20260928_100_cross_decision_pattern_memory',$applied,true))throw new RuntimeException('Upgrade did not apply migration 100.');
 foreach(['research_intelligence_decision_pattern_runs','research_intelligence_decision_patterns','research_intelligence_decision_pattern_members'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('100 did not create '.$table.'.');
 foreach(['research_intelligence_decision_pattern_runs','research_intelligence_decision_patterns','research_intelligence_decision_pattern_members'] as $table)if((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException('Migration 100 fabricated Pattern Memory rows in '.$table.'.');
 $after=[
  'decisions'=>(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn(),
  'plans'=>(int)$pdo->query('SELECT COUNT(*) FROM research_action_plans')->fetchColumn(),
  'outcomes'=>(int)$pdo->query('SELECT COUNT(*) FROM research_decision_outcomes')->fetchColumn(),
  'links'=>(int)$pdo->query('SELECT COUNT(*) FROM research_intelligence_portfolio_decision_links')->fetchColumn()
 ];
 if($before!==$after)throw new RuntimeException('Migration 100 changed authoritative Decision/Action Plan/Outcome/Portfolio link counts.');
 $pending=installer_pending_migrations($pdo,$tmp);if($pending)throw new RuntimeException('Section 3 upgrade left pending migrations: '.implode(', ',$pending));
 $again=migration_apply_pending($pdo,$tmp,20);if($again)throw new RuntimeException('Section 3 repeat upgrade is not a no-op: '.implode(', ',$again));
 echo "PASS: Phase 73 Section 3 upgrade creates empty Pattern Memory stores, preserves authoritative source rows, and is repeat-safe.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
