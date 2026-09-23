<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/installer.php';require_once $root.'/app/functions.php';require_once $root.'/app/release.php';require_once $root.'/app/release-operations.php';
function p49(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}

p49(ANNOTATED_RELEASE==='V1.1 RC1'&&ANNOTATED_RELEASE_VERSION==='1.1.0-rc1'&&ANNOTATED_RELEASE_PHASE===49,'Phase 49 canonical application release identity is V1.1 RC1 / 1.1.0-rc1');
p49(ANNOTATED_EXTENSION_VERSION==='0.36.0','Phase 49 correctly retains unchanged Chrome extension version 0.36.0');
$manifest=json_decode((string)file_get_contents($root.'/extension/manifest.json'),true,512,JSON_THROW_ON_ERROR);
p49(($manifest['manifest_version']??0)===3&&($manifest['version']??'')===ANNOTATED_EXTENSION_VERSION,'canonical release identity matches the actual Manifest V3 Chrome package');

$release=release_manifest_data($root,'phase49-ci-sha');
p49($release['version']==='1.1.0-rc1'&&$release['phase']===49&&$release['build_sha']==='phase49-ci-sha','generated release manifest binds release identity and build SHA');
p49($release['latest_migration']==='20260923_048_research_agent_workspace_core.sql','release manifest identifies the current latest migration after the Research Agent workspace upgrade');
p49((bool)preg_match('/^[a-f0-9]{64}$/',(string)$release['package_fingerprint']),'release manifest has a deterministic SHA-256 package fingerprint');
p49($release['package_fingerprint']===release_package_fingerprint($root),'package fingerprint is deterministic for the same release tree');
p49(count(installer_pending_migrations($pdo,$root.'/database/migrations'))===0,'Phase 49 release-candidate database has zero pending migrations');
p49(!is_file($root.'/database/migrations/20260922_047_release_candidate_operational_hardening.sql'),'Phase 49 introduces no database migration');

$specs=release_worker_specs();$expected=['media','transcription','source_monitor','ai','saved_search','research_automation','evaluation','training','post_training'];
p49(array_keys($specs)===$expected,'one canonical release schedule covers all nine required workers');
p49(($specs['evaluation']['command']??'')==='php bin/evaluation-worker.php','Evaluation Harness worker is part of the release-critical schedule');
$evaluationWorker=(string)file_get_contents($root.'/bin/evaluation-worker.php');
p49(str_contains($evaluationWorker,'release_worker_heartbeat($pdo,\'evaluation\',\'starting\'')&&str_contains($evaluationWorker,'release_worker_heartbeat($pdo,\'evaluation\',$status'),'Evaluation worker emits start and terminal release heartbeats');
$queues=release_queue_health($pdo);p49(isset($queues['evaluation'],$queues['training'],$queues['post_training']),'release queue health covers Evaluation, Training, and Post-Training pipeline queues');

