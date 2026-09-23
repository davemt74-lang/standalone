<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

release_worker_heartbeat($pdo,'research_tasks','starting','Worker invocation started.');
$limit=max(1,min(50,(int)($argv[1]??10)));$processed=0;$failed=0;

for($n=0;$n<$limit;$n++){
    $job=job_claim($pdo,'research_task_jobs',"SELECT j.id job_id,j.*,rt.public_id task_public_id,rt.title task_title,rt.status task_status,rt.priority
      FROM research_task_jobs j JOIN research_tasks rt ON rt.id=j.task_id
      WHERE j.status='queued' AND j.available_at<=NOW() ORDER BY FIELD(rt.priority,'urgent','high','medium','low'),j.available_at,j.created_at LIMIT 1",[],1800);
    if(!$job)break;$id=(int)$job['job_id'];$token=(string)$job['claim_token'];
    try{
        $q=$pdo->prepare("SELECT rt.*,rtp.status plan_status FROM research_tasks rt LEFT JOIN research_task_plans rtp ON rtp.id=rt.plan_id WHERE rt.id=? LIMIT 1");$q->execute([(int)$job['task_id']]);$task=$q->fetch();
        if(!$task||in_array((string)$task['status'],['complete','done','archived'],true)||($task['plan_id']&&$task['plan_status']!=='active')){
            job_claim_complete($pdo,'research_task_jobs',$id,$token);$processed++;continue;
        }
        if(!research_task_dependencies_complete($pdo,(int)$task['id'])){
            $pdo->prepare("UPDATE research_tasks SET status='queued',blocking_reason='Waiting for dependent tasks.',updated_at=NOW() WHERE id=?")->execute([(int)$task['id']]);
            job_claim_complete($pdo,'research_task_jobs',$id,$token);$processed++;continue;
        }
        $agent=research_task_agent_by_id($pdo,(int)$task['research_agent_id']);if(!$agent)throw new RuntimeException('Research Agent is unavailable.');
        $viewer=research_task_owner($pdo,$agent);$model=ai_setting_model_id($pdo,'research',true);
        if(!$model){
            $pdo->prepare("UPDATE research_tasks SET status='waiting',blocking_reason='No Research AI model is configured.',updated_at=NOW() WHERE id=?")->execute([(int)$task['id']]);
            research_task_event($pdo,(int)$task['project_id'],$task['plan_id']?(int)$task['plan_id']:null,(int)$task['id'],'waiting','system',null,['reason'=>'no_research_model']);
            job_claim_complete($pdo,'research_task_jobs',$id,$token);$processed++;continue;
        }
        $ctx=research_task_execution_context($pdo,(string)$task['public_id']);if(!$ctx)throw new RuntimeException('Research task context is unavailable.');$runPublic=ulid_like();
        $pdo->prepare("INSERT INTO research_task_runs(public_id,task_id,plan_id,research_agent_id,project_id,trigger_type,status,input_hash) VALUES(?,?,?,?,?,?,'processing',?)")
          ->execute([$runPublic,(int)$task['id'],$task['plan_id']?:null,(int)$task['research_agent_id'],(int)$task['project_id'],(string)$job['trigger_type'],(string)$ctx['input_hash']]);
        research_task_mark_processing($pdo,(string)$task['public_id']);research_task_event($pdo,(int)$task['project_id'],$task['plan_id']?(int)$task['plan_id']:null,(int)$task['id'],'started','agent',null,['run_id'=>$runPublic]);
        ai_queue_job($pdo,(int)$viewer['id'],'research_task_execution',$model,'research_task_execution',(string)$task['public_id'],['input_hash'=>$ctx['input_hash'],'task_run_public_id'=>$runPublic],match((string)$task['priority']){'urgent'=>1,'high'=>2,'medium'=>3,default=>4});
        $pdo->prepare("UPDATE research_task_runs SET status='queued_ai' WHERE public_id=?")->execute([$runPublic]);job_claim_renew($pdo,'research_task_jobs',$id,$token,1800);job_claim_complete($pdo,'research_task_jobs',$id,$token);$processed++;echo "research task {$task['public_id']} queued for AI execution\n";
    }catch(LostJobClaim $e){$failed++;fwrite(STDERR,"research task job {$id}: lease lost\n");}
    catch(Throwable $e){
        $result=null;try{$result=job_claim_retry_or_fail($pdo,'research_task_jobs',$id,$token,mb_substr($e->getMessage(),0,1000),(int)$job['attempts'],3,180);}catch(LostJobClaim $ignored){}
        if($result==='failed')research_task_mark_failed($pdo,(string)$job['task_public_id'],$e->getMessage());$failed++;fwrite(STDERR,"research task {$job['task_public_id']}: {$e->getMessage()}\n");
    }
}
release_worker_heartbeat($pdo,'research_tasks',$failed?'failure':'success',$failed?("$failed Research task job(s) failed; $processed completed."):("$processed Research task job(s) completed."),$processed);
