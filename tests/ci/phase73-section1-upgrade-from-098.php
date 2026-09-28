<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');require_once $root.'/app/installer.php';
if((string)getenv('PHASE73_SECTION1_RESET_DB')==='1'){
 if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
 $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
 $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase73-s1-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
 $baseline='20260928_098_research_action_plan_outcome_handoff.sql';
 foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
 installer_run($pdo,$root.'/database/schema.sql',$tmp);
 foreach(['research_intelligence_portfolio_decision_links','research_outcome_events','research_decisions'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('098 fixture is missing '.$table.'.');
 $col=$pdo->query("SHOW COLUMNS FROM research_intelligence_portfolio_decision_links LIKE 'decision_id'")->fetch();if($col)throw new RuntimeException('098 fixture unexpectedly has native Portfolio Decision link.');

 foreach(['storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-missions','research-decisions'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}
 $run='p73up'.substr(bin2hex(random_bytes(4)),0,8);$pub=fn(string $p)=>$p.'-'.$run;
 $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")->execute([$pub('u'),'p73up_'.$run,'P73 Upgrade User',$run.'@example.test']);$uid=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$user=$q->fetch();
 $agent=research_agent_create($pdo,$user,['name'=>'P73 Upgrade Agent','description'=>'Legacy portfolio decision fixture','cadence'=>'manual','timezone_name'=>'UTC']);
 $program=research_program_create($pdo,$user,['agent_id'=>$agent['public_id'],'title'=>'P73 Upgrade Program','objective'=>'Preserve legacy decisions.','cadence'=>'manual','tasks'=>[['title'=>'Legacy monitor','task_type'=>'general']]]);
 $portfolio=research_intelligence_portfolio_create($pdo,$user,['title'=>'P73 Upgrade Portfolio','objective'=>'Preserve historical Phase 61 decision state.','briefing_cadence'=>'manual','timezone_name'=>'UTC']);
 $portfolio=research_intelligence_portfolio_add_program($pdo,$user,(string)$portfolio['public_id'],(string)$program['public_id'],'primary');
 $legacy=research_intelligence_portfolio_record_decision($pdo,$user,(string)$portfolio['public_id'],[
   'title'=>'Historical Phase 61 decision','summary'=>'This row must survive migration 099 unchanged.','decision_type'=>'recorded',
   'create_follow_up'=>true,'follow_up_title'=>'Historical follow-up','priority'=>'high'
 ]);
 if(empty($legacy['outcome']['id'])||empty($legacy['task']['id']))throw new RuntimeException('Could not create historical Phase 61 fixture.');
 $q=$pdo->prepare('SELECT public_id,portfolio_id,outcome_id,insight_id,briefing_id,task_id,created_by_user_id,created_at FROM research_intelligence_portfolio_decision_links WHERE outcome_id=?');$q->execute([(int)$legacy['outcome']['id']]);$before=$q->fetch();if(!$before)throw new RuntimeException('Historical Portfolio decision link fixture is missing.');
 $beforeDecisions=(int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn();$beforeOutcomes=(int)$pdo->query('SELECT COUNT(*) FROM research_outcome_events')->fetchColumn();

 $final='20260928_099_portfolio_native_decision_handoff.sql';
 foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)>0&&strcmp($base,$final)<=0)copy($file,$tmp.'/'.$base);}
 $applied=migration_apply_pending($pdo,$tmp,20);if(!in_array('20260928_099_portfolio_native_decision_handoff',$applied,true))throw new RuntimeException('Upgrade did not apply migration 099.');
 $col=$pdo->query("SHOW COLUMNS FROM research_intelligence_portfolio_decision_links LIKE 'decision_id'")->fetch();if(!$col)throw new RuntimeException('099 did not add native decision_id lineage.');
 $col=$pdo->query("SHOW COLUMNS FROM research_intelligence_portfolio_decision_links LIKE 'outcome_id'")->fetch();if(strtoupper((string)($col['Null']??''))!=='YES')throw new RuntimeException('099 did not make legacy outcome_id nullable for native rows.');
 $q=$pdo->prepare('SELECT public_id,portfolio_id,outcome_id,insight_id,briefing_id,task_id,created_by_user_id,created_at,decision_id,handoff_key FROM research_intelligence_portfolio_decision_links WHERE public_id=?');$q->execute([(string)$before['public_id']]);$after=$q->fetch();
 foreach(array_keys($before) as $key)if((string)($after[$key]??'')!==(string)($before[$key]??''))throw new RuntimeException('099 rewrote historical Phase 61 field '.$key.'.');
 if($after['decision_id']!==null||$after['handoff_key']!==null)throw new RuntimeException('099 fabricated native lineage for a historical Phase 61 decision.');
 if((int)$pdo->query('SELECT COUNT(*) FROM research_decisions')->fetchColumn()!==$beforeDecisions)throw new RuntimeException('099 fabricated native Decisions.');
 if((int)$pdo->query('SELECT COUNT(*) FROM research_outcome_events')->fetchColumn()!==$beforeOutcomes)throw new RuntimeException('099 rewrote Outcome Learning history.');
 $pending=installer_pending_migrations($pdo,$tmp);if($pending)throw new RuntimeException('Section 1 upgrade left pending migrations: '.implode(', ',$pending));
 $again=migration_apply_pending($pdo,$tmp,20);if($again)throw new RuntimeException('Section 1 repeat upgrade is not a no-op: '.implode(', ',$again));
 echo "PASS: Phase 73 Section 1 upgrade preserves Phase 61 history byte-for-byte, adds native Decision lineage, fabricates no Decisions, and is repeat-safe.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
