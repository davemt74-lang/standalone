<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
if (users_exist($pdo)) {
    $upgradeUser = current_user($pdo);
    if (!$upgradeUser || $upgradeUser['role'] !== 'admin') { http_response_code(403); exit('Administrator access required.'); }
}

function split_sql(string $sql): array {
    $lines = preg_split('/\R/', $sql); $buf=''; $out=[];
    foreach ($lines as $line) {
        $trim=trim($line);
        if ($trim==='' || str_starts_with($trim,'--')) continue;
        if (preg_match('/^SOURCE\s+(.+);$/i',$trim,$m)) {
            $nested=dirname(__FILE__).'/'.trim($m[1]);
            if (!is_file($nested)) throw new RuntimeException('Missing SQL import: '.$nested);
            foreach(split_sql((string)file_get_contents($nested)) as $q) $out[]=$q;
            continue;
        }
        $buf .= $line."\n";
        if (str_ends_with(rtrim($line), ';')) { $out[]=trim($buf); $buf=''; }
    }
    if(trim($buf)!=='') $out[]=trim($buf);
    return $out;
}
function migration_has_destructive_sql(string $sql): bool {
    $clean=(string)preg_replace('/--.*$/m','',$sql);
    return (bool)preg_match('/\b(DROP\s+(TABLE|COLUMN|INDEX|DATABASE)|TRUNCATE\s+TABLE|RENAME\s+TABLE)\b/i',$clean);
}
function migration_lock(PDO $pdo,int $seconds=10): void {
    $q=$pdo->prepare("SELECT GET_LOCK('annotated_schema_upgrade',?)");$q->execute([$seconds]);
    if((int)$q->fetchColumn()!==1)throw new RuntimeException('Another Annotated database upgrade is already running.');
}
function migration_unlock(PDO $pdo): void { try{$pdo->query("SELECT RELEASE_LOCK('annotated_schema_upgrade')");}catch(Throwable $e){} }

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) PRIMARY KEY, filename VARCHAR(255) NOT NULL, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migration_runs (version VARCHAR(64) PRIMARY KEY, filename VARCHAR(255) NOT NULL, checksum CHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, statement_index INT UNSIGNED NOT NULL DEFAULT 0, statement_count INT UNSIGNED NOT NULL DEFAULT 0, error_message VARCHAR(2000) NULL, started_at DATETIME NULL, completed_at DATETIME NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_schema_migration_run_status(status,updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function migration_inventory(PDO $pdo): array {
    $files=glob(__DIR__.'/database/migrations/*.sql') ?: []; sort($files,SORT_STRING);
    $applied=[]; foreach($pdo->query('SELECT version,checksum FROM schema_migrations') as $r) $applied[$r['version']]=$r['checksum'];
    $runs=[]; foreach($pdo->query('SELECT version,checksum,status,attempts,statement_index,statement_count,error_message FROM schema_migration_runs') as $r) $runs[$r['version']]=$r;
    $pending=[];
    foreach($files as $file){
        $version=basename($file,'.sql');$checksum=hash_file('sha256',$file);$body=(string)file_get_contents($file);
        if(isset($applied[$version])) { if(!hash_equals($applied[$version],$checksum)) throw new RuntimeException("Applied migration changed: $version"); continue; }
        if(isset($runs[$version])&&!hash_equals((string)$runs[$version]['checksum'],$checksum))throw new RuntimeException("Previously attempted migration changed: $version. Restore the original file and add a new corrective migration.");
        if(migration_has_destructive_sql($body))throw new RuntimeException("Migration $version contains destructive SQL. Use an explicitly reviewed expand/contract migration instead.");
        $pending[]=[$version,$file,$checksum,split_sql($body),$runs[$version]??null];
    }
    return [$pending,$runs];
}

[$pending,$runs]=migration_inventory($pdo);
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_csrf();migration_lock($pdo,10);
    try{
        [$pending]=migration_inventory($pdo); // Refresh after acquiring the global upgrade lock.
        foreach($pending as [$version,$file,$checksum,$statements,$prior]){
            $count=count($statements);
            $q=$pdo->prepare("INSERT INTO schema_migration_runs(version,filename,checksum,status,attempts,statement_index,statement_count,error_message,started_at,completed_at) VALUES(?,?,?,'running',1,0,?,NULL,NOW(),NULL) ON DUPLICATE KEY UPDATE filename=VALUES(filename),status='running',attempts=attempts+1,statement_index=0,statement_count=VALUES(statement_count),error_message=NULL,started_at=NOW(),completed_at=NULL");
            $q->execute([$version,basename($file),$checksum,$count]);
            try{
                foreach($statements as $i=>$sql){
                    $pdo->prepare('UPDATE schema_migration_runs SET statement_index=? WHERE version=?')->execute([$i+1,$version]);
                    $pdo->exec($sql);
                }
                $s=$pdo->prepare('INSERT INTO schema_migrations(version,filename,checksum) VALUES(?,?,?)');$s->execute([$version,basename($file),$checksum]);
                $pdo->prepare("UPDATE schema_migration_runs SET status='applied',statement_index=statement_count,error_message=NULL,completed_at=NOW() WHERE version=?")->execute([$version]);
            }catch(Throwable $e){
                $msg=mb_substr($e->getMessage(),0,1900);$pdo->prepare("UPDATE schema_migration_runs SET status='failed',error_message=?,completed_at=NOW() WHERE version=?")->execute([$msg,$version]);
                throw new RuntimeException("Migration $version failed after statement ".($i+1)." of $count. MariaDB DDL may already be committed; keep this migration file unchanged and retry only after correcting the environment. Error: $msg",0,$e);
            }
        }
        header('Location: /upgrade.php?done=1'); exit;
    }catch(Throwable $e){$error=$e->getMessage();}
    finally{migration_unlock($pdo);}
    [$pending,$runs]=migration_inventory($pdo);
}
$failed=array_filter($runs,fn($r)=>($r['status']??'')==='failed');
?><!doctype html><html><head><meta charset="utf-8"><title>Annotated Database Upgrade</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="panel narrow"><h1>Database Upgrade</h1><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?><?php if(isset($_GET['done'])):?><div class="success">Database upgrade completed.</div><?php endif?><p><?=count($pending)?> migration(s) pending.</p><?php if($failed):?><div class="error"><strong>Previous migration failure recorded.</strong><p>MariaDB DDL is not transactionally rolled back. The migration file is checksum-pinned; correct the environment and retry the same immutable, retry-safe migration.</p><?php foreach($failed as $version=>$r):?><p><code><?=h($version)?></code> · attempt <?=h((string)$r['attempts'])?> · statement <?=h((string)$r['statement_index'])?>/<?=h((string)$r['statement_count'])?><br><?=h($r['error_message']??'Unknown error')?></p><?php endforeach?></div><?php endif?><?php if($pending):?><ul><?php foreach($pending as $m):?><li><?=h($m[0])?></li><?php endforeach?></ul><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><button>Run upgrades</button></form><?php else:?><p>Database is current.</p><?php endif?><p><a href="/">Return to Annotated</a></p></main></body></html>