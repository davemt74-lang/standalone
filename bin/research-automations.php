<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$limit=25;foreach($argv??[] as $arg)if(str_starts_with((string)$arg,'--limit='))$limit=max(1,min(200,(int)substr((string)$arg,8)));
if(!research_automation_ready($pdo)){fwrite(STDERR,"Phase 18 migration is required.\n");exit(2);}
$scheduled=research_automation_enqueue_due($pdo,100);$watch=research_automation_enqueue_watch_alerts($pdo,200);
$completed=0;$skipped=0;$failed=0;$retried=0;
for($i=0;$i<$limit;$i++){
    $run=research_automation_claim($pdo);if(!$run)break;
    $viewer=null;$automation=null;
    try{
        $uq=$pdo->prepare("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1");$uq->execute([$run['user_id']]);$viewer=$uq->fetch();
        $aq=$pdo->prepare('SELECT public_id FROM research_automations WHERE id=? LIMIT 1');$aq->execute([$run['automation_id']]);$automationPublic=(string)($aq->fetchColumn()?:'');
        if(!$viewer||$automationPublic==='')throw new RuntimeException('Automation owner or definition is unavailable.');
        $automation=research_automation_access($pdo,$viewer,$automationPublic);if(!$automation)throw new RuntimeException('Automation is no longer accessible.');
        $result=research_automation_execute($pdo,$config,$run);research_automation_complete_run($pdo,$viewer,$automation,$run,$result);
        if($result['status']==='skipped')$skipped++;else $completed++;
    }catch(Throwable $e){
        try{
            if(!$viewer){$uq=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$uq->execute([$run['user_id']]);$viewer=$uq->fetch()?:null;}
            if(!$automation&&$viewer){$aq=$pdo->prepare('SELECT public_id FROM research_automations WHERE id=? LIMIT 1');$aq->execute([$run['automation_id']]);$automationPublic=(string)($aq->fetchColumn()?:'');if($automationPublic!=='')$automation=research_automation_access($pdo,$viewer,$automationPublic);}
            if($viewer&&$automation){$state=research_automation_fail_run($pdo,$viewer,$automation,$run,$e);if($state==='failed')$failed++;else $retried++;}
            else{$state=job_claim_retry_or_fail($pdo,'research_automation_runs',(int)$run['id'],(string)$run['claim_token'],$e->getMessage(),(int)$run['attempts'],3,120);if($state==='failed')$failed++;else $retried++;}
        }catch(Throwable $inner){fwrite(STDERR,'Run '.$run['public_id'].' failure handling: '.$inner->getMessage()."\n");$failed++;}
        fwrite(STDERR,'Run '.$run['public_id'].': '.$e->getMessage()."\n");
    }
}
echo "Research automation scheduled=$scheduled watch=$watch completed=$completed skipped=$skipped retried=$retried failed=$failed\n";
