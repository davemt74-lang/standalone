<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if(!data_training_ready($pdo)){http_response_code(503);exit('Training Registry requires the Phase 41 database upgrade.');}
$error='';$success='';$jobId=trim((string)($_GET['job']??$_POST['job']??''));

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$op=(string)($_POST['op']??'');
    try{
        if($op==='create'){
            $job=data_training_job_create($pdo,$u,$_POST);header('Location:/admin/training.php?job='.rawurlencode((string)$job['public_id']));exit;
        }elseif($op==='update'){
            data_training_job_update_draft($pdo,$u,$jobId,$_POST);$success='Training draft updated.';
        }elseif($op==='queue'){
            data_training_queue($pdo,$u,$jobId);$success='Training job queued.';
        }elseif($op==='cancel'){
            data_training_request_cancel($pdo,$u,$jobId);$success='Training cancellation recorded.';
        }elseif($op==='retry'){
            data_training_retry($pdo,$u,$jobId);$success='Training job requeued for another controlled attempt.';
        }elseif($op==='manual_complete'){
            data_training_manual_complete($pdo,$u,$jobId,$_POST);$success='Manual training artifact registered as an experimental Phase 40 model version.';
        }elseif($op==='cost'){
            $cost=($_POST['actual_cost_usd']??'')!==''?(float)$_POST['actual_cost_usd']:null;data_training_cost_update($pdo,$u,$jobId,$cost);$success='Actual training cost updated.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$stats=data_training_summary($pdo);
$jobs=data_training_job_list($pdo,100);
$job=$jobId!==''?data_training_job_get($pdo,$jobId):null;
$preflight=$job&&$job['status']==='draft'?data_training_preflight($pdo,$job):null;
$currentUse=$job&&$job['status']!=='draft'?data_training_current_use_status($pdo,$job):null;
$completionIntegrity=$job&&$job['status']==='succeeded'?data_training_completion_integrity($pdo,$job):null;
$attempts=$job?data_training_attempts($pdo,(int)$job['id']):[];
$logs=$job?data_training_logs($pdo,(int)$job['id'],200):[];
$events=$job?data_training_events($pdo,(int)$job['id'],100):[];

$datasets=$pdo->query("SELECT id,public_id,name,version_number,purpose,item_count,manifest_hash FROM data_datasets WHERE status='frozen' AND purpose IN ('training','commercial_training') ORDER BY id DESC")->fetchAll();
$baseVersions=$pdo->query("SELECT v.id,v.public_id,v.version_label,v.status,v.version_hash,r.name registry_name,m.display_name runtime_name,p.label provider_label,p.provider_type FROM data_model_versions v JOIN data_model_registry r ON r.id=v.registry_id LEFT JOIN ai_models m ON m.id=v.ai_model_id LEFT JOIN ai_providers p ON p.id=m.provider_id WHERE v.status IN ('approved','active') ORDER BY r.name,v.id DESC")->fetchAll();
$registries=data_model_registry_list($pdo,100);
$runtimeModels=$pdo->query("SELECT m.id,m.display_name,m.model_name,p.label provider_label,p.provider_type FROM ai_models m JOIN ai_providers p ON p.id=m.provider_id WHERE m.enabled=1 AND p.enabled=1 ORDER BY p.label,m.display_name")->fetchAll();
$executors=data_training_executors();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Training Registry · Annotated Admin</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><?=admin_ui_sidebar('training')?>
<main class="panel article">
  <div class="pageTitle"><span class="eyebrow">TRAINING REGISTRY</span><h1>Controlled fine-tuning jobs</h1><p>Train only from explicit frozen training datasets and governed Phase 40 base-model versions. Training outputs return to the Model Registry as <strong>experimental</strong> versions and must still pass Phase 39 evaluation and Phase 40 approval before activation.</p></div>
  <div class="inlineActions"><a class="button secondary" href="/admin/model-campaigns.php">Improvement Campaigns</a><a class="button secondary" href="/admin/datasets.php">Dataset Registry</a><a class="button secondary" href="/admin/model-registry.php">Model Registry</a><a class="button secondary" href="/admin/evaluations.php">Evaluation Harness</a><a class="button secondary" href="/admin/ai.php">AI providers</a></div>
  <?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif?><?php if($success):?><div class="notice success"><?=h($success)?></div><?php endif?>

  <section class="healthGrid">
    <article class="card"><strong><?=h((string)$stats['jobs'])?></strong><p class="meta">Training jobs</p></article>
    <article class="card"><strong><?=h((string)$stats['drafts'])?></strong><p class="meta">Drafts</p></article>
    <article class="card"><strong><?=h((string)$stats['active'])?></strong><p class="meta">Provider active</p></article>
    <article class="card"><strong><?=h((string)$stats['prepared'])?></strong><p class="meta">Manual prepared</p></article>
    <article class="card"><strong><?=h((string)$stats['succeeded'])?></strong><p class="meta">Succeeded</p></article>
    <article class="card"><strong><?=h((string)$stats['blocked'])?></strong><p class="meta">Governance blocked</p></article>
  </section>

  <section class="card">
    <span class="eyebrow">CREATE JOB</span><h2>New controlled training draft</h2>
    <?php if(!$datasets||!$baseVersions||!$registries):?><p class="empty">A training job requires a frozen Training/Commercial Training dataset, an approved or active Phase 40 base model, and a target Model Registry.</p><?php else:?>
    <form method="post" class="settingsForm">
      <?=csrf_field()?><input type="hidden" name="op" value="create">
      <label>Training dataset<select name="dataset_id" required><?php foreach($datasets as $d):?><option value="<?=h((string)$d['id'])?>"><?=h($d['name'])?> · v<?=h((string)$d['version_number'])?> · <?=h($d['purpose'])?> · <?=h((string)$d['item_count'])?> items</option><?php endforeach?></select></label>
      <label>Use class<select name="use_class"><option value="internal_training">Internal training</option><option value="commercial_training">Commercial training</option></select></label>
      <label>Base governed model<select name="base_model_version_id" required><?php foreach($baseVersions as $v):?><option value="<?=h((string)$v['id'])?>"><?=h($v['registry_name'])?> · <?=h($v['version_label'])?> · <?=h($v['status'])?><?=!empty($v['runtime_name'])?' · '.h($v['runtime_name']):''?></option><?php endforeach?></select></label>
      <label>Output Model Registry<select name="output_registry_id" required><?php foreach($registries as $r):?><option value="<?=h((string)$r['id'])?>"><?=h($r['name'])?></option><?php endforeach?></select></label>
      <label>Output experimental version label<input name="output_version_label" maxlength="120" required placeholder="ft-2026-09-01"></label>
      <label>Executor<select name="executor_type"><option value="provider_api">Provider API</option><option value="manual">Manual / self-hosted</option></select></label>
      <label>Description<textarea name="description" rows="3"></textarea></label>
      <label>Epochs<input name="n_epochs" value="auto" placeholder="auto or integer"></label>
      <label>Batch size<input name="batch_size" value="auto" placeholder="auto or integer"></label>
      <label>Learning-rate multiplier<input name="learning_rate_multiplier" value="auto" placeholder="auto or number"></label>
      <label>Seed<input type="number" name="seed" placeholder="optional"></label>
      <label>Provider suffix<input name="suffix" maxlength="64" placeholder="optional"></label>
      <label>Provider file retention seconds<input type="number" name="file_expiry_seconds" min="3600" max="2592000" value="604800"></label>
      <label>Training cost rate, USD / 1M trained tokens<input type="number" name="cost_rate_usd_per_million_tokens" min="0" step="0.000001" placeholder="optional; no price is hardcoded"></label>
      <label>Maximum attempts<input type="number" name="max_attempts" min="1" max="10" value="3"></label>
      <label><input type="checkbox" name="external_provider_acknowledged" value="1"> I acknowledge that Provider API training sends the eligible frozen package to the configured external/provider endpoint.</label>
      <button class="button" type="submit">Create training draft</button>
    </form>
    <?php endif?>
  </section>

  <section class="card">
    <span class="eyebrow">JOBS</span><h2>Training history</h2>
    <?php if(!$jobs):?><p class="empty">No controlled training jobs yet.</p><?php else:?><div class="unifiedActivityList">
      <?php foreach($jobs as $row):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($row['status'])?></span><strong><a href="/admin/training.php?job=<?=rawurlencode((string)$row['public_id'])?>"><?=h($row['output_version_label'])?></a></strong></div><time><?=h($row['created_at'])?></time></div><p class="meta"><?=h($row['dataset_name'])?> v<?=h((string)$row['dataset_version'])?> → <?=h($row['output_registry_name'])?> · base <?=h($row['base_version_label'])?> · <?=h($row['executor_type'])?><?=!empty($row['provider_label'])?' · '.h($row['provider_label']):''?></p></div></article><?php endforeach?>
    </div><?php endif?>
  </section>

  <?php if($job):?>
  <section class="card">
    <div class="caseHeader"><div><span class="eyebrow">JOB DETAIL</span><h2><?=h($job['output_version_label'])?></h2></div><span class="badge"><?=h($job['status'])?></span></div>
    <div class="cognitiveCardMeta">
      <span><small>Dataset</small><?=h($job['dataset_name'])?> v<?=h((string)$job['dataset_version'])?></span>
      <span><small>Purpose / use</small><?=h($job['dataset_purpose'])?> · <?=h($job['use_class'])?></span>
      <span><small>Base model</small><?=h($job['base_version_label'])?></span>
      <span><small>Executor</small><?=h($executors[$job['executor_type']]['label']??$job['executor_type'])?></span>
      <span><small>Examples</small><?=h($job['example_count']!==null?(string)$job['example_count']:'—')?></span>
      <span><small>Attempts</small><?=h((string)$job['attempt_count'])?> / <?=h((string)$job['max_attempts'])?></span>
    </div>
    <p><?=h((string)($job['description']?:'No description.'))?></p>
    <?php if(!empty($job['job_hash'])):?><p class="meta">Job <?=h(substr((string)$job['job_hash'],0,16))?>… · package <?=h(substr((string)$job['package_hash'],0,16))?>… · dataset <?=h(substr((string)$job['dataset_manifest_hash'],0,16))?>… · base <?=h(substr((string)$job['base_model_version_hash'],0,16))?>… · approval <?=h(substr((string)$job['base_approval_receipt_hash'],0,16))?>… · runtime <?=h(substr((string)$job['runtime_snapshot_hash'],0,16))?>…</p><?php endif?>

    <?php if($job['status']==='draft'):?>
      <div class="card">
        <span class="eyebrow">PREFLIGHT</span><h3><?=$preflight&&$preflight['pass']?'Ready to queue':'Blocked'?></h3>
        <div class="cognitiveCardMeta">
          <span><small>Valid examples</small><?=h((string)($preflight['package']['valid_examples']??0))?></span>
          <span><small>Invalid examples</small><?=h((string)($preflight['package']['invalid_examples']??0))?></span>
          <span><small>Attribution-blocked</small><?=h((string)($preflight['package']['attribution_blocked_items']??0))?></span>
          <span><small>Contributors</small><?=h((string)($preflight['package']['contributor_count']??0))?></span>
        </div>
        <?php foreach(($preflight['checks']??[]) as $name=>$ok):?><p class="meta"><?=h(str_replace('_',' ',$name))?>: <strong><?=$ok?'PASS':'BLOCKED'?></strong></p><?php endforeach?>
        <?php if(!empty($preflight['package']['errors'])):?><details><summary>Invalid training items</summary><?php foreach($preflight['package']['errors'] as $e):?><p class="meta">#<?=h((string)$e['position'])?> · <?=h($e['corpus_public_id'])?> · <?=h($e['reason'])?></p><?php endforeach?></details><?php endif?>
      </div>
      <form method="post" class="settingsForm">
        <?=csrf_field()?><input type="hidden" name="op" value="update"><input type="hidden" name="job" value="<?=h($job['public_id'])?>">
        <label>Training dataset<select name="dataset_id"><?php foreach($datasets as $d):?><option value="<?=h((string)$d['id'])?>" <?=((int)$job['dataset_id']===(int)$d['id'])?'selected':''?>><?=h($d['name'])?> · v<?=h((string)$d['version_number'])?> · <?=h($d['purpose'])?></option><?php endforeach?></select></label>
        <label>Use class<select name="use_class"><option value="internal_training" <?=$job['use_class']==='internal_training'?'selected':''?>>Internal training</option><option value="commercial_training" <?=$job['use_class']==='commercial_training'?'selected':''?>>Commercial training</option></select></label>
        <label>Base governed model<select name="base_model_version_id"><?php foreach($baseVersions as $v):?><option value="<?=h((string)$v['id'])?>" <?=((int)$job['base_model_version_id']===(int)$v['id'])?'selected':''?>><?=h($v['registry_name'])?> · <?=h($v['version_label'])?> · <?=h($v['status'])?></option><?php endforeach?></select></label>
        <label>Output Model Registry<select name="output_registry_id"><?php foreach($registries as $r):?><option value="<?=h((string)$r['id'])?>" <?=((int)$job['output_registry_id']===(int)$r['id'])?'selected':''?>><?=h($r['name'])?></option><?php endforeach?></select></label>
        <label>Output version label<input name="output_version_label" value="<?=h($job['output_version_label'])?>"></label>
        <label>Executor<select name="executor_type"><option value="provider_api" <?=$job['executor_type']==='provider_api'?'selected':''?>>Provider API</option><option value="manual" <?=$job['executor_type']==='manual'?'selected':''?>>Manual / self-hosted</option></select></label>
        <label>Description<textarea name="description" rows="3"><?=h((string)$job['description'])?></textarea></label>
        <label>Epochs<input name="n_epochs" value="<?=h((string)$job['config']['n_epochs'])?>"></label>
        <label>Batch size<input name="batch_size" value="<?=h((string)$job['config']['batch_size'])?>"></label>
        <label>Learning-rate multiplier<input name="learning_rate_multiplier" value="<?=h((string)$job['config']['learning_rate_multiplier'])?>"></label>
        <label>Seed<input type="number" name="seed" value="<?=h((string)($job['config']['seed']??''))?>"></label>
        <label>Provider suffix<input name="suffix" maxlength="64" value="<?=h((string)($job['config']['suffix']??''))?>"></label>
        <label>Provider file retention seconds<input type="number" name="file_expiry_seconds" min="3600" max="2592000" value="<?=h((string)$job['config']['file_expiry_seconds'])?>"></label>
        <label>Cost rate USD / 1M trained tokens<input type="number" name="cost_rate_usd_per_million_tokens" min="0" step="0.000001" value="<?=h((string)($job['config']['cost_rate_usd_per_million_tokens']??''))?>"></label>
        <label>Maximum attempts<input type="number" name="max_attempts" min="1" max="10" value="<?=h((string)$job['config']['max_attempts'])?>"></label>
        <label><input type="checkbox" name="external_provider_acknowledged" value="1" <?=!empty($job['config']['external_provider_acknowledged'])?'checked':''?>> External/provider transfer acknowledged</label>
        <button class="button secondary" type="submit">Update training draft</button>
      </form>
      <form method="post" onsubmit="return confirm('Queue this immutable training job? The dataset, base model, config, rights snapshot, and package hash will be frozen.');"><?=csrf_field()?><input type="hidden" name="op" value="queue"><input type="hidden" name="job" value="<?=h($job['public_id'])?>"><button class="button" type="submit" <?=!($preflight&&$preflight['pass'])?'disabled':''?>>Queue controlled training</button></form>
    <?php else:?>
      <div class="card">
        <span class="eyebrow">CURRENT GOVERNANCE</span><h3><?=$currentUse&&$currentUse['usable']?'Current use valid':'Current use blocked'?></h3>
        <p class="meta">Job integrity: <?=!empty($currentUse['job_integrity']['ok'])?'VALID':'FAILED'?> · dataset: <?=!empty($currentUse['dataset_status']['usable'])?'VALID':'BLOCKED'?> · base model: <?=!empty($currentUse['base_integrity']['ok'])?'VALID':'FAILED'?> · approval receipt: <?=!empty($currentUse['base_approval_receipt']['ok'])?'VALID':'BLOCKED'?> · snapshot match: <?=!empty($currentUse['snapshot_match'])?'YES':'NO'?></p>
      </div>
      <?php if(!in_array($job['status'],['succeeded','failed','cancelled','blocked'],true)):?><form method="post" onsubmit="return confirm('Request cancellation of this training job?');"><?=csrf_field()?><input type="hidden" name="op" value="cancel"><input type="hidden" name="job" value="<?=h($job['public_id'])?>"><button class="button secondary" type="submit">Cancel training</button></form><?php endif?>
      <?php if($job['status']==='failed'&&(int)$job['attempt_count']<(int)$job['max_attempts']):?><form method="post"><?=csrf_field()?><input type="hidden" name="op" value="retry"><input type="hidden" name="job" value="<?=h($job['public_id'])?>"><button class="button secondary" type="submit">Retry controlled training</button></form><?php endif?>
      <?php if($job['status']!=='draft'&&$currentUse&&$currentUse['usable']):?><form method="post" action="/admin/training-export.php"><?=csrf_field()?><input type="hidden" name="job_id" value="<?=h($job['public_id'])?>"><button class="button secondary" type="submit">Export exact training JSONL</button></form><?php endif?>
    <?php endif?>

    <?php if($job['status']==='prepared'&&$job['executor_type']==='manual'):?>
    <div class="card">
      <span class="eyebrow">MANUAL COMPLETION</span><h3>Register self-hosted/external artifact</h3>
      <p class="meta">This does not approve or activate the model. The output enters Phase 40 as an experimental version.</p>
      <form method="post" class="settingsForm">
        <?=csrf_field()?><input type="hidden" name="op" value="manual_complete"><input type="hidden" name="job" value="<?=h($job['public_id'])?>">
        <label>Artifact reference<input name="artifact_ref" required placeholder="s3://... / registry://... / local artifact URI"></label>
        <label>Artifact SHA-256<input name="artifact_hash" maxlength="64" required></label>
        <label>Output model name<input name="output_model_name" placeholder="optional runtime/provider model name"></label>
        <label>Existing runtime model binding<select name="runtime_model_id"><option value="">None — bind later in Model Registry</option><?php foreach($runtimeModels as $m):?><option value="<?=h((string)$m['id'])?>"><?=h($m['display_name'])?> · <?=h($m['provider_label'])?> · <?=h($m['model_name'])?></option><?php endforeach?></select></label>
        <label>Actual cost USD<input type="number" name="actual_cost_usd" min="0" step="0.000001"></label>
        <button class="button" type="submit">Register experimental output</button>
      </form>
    </div>
    <?php endif?>

    <?php if($job['provider_job_id']||$job['provider_status']):?>
    <div class="card"><span class="eyebrow">PROVIDER</span><div class="cognitiveCardMeta"><span><small>Provider</small><?=h((string)($job['provider_label']?:'—'))?></span><span><small>File ID</small><?=h((string)($job['provider_file_id']?:'—'))?></span><span><small>Job ID</small><?=h((string)($job['provider_job_id']?:'—'))?></span><span><small>Status</small><?=h((string)($job['provider_status']?:'—'))?></span><span><small>Trained tokens</small><?=h($job['trained_tokens']!==null?number_format((int)$job['trained_tokens']):'—')?></span></div></div>
    <?php endif?>

    <?php if($job['status']==='succeeded'):?>
    <div class="notice <?=$completionIntegrity&&$completionIntegrity['ok']?'success':'error'?>"><strong>COMPLETION INTEGRITY <?=$completionIntegrity&&$completionIntegrity['ok']?'VALID':'FAILED'?></strong><br><?=h(str_replace('_',' ',(string)($completionIntegrity['reason']??'unknown')))?>.</div>
    <div class="notice success"><strong>TRAINING OUTPUT REGISTERED</strong><br>Output model version: <?php if($job['output_model_version_public_id']):?><a href="/admin/model-registry.php?registry=<?=rawurlencode($job['output_registry_public_id'])?>&version=<?=rawurlencode($job['output_model_version_public_id'])?>"><?=h($job['output_model_version_label'])?></a><?php else:?>—<?php endif?>. It is experimental and still requires Phase 39 evaluation and Phase 40 approval.</div>
    <?php if($completionIntegrity&&$completionIntegrity['ok']):?><div class="inlineActions"><a class="button" href="/admin/post-training.php?training_job=<?=rawurlencode((string)$job['public_id'])?>">Start post-training evaluation</a></div><?php endif?>
    <?php endif?>
    <?php if($job['last_error']):?><div class="notice error"><?=h($job['last_error'])?></div><?php endif?>

    <?php if(in_array($job['status'],['succeeded','failed','cancelled','blocked'],true)):?>
    <form method="post" class="settingsForm"><?=csrf_field()?><input type="hidden" name="op" value="cost"><input type="hidden" name="job" value="<?=h($job['public_id'])?>"><label>Actual training cost USD<input type="number" name="actual_cost_usd" min="0" step="0.000001" value="<?=h((string)($job['actual_cost_usd']??''))?>"></label><button class="button secondary" type="submit">Save actual cost</button></form>
    <?php endif?>
    <p class="meta">Estimated cost: <?=h($job['estimated_cost_usd']!==null?'$'.number_format((float)$job['estimated_cost_usd'],6):'—')?> · Actual cost: <?=h($job['actual_cost_usd']!==null?'$'.number_format((float)$job['actual_cost_usd'],6):'—')?><?=!empty($job['completion_hash'])?' · completion '.h(substr((string)$job['completion_hash'],0,16)).'…':''?></p>
  </section>

  <section class="card">
    <span class="eyebrow">ATTEMPTS</span><h2>Execution attempts</h2>
    <?php if(!$attempts):?><p class="empty">No provider attempts yet.</p><?php else:?><div class="unifiedActivityList"><?php foreach($attempts as $a):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($a['status'])?></span><strong>Attempt <?=h((string)$a['attempt_number'])?></strong></div><time><?=h($a['started_at'])?></time></div><p class="meta">Provider file <?=h((string)($a['provider_file_id']?:'—'))?> · provider job <?=h((string)($a['provider_job_id']?:'—'))?><?=!empty($a['request_hash'])?' · request '.h(substr((string)$a['request_hash'],0,12)).'…':''?></p><?php if($a['error_text']):?><p class="error"><?=h($a['error_text'])?></p><?php endif?></div></article><?php endforeach?></div><?php endif?>
  </section>

  <section class="card">
    <span class="eyebrow">TRAINING LOG</span><h2>Provider & worker events</h2>
    <?php if(!$logs):?><p class="empty">No training logs yet.</p><?php else:?><div class="unifiedActivityList"><?php foreach($logs as $l):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($l['source'])?> · <?=h($l['level'])?></span><strong><?=h((string)($l['event_type']?:'event'))?></strong></div><time><?=h($l['created_at'])?></time></div><p><?=h($l['message'])?></p><p class="meta">Log <?=h(substr((string)$l['log_hash'],0,14))?>…</p></div></article><?php endforeach?></div><?php endif?>
  </section>

  <section class="card">
    <span class="eyebrow">AUDIT EVENTS</span><h2>Training governance history</h2>
    <?php if(!$events):?><p class="empty">No training events.</p><?php else:?><div class="unifiedActivityList"><?php foreach($events as $e):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($e['event_type'])?></span><strong><?=h((string)($e['actor_name']?:'Worker / system'))?></strong></div><time><?=h($e['created_at'])?></time></div></div></article><?php endforeach?></div><?php endif?>
  </section>
  <?php endif?>

  <section class="card">
    <span class="eyebrow">WORKER</span><h2>Controlled execution worker</h2>
    <p><code>php bin/training-worker.php 5</code></p>
    <p class="meta">Provider jobs are uploaded/submitted/polled/cancelled by the worker, not by ordinary page loads. A successful provider job creates an enabled-but-unrouted runtime model and a Phase 40 experimental version; it never edits <code>ai_settings</code>.</p>
  </section>
</main></body></html>