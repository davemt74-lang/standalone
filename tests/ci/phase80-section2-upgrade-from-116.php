<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';require_once $root.'/app/migrations.php';
if((string)getenv('PHASE80_SECTION2_RESET_DB')==='1'){if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');$dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);$admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$q=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$q);$admin->exec('CREATE DATABASE '.$q.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-p80s2-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create fixture.');
try{$baseline='20261001_116_research_agent_stories_toggle.sql';foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}installer_run($pdo,$root.'/database/schema.sql',$tmp);
if(installer_table_exists($pdo,'sponsored_research_campaigns'))throw new RuntimeException('116 fixture unexpectedly contains Section 2 campaigns.');
$applied=migration_apply_pending($pdo,$root.'/database/migrations',20);if(!in_array('20261001_117_phase80_sponsored_research_campaigns',$applied,true))throw new RuntimeException('Upgrade did not apply migration 117.');
foreach(['sponsored_research_campaigns','sponsored_research_campaign_questions','sponsored_research_campaign_versions','sponsored_research_campaign_events'] as $t)if(!installer_table_exists($pdo,$t))throw new RuntimeException('117 missing '.$t);
if(installer_pending_migrations($pdo,$root.'/database/migrations'))throw new RuntimeException('Upgrade left pending migrations.');if(migration_apply_pending($pdo,$root.'/database/migrations',20))throw new RuntimeException('Repeat 117 upgrade is not a no-op.');echo "PASS: migration 116 upgrades through Phase 80 Section 2 migration 117.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
