<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if((string)getenv('PHASE72_SECTION2_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase72-s2-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
    $baseline='20260927_093_research_action_plan_ledger_foundation.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);
    foreach(['research_action_plans','research_action_plan_versions','research_action_plan_events','research_tasks','research_task_plans'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('093 fixture is missing '.$table.'.');
    foreach(['research_action_plan_milestones','research_action_plan_milestone_dependencies','research_action_plan_task_links'] as $table)if(installer_table_exists($pdo,$table))throw new RuntimeException('093 fixture unexpectedly contains '.$table.'.');

    foreach(['storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-action-plans'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}

    $run='p72s2up'.substr(bin2hex(random_bytes(4)),0,8);$username='u_'.$run;$public='u-'.$run;
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$public,$username,'P72 S2 Upgrade User',$username.'@example.test']);
    $uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$viewer=$q->fetch();

    $agent=research_agent_create($pdo,$viewer,['name'=>'P72 S2 Upgrade Agent','description'=>'Existing Section 1 state','cadence'=>'manual','timezone_name'=>'UTC']);
    $project=research_agent_workspace_project($pdo,$viewer,(string)$agent['public_id']);
    $decision=research_decision_create($pdo,$viewer,['agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Existing execution Decision','statement'=>'Proceed with existing execution.','rationale'=>'Upgrade fixture.']);
    $decision=research_decision_set_status($pdo,$viewer,(string)$decision['public_id'],'accepted');
    $plan=research_action_plan_from_decision($pdo,$viewer,(string)$decision['public_id'],[
      'idempotency_key'=>'existing-section1-plan','title'=>'Existing Section 1 Action Plan','objective'=>'Survive migration 094 unchanged.',
      'expected_result'=>'Existing Action Plan data remains intact.','success_measures'=>['Existing plan survives upgrade.']
    ]);
    $before=$pdo->prepare("SELECT public_id,decision_id,research_agent_id,project_id,created_by_user_id,owner_user_id,title,objective,expected_result,status,priority,source_decision_revision,source_decision_config_hash,source_decision_status,idempotency_key,current_revision,config_hash FROM research_action_plans WHERE id=?");$before->execute([(int)$plan['id']]);$beforeRow=$before->fetch();
    $tasksBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_tasks')->fetchColumn();$taskPlansBefore=(int)$pdo->query('SELECT COUNT(*) FROM research_task_plans')->fetchColumn();

    $section2Final='20260927_094_research_action_plan_milestones_tasks_dependencies.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)>0&&strcmp($base,$section2Final)<=0)copy($file,$tmp.'/'.$base);}
    $applied=migration_apply_pending($pdo,$tmp,20);
    if(!in_array('20260927_094_research_action_plan_milestones_tasks_dependencies',$applied,true))throw new RuntimeException('Upgrade did not apply migration 094.');

    foreach(['research_action_plan_milestones','research_action_plan_milestone_dependencies','research_action_plan_task_links'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('094 did not create '.$table.'.');
    $cols=$pdo->query("SHOW COLUMNS FROM research_action_plans")->fetchAll(PDO::FETCH_COLUMN);if(!in_array('execution_task_plan_id',$cols,true))throw new RuntimeException('094 did not add execution_task_plan_id.');
    $after=$pdo->prepare("SELECT public_id,decision_id,research_agent_id,project_id,created_by_user_id,owner_user_id,title,objective,expected_result,status,priority,source_decision_revision,source_decision_config_hash,source_decision_status,idempotency_key,current_revision,config_hash FROM research_action_plans WHERE id=?");$after->execute([(int)$plan['id']]);$afterRow=$after->fetch();
    if($afterRow!==$beforeRow)throw new RuntimeException('094 rewrote existing Section 1 Action Plan state.');
    $q=$pdo->prepare('SELECT execution_task_plan_id FROM research_action_plans WHERE id=?');$q->execute([(int)$plan['id']]);if($q->fetchColumn()!==null)throw new RuntimeException('094 fabricated an execution Task Plan link.');
    foreach(['research_action_plan_milestones','research_action_plan_milestone_dependencies','research_action_plan_task_links'] as $table)if((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException('094 fabricated state in '.$table.'.');
    if((int)$pdo->query('SELECT COUNT(*) FROM research_tasks')->fetchColumn()!==$tasksBefore||(int)$pdo->query('SELECT COUNT(*) FROM research_task_plans')->fetchColumn()!==$taskPlansBefore)throw new RuntimeException('094 fabricated existing Research Task state.');

    $pending=installer_pending_migrations($pdo,$tmp);if($pending)throw new RuntimeException('Phase 72 Section 2 upgrade left pending Section 2 migrations: '.implode(', ',$pending));
    $again=migration_apply_pending($pdo,$tmp,20);if($again)throw new RuntimeException('Phase 72 Section 2 repeat upgrade is not a no-op: '.implode(', ',$again));
    echo "PASS: Phase 72 Section 2 upgrade preserved existing Action Plan/Task state, created no synthetic milestones/tasks, and is repeat-safe.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
