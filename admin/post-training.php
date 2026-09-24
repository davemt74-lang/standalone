<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if(!data_post_training_ready($pdo)){http_response_code(503);exit('Post-Training Evaluation requires the Phase 42 database upgrade.');}

$error='';$success='';
$planId=trim((string)($_GET['plan']??$_POST['plan']??''));
$trainingJobId=trim((string)($_GET['training_job']??$_POST['training_job']??''));

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$op=(string)($_POST['op']??'');
    try{
        if($op==='create_plan'){
            $suiteIds=$_POST['suite_ids']??[];if(!is_array($suiteIds))$suiteIds=[$suiteIds];
            $plan=data_post_training_plan_create($pdo,$u,$trainingJobId,$suiteIds,$_POST);
            header('Location:/admin/post-training.php?plan='.rawurlencode((string)$plan['public_id']));exit;
        }elseif($op==='prepare'){
            data_post_training_prepare($pdo,$u,$planId);$success='Equivalent candidate benchmark suites created and evaluation runs queued.';
        }elseif($op==='refresh'){
            $p=data_post_training_refresh($pdo,$u,$planId);$success='Readiness state refreshed: '.$p['status'].'.';
        }elseif($op==='archive'){
            data_post_training_archive($pdo,$u,$planId);$success='Post-training plan archived.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$stats=data_post_training_summary($pdo);
$plans=data_post_training_plans($pdo,100);
$eligibleJobs=data_post_training_eligible_jobs($pdo,100);
$selectedTrainingJob=$trainingJobId!==''?data_training_job_get($pdo,$trainingJobId):null;
$eligibleSuites=$selectedTrainingJob?data_post_training_eligible_suites($pdo,$trainingJobId):[];

$plan=$planId!==''?data_post_training_plan_get($pdo,$planId):null;
$links=$plan?data_post_training_links($pdo,(int)$plan['id']):[];
$packets=$plan?data_post_training_packets($pdo,(int)$plan['id']):[];
$events=$plan?data_post_training_events($pdo,(int)$plan['id'],100):[];
$planIntegrity=$plan?data_post_training_plan_integrity($pdo,$plan):null;
$planJob=$plan?data_training_job_get($pdo,$plan['training_job_public_id']):null;
$lineage=$plan&&$planJob?data_post_training_lineage($pdo,$planJob,$plan):null;
$latestPacket=$packets[0]??null;
$latestPacketIntegrity=$latestPacket?data_post_training_packet_integrity($latestPacket):null;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Post-Training Readiness · Annotated Admin</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><?=admin_ui_sidebar('post_training')?>
<main class="panel article">
  <div class="pageTitle">
    <span class="eyebrow">POST-TRAINING READINESS</span>
    <h1>Evaluation & promotion-readiness packets</h1>
    <p>Turn successful Phase 41 training outputs into equivalent Phase 39 benchmarks, human-reviewed evidence, regression comparisons, and a tamper-evident packet for human release consideration. This workflow never promotes, approves, activates, or routes a model automatically.</p>
  </div>
  <div class="inlineActions">
    <a class="button secondary" href="/admin/model-campaigns.php">Improvement Campaigns</a>
    <a class="button secondary" href="/admin/training.php">Training Registry</a>
    <a class="button secondary" href="/admin/evaluations.php">Evaluation Harness</a>
    <a class="button secondary" href="/admin/model-registry.php">Model Registry</a>
  </div>
  <?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif?>
  <?php if($success):?><div class="notice success"><?=h($success)?></div><?php endif?>

  <section class="healthGrid">
    <article class="card"><strong><?=h((string)$stats['plans'])?></strong><p class="meta">Plans</p></article>
    <article class="card"><strong><?=h((string)$stats['draft'])?></strong><p class="meta">Draft</p></article>
    <article class="card"><strong><?=h((string)$stats['evaluating'])?></strong><p class="meta">Evaluating</p></article>
    <article class="card"><strong><?=h((string)$stats['awaiting_review'])?></strong><p class="meta">Awaiting review</p></article>
    <article class="card"><strong><?=h((string)$stats['ready_count'])?></strong><p class="meta">Ready packets</p></article>
    <article class="card"><strong><?=h((string)$stats['blocked'])?></strong><p class="meta">Blocked</p></article>
  </section>

  <section class="card">
    <span class="eyebrow">NEW PLAN</span><h2>Choose a successful training output</h2>
    <?php if(!$eligibleJobs):?><p class="empty">Every successful Phase 41 output already has a plan, or no eligible output is available yet.</p><?php else:?><div class="unifiedActivityList">
      <?php foreach($eligibleJobs as $j):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge">succeeded</span><strong><?=h($j['output_version_label'])?></strong></div><a class="button secondary" href="/admin/post-training.php?training_job=<?=rawurlencode((string)$j['public_id'])?>">Select output</a></div><p class="meta"><?=h($j['registry_name'])?> · baseline <?=h($j['baseline_version_label'])?> · output status <?=h($j['output_version_status'])?></p></div></article><?php endforeach?>
    </div><?php endif?>

    <?php if($selectedTrainingJob):?>
      <div class="card">
        <span class="eyebrow">BASELINE BENCHMARKS</span><h3><?=h($selectedTrainingJob['output_version_label'])?></h3>
        <?php if(!$eligibleSuites):?><p class="notice error">No eligible baseline suite is available. The Phase 41 base model needs at least one active Phase 39 model suite with an integrity-valid baseline run that is already linked as governed Phase 40 evidence.</p><?php else:?>
        <form method="post" class="settingsForm">
          <?=csrf_field()?><input type="hidden" name="op" value="create_plan"><input type="hidden" name="training_job" value="<?=h($selectedTrainingJob['public_id'])?>">
          <?php foreach($eligibleSuites as $entry):$s=$entry['suite'];$r=$entry['run'];?>
            <label><input type="checkbox" name="suite_ids[]" value="<?=h($s['public_id'])?>" checked> <strong><?=h($s['name'])?></strong> · <?=h($s['dataset_name'])?> v<?=h((string)$s['dataset_version'])?> · baseline <?=h($r['public_id'])?> · fingerprint <?=h(substr((string)$entry['fingerprint']['hash'],0,12))?>…</label>
          <?php endforeach?>
          <label>Required human reviews per candidate run<input type="number" name="required_human_reviews_per_run" min="1" max="100" value="1"></label>
          <label>Minimum human pass rate<input type="number" name="minimum_human_pass_rate" min="0" max="1" step="0.01" value="0.5"></label>
          <label><input type="checkbox" name="require_no_regression" value="1" checked> Require configured no-regression policy</label>
          <label>Maximum regressed metrics<input type="number" name="max_regressed_metrics" min="0" max="50" value="0"></label>
          <label>Regression tolerance<input type="number" name="regression_tolerance" min="0" max="0.25" step="0.001" value="0.02"></label>
          <label><input type="checkbox" name="require_phase40_gate" value="1" checked> Require existing Phase 40 release gates to pass</label>
          <button class="button" type="submit">Create post-training plan</button>
        </form>
        <?php endif?>
      </div>
    <?php endif?>
  </section>

  <section class="card">
    <span class="eyebrow">PLANS</span><h2>Post-training evaluation history</h2>
    <?php if(!$plans):?><p class="empty">No post-training plans yet.</p><?php else:?><div class="unifiedActivityList">
      <?php foreach($plans as $row):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($row['status'])?></span><strong><a href="/admin/post-training.php?plan=<?=rawurlencode((string)$row['public_id'])?>"><?=h($row['output_version_label'])?></a></strong></div><time><?=h($row['updated_at'])?></time></div><p class="meta"><?=h($row['registry_name'])?> · baseline <?=h($row['baseline_version_label'])?> · <?=h((string)$row['suite_count'])?> benchmark<?=((int)$row['suite_count']===1?'':'s')?> · <?=h((string)$row['packet_count'])?> packet<?=((int)$row['packet_count']===1?'':'s')?></p></div></article><?php endforeach?>
    </div><?php endif?>
  </section>

  <?php if($plan):?>
  <section class="card">
    <div class="caseHeader"><div><span class="eyebrow">PLAN DETAIL</span><h2><?=h($plan['output_version_label'])?></h2></div><span class="badge"><?=h($plan['status'])?></span></div>
    <div class="cognitiveCardMeta">
      <span><small>Training job</small><?=h($plan['training_job_public_id'])?></span>
      <span><small>Output model</small><?=h($plan['output_version_public_id'])?> · <?=h($plan['output_version_status'])?></span>
      <span><small>Baseline</small><?=h($plan['baseline_version_label'])?> · <?=h($plan['baseline_version_status'])?></span>
      <span><small>Plan integrity</small><?=!empty($planIntegrity['ok'])?'VALID':'FAILED'?></span>
      <span><small>Benchmarks</small><?=h((string)count($links))?></span>
      <span><small>Packets</small><?=h((string)count($packets))?></span>
    </div>
    <?php if($plan['plan_hash']):?><p class="meta">Plan <?=h(substr((string)$plan['plan_hash'],0,16))?>… · policy <?=h(substr((string)$plan['policy_hash'],0,16))?>… · training completion <?=h(substr((string)$plan['training_completion_hash'],0,16))?>…</p><?php endif?>

    <div class="card">
      <span class="eyebrow">CURRENT LINEAGE</span><h3><?=$lineage&&$lineage['pass']?'Governance current':'Governance blocked'?></h3>
      <?php foreach(($lineage['checks']??[]) as $name=>$ok):?><p class="meta"><?=h(str_replace('_',' ',$name))?>: <strong><?=$ok?'PASS':'BLOCKED'?></strong></p><?php endforeach?>
    </div>

    <?php if($plan['status']==='draft'):?>
      <div class="notice">Preparing this plan clones each selected baseline benchmark definition into a new candidate suite bound to the trained runtime model and queues Phase 39 evaluation runs. The cloned suites must remain fingerprint-equivalent.</div>
      <form method="post" onsubmit="return confirm('Freeze this plan and queue equivalent candidate benchmark runs?');"><?=csrf_field()?><input type="hidden" name="op" value="prepare"><input type="hidden" name="plan" value="<?=h($plan['public_id'])?>"><button class="button" type="submit">Prepare & queue evaluations</button></form>
    <?php elseif(!in_array($plan['status'],['archived'],true)):?>
      <div class="inlineActions">
        <form method="post"><?=csrf_field()?><input type="hidden" name="op" value="refresh"><input type="hidden" name="plan" value="<?=h($plan['public_id'])?>"><button class="button" type="submit">Refresh readiness</button></form>
        <form method="post" onsubmit="return confirm('Archive this post-training plan? Historical evidence and packets remain auditable.');"><?=csrf_field()?><input type="hidden" name="op" value="archive"><input type="hidden" name="plan" value="<?=h($plan['public_id'])?>"><button class="button secondary" type="submit">Archive</button></form>
      </div>
    <?php endif?>
    <?php if($plan['last_error']):?><div class="notice error"><?=h($plan['last_error'])?></div><?php endif?>

    <section class="card">
      <span class="eyebrow">BENCHMARKS</span><h3>Equivalent baseline → candidate evidence</h3>
      <div class="unifiedActivityList">
      <?php foreach($links as $link):$cmp=$link['comparison'];?>
        <article class="unifiedActivityItem"><div class="unifiedActivityMain">
          <div class="unifiedActivityHead"><div><span class="badge"><?=h($link['status'])?></span><strong><?=h($link['template_suite_name'])?></strong></div><span class="meta"><?=h(substr((string)$link['benchmark_fingerprint'],0,12))?>…</span></div>
          <p class="meta">Baseline run <a href="/admin/evaluations.php?suite=<?=rawurlencode((string)$link['template_suite_public_id'])?>&run=<?=rawurlencode((string)$link['baseline_run_public_id'])?>"><?=h($link['baseline_run_public_id'])?></a>
          <?php if($link['candidate_run_public_id']):?> → Candidate <a href="/admin/evaluations.php?suite=<?=rawurlencode((string)$link['candidate_suite_public_id'])?>&run=<?=rawurlencode((string)$link['candidate_run_public_id'])?>"><?=h($link['candidate_run_public_id'])?></a> · <?=h((string)$link['candidate_run_status'])?><?php endif?></p>
          <?php if($cmp):?><div class="cognitiveCardMeta">
            <span><small>Regressed metrics</small><?=h((string)$cmp['regressed_metrics'])?></span>
            <span><small>Human reviews</small><?=h((string)$cmp['candidate_human']['reviews'])?></span>
            <span><small>Human pass</small><?=h((string)$cmp['candidate_human']['pass'])?></span>
            <span><small>Human fail</small><?=h((string)$cmp['candidate_human']['fail'])?></span>
            <span><small>Latency delta</small><?=h(number_format((float)$cmp['latency_ms']['delta'],1))?> ms</span>
          </div><?php foreach($cmp['deltas'] as $metric=>$d):?><p class="meta"><?=h(str_replace('_',' ',$metric))?>: baseline <?=h(number_format((float)$d['baseline'],3))?> · candidate <?=h(number_format((float)$d['candidate'],3))?> · delta <?=h(number_format((float)$d['delta'],3))?><?=$d['regressed']?' · REGRESSION':''?></p><?php endforeach?><?php endif?>
        </div></article>
      <?php endforeach?>
      </div>
    </section>

    <?php if($latestPacket):?>
    <section class="card">
      <span class="eyebrow">READINESS PACKET</span><h3>Ready for human consideration</h3>
      <div class="notice <?=$latestPacketIntegrity&&$latestPacketIntegrity['ok']?'success':'error'?>"><strong>PACKET INTEGRITY <?=$latestPacketIntegrity&&$latestPacketIntegrity['ok']?'VALID':'FAILED'?></strong><br>Packet <?=h($latestPacket['public_id'])?> · hash <?=h(substr((string)$latestPacket['packet_hash'],0,18))?>… · sequence <?=h((string)$latestPacket['sequence_number'])?>.</div>
      <p>This packet means the configured evidence, review, regression, and Phase 40 gates were satisfied at packet creation. It is <strong>not</strong> an approval or activation decision.</p>
      <div class="inlineActions">
        <form method="post" action="/admin/post-training-export.php"><?=csrf_field()?><input type="hidden" name="plan_id" value="<?=h($plan['public_id'])?>"><input type="hidden" name="packet_id" value="<?=h($latestPacket['public_id'])?>"><button class="button secondary" type="submit">Export readiness JSON</button></form>
        <a class="button" href="/admin/model-release.php?packet=<?=rawurlencode((string)$latestPacket['public_id'])?>">Open Model Release Decision</a><a class="button secondary" href="/admin/model-registry.php?registry=<?=rawurlencode((string)$plan['registry_public_id'])?>&version=<?=rawurlencode((string)$plan['output_version_public_id'])?>">Open Model Registry for human decision</a>
      </div>
    </section>
    <?php endif?>
  </section>

  <section class="card">
    <span class="eyebrow">AUDIT EVENTS</span><h2>Readiness governance history</h2>
    <?php if(!$events):?><p class="empty">No readiness events yet.</p><?php else:?><div class="unifiedActivityList"><?php foreach($events as $e):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($e['event_type'])?></span><strong><?=h((string)($e['actor_name']?:'Worker / system'))?></strong></div><time><?=h($e['created_at'])?></time></div></div></article><?php endforeach?></div><?php endif?>
  </section>
  <?php endif?>

  <section class="card">
    <span class="eyebrow">WORKERS</span><h2>Two-stage evaluation execution</h2>
    <p><code>php bin/evaluation-worker.php 5</code> executes the Phase 39 model benchmarks. <code>php bin/post-training-worker.php 10</code> reconciles completed runs, human reviews, regression policy, linked Phase 40 evidence, and readiness packets.</p>
    <p class="meta">Neither worker promotes or activates model versions.</p>
  </section>
</main></body></html>