<?php
declare(strict_types=1);

require_once __DIR__.'/installer.php';

/**
 * Read-only migration/schema preflight for runtime pages.
 * Unlike migration_inventory(), this does not create or alter any tables.
 */
function app_schema_runtime_status(PDO $pdo,string $migrationDir): array {
    try{
        if(!installer_table_exists($pdo,'schema_migrations')){
            return ['ready'=>false,'pending'=>['schema_migrations'],'changed'=>[],'error'=>null];
        }
        $files=glob(rtrim($migrationDir,'/').'/*.sql')?:[];
        sort($files,SORT_STRING);
        $expected=[];
        foreach($files as $file){
            $version=basename($file,'.sql');
            $expected[$version]=hash_file('sha256',$file);
        }

        $applied=[];
        foreach($pdo->query('SELECT version,checksum FROM schema_migrations') as $row){
            $applied[(string)$row['version']]=(string)$row['checksum'];
        }

        $pending=[];$changed=[];
        foreach($expected as $version=>$checksum){
            if(!isset($applied[$version])){$pending[]=$version;continue;}
            if(!hash_equals($checksum,$applied[$version]))$changed[]=$version;
        }

        // Direct dependencies used by home.php before optional feature guards.
        $requiredTables=['users','annotations','follows','blocks','teams','team_members','user_preferences'];
        foreach($requiredTables as $table)if(!installer_table_exists($pdo,$table))$pending[]='table:'.$table;

        $requiredColumns=[
            ['users','profile_image_url'],['users','bio'],
            ['user_preferences','profile_visibility'],['user_preferences','search_visibility'],
        ];
        foreach($requiredColumns as [$table,$column]){
            $q=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
            $q->execute([$table,$column]);
            if(!$q->fetchColumn())$pending[]='column:'.$table.'.'.$column;
        }

        $pending=array_values(array_unique($pending));
        return ['ready'=>!$pending&&!$changed,'pending'=>$pending,'changed'=>$changed,'error'=>null];
    }catch(Throwable $e){
        return ['ready'=>false,'pending'=>[],'changed'=>[],'error'=>$e->getMessage()];
    }
}

function app_schema_runtime_incident(Throwable|string $error,string $surface): string {
    $message=$error instanceof Throwable?$error->getMessage():$error;
    $incident=substr(hash('sha256',$surface.'|'.$message.'|'.microtime(true).'|'.random_bytes(8)),0,12);
    error_log('[Annotated '.$surface.' '.$incident.'] '.$message.($error instanceof Throwable?' in '.$error->getFile().':'.$error->getLine():''));
    return $incident;
}
