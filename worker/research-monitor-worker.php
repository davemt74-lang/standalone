<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

release_worker_heartbeat($pdo,'research_monitor','starting','Worker invocation started.');
research_monitor_queue_due($pdo,150);
$limit=max(1,min(50,(int)($argv[1]??10)));$processed=0;$failed=0;

for($n=0;$n<$limit;$n++){
    $job=job_claim($pdo,'research_monitor_jobs',"SELECT j.id job_id,j.*,rmw.public_id watch_public_id,rmw.watch_type,rmw.target,rmw.canonical_url,rmw.query_text,rmw.cadence,rmw.alert_level,rmw.auto_promote,rmw.last_checked_at,rmw.last_result_hash,rmw.created_by_user_id,rmw.created_at watch_created_at
      FROM research_monitor_jobs j JOIN research_monitor_watches rmw ON rmw.id=j.watch_id
      WHERE j.status='queued' AND j.available_at<=NOW() AND rmw.status='active'
      ORDER BY j.available_at,j.created_at LIMIT 1",[],1800);
    if(!$job)break;$id=(int)$job['job_id'];$token=(string)$job['claim_token'];
    $watch=[
      'id'=>(int)$job['watch_id'],'public_id'=>(string)$job['watch_public_id'],'research_agent_id'=>(int)$job['research_agent_id'],'project_id'=>(int)$job['project_id'],
      'created_by_user_id'=>(int)$job['created_by_user_id'],'watch_type'=>(string)$job['watch_type'],'target'=>(string)$job['target'],'canonical_url'=>$job['canonical_url'],
      'query_text'=>$job['query_text'],'cadence'=>(string)$job['cadence'],'alert_level'=>(string)$job['alert_level'],'auto_promote'=>(int)$job['auto_promote'],
      'last_checked_at'=>$job['last_checked_at'],'last_result_hash'=>$job['last_result_hash'],'created_at'=>(string)$job['watch_created_at']
    ];
    try{
        $result=research_monitor_run($pdo,$config,$watch,(string)$job['trigger_type']);job_claim_renew($pdo,'research_monitor_jobs',$id,$token,1800);
        $pdo->beginTransaction();job_claim_assert($pdo,'research_monitor_jobs',$id,$token);
        $q=$pdo->prepare('SELECT rerun_requested FROM research_monitor_jobs WHERE id=? AND claim_token=? FOR UPDATE');$q->execute([$id,$token]);$rerun=(int)$q->fetchColumn()===1;
        if($rerun){
            $pdo->prepare("UPDATE research_monitor_jobs SET status='queued',attempts=0,claim_token=NULL,lease_expires_at=NULL,available_at=NOW(),last_error=NULL,started_at=NULL,completed_at=NULL,rerun_requested=0 WHERE id=? AND claim_token=?")->execute([$id,$token]);
        }else job_claim_complete($pdo,'research_monitor_jobs',$id,$token);
        $pdo->commit();$processed++;echo 'monitor watch '.$watch['public_id'].' done'.($rerun?' (rerun queued)':'')."\n";
    }catch(LostJobClaim $e){
        if($pdo->inTransaction())$pdo->rollBack();$failed++;fwrite(STDERR,'monitor watch '.$watch['public_id'].": lease lost; stale result discarded\n");
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();try{job_claim_retry_or_fail($pdo,'research_monitor_jobs',$id,$token,mb_substr($e->getMessage(),0,1000),(int)$job['attempts'],3,300);}catch(LostJobClaim $ignored){}
        $failed++;fwrite(STDERR,'monitor watch '.$watch['public_id'].': '.$e->getMessage()."\n");
    }
}
release_worker_heartbeat($pdo,'research_monitor',$failed?'failure':'success',$failed?("$failed Research Monitoring job(s) failed; $processed completed."):("$processed Research Monitoring job(s) completed."),$processed);
