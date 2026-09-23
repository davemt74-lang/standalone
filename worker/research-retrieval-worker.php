<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
release_worker_heartbeat($pdo,'research_retrieval','starting','Worker invocation started.');
$job=job_claim($pdo,'research_retrieval_jobs',"SELECT j.id job_id,j.*,rp.public_id project_public_id FROM research_retrieval_jobs j JOIN research_projects rp ON rp.id=j.project_id WHERE j.status='queued' AND j.available_at<=NOW() ORDER BY j.available_at,j.created_at LIMIT 1",[],3600);
if(!$job){release_worker_heartbeat($pdo,'research_retrieval','success','No queued Research retrieval jobs.');echo "No queued Research retrieval jobs.\n";exit(0);}
$id=(int)$job['job_id'];$token=(string)$job['claim_token'];$projectId=(int)$job['project_id'];
try{
    research_retrieval_rebuild_project($pdo,$config,$projectId,null,true);
    job_claim_renew($pdo,'research_retrieval_jobs',$id,$token,3600);
    $pdo->beginTransaction();job_claim_assert($pdo,'research_retrieval_jobs',$id,$token);
    $rq=$pdo->prepare("SELECT rerun_requested FROM research_retrieval_jobs WHERE id=? AND claim_token=? FOR UPDATE");$rq->execute([$id,$token]);$rerun=(int)$rq->fetchColumn()===1;
    if($rerun){
        $pdo->prepare("UPDATE research_retrieval_jobs SET status='queued',attempts=0,claim_token=NULL,lease_expires_at=NULL,available_at=NOW(),last_error=NULL,started_at=NULL,completed_at=NULL,rerun_requested=0 WHERE id=? AND claim_token=?")->execute([$id,$token]);
    }else job_claim_complete($pdo,'research_retrieval_jobs',$id,$token);
    $pdo->commit();
    release_worker_heartbeat($pdo,'research_retrieval','success',$rerun?'Research retrieval index rebuilt; newer changes requeued.':'Research retrieval index rebuilt.',1);echo ($rerun?"Research retrieval requeued.":"Research retrieval index rebuilt.")."\n";
}catch(LostJobClaim $e){
    if($pdo->inTransaction())$pdo->rollBack();release_worker_heartbeat($pdo,'research_retrieval','failure','Lease lost; stale index result discarded.');fwrite(STDERR,"Research retrieval lease lost.\n");exit(2);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();$msg=mb_substr($e->getMessage(),0,1000);
    try{$state=job_claim_retry_or_fail($pdo,'research_retrieval_jobs',$id,$token,$msg,(int)$job['attempts'],3,90);if($state==='failed')$pdo->prepare("UPDATE research_retrieval_projects SET status='failed',last_error=? WHERE project_id=?")->execute([$msg,$projectId]);}catch(LostJobClaim $lost){}
    release_worker_heartbeat($pdo,'research_retrieval','failure',$msg);fwrite(STDERR,$msg."\n");exit(1);
}
