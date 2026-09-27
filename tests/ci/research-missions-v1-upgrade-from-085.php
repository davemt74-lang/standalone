<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
require_once $root.'/app/installer.php';
if((string)getenv('RESEARCH_MISSIONS_SECTION6_RESET_DB')==='1'){
    if(!preg_match('/(?:^|;)dbname=([^;]+)/',$dsn,$m))throw new RuntimeException('Upgrade rehearsal DSN must name a database.');
    $dbName=installer_validate_db_identifier((string)$m[1]);$adminDsn=(string)preg_replace('/;?dbname=[^;]+/','',$dsn);
    $admin=new PDO($adminDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$dbName).chr(96);$admin->exec('DROP DATABASE IF EXISTS '.$quoted);$admin->exec('CREATE DATABASE '.$quoted.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$tmp=sys_get_temp_dir().'/annotated-missions-section6-upgrade-'.bin2hex(random_bytes(6));if(!mkdir($tmp,0700,true))throw new RuntimeException('Could not create migration fixture directory.');
try{
    $baseline='20260926_085_research_missions_v1.sql';
    foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){$base=basename($file);if(strcmp($base,$baseline)<=0)copy($file,$tmp.'/'.$base);}
    installer_run($pdo,$root.'/database/schema.sql',$tmp);
    if(!installer_table_exists($pdo,'research_missions')||!installer_table_exists($pdo,'research_reviews'))throw new RuntimeException('085 fixture is missing Mission or Review tables.');
    $q=$pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research_reviews' AND COLUMN_NAME='subject_type'");$q->execute();$beforeType=(string)$q->fetchColumn();
    if(str_contains($beforeType,"'mission'"))throw new RuntimeException('085 fixture unexpectedly accepts Mission review subjects.');

    $userPublic='rm6-upgrade-user';$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?, ?,NOW(),'active','user','pro','cloaked')")
      ->execute([$userPublic,'rm6_upgrade_user','RM6 Upgrade User','rm6-upgrade@example.test']);$userId=(int)$pdo->lastInsertId();
    $projectPublic='rm6-upgrade-project';$pdo->prepare("INSERT INTO research_projects(public_id,owner_user_id,title,status) VALUES(?,?,?,'active')")->execute([$projectPublic,$userId,'RM6 Upgrade Project']);$projectId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO research_reviews(public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status) VALUES(?,?,?,'document','rm6-doc',REPEAT('a',64),'Document revision 1','Existing Review','open')")
      ->execute(['rm6-existing-review',$projectId,$userId]);
    $beforeReviews=(int)$pdo->query('SELECT COUNT(*) FROM research_reviews')->fetchColumn();

    $applied=migration_apply_pending($pdo,$root.'/database/migrations',20);
    if(!in_array('20260927_086_research_missions_collaboration_cognition_reporting',$applied,true))throw new RuntimeException('Upgrade did not apply migration 086.');
    $q=$pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='research_reviews' AND COLUMN_NAME='subject_type'");$q->execute();$afterType=(string)$q->fetchColumn();
    if(!str_contains($afterType,"'mission'"))throw new RuntimeException('086 did not add Mission review subject compatibility.');
    if((int)$pdo->query('SELECT COUNT(*) FROM research_reviews')->fetchColumn()!==$beforeReviews)throw new RuntimeException('086 rewrote or removed existing review history.');

    $pdo->prepare("INSERT INTO research_reviews(public_id,project_id,requested_by_user_id,subject_type,subject_public_id,subject_hash,subject_version_label,title,status) VALUES(?,?,?,'mission','rm6-mission',REPEAT('b',64),'Mission revision 1','Mission Review','open')")
      ->execute(['rm6-mission-review',$projectId,$userId]);
    $q=$pdo->query("SELECT subject_type FROM research_reviews WHERE public_id='rm6-mission-review'");if((string)$q->fetchColumn()!=='mission')throw new RuntimeException('086 cannot persist a Mission review subject.');

    $pending=installer_pending_migrations($pdo,$root.'/database/migrations');if($pending)throw new RuntimeException('Upgrade left pending migrations: '.implode(', ',$pending));
    $again=migration_apply_pending($pdo,$root.'/database/migrations',20);if($again)throw new RuntimeException('Section 6 repeat upgrade is not a no-op: '.implode(', ',$again));
    echo "PASS: Research Missions V1 Section 6 upgrade rehearsal preserved existing reviews and enabled Mission review subjects through migration 086.\n";
}finally{
    foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);@rmdir($tmp);
}
