<?php
declare(strict_types=1);

function migration_split_sql(string $sql,string $baseDir): array {
    $lines=preg_split('/\R/',$sql);$buf='';$out=[];
    foreach($lines as $line){
        $trim=trim($line);if($trim===''||str_starts_with($trim,'--'))continue;
        if(preg_match('/^SOURCE\s+(.+);$/i',$trim,$m)){$nested=rtrim($baseDir,'/').'/'.trim($m[1]);if(!is_file($nested))throw new RuntimeException('Missing SQL import: '.$nested);foreach(migration_split_sql((string)file_get_contents($nested),dirname($nested)) as $q)$out[]=$q;continue;}
        $buf.=$line."\n";if(str_ends_with(rtrim($line),';')){$out[]=trim($buf);$buf='';}
    }
    if(trim($buf)!=='')$out[]=trim($buf);return $out;
}
function migration_has_destructive_sql(string $sql): bool {
    $clean=(string)preg_replace('/--.*$/m','',$sql);
    return (bool)preg_match('/\b(DROP\s+(TABLE|COLUMN|INDEX|DATABASE)|TRUNCATE\s+TABLE|RENAME\s+TABLE)\b/i',$clean);
}
function migration_prepare_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) PRIMARY KEY, filename VARCHAR(255) NOT NULL, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migration_runs (version VARCHAR(64) PRIMARY KEY, filename VARCHAR(255) NOT NULL, checksum CHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, statement_index INT UNSIGNED NOT NULL DEFAULT 0, statement_count INT UNSIGNED NOT NULL DEFAULT 0, error_message VARCHAR(2000) NULL, started_at DATETIME NULL, completed_at DATETIME NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_schema_migration_run_status(status,updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function migration_lock(PDO $pdo,int $seconds=10): void {
    $q=$pdo->prepare("SELECT GET_LOCK('annotated_schema_upgrade',?)");$q->execute([$seconds]);$locked=(int)$q->fetchColumn();$q->closeCursor();
    if($locked!==1)throw new RuntimeException('Another Annotated database upgrade is already running.');
}
function migration_unlock(PDO $pdo): void {
    try{$q=$pdo->query("SELECT RELEASE_LOCK('annotated_schema_upgrade')");if($q){$q->fetchColumn();$q->closeCursor();}}catch(Throwable $e){}
}
function migration_identifier(string $value): string {
    $value=trim($value);
    if(strlen($value)>=2&&$value[0]==='`'&&$value[strlen($value)-1]==='`')$value=substr($value,1,-1);
    if($value===''||!preg_match('/^[A-Za-z0-9_]+$/',$value))throw new RuntimeException('Unsupported migration identifier: '.$value);
    return $value;
}
function migration_column_exists(PDO $pdo,string $table,string $column): bool {
    $q=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
    $q->execute([$table,$column]);return (bool)$q->fetchColumn();
}
function migration_index_exists(PDO $pdo,string $table,string $index): bool {
    $q=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1');
    $q->execute([$table,$index]);return (bool)$q->fetchColumn();
}
function migration_constraint_exists(PDO $pdo,string $table,string $constraint): bool {
    $q=$pdo->prepare('SELECT 1 FROM information_schema.table_constraints WHERE table_schema=DATABASE() AND table_name=? AND constraint_name=? LIMIT 1');
    $q->execute([$table,$constraint]);return (bool)$q->fetchColumn();
}
function migration_split_alter_clauses(string $body): array {
    $out=[];$buf='';$depth=0;$quote=null;$len=strlen($body);
    for($i=0;$i<$len;$i++){
        $ch=$body[$i];
        if($quote!==null){
            $buf.=$ch;
            if($quote==='`'){
                if($ch==='`'){
                    if($i+1<$len&&$body[$i+1]==='`'){$buf.=$body[++$i];continue;}
                    $quote=null;
                }
            }elseif($ch===$quote){
                if($i+1<$len&&$body[$i+1]===$quote){$buf.=$body[++$i];continue;}
                if($i===0||$body[$i-1]!=='\\')$quote=null;
            }
            continue;
        }
        if($ch==='`'||$ch==="'"||$ch==='"'){$quote=$ch;$buf.=$ch;continue;}
        if($ch==='('){$depth++;$buf.=$ch;continue;}
        if($ch===')'){$depth=max(0,$depth-1);$buf.=$ch;continue;}
        if($ch===','&&$depth===0){if(trim($buf)!=='')$out[]=trim($buf);$buf='';continue;}
        $buf.=$ch;
    }
    if(trim($buf)!=='')$out[]=trim($buf);
    return $out;
}
function migration_portable_alter(PDO $pdo,string $sql): ?string {
    $statement=rtrim(trim($sql),';');
    if(!preg_match('/^ALTER\\s+TABLE\\s+(`?[A-Za-z0-9_]+`?)\\s+(.+)$/is',$statement,$m))return null;
    $tableRaw=$m[1];$table=migration_identifier($tableRaw);$clauses=migration_split_alter_clauses($m[2]);
    $changed=false;$kept=[];
    foreach($clauses as $clause){
        if(preg_match('/^ADD\\s+COLUMN\\s+IF\\s+NOT\\s+EXISTS\\s+(`?[A-Za-z0-9_]+`?)\\s+(.+)$/is',$clause,$cm)){
            $changed=true;$column=migration_identifier($cm[1]);if(migration_column_exists($pdo,$table,$column))continue;
            $kept[]='ADD COLUMN '.$cm[1].' '.$cm[2];continue;
        }
        if(preg_match('/^ADD\\s+(?:(UNIQUE)\\s+)?(INDEX|KEY)\\s+IF\\s+NOT\\s+EXISTS\\s+(`?[A-Za-z0-9_]+`?)\\s*(.+)$/is',$clause,$im)){
            $changed=true;$index=migration_identifier($im[3]);if(migration_index_exists($pdo,$table,$index))continue;
            $kept[]='ADD '.($im[1]?'UNIQUE ':'').$im[2].' '.$im[3].' '.$im[4];continue;
        }
        if(preg_match('/^ADD\\s+CONSTRAINT\\s+(`?[A-Za-z0-9_]+`?)\\s+(.+)$/is',$clause,$fm)){
            $changed=true;$constraint=migration_identifier($fm[1]);if(migration_constraint_exists($pdo,$table,$constraint))continue;
            $kept[]=$clause;continue;
        }
        $kept[]=$clause;
    }
    if(!$changed)return null;
    if(!$kept)return '';
    return 'ALTER TABLE '.$tableRaw."\n  ".implode(",\n  ",$kept).';';
}
function migration_execute_statement(PDO $pdo,string $sql): void {
    $portable=migration_portable_alter($pdo,$sql);
    if($portable!==null){if($portable==='')return;$sql=$portable;}
    $verb=strtoupper((string)(preg_split('/\\s+/',ltrim($sql),2)[0]??''));
    if(in_array($verb,['SELECT','SHOW','DESCRIBE','DESC','EXPLAIN'],true)){
        $q=$pdo->query($sql);if($q){$q->fetchAll();$q->closeCursor();}return;
    }
    $pdo->exec($sql);
}
function migration_inventory(PDO $pdo,string $dir): array {
    migration_prepare_tables($pdo);$files=glob(rtrim($dir,'/').'/*.sql')?:[];sort($files,SORT_STRING);
    $applied=[];foreach($pdo->query('SELECT version,checksum FROM schema_migrations') as $r)$applied[$r['version']]=$r['checksum'];
    $runs=[];foreach($pdo->query('SELECT version,checksum,status,attempts,statement_index,statement_count,error_message FROM schema_migration_runs') as $r)$runs[$r['version']]=$r;
    $pending=[];
    foreach($files as $file){$version=basename($file,'.sql');$checksum=hash_file('sha256',$file);$body=(string)file_get_contents($file);if(isset($applied[$version])){if(!hash_equals($applied[$version],$checksum))throw new RuntimeException("Applied migration changed: $version");continue;}if(isset($runs[$version])&&!hash_equals((string)$runs[$version]['checksum'],$checksum))throw new RuntimeException("Previously attempted migration changed: $version. Restore the original file and add a new corrective migration.");if(migration_has_destructive_sql($body))throw new RuntimeException("Migration $version contains destructive SQL. Use an explicitly reviewed expand/contract migration instead.");$pending[]=[$version,$file,$checksum,migration_split_sql($body,dirname($file)),$runs[$version]??null];}
    return [$pending,$runs];
}
function migration_apply_pending(PDO $pdo,string $dir,int $lockSeconds=10): array {
    migration_prepare_tables($pdo);migration_lock($pdo,$lockSeconds);$applied=[];
    try{[$pending]=migration_inventory($pdo,$dir);foreach($pending as [$version,$file,$checksum,$statements,$prior]){$count=count($statements);$q=$pdo->prepare("INSERT INTO schema_migration_runs(version,filename,checksum,status,attempts,statement_index,statement_count,error_message,started_at,completed_at) VALUES(?,?,?,'running',1,0,?,NULL,NOW(),NULL) ON DUPLICATE KEY UPDATE filename=VALUES(filename),status='running',attempts=attempts+1,statement_index=0,statement_count=VALUES(statement_count),error_message=NULL,started_at=NOW(),completed_at=NULL");$q->execute([$version,basename($file),$checksum,$count]);$i=-1;try{foreach($statements as $i=>$sql){$pdo->prepare('UPDATE schema_migration_runs SET statement_index=? WHERE version=?')->execute([$i+1,$version]);migration_execute_statement($pdo,$sql);}$s=$pdo->prepare('INSERT INTO schema_migrations(version,filename,checksum) VALUES(?,?,?)');$s->execute([$version,basename($file),$checksum]);$pdo->prepare("UPDATE schema_migration_runs SET status='applied',statement_index=statement_count,error_message=NULL,completed_at=NOW() WHERE version=?")->execute([$version]);$applied[]=$version;}catch(Throwable $e){$msg=mb_substr($e->getMessage(),0,1900);$pdo->prepare("UPDATE schema_migration_runs SET status='failed',error_message=?,completed_at=NOW() WHERE version=?")->execute([$msg,$version]);throw new RuntimeException("Migration $version failed after statement ".($i+1)." of $count. MariaDB DDL may already be committed; keep this migration file unchanged and retry only after correcting the environment. Error: $msg",0,$e);}}return $applied;}finally{migration_unlock($pdo);}
}
