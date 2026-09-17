<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use($root,&$fail){$s=(string)file_get_contents($root.'/'.$file);if(!str_contains($s,$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use($root,&$fail){$s=(string)file_get_contents($root.'/'.$file);if(str_contains($s,$needle))$fail[]=$message;};

if(!is_file($root.'/database/migrations/20260917_006_worker_leases.sql'))$fail[]='Worker lease migration 006 is missing.';
foreach(['media_jobs','transcription_jobs','ai_jobs','source_monitor_jobs'] as $table){
    $migration=(string)file_get_contents($root.'/database/migrations/20260917_006_worker_leases.sql');
    if(!str_contains($migration,"ALTER TABLE $table ADD COLUMN IF NOT EXISTS claim_token"))$fail[]="$table must have claim tokens.";
    if(!str_contains($migration,"ALTER TABLE $table ADD COLUMN IF NOT EXISTS lease_expires_at"))$fail[]="$table must have expiring leases.";
}
$need('app/jobs.php','function job_claim(','Workers need one centralized atomic claim primitive.');
$need('app/jobs.php','FOR UPDATE','Job claims must lock the selected queue row.');
$need('app/jobs.php','claim_token','Job completion must be bound to a unique claim token.');
$need('app/jobs.php','job_reclaim_expired','Expired worker leases must be safely reclaimable.');
$need('app/jobs.php','job_claim_retry_or_fail','Retries/failures must preserve claim ownership.');
foreach(['worker/media-worker.php','worker/transcription-worker.php','worker/ai-worker.php','worker/source-monitor-worker.php'] as $file){
    $need($file,'job_claim($pdo',"$file must use the atomic lease claim helper.");
    $avoid($file,"status='processing',attempts=attempts+1", "$file must not hand-roll non-tokenized job claims.");
}
$need('api/extension-publish.php','lock_source_row($pdo,$sourceId)','Publish must lock the canonical Source before version allocation.');
$need('api/extension-publish.php','next_source_version_number($pdo,$sourceId)','Publish must allocate Source Version numbers under the Source lock.');
$need('worker/source-monitor-worker.php','lock_source_row($pdo','Source monitor must reconcile fetched content under the canonical Source lock.');
$need('worker/source-monitor-worker.php','source_current_version($pdo,$source)','Source monitor must re-read the latest version after locking.');
$need('app/functions.php','FOR UPDATE','Concurrent first-seen Source creation must recover with a current read after unique-key races.');
$need('app/migrations.php','GET_LOCK','Database upgrades must use a MariaDB advisory lock.');
$need('app/migrations.php','schema_migration_runs','Database upgrades must persist attempts and partial failures.');
$need('app/migrations.php','MariaDB DDL may already be committed','Upgrade failures must explicitly account for non-transactional DDL.');
$avoid('app/migrations.php','$pdo->beginTransaction();','DDL migrations must not pretend transaction rollback is atomic.');
$need('app/migrations.php','Previously attempted migration changed','Failed/attempted migration checksums must remain immutable.');
$need('upgrade.php','migration_apply_pending','Web upgrades must use the shared migration runner exercised by MariaDB CI.');

foreach(glob($root.'/database/migrations/*.sql')?:[] as $file){
    $body=(string)file_get_contents($file);
    if(preg_match('/\b(DROP\s+(TABLE|COLUMN|INDEX|DATABASE)|TRUNCATE\s+TABLE|RENAME\s+TABLE)\b/i',(string)preg_replace('/--.*$/m','',$body)))$fail[]='Destructive migration SQL requires explicit expand/contract review: '.basename($file);
}
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Concurrency and migration contracts passed.\n";
