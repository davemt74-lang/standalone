<?php
declare(strict_types=1);

require_once __DIR__.'/migrations.php';

function installer_database_name(PDO $pdo): string {
    $name=(string)($pdo->query('SELECT DATABASE()')->fetchColumn()?:'');
    if($name==='')throw new RuntimeException('The configured DSN must select a MariaDB database.');
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
        throw new RuntimeException('Base schema import stopped after statement '.$count.'. MariaDB DDL may already be committed. Recreate the empty database and retry. Error: '.mb_substr($e->getMessage(),0,1200),0,$e);
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
