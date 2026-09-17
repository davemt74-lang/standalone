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
        $buf .= $line."\n";
        if (str_ends_with(rtrim($line), ';')) { $out[]=trim($buf); $buf=''; }
    }
    if(trim($buf)!=='') $out[]=trim($buf);
    return $out;
}
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) PRIMARY KEY, filename VARCHAR(255) NOT NULL, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$files=glob(__DIR__.'/database/migrations/*.sql') ?: []; sort($files,SORT_STRING);
$applied=[]; foreach($pdo->query('SELECT version,checksum FROM schema_migrations') as $r) $applied[$r['version']]=$r['checksum'];
$pending=[];
foreach($files as $file){ $version=basename($file,'.sql'); $checksum=hash_file('sha256',$file); if(isset($applied[$version])) { if(!hash_equals($applied[$version],$checksum)) throw new RuntimeException("Applied migration changed: $version"); continue; } $pending[]=[$version,$file,$checksum]; }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_csrf();
    foreach($pending as [$version,$file,$checksum]){
        $pdo->beginTransaction();
        try {
            foreach(split_sql(file_get_contents($file)) as $sql) $pdo->exec($sql);
            $s=$pdo->prepare('INSERT INTO schema_migrations(version,filename,checksum) VALUES(?,?,?)'); $s->execute([$version,basename($file),$checksum]);
            $pdo->commit();
        } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }
    header('Location: /upgrade.php?done=1'); exit;
}
?><!doctype html><html><head><meta charset="utf-8"><title>Annotated Database Upgrade</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="panel narrow"><h1>Database Upgrade</h1><p><?=count($pending)?> migration(s) pending.</p><?php if($pending):?><ul><?php foreach($pending as $m):?><li><?=h($m[0])?></li><?php endforeach?></ul><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><button>Run upgrades</button></form><?php else:?><p>Database is current.</p><?php endif?><p><a href="/">Return to Annotated</a></p></main></body></html>
