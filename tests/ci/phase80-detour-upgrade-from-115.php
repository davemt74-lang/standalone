<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';require_once $root.'/app/migrations.php';
if((string)getenv('RESEARCH_AGENT_STORIES_TOGGLE_RESET_DB')==='1'){
 if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
 $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);$admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$q=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$q);$admin->exec('CREATE DATABASE '.$q.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-story-toggle-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture.');
try{
 $baseline='20260930_115_phase80_research_sponsor_account_governance.sql';foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
 installer_run($pdo,$root.'/database/schema.sql',$tmp);
 $q=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research_agent_story_policies' AND COLUMN_NAME='stories_enabled'");if((int)$q->fetchColumn()!==0)throw new RuntimeException('115 fixture unexpectedly has stories_enabled.');
 $applied=migration_apply_pending($pdo,$root.'/database/migrations',20);if(!in_array('20261001_116_research_agent_stories_toggle',$applied,true))throw new RuntimeException('Upgrade did not apply migration 116.');
 $q=$pdo->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research_agent_story_policies' AND COLUMN_NAME='stories_enabled'");$default=$q->fetchColumn();if((string)$default!=='1')throw new RuntimeException('stories_enabled missing or not default-on after migration 116.');
 if(installer_pending_migrations($pdo,$root.'/database/migrations'))throw new RuntimeException('Upgrade left pending migrations.');
 if(migration_apply_pending($pdo,$root.'/database/migrations',20))throw new RuntimeException('Repeat migration 116 upgrade is not a no-op.');
 echo "PASS: migration 115 upgrades through 116 with Stories default-on compatibility.\n";
}finally{foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);}
