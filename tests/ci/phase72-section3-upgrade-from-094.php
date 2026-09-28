<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if((string)getenv('PHASE72_SECTION3_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase72-s3-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
    $baseline='20260927_094_research_action_plan_milestones_tasks_dependencies.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);
    foreach(['research_action_plans','research_action_plan_milestones','research_action_plan_task_links','research_programs','research_program_runs','research_program_deltas'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('094 fixture is missing '.$table.'.');
    if(installer_table_exists($pdo,'research_action_plan_program_links'))throw new RuntimeException('094 fixture unexpectedly contains Action Plan Program links.');

    foreach(['storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','research-actions','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','research-tasks','research-programs','research-missions','cross-research','research-outcomes','research-decisions','research-action-plans'] as $lib){$p=$root.'/app/'.$lib.'.php';if(is_file($p))require_once $p;}

    $run='p72s3up'.substr(bin2hex(random_bytes(4)),0,8);$username='u_'.$run;$public='u-'.$run;
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','user','pro','cloaked')")
      ->execute([$public,$username,'P72 S3 Upgrade User',$username.'@example.test']);
    $uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$viewer=$q->fetch();

    $agent=research_agent_create($pdo,$viewer,['name'=>'P72 S3 Upgrade Agent','description'=>'Existing Section 2 state','cadence'=>'manual','timezone_name'=>'UTC']);
    $project=research_agent_workspace_project($pdo,$viewer,(string)$agent['public_id']);
    $decision=research_decision_create($pdo,$viewer,['agent_id'=>$agent['public_id'],'decision_type'=>'decision','title'=>'Existing follow-through Decision','statement'=>'Proceed with existing Action Plan execution.','rationale'=>'Upgrade fixture.']);
    $decision=research_decision_set_status($pdo,$viewer,(string)$decision['public_id'],'accepted');
    $plan=research_action_plan_from_decision($pdo,$viewer,(string)$decision['public_id'],[
      'idempotency_key'=>'existing-section2-plan','title'=>'Existing Section 2 Action Plan','objective'=>'Survive migration 095 unchanged.',
      'expected_result'=>'Existing Action Plan execution remains intact.','success_measures'=>['Existing execution survives upgrade.']
    ]);
    $milestone=research_action_plan_create_milestone($pdo,$viewer,(string)$plan['public_id'],['title'=>'Existing milestone','completion_criteria'=>['Milestone remains intact.']]);
    $task=research_action_plan_add_task($pdo,$viewer,(string)$plan['public_id'],['milestone_id'=>$milestone['public_id'],'title'=>'Existing execution task','task_type'=>'general']);
    $program=research_program_create($pdo,$viewer,[
      'agent_id'=>$agent['public_id'],'title'=>'Existing recurring Program','objective'=>'Existing Program survives Action Plan follow-through migration.',
      'cadence'=>'manual','quiet_mode'=>'always','materiality_threshold'=>'any'
    ]);
    $runPublic=research_program_enqueue($pdo,$program,(int)$viewer['id'],'manual',null);if($runPublic===null)throw new RuntimeException('Could not create existing Program run fixture.');
    $runRow=research_program_run_row($pdo,$viewer,(string)$runPublic);if(!$runRow)throw new RuntimeException('Existing Program run fixture is unavailable.');
    $delta=research_program_add_delta($pdo,$program,(int)$runRow['id'],'baseline','Existing Program baseline before migration 095.',null,null,[],['fixture'=>true],'important');

    $beforePlan=$pdo->prepare("SELECT public_id,title,objective,expected_result,status,current_revision,config_hash,execution_task_plan_id FROM research_action_plans WHERE id=?");$beforePlan->execute([(int)$plan['id']]);$beforePlanRow=$beforePlan->fetch();
    $beforeMilestone=$pdo->prepare("SELECT public_id,title,status,position FROM research_action_plan_milestones WHERE id=?");$beforeMilestone->execute([(int)$milestone['id']]);$beforeMilestoneRow=$beforeMilestone->fetch();
    $beforeTask=$pdo->prepare("SELECT public_id,title,status,plan_id FROM research_tasks WHERE id=?");$beforeTask->execute([(int)$task['id']]);$beforeTaskRow=$beforeTask->fetch();
    $beforeProgram=$pdo->prepare("SELECT public_id,title,status,cadence,current_revision,config_hash FROM research_programs WHERE id=?");$beforeProgram->execute([(int)$program['id']]);$beforeProgramRow=$beforeProgram->fetch();
    $beforeRun=$pdo->prepare("SELECT public_id,program_id,status,trigger_type,trigger_key FROM research_program_runs WHERE id=?");$beforeRun->execute([(int)$runRow['id']]);$beforeRunRow=$beforeRun->fetch();
    $beforeDelta=$pdo->prepare("SELECT public_id,run_id,program_id,delta_type,importance,fingerprint,summary FROM research_program_deltas WHERE fingerprint=?");$beforeDelta->execute([(string)$delta['fingerprint']]);$beforeDeltaRow=$beforeDelta->fetch();

    $section3Final='20260927_095_research_action_plan_program_follow_through.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)>0&&strcmp($base,$section3Final)<=0)copy($file,$tmp.'/'.$base);}
    $applied=migration_apply_pending($pdo,$tmp,20);
    if(!in_array('20260927_095_research_action_plan_program_follow_through',$applied,true))throw new RuntimeException('Upgrade did not apply migration 095.');
    if(!installer_table_exists($pdo,'research_action_plan_program_links'))throw new RuntimeException('095 did not create research_action_plan_program_links.');
    $col=$pdo->query("SHOW COLUMNS FROM research_program_deltas LIKE 'delta_type'")->fetch();$type=(string)($col['Type']??'');
    foreach(["'action_plan_status_changed'","'action_plan_source_stale'","'milestone_completed'","'execution_progress_changed'"] as $needle)if(!str_contains($type,$needle))throw new RuntimeException('095 did not add Program delta type '.$needle.'.');

    $afterPlan=$pdo->prepare("SELECT public_id,title,objective,expected_result,status,current_revision,config_hash,execution_task_plan_id FROM research_action_plans WHERE id=?");$afterPlan->execute([(int)$plan['id']]);if($afterPlan->fetch()!==$beforePlanRow)throw new RuntimeException('095 rewrote existing Action Plan state.');
    $afterMilestone=$pdo->prepare("SELECT public_id,title,status,position FROM research_action_plan_milestones WHERE id=?");$afterMilestone->execute([(int)$milestone['id']]);if($afterMilestone->fetch()!==$beforeMilestoneRow)throw new RuntimeException('095 rewrote existing milestone state.');
    $afterTask=$pdo->prepare("SELECT public_id,title,status,plan_id FROM research_tasks WHERE id=?");$afterTask->execute([(int)$task['id']]);if($afterTask->fetch()!==$beforeTaskRow)throw new RuntimeException('095 rewrote existing Research Task state.');
    $afterProgram=$pdo->prepare("SELECT public_id,title,status,cadence,current_revision,config_hash FROM research_programs WHERE id=?");$afterProgram->execute([(int)$program['id']]);if($afterProgram->fetch()!==$beforeProgramRow)throw new RuntimeException('095 rewrote existing Research Program state.');
    $afterRun=$pdo->prepare("SELECT public_id,program_id,status,trigger_type,trigger_key FROM research_program_runs WHERE id=?");$afterRun->execute([(int)$runRow['id']]);if($afterRun->fetch()!==$beforeRunRow)throw new RuntimeException('095 rewrote existing Program run state.');
    $afterDelta=$pdo->prepare("SELECT public_id,run_id,program_id,delta_type,importance,fingerprint,summary FROM research_program_deltas WHERE fingerprint=?");$afterDelta->execute([(string)$delta['fingerprint']]);if($afterDelta->fetch()!==$beforeDeltaRow)throw new RuntimeException('095 rewrote existing Program delta state.');
    if((int)$pdo->query('SELECT COUNT(*) FROM research_action_plan_program_links')->fetchColumn()!==0)throw new RuntimeException('095 fabricated Action Plan Program links.');

    $pending=installer_pending_migrations($pdo,$tmp);if($pending)throw new RuntimeException('Phase 72 Section 3 upgrade left pending Section 3 migrations: '.implode(', ',$pending));
    $again=migration_apply_pending($pdo,$tmp,20);if($again)throw new RuntimeException('Phase 72 Section 3 repeat upgrade is not a no-op: '.implode(', ',$again));
    echo "PASS: Phase 72 Section 3 upgrade preserved Action Plan/Milestone/Task/Program/Run/Delta state, created no synthetic links, and is repeat-safe.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
