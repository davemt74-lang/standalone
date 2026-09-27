<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if((string)getenv('PHASE70_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase70-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
    $baseline='20260926_083_research_intelligence_delivery_subscriptions.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);
    foreach(['research_intelligence_subscriptions','research_report_deliveries','research_report_presets'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('083 fixture is missing '.$table.'.');
    foreach(['research_longitudinal_snapshots','research_longitudinal_changes','research_longitudinal_milestones'] as $table)if(installer_table_exists($pdo,$table))throw new RuntimeException('083 fixture unexpectedly contains Phase 70 table '.$table.'.');

    foreach(['storage','jobs','functions','access','notifications','rate-limit','conversations','research-automation','research-workspace','research-agents','research-agent-workspace','research-tasks','research-programs','research-system-reports','research-report-studio','research-intelligence-delivery'] as $lib)require_once $root.'/app/'.$lib.'.php';
    $run='p70up'.substr(bin2hex(random_bytes(5)),0,10);$username='phase70_'.$run;
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','admin','pro','cloaked')")
      ->execute(['u-'.$run,$username,'Phase 70 Upgrade',$username.'@example.test']);$uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$viewer=$q->fetch();
    $agent=research_agent_create($pdo,$viewer,['name'=>'Existing Phase 69 Agent','description'=>'Migration 084 fixture.','cadence'=>'manual','timezone_name'=>'UTC']);
    $program=research_program_create($pdo,$viewer,['agent_id'=>$agent['public_id'],'title'=>'Existing Phase 69 Program','objective'=>'Preserve subscription state.','cadence'=>'weekly','timezone_name'=>'UTC','priority'=>'medium','deliverable_type'=>'weekly_report']);
    $preset=research_report_studio_preset_save($pdo,$viewer,(string)$agent['public_id'],['name'=>'Existing Subscription Brief','report_type'=>'research_brief','depth'=>'standard','program_id'=>$program['public_id']]);
    $sub=research_report_subscription_create($pdo,$viewer,(string)$agent['public_id'],['name'=>'Existing Subscription','preset_id'=>$preset['public_id'],'program_id'=>$program['public_id'],'delivery_policy'=>'if_changed']);
    $report=research_report_studio_run_preset($pdo,[],$viewer,(string)$agent['public_id'],(string)$preset['public_id'],false);

    $applied=migration_apply_pending($pdo,$root.'/database/migrations',20);
    if(!in_array('20260926_084_longitudinal_research_intelligence',$applied,true))throw new RuntimeException('Upgrade did not apply migration 084.');
    foreach(['research_longitudinal_snapshots','research_longitudinal_changes','research_longitudinal_milestones'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('084 upgraded schema missing '.$table.'.');

    $q=$pdo->prepare('SELECT COUNT(*) FROM research_intelligence_subscriptions WHERE public_id=?');$q->execute([(string)$sub['public_id']]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('084 altered the existing Phase 69 subscription.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM research_report_presets WHERE public_id=? AND program_id IS NOT NULL');$q->execute([(string)$preset['public_id']]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('084 altered the existing Report preset/Program relationship.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM research_system_reports WHERE public_id=? AND document_object_id IS NULL');$q->execute([(string)$report['public_id']]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('084 altered the existing Report Run.');
    if((int)$pdo->query('SELECT COUNT(*) FROM research_longitudinal_snapshots')->fetchColumn()!==0||(int)$pdo->query('SELECT COUNT(*) FROM research_longitudinal_changes')->fetchColumn()!==0||(int)$pdo->query('SELECT COUNT(*) FROM research_longitudinal_milestones')->fetchColumn()!==0)throw new RuntimeException('084 must not fabricate historical state on upgrade.');

    $pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException('Upgrade left pending migrations: '.implode(', ',$pending));
    $again=migration_apply_pending($pdo,$root.'/database/migrations',20);if($again)throw new RuntimeException('Phase 70 repeat upgrade is not a no-op: '.implode(', ',$again));
    echo "PASS: Phase 70 upgrade rehearsal preserved Phase 69 state through migration 084 without fabricating history.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
