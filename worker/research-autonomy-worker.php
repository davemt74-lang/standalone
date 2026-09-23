<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

release_worker_heartbeat($pdo,'research_autonomy','starting','Worker invocation started.');
$job=job_claim($pdo,'research_autonomy_jobs',"SELECT j.id job_id,j.*,ra.public_id agent_public_id
  FROM research_autonomy_jobs j JOIN research_agents ra ON ra.id=j.research_agent_id
  WHERE j.status='queued' AND j.available_at<=NOW() ORDER BY j.available_at,j.created_at LIMIT 1",[],3600);
if(!$job){release_worker_heartbeat($pdo,'research_autonomy','success','No queued autonomous Research jobs.');echo "No queued autonomous Research jobs.\n";exit(0);}
$id=(int)$job['job_id'];$token=(string)$job['claim_token'];
try{
    $agent=research_autonomy_agent_by_id($pdo,(int)$job['research_agent_id']);
    if(!$agent)throw new RuntimeException('Research Agent is no longer active.');
    $result=research_autonomy_run($pdo,$config,$agent,$job['requested_by_user_id']!==null?(int)$job['requested_by_user_id']:null,(string)$job['trigger_type'],(string)($job['reason']??''));
    job_claim_renew($pdo,'research_autonomy_jobs',$id,$token,3600);
    $pdo->beginTransaction();job_claim_assert($pdo,'research_autonomy_jobs',$id,$token);
    $rq=$pdo->prepare("SELECT rerun_requested FROM research_autonomy_jobs WHERE id=? AND claim_token=? FOR UPDATE");$rq->execute([$id,$token]);$rerun=(int)$rq->fetchColumn()===1;
    if($rerun){
        $pdo->prepare("UPDATE research_autonomy_jobs SET status='queued',attempts=0,claim_token=NULL,lease_expires_at=NULL,available_at=NOW(),last_error=NULL,started_at=NULL,completed_at=NULL,rerun_requested=0 WHERE id=? AND claim_token=?")->execute([$id,$token]);
    }else job_claim_complete($pdo,'research_autonomy_jobs',$id,$token);
    $pdo->commit();
    $message=$rerun?'Autonomous Research completed; newer workspace changes requeued.':'Autonomous Research workspace updated.';
    release_worker_heartbeat($pdo,'research_autonomy','success',$message,1);echo $message." Run ".($result['public_id']??'')."\n";
}catch(LostJobClaim $e){
    if($pdo->inTransaction())$pdo->rollBack();release_worker_heartbeat($pdo,'research_autonomy','failure','Lease lost; stale autonomous result discarded.');fwrite(STDERR,"Autonomous Research lease lost.\n");exit(2);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();$msg=mb_substr($e->getMessage(),0,1000);
    try{job_claim_retry_or_fail($pdo,'research_autonomy_jobs',$id,$token,$msg,(int)$job['attempts'],3,90);}catch(LostJobClaim $ignored){}
    release_worker_heartbeat($pdo,'research_autonomy','failure',$msg);fwrite(STDERR,$msg."\n");exit(1);
}
