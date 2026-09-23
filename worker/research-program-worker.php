<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';

release_worker_heartbeat($pdo,'research_programs','starting','Worker invocation started.');
$limit=max(1,min(50,(int)($argv[1]??10)));$scheduled=0;$reconciled=0;$processed=0;$skipped=0;$failed=0;$retried=0;

try{
    $reconciled=research_program_reconcile_runs($pdo,200);
    $scheduled=research_program_enqueue_due($pdo,200);

    for($i=0;$i<$limit;$i++){
        $run=research_program_claim($pdo);if(!$run)break;$token=(string)$run['claim_token'];$program=research_program_by_id($pdo,(int)$run['program_id']);
        if($program)$program=research_program_effective_for_run($program,$run);
        if(!$program){
            try{job_claim_complete($pdo,'research_program_runs',(int)$run['id'],$token,'skipped');}catch(Throwable $ignored){}
            $skipped++;continue;
        }
        try{
            $viewer=research_program_owner($pdo,$program);
            $accessible=research_program_access($pdo,$viewer,(string)$program['public_id']);
            if(!$accessible&&$run['trigger_type']!=='manual'){
                $pdo->prepare("UPDATE research_programs SET status='paused',next_run_at=NULL,updated_at=NOW() WHERE id=? AND status='active'")->execute([(int)$program['id']]);
                $pdo->prepare("UPDATE research_program_runs SET summary='Program paused because current project access is unavailable.' WHERE id=?")->execute([(int)$run['id']]);
                job_claim_complete($pdo,'research_program_runs',(int)$run['id'],$token,'skipped');$skipped++;continue;
            }
            $result=research_program_prepare_run($pdo,$program,$viewer,$run);
            job_claim_renew($pdo,'research_program_runs',(int)$run['id'],$token,1800);
            if(($result['status']??'')==='skipped'){
                if(!empty($result['quiet']))research_program_complete_quiet_run($pdo,$program,$run,$token,$result);
                else{$pdo->prepare("UPDATE research_program_runs SET summary=? WHERE id=?")->execute([mb_substr((string)($result['summary']??'Program run skipped.'),0,12000),(int)$run['id']]);job_claim_complete($pdo,'research_program_runs',(int)$run['id'],$token,'skipped');$pdo->prepare("UPDATE research_programs SET run_count=run_count+1,last_run_at=NOW(),updated_at=NOW() WHERE id=?")->execute([(int)$program['id']]);}
                $skipped++;
            }else{
                research_program_activate_run($pdo,$program,$run,$token,$result);$processed++;
            }
        }catch(LostJobClaim $e){$failed++;fwrite(STDERR,'Program run '.$run['public_id'].": lease lost; stale orchestration discarded\n");}
        catch(Throwable $e){
            try{$state=research_program_run_fail($pdo,$program,$run,$token,$e);if($state==='failed')$failed++;else $retried++;}catch(Throwable $inner){$failed++;fwrite(STDERR,'Program run '.$run['public_id'].' failure handling: '.$inner->getMessage()."\n");}
            fwrite(STDERR,'Program run '.$run['public_id'].': '.$e->getMessage()."\n");
        }
    }
    $reconciled+=research_program_reconcile_runs($pdo,200);
    $status=$failed?'failure':'success';$message="scheduled=$scheduled active_plans=$processed reconciled=$reconciled quiet_or_skipped=$skipped retried=$retried failed=$failed";
    release_worker_heartbeat($pdo,'research_programs',$status,$message,$processed+$skipped+$reconciled);echo "Research Programs $message\n";
}catch(Throwable $e){
    $message=mb_substr($e->getMessage(),0,1000);release_worker_heartbeat($pdo,'research_programs','failure',$message);fwrite(STDERR,$message."\n");exit(1);
}
