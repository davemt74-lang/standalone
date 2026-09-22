<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if(!data_model_deployment_ready($pdo)){http_response_code(503);exit('Model Deployment requires the Phase 44 database upgrade.');}
$error='';$success='';
$deploymentId=trim((string)($_GET['deployment']??$_POST['deployment']??''));
$releaseId=trim((string)($_GET['release']??$_POST['release']??''));
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$op=(string)($_POST['op']??'');
    try{
        if($op==='create'){$d=data_model_deployment_create($pdo,$u,$releaseId,$_POST);header('Location:/admin/model-deployment.php?deployment='.rawurlencode((string)$d['public_id']));exit;}
        if($op==='preflight'){data_model_deployment_preflight($pdo,$u,$deploymentId);$success='Preflight passed. No production routing changed.';}
        if($op==='shadow'){data_model_deployment_start_shadow($pdo,$u,$deploymentId);$success='Shadow stage started. User-visible routing remains on the baseline; matching AI requests are mirrored asynchronously to the candidate.';}
        if($op==='checkpoint'){data_model_deployment_checkpoint_submit($pdo,$u,$deploymentId,(string)($_POST['recommendation']??''),(string)($_POST['note']??''));$success='Independent rollout checkpoint signed.';}
        if($op==='advance'){$d=data_model_deployment_advance($pdo,$u,$deploymentId);$success='Rollout advanced to '.$d['status'].'.';}
        if($op==='pause'){data_model_deployment_pause($pdo,$u,$deploymentId);$success='Staged rollout paused; temporary route overrides are disabled.';}
        if($op==='resume'){data_model_deployment_resume($pdo,$u,$deploymentId);$success='Staged rollout resumed. A fresh checkpoint is required before the next advance.';}
        if($op==='stop'){data_model_deployment_stop($pdo,$u,$deploymentId,(string)($_POST['note']??''));$success='Deployment stopped without changing full production routing.';}
        if($op==='rollback'){data_model_deployment_rollback($pdo,$u,$deploymentId,(string)($_POST['note']??''));$success='Deployment rolled back to the governed baseline routing/model.';}
    }catch(Throwable $e){$error=$e->getMessage();}
}
$stats=data_model_deployment_summary($pdo);$eligible=data_model_deployment_eligible_decisions($pdo,100);$deployments=data_model_deployments($pdo,100);$deployment=$deploymentId!==''?data_model_deployment_get($pdo,$deploymentId):null;$release=$releaseId!==''?data_model_release_decision_get($pdo,$releaseId):null;
$context=$deployment&&in_array($deployment['status'],['draft','preflight_passed','shadow','canary','limited'],true)?data_model_deployment_runtime_context($pdo,$deployment):null;$checkpoints=$deployment?data_model_deployment_checkpoints($pdo,(int)$deployment['id']):[];$events=$deployment?data_model_deployment_events($pdo,(int)$deployment['id'],150):[];$checkpointSummary=$deployment&&in_array($deployment['status'],['shadow','canary','limited'],true)?data_model_deployment_checkpoint_summary($pdo,$deployment,(string)$deployment['status']):null;
$routeLabels=data_model_deployment_route_labels();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Model Deployments · Annotated Admin</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="panel article">
  <div class="pageTitle"><span class="eyebrow">PHASE 44 · GOVERNED MODEL DEPLOYMENT</span><h1>Staged rollout & rollback</h1><p>A signed Phase 43 Proceed decision authorizes consideration, not automatic production use. Phase 44 revalidates that decision, requires an approved runtime-bound candidate and active rollback model, then moves through explicit human-gated Shadow → Canary → Limited → Full stages. Shadow mirrors production-shaped AI requests asynchronously while the baseline response remains authoritative.</p></div>
  <div class="inlineActions"><a class="button secondary" href="/admin/model-release.php">Release Decisions</a><a class="button secondary" href="/admin/model-registry.php">Model Registry</a><a class="button secondary" href="/admin/model-observability.php">Model Health</a><a class="button secondary" href="/admin/ai.php">Baseline AI Routing</a></div>
  <?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif?><?php if($success):?><div class="notice success"><?=h($success)?></div><?php endif?>
  <section class="healthGrid">
    <article class="card"><strong><?=h((string)$stats['deployments'])?></strong><p class="meta">Deployments</p></article>
    <article class="card"><strong><?=h((string)$stats['in_progress'])?></strong><p class="meta">In progress</p></article>
    <article class="card"><strong><?=h((string)$stats['full'])?></strong><p class="meta">Full</p></article>
    <article class="card"><strong><?=h((string)$stats['rolled_back'])?></strong><p class="meta">Rolled back</p></article>
    <article class="card"><strong><?=h((string)$stats['overrides'])?></strong><p class="meta">Temporary route overrides</p></article>
  </section>

  <?php if($release&&!$deployment):?>
  <section class="card"><span class="eyebrow">NEW DEPLOYMENT</span><h2><?=h($release['title'])?></h2><p class="meta">Candidate <?=h($release['model_version_label'])?> · rollback <?=h($release['rollback_plan']['target_model_version_public_id']??'')?></p>
    <?php $ri=data_model_release_decision_integrity($pdo,$release);?>
    <p><span class="badge"><?=$ri['ok']?'Signed Proceed verified':'Integrity blocked'?></span></p>
    <form method="post" class="settingsForm"><?=csrf_field()?><input type="hidden" name="op" value="create"><input type="hidden" name="release" value="<?=h($release['public_id'])?>">
      <fieldset><legend>Governed task routes</legend><?php foreach($routeLabels as $key=>$label):?><label><input type="checkbox" name="route_keys[]" value="<?=h($key)?>"> <?=h($label)?></label><?php endforeach?></fieldset>
      <label>Canary traffic %<input type="number" name="planned_traffic_percent" min="1" max="50" value="<?=h((string)max(1,min(50,(int)($release['deployment_plan']['initial_traffic_percent']??10))))?>"></label>
      <label>Monitoring owner<input name="monitoring_owner" required value="<?=h((string)($release['deployment_plan']['monitoring_owner']??''))?>"></label>
      <label>Success criteria<textarea name="success_criteria" rows="4" required><?=h((string)($release['deployment_plan']['success_criteria']??''))?></textarea></label>
      <label>Monitoring window minutes<input type="number" name="monitoring_window_minutes" min="15" max="10080" value="<?=h((string)($release['deployment_plan']['monitoring_window_minutes']??120))?>"></label>
      <button class="button" type="submit" <?=$ri['ok']?'':'disabled'?>>Create deployment draft</button>
    </form><p class="meta">Creating a deployment draft does not change model lifecycle or routing.</p>
  </section>
  <?php endif?>

  <?php if(!$deployment):?>
  <section class="card"><span class="eyebrow">ELIGIBLE PROCEED DECISIONS</span><h2>Ready for deployment planning</h2><?php if(!$eligible):?><p class="empty">No unused signed Proceed decisions.</p><?php else:?><div class="unifiedActivityList"><?php foreach($eligible as $e):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><strong><?=h($e['title'])?></strong><div class="meta"><?=h($e['registry_name'])?> · <?=h($e['model_version_label'])?> · <?=h($e['model_status'])?></div></div><a class="button small" href="/admin/model-deployment.php?release=<?=rawurlencode((string)$e['public_id'])?>">Plan deployment</a></div></div></article><?php endforeach?></div><?php endif?></section>
  <section class="card"><span class="eyebrow">DEPLOYMENT HISTORY</span><h2>Governed rollout records</h2><?php if(!$deployments):?><p class="empty">No deployments yet.</p><?php else:?><div class="unifiedActivityList"><?php foreach($deployments as $d):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($d['status'])?></span> <strong><a href="/admin/model-deployment.php?deployment=<?=rawurlencode((string)$d['public_id'])?>"><?=h($d['model_version_label'])?></a></strong><div class="meta"><?=h($d['registry_name'])?> · rollback <?=h($d['rollback_version_label'])?></div></div><span class="meta"><?=h((string)$d['current_traffic_percent'])?>% candidate</span></div></div></article><?php endforeach?></div><?php endif?></section>
  <?php else:?>
  <section class="card"><span class="eyebrow">DEPLOYMENT CONTROL</span><h2><?=h($deployment['model_version_label'])?> → <?=h($deployment['status'])?></h2><div class="meta"><?=h($deployment['registry_name'])?> · deployment <?=h($deployment['public_id'])?></div>
    <p><span class="badge"><?=h($deployment['status'])?></span> <strong><?=h((string)$deployment['current_traffic_percent'])?>%</strong> candidate served traffic</p>
    <p class="meta">Routes: <?=h(implode(', ',array_map(fn($k)=>$routeLabels[$k]??$k,(array)$deployment['route_keys'])))?> · planned canary <?=h((string)$deployment['planned_traffic_percent'])?>%</p>
    <div class="notice">Human checkpoint boundary: Phase 44 never advances Shadow → Canary → Limited → Full automatically. The deployment creator cannot satisfy the independent checkpoint.</div>
    <?php if($context):?><details open><summary>Preflight/current governance checks</summary><div class="unifiedActivityList"><?php foreach($context['checks'] as $name=>$ok):?><div class="unifiedActivityItem"><span class="badge"><?=$ok?'PASS':'BLOCK'?></span> <?=h(str_replace('_',' ',$name))?></div><?php endforeach?></div></details><?php endif?>
    <div class="inlineActions">
      <?php if($deployment['status']==='draft'):?><form method="post"><?=csrf_field()?><input type="hidden" name="deployment" value="<?=h($deployment['public_id'])?>"><input type="hidden" name="op" value="preflight"><button class="button">Run preflight</button></form><?php endif?>
      <?php if($deployment['status']==='preflight_passed'):?><form method="post"><?=csrf_field()?><input type="hidden" name="deployment" value="<?=h($deployment['public_id'])?>"><input type="hidden" name="op" value="shadow"><button class="button">Start Shadow</button></form><?php endif?>
      <?php if(in_array($deployment['status'],['shadow','canary','limited'],true)):?><form method="post"><?=csrf_field()?><input type="hidden" name="deployment" value="<?=h($deployment['public_id'])?>"><input type="hidden" name="op" value="advance"><button class="button" <?=$checkpointSummary&&$checkpointSummary['pass']?'':'disabled'?>><?=h($deployment['status']==='limited'?'Activate Full Rollout':'Advance Stage')?></button></form><form method="post"><?=csrf_field()?><input type="hidden" name="deployment" value="<?=h($deployment['public_id'])?>"><input type="hidden" name="op" value="pause"><button class="button secondary">Pause</button></form><?php endif?>
      <?php if($deployment['status']==='paused'):?><form method="post"><?=csrf_field()?><input type="hidden" name="deployment" value="<?=h($deployment['public_id'])?>"><input type="hidden" name="op" value="resume"><button class="button">Resume <?=h((string)$deployment['paused_stage'])?></button></form><?php endif?>
      <?php if(in_array($deployment['status'],['preflight_passed','shadow','canary','limited','paused'],true)):?><form method="post"><?=csrf_field()?><input type="hidden" name="deployment" value="<?=h($deployment['public_id'])?>"><input type="hidden" name="op" value="stop"><button class="button secondary">Stop rollout</button></form><?php endif?>
      <?php if(!in_array($deployment['status'],['draft','stopped','rolled_back'],true)):?><form method="post"><?=csrf_field()?><input type="hidden" name="deployment" value="<?=h($deployment['public_id'])?>"><input type="hidden" name="op" value="rollback"><input type="hidden" name="note" value="Operator-requested governed rollback"><button class="button danger">Rollback</button></form><?php endif?>
      <form method="post" action="/admin/model-deployment-export.php"><?=csrf_field()?><input type="hidden" name="deployment" value="<?=h($deployment['public_id'])?>"><button class="button secondary">Export audit JSON</button></form><a class="button secondary" href="/admin/model-observability.php?deployment=<?=rawurlencode((string)$deployment['public_id'])?>">Open Production Health</a>
    </div>
  </section>

  <?php if(in_array($deployment['status'],['shadow','canary','limited'],true)):?>
  <section class="card"><span class="eyebrow">INDEPENDENT HUMAN CHECKPOINT</span><h2>Sign <?=h(ucfirst($deployment['status']))?> outcome</h2><p class="meta">Your signature binds to deployment revision <?=h((string)$deployment['revision'])?> and the current routing/model/release hashes. Any pause, resume, stage change, or plan state change invalidates the current signature.</p>
    <form method="post" class="settingsForm"><?=csrf_field()?><input type="hidden" name="deployment" value="<?=h($deployment['public_id'])?>"><input type="hidden" name="op" value="checkpoint"><label>Recommendation<select name="recommendation"><option value="proceed">Proceed</option><option value="hold">Hold</option><option value="stop">Stop</option></select></label><label>Review note<textarea name="note" rows="4" required></textarea></label><button class="button">Sign checkpoint</button></form>
    <?php if($checkpointSummary):?><p class="meta">Current signatures: proceed <?=h((string)$checkpointSummary['counts']['proceed'])?> · hold <?=h((string)$checkpointSummary['counts']['hold'])?> · stop <?=h((string)$checkpointSummary['counts']['stop'])?> · stale <?=h((string)count($checkpointSummary['stale']))?></p><?php endif?>
  </section><?php endif?>

  <section class="card"><span class="eyebrow">CHECKPOINT SIGNATURES</span><h2>Human stage approvals</h2><?php if(!$checkpoints):?><p class="empty">No checkpoint signatures yet.</p><?php else:?><div class="unifiedActivityList"><?php foreach($checkpoints as $c):?><?php $ci=data_model_deployment_checkpoint_integrity($pdo,$deployment,$c);?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($c['stage'])?></span> <strong><?=h($c['recommendation'])?></strong> · <?=h($c['reviewer_name'])?></div><span class="meta"><?=$ci['ok']?'current':'stale'?></span></div><p><?=h((string)$c['note'])?></p><p class="meta">Snapshot <?=h(substr((string)$c['deployment_snapshot_hash'],0,16))?>… · signature <?=h(substr((string)$c['signature_hash'],0,16))?>…</p></div></article><?php endforeach?></div><?php endif?></section>
  <section class="card"><span class="eyebrow">AUDIT EVENTS</span><h2>Deployment ledger</h2><?php if(!$events):?><p class="empty">No events.</p><?php else:?><div class="unifiedActivityList"><?php foreach($events as $e):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><strong><?=h(str_replace('_',' ',$e['event_type']))?></strong><span class="meta"><?=h($e['created_at'])?></span></div><p class="meta"><?=h((string)($e['actor_name']??'System'))?> · <?=h((string)($e['stage']??''))?><?=!empty($e['route_snapshot_hash'])?' · route '.h(substr((string)$e['route_snapshot_hash'],0,14)).'…':''?></p></div></article><?php endforeach?></div><?php endif?></section>
  <?php endif?>
</main></body></html>
