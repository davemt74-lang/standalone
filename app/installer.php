<?php
declare(strict_types=1);

require_once __DIR__.'/migrations.php';

function installer_database_name(PDO $pdo): string {
    $name=(string)($pdo->query('SELECT DATABASE()')->fetchColumn()?:'');
    if($name==='')throw new RuntimeException('The configured DSN must select a database.');
    return $name;
}
function installer_database_table_count(PDO $pdo): int {
    $db=installer_database_name($pdo);
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_type='BASE TABLE'");
    $q->execute([$db]);
    return (int)$q->fetchColumn();
}
function installer_schema_tables(string $schemaFile): array {
    if(!is_file($schemaFile))throw new RuntimeException('Missing database/schema.sql.');
    $sql=(string)file_get_contents($schemaFile);
    preg_match_all('/CREATE\\s+TABLE\\s+IF\\s+NOT\\s+EXISTS\\s+\\x60?([A-Za-z0-9_]+)\\x60?/i',$sql,$m);
    $tables=array_values(array_unique($m[1]??[]));
    if(!$tables)throw new RuntimeException('database/schema.sql does not define any tables.');
    return $tables;
}
function installer_table_exists(PDO $pdo,string $table): bool {
    if(!preg_match('/^[A-Za-z0-9_]+$/',$table))throw new InvalidArgumentException('Invalid table name.');
    $db=installer_database_name($pdo);
    $q=$pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name=? AND table_type='BASE TABLE' LIMIT 1");
    $q->execute([$db,$table]);
    return (bool)$q->fetchColumn();
}
function installer_base_schema_ready(PDO $pdo,string $schemaFile): bool {
    foreach(installer_schema_tables($schemaFile) as $table)if(!installer_table_exists($pdo,$table))return false;
    return true;
}
function installer_pending_migrations(PDO $pdo,string $migrationDir): array {
    [$pending]=migration_inventory($pdo,$migrationDir);
    return $pending;
}
function installer_import_base_schema(PDO $pdo,string $schemaFile): int {
    if(installer_base_schema_ready($pdo,$schemaFile))return 0;
    $existing=installer_database_table_count($pdo);
    if($existing>0)throw new RuntimeException('The configured database is not empty and does not contain a complete Annotated base schema. Use an empty database for a fresh install.');
    $sql=(string)file_get_contents($schemaFile);
    $statements=migration_split_sql($sql,dirname($schemaFile));
    if(!$statements)throw new RuntimeException('database/schema.sql contains no executable statements.');
    $count=0;
    try{
        foreach($statements as $statement){migration_execute_statement($pdo,$statement);$count++;}
    }catch(Throwable $e){
        throw new RuntimeException('Base schema import stopped after statement '.$count.'. Database DDL may already be committed. Recreate the empty database and retry. Error: '.mb_substr($e->getMessage(),0,1200),0,$e);
    }
    if(!installer_base_schema_ready($pdo,$schemaFile))throw new RuntimeException('Base schema import completed but required tables are missing.');
    return $count;
}
function installer_run(PDO $pdo,string $schemaFile,string $migrationDir): array {
    $schemaStatements=installer_import_base_schema($pdo,$schemaFile);
    $applied=migration_apply_pending($pdo,$migrationDir,15);
    $pending=installer_pending_migrations($pdo,$migrationDir);
    if($pending)throw new RuntimeException(count($pending).' migration(s) are still pending after installation.');
    return ['schema_statements'=>$schemaStatements,'migrations'=>$applied];
}


function installer_request_is_https(): bool {
    if(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')return true;
    if((string)($_SERVER['SERVER_PORT']??'')==='443')return true;
    $forwarded=strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??'')));
    if($forwarded==='')return false;
    return trim(explode(',',$forwarded)[0])==='https';
}
function installer_default_base_url(): string {
    $scheme=installer_request_is_https()?'https':'http';
    $host=trim((string)($_SERVER['HTTP_HOST']??''));
    if($host==='')return 'http://localhost';
    return $scheme.'://'.$host;
}
function installer_validate_db_identifier(string $name): string {
    $name=trim($name);
    if($name===''||!preg_match('/^[A-Za-z0-9_$-]{1,64}$/',$name))throw new RuntimeException('Database name may contain only letters, numbers, underscore, dollar sign, and hyphen.');
    return $name;
}
function installer_build_config(array $input,string $root): array {
    $baseUrl=rtrim(trim((string)($input['base_url']??'')),'/');
    if(!filter_var($baseUrl,FILTER_VALIDATE_URL))throw new RuntimeException('Enter a valid site URL.');
    $parts=parse_url($baseUrl);
    if(!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||empty($parts['host']))throw new RuntimeException('Site URL must use http or https.');

    $host=trim((string)($input['db_host']??'127.0.0.1'));
    if($host==='')throw new RuntimeException('Database host is required.');
    $port=(int)($input['db_port']??3306);
    if($port<1||$port>65535)throw new RuntimeException('Database port is invalid.');
    $name=installer_validate_db_identifier((string)($input['db_name']??''));
    $user=trim((string)($input['db_user']??''));
    if($user==='')throw new RuntimeException('Database username is required.');
    $pass=(string)($input['db_pass']??'');

    return [
        'app'=>[
            'name'=>'Annotated',
            'base_url'=>$baseUrl,
            'session_name'=>'annotated_session',
            'encryption_key'=>bin2hex(random_bytes(32)),
        ],
        'db'=>[
            'dsn'=>'mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4',
            'user'=>$user,
            'pass'=>$pass,
        ],
        'storage'=>[
            'private_root'=>dirname($root).'/annotated-private',
        ],
        'extension'=>[
            'allowed_ids'=>[],
            'session_ttl_days'=>30,
        ],
        'transcription'=>[
            'command'=>'',
            'provider'=>'local',
            'model'=>'',
        ],
        'oauth'=>[
            'google'=>['client_id'=>'','client_secret'=>'','redirect_uri'=>''],
            'x'=>['client_id'=>'','client_secret'=>'','redirect_uri'=>''],
        ],
    ];
}
function installer_write_config(string $configFile,array $config): void {
    if(is_file($configFile))throw new RuntimeException('config.php already exists.');
    $body="<?php\ndeclare(strict_types=1);\n\nreturn ".var_export($config,true).";\n";
    $dir=dirname($configFile);
    if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('The application directory is not writable. Temporarily allow PHP to write config.php, then reload the installer.');
    $tmp=$configFile.'.tmp-'.bin2hex(random_bytes(6));
    if(file_put_contents($tmp,$body,LOCK_EX)===false)throw new RuntimeException('Unable to write the temporary configuration file.');
    @chmod($tmp,0600);
    if(!@rename($tmp,$configFile)){@unlink($tmp);throw new RuntimeException('Unable to create config.php.');}
}
function installer_connect(array $config): PDO {
    $db=$config['db']??[];
    return new PDO((string)($db['dsn']??''),(string)($db['user']??''),(string)($db['pass']??''),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
}
