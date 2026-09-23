<?php
declare(strict_types=1);

final class LostJobClaim extends RuntimeException {}

function job_table_meta(string $table): array {
    return match($table) {
        'media_jobs' => ['schedule'=>'available_at','terminal'=>['done','blocked','failed']],
        'transcription_jobs' => ['schedule'=>'available_at','terminal'=>['done','blocked','failed']],
        'ai_jobs' => ['schedule'=>'available_at','terminal'=>['done','blocked','failed']],
        'source_monitor_jobs' => ['schedule'=>'scheduled_at','terminal'=>['done','failed']],
        'research_automation_runs' => ['schedule'=>'available_at','terminal'=>['completed','failed','skipped']],
        'research_file_jobs' => ['schedule'=>'available_at','terminal'=>['done','blocked','failed']],
        'research_transcription_jobs' => ['schedule'=>'available_at','terminal'=>['done','blocked','failed']],
        'research_retrieval_jobs' => ['schedule'=>'available_at','terminal'=>['done','failed']],
        'research_autonomy_jobs' => ['schedule'=>'available_at','terminal'=>['done','failed']],
        default => throw new InvalidArgumentException('Unsupported job table.'),
    };
}

function job_reclaim_expired(PDO $pdo,string $table): int {
    job_table_meta($table);
    $sql="UPDATE $table SET status='queued',claim_token=NULL,lease_expires_at=NULL,started_at=NULL,last_error=CASE WHEN last_error IS NULL OR last_error='' THEN 'Recovered expired worker lease.' ELSE CONCAT(LEFT(last_error,850),' | Recovered expired worker lease.') END WHERE status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at<NOW()";
    return $pdo->exec($sql);
}

function job_claim(PDO $pdo,string $table,string $selectSql,array $params=[],int $leaseSeconds=900): ?array {
    job_table_meta($table);
    $leaseSeconds=max(60,min(21600,$leaseSeconds));
    if($pdo->inTransaction()) throw new RuntimeException('job_claim requires no active transaction.');
    $pdo->beginTransaction();
    try {
        job_reclaim_expired($pdo,$table);
        $q=$pdo->prepare($selectSql.' FOR UPDATE');
        $q->execute($params);
        $job=$q->fetch();
        if(!$job){$pdo->commit();return null;}
        $token=bin2hex(random_bytes(16));
        $sql="UPDATE $table SET status='processing',attempts=attempts+1,claim_token=?,lease_expires_at=DATE_ADD(NOW(),INTERVAL $leaseSeconds SECOND),started_at=NOW(),completed_at=NULL WHERE id=? AND status='queued'";
        $u=$pdo->prepare($sql);$u->execute([$token,$job['id']]);
        if($u->rowCount()!==1) throw new RuntimeException('Unable to atomically claim job.');
        $pdo->commit();
        $job['claim_token']=$token;
        $job['lease_seconds']=$leaseSeconds;
        $job['attempts']=(int)($job['attempts']??0)+1;
        return $job;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function job_claim_renew(PDO $pdo,string $table,int $id,string $token,int $leaseSeconds): void {
    job_table_meta($table);$leaseSeconds=max(60,min(21600,$leaseSeconds));
    $q=$pdo->prepare("UPDATE $table SET lease_expires_at=DATE_ADD(NOW(),INTERVAL $leaseSeconds SECOND) WHERE id=? AND status='processing' AND claim_token=? AND lease_expires_at>=NOW()");
    $q->execute([$id,$token]);
    if($q->rowCount()!==1) throw new LostJobClaim('Worker lease is no longer current.');
}

function job_claim_assert(PDO $pdo,string $table,int $id,string $token): void {
    job_table_meta($table);
    $q=$pdo->prepare("SELECT 1 FROM $table WHERE id=? AND status='processing' AND claim_token=? AND lease_expires_at>=NOW()");
    $q->execute([$id,$token]);
    if(!$q->fetchColumn()) throw new LostJobClaim('Worker lease is no longer current.');
}

function job_claim_complete(PDO $pdo,string $table,int $id,string $token,string $status='done'): void {
    $meta=job_table_meta($table);
    if(!in_array($status,$meta['terminal'],true)) throw new InvalidArgumentException('Unsupported terminal job status.');
    $q=$pdo->prepare("UPDATE $table SET status=?,claim_token=NULL,lease_expires_at=NULL,last_error=NULL,completed_at=NOW() WHERE id=? AND status='processing' AND claim_token=? AND lease_expires_at>=NOW()");
    $q->execute([$status,$id,$token]);
    if($q->rowCount()!==1) throw new LostJobClaim('Worker lease was lost before completion.');
}

function job_claim_retry_or_fail(PDO $pdo,string $table,int $id,string $token,string $message,int $attempts,int $maxAttempts=3,int $delaySeconds=60): string {
    $meta=job_table_meta($table);$maxAttempts=max(1,$maxAttempts);$delaySeconds=max(0,min(86400,$delaySeconds));$message=mb_substr($message,0,1000);
    if($attempts >= $maxAttempts){
        $q=$pdo->prepare("UPDATE $table SET status='failed',claim_token=NULL,lease_expires_at=NULL,last_error=?,completed_at=NOW() WHERE id=? AND status='processing' AND claim_token=? AND lease_expires_at>=NOW()");
        $q->execute([$message,$id,$token]);$result='failed';
    } else {
        $schedule=$meta['schedule'];
        $q=$pdo->prepare("UPDATE $table SET status='queued',claim_token=NULL,lease_expires_at=NULL,started_at=NULL,last_error=?,completed_at=NULL,$schedule=DATE_ADD(NOW(),INTERVAL $delaySeconds SECOND) WHERE id=? AND status='processing' AND claim_token=? AND lease_expires_at>=NOW()");
        $q->execute([$message,$id,$token]);$result='queued';
    }
    if($q->rowCount()!==1) throw new LostJobClaim('Worker lease was lost before retry/failure update.');
    return $result;
}
