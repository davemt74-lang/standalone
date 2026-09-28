<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');require_once $root.'/app/installer.php';
if((string)getenv('PHASE73_SECTION6_RESET_DB')==='1'){
 if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
 $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);$admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase73-s6-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
 $baseline='20260928_102_recurring_strategic_review.sql';foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}installer_run($pdo,$root.'/database/schema.sql',$tmp);
 foreach(['research_intelligence_strategic_reviews','research_intelligence_strategic_review_settings','research_executive_briefings','research_reviews','research_decisions','research_action_plans'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('102 fixture is missing '.$table.'.');
 if(installer_table_exists($pdo,'research_intelligence_strategic_briefings'))throw new RuntimeException('102 fixture unexpectedly contains research_intelligence_strategic_briefings.');
 foreach(['storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-missions','research-intelligence-decision-rollups','research-intelligence-pattern-memory','research-intelligence-strategic-graph','research-intelligence-strategic-reviews'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
 $run='p73s6up'.substr(bin2hex(random_bytes(4)),0,8);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(2)),0,4);
 $makeUser=function(string $name)use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$id]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
 $owner=$makeUser('P73S6UpgradeOwner');$reviewer=$makeUser('P73S6UpgradeReviewer');
 $teamPublic=$pub('team');$pdo->prepare('INSERT INTO teams(public_id,owner_user_id,name) VALUES(?,?,?)')->execute([$teamPublic,$owner['id'],'P73 S6 Upgrade Team']);$teamId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'owner'),(?,?,'researcher')")->execute([$teamId,$owner['id'],$teamId,$reviewer['id']]);
 $agent=research_agent_create($pdo,$owner,['name'=>'P73 S6 Upgrade Agent','description'=>'Migration 103 preservation fixture','cadence'=>'manual','timezone_name'=>'UTC','team_id'=>$teamPublic]);
 $program=research_program_create($pdo,$owner,['agent_id'=>$agent['public_id'],'title'=>'P73 S6 Upgrade Program','objective'=>'Preserve pre-103 state.','cadence'=>'manual','tasks'=>[['title'=>'Monitor','task_type'=>'general']]]);
 $portfolio=research_intelligence_portfolio_create($pdo,$owner,['title'=>'P73 S6 Upgrade Portfolio','objective'=>'Preserve existing briefing and strategic review state.','team_id'=>$teamPublic,'briefing_cadence'=>'manual','timezone_name'=>'UTC']);$portfolio=research_intelligence_portfolio_add_program($pdo,$owner,(string)$portfolio['public_id'],(string)$program['public_id'],'primary');
 $native=research_intelligence_portfolio_create_native_decision($pdo,$owner,(string)$portfolio['public_id'],['idempotency_key'=>'s6-up-decision','title'=>'Existing Decision','statement'=>'Existing Decision before migration 103.','rationale'=>'Preserved rationale.']);$decision=research_decision_set_status($pdo,$owner,(string)$native['decision']['public_id'],'accepted');
 $plan=research_action_plan_from_decision($pdo,$owner,(string)$decision['public_id'],['idempotency_key'=>'s6-up-plan','title'=>'Existing Action Plan','objective'=>'Preserve plan.','expected_result'=>'Preserved.','success_measures'=>[['label'=>'Preserved','target'=>'Yes']]]);
 research_intelligence_strategic_review_configure($pdo,$owner,(string)$portfolio['public_id'],['status'=>'active','cadence'=>'monthly','due_offset_hours'=>72,'reviewers'=>[['user_id'=>$reviewer['public_id'],'role'=>'reviewer','required'=>true]]]);
 $strategic=research_intelligence_strategic_review_create($pdo,$owner,(string)$portfolio['public_id'],['trigger_type'=>'manual','idempotency_key'=>'s6-up-strategic-review']);
 $legacyBrief=research_intelligence_portfolio_create_briefing($pdo,$owner,(string)$portfolio['public_id'],['title'=>'Existing Executive Briefing']);
 $before=[
  'decisions'=>(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn(),
  'plans'=>(int)$pdo->query('SELECT COUNT(*) FROM research_action_plans')->fetchColumn(),
  'strategic_reviews'=>(int)$pdo->query('SELECT COUNT(*) FROM research_intelligence_strategic_reviews')->fetchColumn(),
  'reviews'=>(int)$pdo->query('SELECT COUNT(*) FROM research_reviews')->fetchColumn(),
  'briefings'=>(int)$pdo->query('SELECT COUNT(*) FROM research_executive_briefings')->fetchColumn(),
  'documents'=>(int)$pdo->query("SELECT COUNT(*) FROM research_workspace_objects WHERE object_type='document'")->fetchColumn()
 ];

 $final='20260928_103_executive_strategic_briefings_team_review.sql';foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)>0&&strcmp($base,$final)<=0)copy($file,$tmp.'/'.$base);}
 $applied=migration_apply_pending($pdo,$tmp,20);if(!in_array('20260928_103_executive_strategic_briefings_team_review',$applied,true))throw new RuntimeException('Upgrade did not apply migration 103.');
 if(!installer_table_exists($pdo,'research_intelligence_strategic_briefings'))throw new RuntimeException('103 did not create research_intelligence_strategic_briefings.');
 if((int)$pdo->query('SELECT COUNT(*) FROM research_intelligence_strategic_briefings')->fetchColumn()!==0)throw new RuntimeException('Migration 103 fabricated Strategic Briefing lineage rows.');
 $after=[
  'decisions'=>(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn(),
  'plans'=>(int)$pdo->query('SELECT COUNT(*) FROM research_action_plans')->fetchColumn(),
  'strategic_reviews'=>(int)$pdo->query('SELECT COUNT(*) FROM research_intelligence_strategic_reviews')->fetchColumn(),
  'reviews'=>(int)$pdo->query('SELECT COUNT(*) FROM research_reviews')->fetchColumn(),
  'briefings'=>(int)$pdo->query('SELECT COUNT(*) FROM research_executive_briefings')->fetchColumn(),
  'documents'=>(int)$pdo->query("SELECT COUNT(*) FROM research_workspace_objects WHERE object_type='document'")->fetchColumn()
 ];
 if($before!==$after)throw new RuntimeException('Migration 103 changed authoritative pre-existing state counts.');
 $pending=installer_pending_migrations($pdo,$tmp);if($pending)throw new RuntimeException('Section 6 upgrade left pending migrations: '.implode(', ',$pending));$again=migration_apply_pending($pdo,$tmp,20);if($again)throw new RuntimeException('Section 6 repeat upgrade is not a no-op: '.implode(', ',$again));
 echo "PASS: Phase 73 Section 6 upgrade creates empty strategic briefing lineage, preserves Decision/Action Plan/Strategic Review/Executive Briefing state, and is repeat-safe.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
