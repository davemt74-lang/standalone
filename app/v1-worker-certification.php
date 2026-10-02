<?php
declare(strict_types=1);

/**
 * V1 worker certification uses the original registry, heartbeats and queues.
 * It never starts workers, drains jobs, changes credentials or grants Agent powers.
 */
function v1_worker_roles(): array {
    return [
        'media'=>'core','transcription'=>'core','research_files'=>'core',
        'research_transcription'=>'core','research_retrieval'=>'core',
        'research_autonomy'=>'core','research_monitor'=>'core','research_tasks'=>'core',
        'research_programs'=>'core','source_monitor'=>'core','ai'=>'core',
        'saved_search'=>'core','research_automation'=>'core',
        'evaluation'=>'on_demand','training'=>'on_demand','post_training'=>'on_demand',
    ];
}
function v1_worker_certification_analyze(array $specs,array $workers,array $queues,string $root): array {
    $roles=v1_worker_roles();$rows=[];$blocking=[];$warnings=[];
    foreach($specs as $name=>$spec){
        $role=$roles[$name]??'core';
        $health=$workers[$name]??[];
        $queue=$queues[$name]??['error'=>'unavailable'];
        $queued=(int)($queue['queued']??0);
        $active=(int)($queue['processing']??0);
        $failed=(int)($queue['failed']??0);
        $isOnDemand=$role==='on_demand';
        // Post-training drafts require human review, not a runnable worker.
        // Its actual processor operates only on evaluating/awaiting_review.
        $actionableQueued=$name==='post_training'?0:$queued;
        $needed=!$isOnDemand||$actionableQueued>0||$active>0
            ||($name==='post_training'&&(int)($queue['review']??0)>0);
        $command=(string)($spec['command']??'');
        $matches=[];
        preg_match('/^php\s+([a-z0-9_\/-]+\.php)(?:\s|$)/i',$command,$matches);
        $script=(string)($matches[1]??'');
        $filePresent=$script!==''&&is_file(rtrim($root,'/').'/'.$script);
        $status=(string)($health['status']??'never');
        $seenAge=$health['age_seconds']??null;
        $staleAfter=(int)($spec['stale_after']??3600);
        $recent=$seenAge!==null&&(int)$seenAge<=$staleAfter;
        $successful=(string)($health['last_success_at']??'')!=='';
        // "starting" or "idle" never proves a real successful invocation;
        // last_success_at is retained by canonical heartbeat code.
        $recentSuccess=false;
        $lastSuccess=(string)($health['last_success_at']??'');
        if($lastSuccess!==''){
            $timestamp=strtotime($lastSuccess.' UTC');
            $recentSuccess=$timestamp!==false&&$timestamp>=time()-$staleAfter&&$timestamp<=time()+60;
        }
        $queueAvailable=!isset($queue['error']);
        $issues=[];
        if(!$filePresent)$issues[]='worker_script_missing';
        if(!$queueAvailable)$issues[]='queue_unavailable';
        if($failed>0)$issues[]='failed_jobs_present';
        if($needed){
            if(!$recent||in_array($status,['never','stale','failure'],true))$issues[]='worker_not_healthy';
            if(!$recentSuccess)$issues[]='no_recent_success';
        } elseif($status==='failure'||($status==='stale'&&($active>0||$queued>0))){
            $issues[]='on_demand_worker_failure';
        }
        $issues=array_values(array_unique($issues));
        $state=$issues?'blocked':($needed?'verified':'inactive_on_demand');
        if($issues)$blocking[$name]=$issues;
        if(!$needed&&in_array($status,['never','stale'],true))$warnings[$name]='No work pending; on-demand heartbeat is not required.';
        $rows[$name]=[
            'name'=>$name,'role'=>$role,'required_now'=>$needed,'state'=>$state,
            'heartbeat'=>$status,'last_seen_at'=>$health['last_seen_at']??null,
            'last_success_at'=>$health['last_success_at']??null,
            'stale_after_seconds'=>$staleAfter,'command'=>$command,
            'queue'=>['queued'=>$queued,'processing'=>$active,'failed'=>$failed,'blocked'=>(int)($queue['blocked']??0)],
            'issues'=>$issues,
        ];
    }
    $instructions=[
      'Install the existing workers using their documented commands; never start a second scheduler for Research Automation.',
      'Verify each core worker records a fresh successful heartbeat after actual invocation and its queue remains accessible.',
      'Run one authorized staged Research task and verify the corresponding Agent result and notification with the intended account.',
      'Run a staged Sponsored Research deadline/revision scenario through the existing Research Automation cron; verify one authorized notification and no notice for a revoked user.',
      'Confirm your actual server cron/Supervisor configuration, DNS/HTTPS and notification transport; a database heartbeat alone does not prove scheduling or delivery.',
      'Do not automatically retry or delete failed/blocked jobs; diagnose with the original job/Agent permissions and administrator confirmation.',
    ];
    return [
      'schema'=>'annotated.v1-worker-certification',
      'code_and_heartbeat_ready'=>!$blocking,
      'production_certified'=>false,
      'production_evidence'=>'not_verified_by_repository',
      'worker_count'=>count($rows),
      'blocking'=>$blocking,'warnings'=>$warnings,'workers'=>$rows,'operator_checks'=>$instructions,
    ];
}
function v1_worker_certification_snapshot(PDO $pdo,string $root): array {
    return v1_worker_certification_analyze(release_worker_specs(),release_worker_health($pdo),release_queue_health($pdo),$root);
}
function v1_worker_certification_agent_context(array $report): string {
    $lines=['[V1 WORKER CERTIFICATION — READ ONLY]',
        'Heartbeat and queue evidence: '.($report['code_and_heartbeat_ready']?'ready':'blocked').'. Production scheduler and actual notification delivery are NOT independently verified.'];
    foreach($report['blocking'] as $name=>$issues)$lines[]=$name.': '.implode(', ',$issues);
    $lines[]='Do not restart workers, manipulate queues or execute paid project actions autonomously. Offer the administrator the existing health page, exact canonical worker commands and a confirmation-required repair plan.';
    return implode("\n",$lines);
}
