<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if((string)getenv('RESEARCH_MISSIONS_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-missions-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
    $baseline='20260926_084_longitudinal_research_intelligence.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);
    foreach(['research_longitudinal_snapshots','research_longitudinal_changes','research_longitudinal_milestones'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('084 fixture is missing '.$table.'.');
    foreach(['research_missions','research_mission_versions','research_mission_criteria','research_mission_subquestions','research_mission_events'] as $table)if(installer_table_exists($pdo,$table))throw new RuntimeException('084 fixture unexpectedly contains Research Missions table '.$table.'.');

    $pdo->exec("INSERT INTO research_longitudinal_snapshots(public_id,research_agent_id,project_id,trigger_type,state_hash,state_json) SELECT 'upgrade-existing-snapshot',ra.id,rp.id,'baseline',REPEAT('a',64),JSON_OBJECT('fixture',true) FROM research_agents ra JOIN research_projects rp ON rp.id=ra.project_id LIMIT 1");
    $before=(int)$pdo->query('SELECT COUNT(*) FROM research_longitudinal_snapshots')->fetchColumn();

    $applied=migration_apply_pending($pdo,$root.'/database/migrations',20);
    if(!in_array('20260926_085_research_missions_v1',$applied,true))throw new RuntimeException('Upgrade did not apply migration 085.');
    foreach(['research_missions','research_mission_versions','research_mission_criteria','research_mission_subquestions','research_mission_events'] as $table)if(!installer_table_exists($pdo,$table))throw new RuntimeException('085 upgraded schema missing '.$table.'.');
    if((int)$pdo->query('SELECT COUNT(*) FROM research_longitudinal_snapshots')->fetchColumn()!==$before)throw new RuntimeException('085 changed existing longitudinal history.');
    if((int)$pdo->query('SELECT COUNT(*) FROM research_missions')->fetchColumn()!==0)throw new RuntimeException('085 must not fabricate Missions for existing projects.');

    $pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException('Upgrade left pending migrations: '.implode(', ',$pending));
    $again=migration_apply_pending($pdo,$root.'/database/migrations',20);if($again)throw new RuntimeException('Research Missions repeat upgrade is not a no-op: '.implode(', ',$again));
    echo "PASS: Research Missions V1 upgrade rehearsal preserved Phase 70 state through migration 085 without fabricating Missions.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
