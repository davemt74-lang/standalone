<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if((string)getenv('PHASE69_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase69-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
    $baseline='20260926_082_research_agent_report_studio.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);
    if(!installer_table_exists($pdo,'research_report_presets'))throw new RuntimeException('082 fixture is missing Report Studio presets.');
    if(installer_table_exists($pdo,'research_intelligence_subscriptions')||installer_table_exists($pdo,'research_report_deliveries'))throw new RuntimeException('082 fixture unexpectedly contains Phase 69 delivery tables.');

    foreach(['storage','jobs','functions','access','notifications','rate-limit','conversations','research-automation','research-workspace','research-agents','research-agent-workspace','research-tasks','research-programs','research-system-reports','research-report-studio'] as $lib)require_once $root.'/app/'.$lib.'.php';
    $run='p69up'.substr(bin2hex(random_bytes(5)),0,10);$username='phase69_'.$run;
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','admin','pro','cloaked')")
      ->execute(['u-'.$run,$username,'Phase 69 Upgrade',$username.'@example.test']);$uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$viewer=$q->fetch();

    $agent=research_agent_create($pdo,$viewer,['name'=>'Phase 68 Existing Agent','description'=>'Migration 083 fixture.','cadence'=>'manual','timezone_name'=>'UTC']);
    $program=research_program_create($pdo,$viewer,['agent_id'=>$agent['public_id'],'title'=>'Existing Intelligence Program','objective'=>'Existing Phase 68 Program must survive migration 083.','cadence'=>'weekly','timezone_name'=>'UTC','priority'=>'medium','deliverable_type'=>'weekly_report']);
    $preset=research_report_studio_preset_save($pdo,$viewer,(string)$agent['public_id'],['name'=>'Existing Weekly Brief','report_type'=>'research_brief','depth'=>'standard','program_id'=>$program['public_id']]);
    $report=research_report_studio_run_preset($pdo,[],$viewer,(string)$agent['public_id'],(string)$preset['public_id'],false);
    if(empty($report['public_id'])||!empty($report['document_public_id']))throw new RuntimeException('Phase 68 fixture Report Run is invalid.');

    $applied=migration_apply_pending($pdo,$root.'/database/migrations',20);
    if(!in_array('20260926_083_research_intelligence_delivery_subscriptions',$applied,true))throw new RuntimeException('Upgrade did not apply migration 083.');
    foreach(['research_intelligence_subscriptions','research_report_deliveries','research_report_delivery_events'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('083 upgraded schema missing '.$table.'.');

    $columnType=(string)$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research_report_deliveries' AND COLUMN_NAME='status'")->fetchColumn();
    if(!str_contains($columnType,"'pending'")||!str_contains($columnType,"'suppressed'")||!str_contains($columnType,"'viewed'"))throw new RuntimeException('083 delivery lifecycle enum is incomplete.');
    $nullable=(string)$pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research_intelligence_subscriptions' AND COLUMN_NAME='subscriber_user_id'")->fetchColumn();
    if($nullable!=='YES')throw new RuntimeException('083 must preserve historical subscriptions when a subscriber account is deleted.');

    $q=$pdo->prepare("SELECT rrp.public_id preset_public_id,rp.public_id program_public_id FROM research_report_presets rrp LEFT JOIN research_programs rp ON rp.id=rrp.program_id WHERE rrp.public_id=?");$q->execute([(string)$preset['public_id']]);$links=$q->fetch();
    if(!$links||$links['program_public_id']!==$program['public_id'])throw new RuntimeException('083 altered the existing Phase 68 preset/Program relationship.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM research_system_reports WHERE public_id=? AND document_object_id IS NULL');$q->execute([(string)$report['public_id']]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('083 altered the existing Phase 68 Report Run.');
    if((int)$pdo->query('SELECT COUNT(*) FROM research_intelligence_subscriptions')->fetchColumn()!==0||(int)$pdo->query('SELECT COUNT(*) FROM research_report_deliveries')->fetchColumn()!==0)throw new RuntimeException('083 must not create subscriptions or deliveries implicitly.');

    $pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException('Upgrade left pending migrations: '.implode(', ',$pending));
    $again=migration_apply_pending($pdo,$root.'/database/migrations',20);if($again)throw new RuntimeException('Phase 69 repeat upgrade is not a no-op: '.implode(', ',$again));
    echo "PASS: Phase 69 upgrade rehearsal preserved Phase 68 Report Studio state through migration 083 and repeat safety.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
