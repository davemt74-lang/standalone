<?php
declare(strict_types=1);

/**
 * Phase 49 — Release Candidate Deployment & Operational Hardening.
 *
 * Operational helpers only. No database migration and no product-feature authority.
 */
function release_latest_migration(string $root): ?string {
    $files=glob(rtrim($root,'/').'/database/migrations/*.sql')?:[];sort($files,SORT_STRING);return $files?basename((string)end($files)):null;
}
function release_package_fingerprint(string $root): string {
    $root=rtrim($root,'/');$files=[
      $root.'/app/release.php',$root.'/app/release-operations.php',$root.'/database/schema.sql',$root.'/extension/manifest.json',
      $root.'/bin/release-preflight.php',$root.'/bin/release-backup.php',$root.'/bin/release-backup-verify.php',$root.'/bin/release-restore-plan.php',
      $root.'/docs/RELEASE-V1.1-RC1.md',$root.'/docs/phase-49-release-candidate-operational-hardening.md'
    ];$latest=release_latest_migration($root);if($latest)$files[]=$root.'/database/migrations/'.$latest;
    $material=[];foreach($files as $file)$material[substr($file,strlen($root)+1)]=is_file($file)?hash_file('sha256',$file):null;
    return hash('sha256',json_encode($material,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
function release_manifest_data(string $root,?string $buildSha=null): array {
    $manifestPath=rtrim($root,'/').'/extension/manifest.json';$manifest=is_file($manifestPath)?json_decode((string)file_get_contents($manifestPath),true):null;
    return [
      'schema'=>'annotated.release-manifest.v1',
      'release'=>ANNOTATED_RELEASE,
      'version'=>ANNOTATED_RELEASE_VERSION,
      'phase'=>ANNOTATED_RELEASE_PHASE,
      'channel'=>'release_candidate',
      'extension_version'=>ANNOTATED_EXTENSION_VERSION,
      'extension_manifest_version'=>(int)($manifest['manifest_version']??0),
      'minimum_php'=>'8.1.0',
      'supported_database_families'=>['MariaDB','MySQL 8'],
      'latest_migration'=>release_latest_migration($root),
      'package_fingerprint'=>release_package_fingerprint($root),
      'build_sha'=>$buildSha?:null,
    ];
}
function release_database_target(array $config): array {
    $dsn=trim((string)($config['db']['dsn']??''));if(!str_starts_with(strtolower($dsn),'mysql:'))throw new RuntimeException('Release backup supports MySQL/MariaDB DSNs only.');
    $target=['host'=>'127.0.0.1','port'=>3306,'database'=>'','unix_socket'=>null,'charset'=>'utf8mb4'];
    foreach(explode(';',substr($dsn,6)) as $part){if(!str_contains($part,'='))continue;[$k,$v]=array_map('trim',explode('=',$part,2));if($k==='host')$target['host']=$v;elseif($k==='port')$target['port']=max(1,(int)$v);elseif($k==='dbname')$target['database']=$v;elseif($k==='unix_socket')$target['unix_socket']=$v;elseif($k==='charset')$target['charset']=$v;}
    if($target['database']===''||!preg_match('/^[A-Za-z0-9_$-]{1,128}$/',$target['database']))throw new RuntimeException('Database name is missing or unsafe for release backup.');
    $target['user']=(string)($config['db']['user']??'');$target['password']=(string)($config['db']['pass']??'');if($target['user']==='')throw new RuntimeException('Database user is missing.');
    return $target;
}
function release_command_path(array|string $commands): ?string {
    foreach((array)$commands as $command){if(!preg_match('/^[A-Za-z0-9._-]+$/',(string)$command))continue;$out=[];$code=1;if(function_exists('exec')){@exec('command -v '.escapeshellarg((string)$command).' 2>/dev/null',$out,$code);if($code===0&&!empty($out[0]))return trim((string)$out[0]);}}
    return null;
}
function release_normalize_path(string $path): string {
    $path=str_replace('\\','/',$path);$absolute=str_starts_with($path,'/');$parts=[];
    foreach(explode('/',$path) as $part){if($part===''||$part==='.')continue;if($part==='..'){array_pop($parts);continue;}$parts[]=$part;}
    return ($absolute?'/':'').implode('/',$parts);
}
function release_resolve_path(string $path): string {
    $normalized=release_normalize_path($path);$probe=$normalized;$tail=[];
    while(!file_exists($probe)&&dirname($probe)!==$probe){array_unshift($tail,basename($probe));$probe=dirname($probe);}
    $resolved=realpath($probe)?:$probe;return release_normalize_path(rtrim($resolved,'/').($tail?'/'.implode('/',$tail):''));
}
function release_backup_destination_assert(string $base,string $root): string {
    $base=rtrim(trim($base),'/');if($base==='')throw new RuntimeException('Backup output directory is required.');
    if(!str_starts_with($base,'/'))$base=getcwd().'/'.$base;$normalized=release_resolve_path($base);$rootNormalized=release_resolve_path($root);
    if($normalized===$rootNormalized||str_starts_with(rtrim($normalized,'/').'/',rtrim($rootNormalized,'/').'/'))throw new RuntimeException('Backup output must be outside the Annotated application/web tree.');
    return $normalized;
}
function release_mysql_option_quote(string $value): string {
    return '"'.str_replace(["\\","\"","\n","\r","\t"],["\\\\","\\\"","\\n","\\r","\\t"],$value).'"';
}
function release_backup_requirements(array $config): array {
    $checks=[];$target=null;try{$target=release_database_target($config);$checks['database_target']=['pass'=>true,'detail'=>$target['database'].' @ '.($target['unix_socket']?:($target['host'].':'.$target['port']))];}catch(Throwable $e){$checks['database_target']=['pass'=>false,'detail'=>$e->getMessage()];}
    $commands=['dump'=>['mysqldump','mariadb-dump'],'client'=>['mysql','mariadb'],'tar'=>['tar'],'gzip'=>['gzip']];
    $paths=[];foreach($commands as $key=>$names){$paths[$key]=release_command_path($names);$checks[$key]=['pass'=>$paths[$key]!==null,'detail'=>$paths[$key]?:('Missing '.implode(' or ',$names))];}
    $checks['zlib']=['pass'=>function_exists('gzopen'),'detail'=>function_exists('gzopen')?'PHP zlib available.':'PHP zlib extension is required.'];
    $storage=(string)($config['storage']['private_root']??'');$checks['storage']=['pass'=>$storage!==''&&is_dir($storage)&&is_readable($storage),'detail'=>$storage!==''?$storage:'Private storage path is not configured.'];
    $pass=count(array_filter($checks,fn($c)=>!$c['pass']))===0;
    return ['pass'=>$pass,'checks'=>$checks,'commands'=>$paths,'database'=>$target,'storage_root'=>$storage];
}
function release_process_run(array $command,?string $stdoutFile=null,array $env=[]): array {
    if(!function_exists('proc_open'))throw new RuntimeException('proc_open is required for release operations.');
    $descriptors=[0=>['file','/dev/null','r'],1=>$stdoutFile!==null?['file',$stdoutFile,'wb']:['pipe','w'],2=>['pipe','w']];
    $pipes=[];$proc=proc_open($command,$descriptors,$pipes,null,$env?:null);if(!is_resource($proc))throw new RuntimeException('Unable to start release operation process.');
    $stdout='';if($stdoutFile===null&&isset($pipes[1])){$stdout=(string)stream_get_contents($pipes[1]);fclose($pipes[1]);}
    $stderr=isset($pipes[2])?(string)stream_get_contents($pipes[2]):'';if(isset($pipes[2]))fclose($pipes[2]);$code=proc_close($proc);
    return ['code'=>$code,'stdout'=>$stdout,'stderr'=>$stderr];
}
function release_gzip_file(string $source,string $dest): void {
    $in=fopen($source,'rb');$out=gzopen($dest,'wb9');if(!$in||!$out){if(is_resource($in))fclose($in);if(is_resource($out))gzclose($out);throw new RuntimeException('Unable to open backup compression streams.');}
    try{while(!feof($in)){$chunk=fread($in,1024*1024);if($chunk===false)throw new RuntimeException('Unable to read backup dump.');if($chunk!==''&&gzwrite($out,$chunk)===false)throw new RuntimeException('Unable to compress backup dump.');}}finally{fclose($in);gzclose($out);}
}
function release_backup_manifest_write(string $backupDir,array $config,string $root,array $extra=[]): array {
    $backupDir=rtrim($backupDir,'/');$required=['database.sql.gz','private-storage.tar.gz'];$files=[];
    foreach($required as $name){$path=$backupDir.'/'.$name;if(!is_file($path))throw new RuntimeException('Backup component missing: '.$name);$files[$name]=['sha256'=>hash_file('sha256',$path),'bytes'=>filesize($path)?:0];}
    $target=release_database_target($config);$manifest=[
      'schema'=>'annotated.release-backup.v1',
      'release'=>release_manifest_data($root,(string)($extra['build_sha']??'')),
      'created_at'=>gmdate('c'),
      'database'=>['host'=>$target['host'],'port'=>$target['port'],'database'=>$target['database'],'unix_socket'=>$target['unix_socket']],
      'private_storage_basename'=>basename(rtrim((string)$config['storage']['private_root'],'/')),
      'files'=>$files,
    ];
    $id=hash('sha256',json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$manifest['backup_id']=$id;
    $manifestPath=$backupDir.'/manifest.json';file_put_contents($manifestPath,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL,LOCK_EX);@chmod($manifestPath,0600);
    return $manifest;
}
function release_backup_manifest_verify(string $backupDir): array {
    $backupDir=rtrim($backupDir,'/');$path=$backupDir.'/manifest.json';if(!is_file($path))return ['ok'=>false,'errors'=>['manifest.json is missing.'],'manifest'=>null];
    try{$manifest=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){return ['ok'=>false,'errors'=>['manifest.json is invalid JSON.'],'manifest'=>null];}
    $errors=[];$copy=$manifest;$stored=(string)($copy['backup_id']??'');unset($copy['backup_id']);$computed=hash('sha256',json_encode($copy,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));if($stored===''||!hash_equals($stored,$computed))$errors[]='Backup manifest identity hash does not match.';
    foreach((array)($manifest['files']??[]) as $name=>$meta){if(!preg_match('/^[A-Za-z0-9._-]+$/',(string)$name)){$errors[]='Unsafe backup component name.';continue;}$file=$backupDir.'/'.$name;if(!is_file($file)){$errors[]=$name.' is missing.';continue;}$sha=hash_file('sha256',$file);if(empty($meta['sha256'])||!hash_equals((string)$meta['sha256'],$sha))$errors[]=$name.' checksum does not match.';$bytes=filesize($file)?:0;if((int)($meta['bytes']??-1)!==$bytes)$errors[]=$name.' byte size does not match.';}
    foreach(['database.sql.gz','private-storage.tar.gz'] as $required)if(!isset($manifest['files'][$required]))$errors[]=$required.' is absent from manifest.';
    return ['ok'=>!$errors,'errors'=>$errors,'manifest'=>$manifest,'computed_backup_id'=>$computed];
}
function release_restore_plan(string $backupDir,array $config): array {
    $verified=release_backup_manifest_verify($backupDir);if(!$verified['ok'])throw new RuntimeException('Backup verification failed: '.implode(' ',$verified['errors']));$target=release_database_target($config);$req=release_backup_requirements($config);
    $storage=(string)$config['storage']['private_root'];$parent=dirname(rtrim($storage,'/'));$base=basename(rtrim($storage,'/'));
    return [
      'backup_id'=>$verified['manifest']['backup_id'],
      'database_target'=>$target['database'],
      'storage_target'=>$storage,
      'steps'=>[
        'Stop web traffic and every Annotated worker.',
        'Create a fresh emergency backup of the currently deployed database and private storage.',
        'Restore database.sql.gz into the configured database using a temporary 0600 client option file.',
        'Restore private-storage.tar.gz into '.$parent.' and confirm the restored directory is '.$base.'.',
        'Deploy the matching application package recorded with this backup.',
        'Run upgrade.php only if the restored package requires forward migrations.',
        'Run php bin/release-preflight.php and the post-deploy smoke checklist before reopening traffic.',
      ],
      'commands'=>[
        'database'=>($req['commands']['gzip']?:'gzip').' -dc '.escapeshellarg(rtrim($backupDir,'/').'/database.sql.gz').' | '.($req['commands']['client']?:'mysql').' --defaults-extra-file=<0600-client.cnf> '.escapeshellarg($target['database']),
        'storage'=>($req['commands']['tar']?:'tar').' -C '.escapeshellarg($parent).' -xzf '.escapeshellarg(rtrim($backupDir,'/').'/private-storage.tar.gz'),
      ],
      'destructive'=>true,
      'execution'=>'manual_confirmation_required',
    ];
}
function release_config_file_security(string $root): array {
    $path=rtrim($root,'/').'/config.php';if(!is_file($path))return ['pass'=>false,'detail'=>'config.php is missing.'];clearstatcache(true,$path);$mode=fileperms($path);$writableByOthers=$mode!==false&&(($mode&0022)!==0);return ['pass'=>!$writableByOthers,'detail'=>$writableByOthers?'config.php is group/world writable; restrict filesystem permissions.':'config.php is not group/world writable.'];
}
function release_operational_audit(PDO $pdo,array $config,string $root): array {
    $health=release_environment_checks($pdo,$config);$backup=release_backup_requirements($config);$configSecurity=release_config_file_security($root);$manifest=release_manifest_data($root);
    $extPath=rtrim($root,'/').'/extension/manifest.json';$ext=is_file($extPath)?json_decode((string)file_get_contents($extPath),true):[];$extensionMatch=(string)($ext['version']??'')===ANNOTATED_EXTENSION_VERSION&&(int)($ext['manifest_version']??0)===3;
    $workers=$health['workers'];$workerProblems=[];foreach($workers as $name=>$worker)if(in_array($worker['status'],['never','stale','failure'],true))$workerProblems[]=$name.':'.$worker['status'];
    $checks=[
      'environment'=>['pass'=>$health['ready'],'detail'=>$health['ready']?'Critical environment checks pass.':'One or more critical environment checks fail.'],
      'required_workers'=>['pass'=>!$workerProblems,'detail'=>$workerProblems?('Worker readiness failures: '.implode(', ',$workerProblems)):'All required workers have fresh non-failing heartbeats.'],
      'backup_tooling'=>['pass'=>$backup['pass'],'detail'=>$backup['pass']?'Database/private-storage backup tooling is available.':'Backup prerequisites are incomplete.'],
      'config_permissions'=>$configSecurity,
      'extension_identity'=>['pass'=>$extensionMatch,'detail'=>$extensionMatch?'Manifest V3 extension version matches release identity.':'Extension manifest does not match the canonical release identity.'],
    ];
    $ready=count(array_filter($checks,fn($c)=>!$c['pass']))===0;
    return ['ready'=>$ready,'release'=>$manifest,'checks'=>$checks,'environment'=>$health,'backup'=>$backup,'worker_specs'=>release_worker_specs(),'generated_at'=>gmdate('c')];
}