$parts=parse_url('https://annotated.example.test');p49(($parts['scheme']??'')==='https','test production URL fixture is HTTPS');
$dbName='';foreach(explode(';',substr($dsn,6)) as $part)if(str_starts_with($part,'dbname='))$dbName=substr($part,7);
$storage=sys_get_temp_dir().'/annotated-p49-storage-'.bin2hex(random_bytes(5));$backupRoot=sys_get_temp_dir().'/annotated-p49-backups-'.bin2hex(random_bytes(5));$configRoot=sys_get_temp_dir().'/annotated-p49-config-'.bin2hex(random_bytes(5));mkdir($storage,0700,true);mkdir($backupRoot,0700,true);mkdir($configRoot,0700,true);
$config=['app'=>['base_url'=>'https://annotated.example.test','encryption_key'=>str_repeat('k',48)],'db'=>['dsn'=>$dsn,'user'=>$dbUser,'pass'=>$dbPass],'storage'=>['private_root'=>$storage],'extension'=>['allowed_ids'=>[]],'oauth'=>['google'=>[],'x'=>[]],'transcription'=>['command'=>'/bin/true']];
try{
    $target=release_database_target($config);p49($target['database']===$dbName&&$target['user']===$dbUser,'release backup parser derives current MySQL/MariaDB target without exposing credentials');
    $insideBlocked=false;try{release_backup_destination_assert($root.'/backups',$root);}catch(RuntimeException $e){$insideBlocked=str_contains($e->getMessage(),'outside');}p49($insideBlocked,'backup destination guard rejects application/web-tree output');
    $outside=release_backup_destination_assert($backupRoot,$root);p49($outside===$backupRoot,'backup destination guard accepts a private directory outside the application tree');
    $symlink=$backupRoot.'/web-link';$symlinkMade=@symlink($root,$symlink);if($symlinkMade){$symlinkBlocked=false;try{release_backup_destination_assert($symlink.'/backups',$root);}catch(RuntimeException $e){$symlinkBlocked=str_contains($e->getMessage(),'outside');}p49($symlinkBlocked,'backup destination guard resolves symlinked ancestors and blocks web-tree escape paths');@unlink($symlink);}

    file_put_contents($configRoot.'/config.php',"<?php return [];\n");chmod($configRoot.'/config.php',0640);p49(release_config_file_security($configRoot)['pass'],'release config permission audit accepts non-writable group/world config.php');chmod($configRoot.'/config.php',0666);p49(!release_config_file_security($configRoot)['pass'],'release config permission audit blocks group/world-writable config.php');chmod($configRoot.'/config.php',0640);

    $backupDir=$backupRoot.'/fixture';mkdir($backupDir,0700,true);file_put_contents($backupDir.'/database.sql.gz','phase49-database-backup-fixture');file_put_contents($backupDir.'/private-storage.tar.gz','phase49-private-storage-fixture');
    $backupManifest=release_backup_manifest_write($backupDir,$config,$root,['build_sha'=>'phase49-ci-sha']);
    p49((bool)preg_match('/^[a-f0-9]{64}$/',(string)$backupManifest['backup_id']),'release backup writes deterministic backup identity hash');
    p49(!str_contains(json_encode($backupManifest,JSON_UNESCAPED_SLASHES),(string)$dbPass),'release backup manifest never stores the database password');
    $verified=release_backup_manifest_verify($backupDir);p49($verified['ok'],'independent backup verification accepts intact database/private-storage pair');
    $plan=release_restore_plan($backupDir,$config);p49($plan['backup_id']===$backupManifest['backup_id']&&$plan['execution']==='manual_confirmation_required'&&$plan['destructive'],'restore planner requires explicit human control for destructive recovery');
    p49(!str_contains((string)$plan['commands']['database'],(string)$dbPass),'restore-plan command template never includes the database password');
    file_put_contents($backupDir.'/database.sql.gz','tampered',FILE_APPEND);$tampered=release_backup_manifest_verify($backupDir);p49(!$tampered['ok']&&count(array_filter($tampered['errors'],fn($e)=>str_contains($e,'checksum')||str_contains($e,'byte size')))>0,'backup verification detects post-creation database dump tampering');

    $routingBefore=$pdo->query('SELECT admin_default_model_id,pro_default_model_id,source_monitor_model_id,moderation_model_id,research_model_id,transcript_cleanup_model_id,annotation_intelligence_model_id FROM ai_settings WHERE id=1')->fetch();
    $deploymentsBefore=installer_table_exists($pdo,'data_model_deployments')?(int)$pdo->query('SELECT COUNT(*) FROM data_model_deployments')->fetchColumn():0;
    $versionsBefore=installer_table_exists($pdo,'data_model_versions')?(int)$pdo->query('SELECT COUNT(*) FROM data_model_versions')->fetchColumn():0;
    $ops=release_operational_audit($pdo,$config,$root);
    p49(($ops['release']['version']??'')==='1.1.0-rc1'&&($ops['checks']['extension_identity']['pass']??false),'operational audit reports the canonical V1.1 RC identity and matching extension');
    p49(isset($ops['backup']['checks']['dump'],$ops['backup']['checks']['client'],$ops['backup']['checks']['tar'],$ops['backup']['checks']['gzip'],$ops['backup']['checks']['zlib']),'operational audit exposes concrete backup/restore prerequisites without pretending missing tools are present');
    $routingAfter=$pdo->query('SELECT admin_default_model_id,pro_default_model_id,source_monitor_model_id,moderation_model_id,research_model_id,transcript_cleanup_model_id,annotation_intelligence_model_id FROM ai_settings WHERE id=1')->fetch();
    $deploymentsAfter=installer_table_exists($pdo,'data_model_deployments')?(int)$pdo->query('SELECT COUNT(*) FROM data_model_deployments')->fetchColumn():0;
    $versionsAfter=installer_table_exists($pdo,'data_model_versions')?(int)$pdo->query('SELECT COUNT(*) FROM data_model_versions')->fetchColumn():0;
    p49($routingAfter===$routingBefore&&$deploymentsAfter===$deploymentsBefore&&$versionsAfter===$versionsBefore,'Phase 49 operational audit is read-only with respect to routing, deployments, and governed model records');
}finally{
    @unlink($configRoot.'/config.php');@rmdir($configRoot);
    if(is_dir($backupRoot.'/fixture')){foreach(glob($backupRoot.'/fixture/*')?:[] as $file)@unlink($file);@rmdir($backupRoot.'/fixture');}
    @rmdir($backupRoot);@rmdir($storage);
}
echo "Phase 49 Release Candidate Deployment & Operational Hardening MariaDB suite passed.\n";
