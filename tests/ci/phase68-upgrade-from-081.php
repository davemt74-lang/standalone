<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if((string)getenv('PHASE68_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-phase68-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
    $baseline='20260926_081_phase67_system_report_provenance_hardening.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);
    if(!installer_table_exists($pdo,'research_system_reports'))throw new RuntimeException('081 fixture is missing Phase 67 System Reports.');
    if(installer_table_exists($pdo,'research_report_presets'))throw new RuntimeException('081 fixture unexpectedly contains Phase 68 Report presets.');

    foreach(['storage','jobs','functions','access','notifications','rate-limit','conversations','research-automation','research-workspace','research-agents','research-agent-workspace'] as $lib)require_once $root.'/app/'.$lib.'.php';
    $run='p68up'.substr(bin2hex(random_bytes(5)),0,10);$username='phase68_'.$run;
    $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','admin','pro','cloaked')")
      ->execute(['u-'.$run,$username,'Phase 68 Upgrade',$username.'@example.test']);$uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$uid]);$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$viewer=$q->fetch();
    $agent=research_agent_create($pdo,$viewer,['name'=>'Legacy Phase 67 Agent','description'=>'Migration fixture.','cadence'=>'manual','timezone_name'=>'UTC']);
    $project=research_agent_workspace_project($pdo,$viewer,(string)$agent['public_id']);if(!$project)throw new RuntimeException('Could not create legacy Research project.');
    $doc=research_agent_workspace_create_document($pdo,$viewer,$project,['title'=>'Legacy Phase 67 Report','document_type'=>'report','content_html'=>'<h1>Legacy Phase 67 Report</h1><h2>Finding</h2><p>Preserve this generated report.</p>','summary'=>'Legacy report summary.'],false);
    $legacy='legacy-report-'.$run;$pdo->prepare("INSERT INTO research_system_reports(public_id,research_agent_id,project_id,requested_by_user_id,report_type,title,status,document_object_id,input_state_hash,evidence_refs_json,metrics_json)
      VALUES(?,?,?,?,?,?,'ready',?,?,?,?)")->execute([$legacy,(int)$agent['id'],(int)$project['id'],$uid,'research_brief','Legacy Phase 67 Report',(int)$doc['id'],hash('sha256','legacy-state'),'[]','{}']);

    $applied=migration_apply_pending($pdo,$root.'/database/migrations',20);
    if(!in_array('20260926_082_research_agent_report_studio',$applied,true))throw new RuntimeException('Upgrade did not apply migration 082.');
    if(!installer_table_exists($pdo,'research_report_presets'))throw new RuntimeException('082 upgraded schema is missing research_report_presets.');

    $q=$pdo->prepare("SELECT rendered_html,rendered_summary,generation_mode,document_object_id,document_created_at FROM research_system_reports WHERE public_id=?");$q->execute([$legacy]);$row=$q->fetch();
    if(!$row||!str_contains((string)$row['rendered_html'],'Preserve this generated report.')||(string)$row['rendered_summary']!=='Legacy report summary.'||(string)$row['generation_mode']!=='legacy'||(int)$row['document_object_id']!==(int)$doc['id']||empty($row['document_created_at']))throw new RuntimeException('082 did not backfill the legacy Phase 67 Report Run from its Research Document.');

    $nullable=(string)$pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research_system_reports' AND COLUMN_NAME='document_object_id'")->fetchColumn();
    if($nullable!=='YES')throw new RuntimeException('082 must make document_object_id nullable.');
    $deleteRule=(string)$pdo->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='research_system_reports' AND CONSTRAINT_NAME='fk_system_reports_document'")->fetchColumn();
    if(strtoupper($deleteRule)!=='SET NULL')throw new RuntimeException('082 must preserve Report Runs when derived Documents are deleted.');

    $pdo->prepare('DELETE FROM research_workspace_objects WHERE id=?')->execute([(int)$doc['id']]);
    $q=$pdo->prepare('SELECT document_object_id,rendered_html FROM research_system_reports WHERE public_id=?');$q->execute([$legacy]);$after=$q->fetch();
    if(!$after||$after['document_object_id']!==null||!str_contains((string)$after['rendered_html'],'Preserve this generated report.'))throw new RuntimeException('Deleting a legacy derived Document must preserve the migrated Report Run.');

    $pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException('Upgrade left pending migrations: '.implode(', ',$pending));
    $again=migration_apply_pending($pdo,$root.'/database/migrations',20);if($again)throw new RuntimeException('Phase 68 repeat upgrade is not a no-op: '.implode(', ',$again));
    echo "PASS: Phase 68 upgrade rehearsal preserved a real Phase 67 report/document relationship through migration 082 and repeat safety.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
