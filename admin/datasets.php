<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if(!data_dataset_ready($pdo)){http_response_code(503);exit('Dataset Registry requires the Phase 38 database upgrade.');}
$error='';$success='';$id=trim((string)($_GET['id']??$_POST['id']??''));
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$op=(string)($_POST['op']??'');
    try{
        if($op==='create'){
            $d=data_dataset_create($pdo,$u,$_POST);header('Location:/admin/datasets.php?id='.rawurlencode((string)$d['public_id']));exit;
        }
        if($op==='update'){
            data_dataset_update_draft($pdo,$u,$id,$_POST);$success='Dataset draft updated.';
        }elseif($op==='freeze'){
            data_dataset_freeze($pdo,$u,$id);$success='Dataset frozen. The snapshot and manifest are now immutable.';
        }elseif($op==='retire'){
            data_dataset_retire($pdo,$u,$id);$success='Dataset retired.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$stats=data_dataset_summary($pdo);$datasets=data_dataset_list($pdo,100);$selected=$id!==''?data_dataset_get($pdo,$id):null;
$preview=$selected&&$selected['status']==='draft'?data_dataset_preview($pdo,$id,20):null;
$useStatus=$selected&&$selected['status']==='frozen'?data_dataset_current_use_status($pdo,$id):null;
$items=$selected&&in_array($selected['status'],['frozen','retired'],true)?data_dataset_items($pdo,$id,100,0):[];
$events=$selected?data_dataset_events($pdo,$id,50):[];
$purposeOptions=data_dataset_purposes();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dataset Registry · Annotated Admin</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="panel article">
  <div class="pageTitle"><span class="eyebrow">DATASET REGISTRY</span><h1>Frozen dataset manifests</h1><p>Create reproducible dataset snapshots only from the governed Phase 37 corpus. Freezing preserves the exact selected text and hashes; current rights and consent are revalidated before data can be reused.</p></div>
  <?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif?><?php if($success):?><div class="notice success"><?=h($success)?></div><?php endif?>

  <section class="healthGrid">
    <article class="card"><strong><?=h((string)$stats['datasets'])?></strong><p class="meta">Datasets</p></article>
    <article class="card"><strong><?=h((string)$stats['drafts'])?></strong><p class="meta">Drafts</p></article>
    <article class="card"><strong><?=h((string)$stats['frozen'])?></strong><p class="meta">Frozen</p></article>
    <article class="card"><strong><?=h((string)$stats['retired'])?></strong><p class="meta">Retired</p></article>
    <article class="card"><strong><?=h((string)$stats['frozen_items'])?></strong><p class="meta">Frozen item snapshots</p></article>
  </section>

  <section class="card">
    <span class="eyebrow">CREATE DATASET</span><h2>New governed dataset draft</h2>
    <form method="post" class="settingsForm">
      <?=csrf_field()?><input type="hidden" name="op" value="create">
      <label>Name<input name="name" maxlength="180" required placeholder="Annotated Retrieval Baseline"></label>
      <label>Purpose<select name="purpose"><?php foreach($purposeOptions as $key=>$meta):?><option value="<?=h($key)?>"><?=h($meta['label'])?></option><?php endforeach?></select></label>
      <label>Corpus types<input name="corpus_types" placeholder="annotation_commentary, report_summary"></label>
      <label>Maximum items<input type="number" name="max_items" min="1" max="10000" value="1000"></label>
      <label>Description<textarea name="description" rows="3" placeholder="What this dataset is for and what it should contain."></textarea></label>
      <button class="button" type="submit">Create draft</button>
    </form>
  </section>

  <section class="card">
    <span class="eyebrow">REGISTRY</span><h2>Datasets</h2>
    <?php if(!$datasets):?><p class="empty">No datasets have been created yet.</p><?php else:?><div class="unifiedActivityList">
      <?php foreach($datasets as $d):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($d['status'])?></span><strong><a href="/admin/datasets.php?id=<?=rawurlencode((string)$d['public_id'])?>"><?=h($d['name'])?> · v<?=h((string)$d['version_number'])?></a></strong></div><time><?=h($d['updated_at'])?></time></div><p class="meta"><?=h($purposeOptions[$d['purpose']]['label']??$d['purpose'])?> · <?=h((string)$d['item_count'])?> items<?=!empty($d['manifest_hash'])?' · manifest '.h(substr((string)$d['manifest_hash'],0,12)).'…':''?></p></div></article><?php endforeach?>
    </div><?php endif?>
  </section>

  <?php if($selected):$policy=$selected['selection_policy'];?>
  <section class="card">
    <div class="caseHeader"><div><span class="eyebrow">DATASET DETAIL</span><h2><?=h($selected['name'])?> · v<?=h((string)$selected['version_number'])?></h2></div><span class="badge"><?=h($selected['status'])?></span></div>
    <div class="cognitiveCardMeta">
      <span><small>Purpose</small><?=h($purposeOptions[$selected['purpose']]['label']??$selected['purpose'])?></span>
      <span><small>Policy hash</small><?=h(substr((string)$selected['selection_policy_hash'],0,16))?>…</span>
      <span><small>Manifest hash</small><?=h($selected['manifest_hash']?substr((string)$selected['manifest_hash'],0,16).'…':'Not frozen')?></span>
      <span><small>Items</small><?=h((string)$selected['item_count'])?></span>
      <span><small>Bytes</small><?=h(number_format((int)$selected['content_bytes']))?></span>
    </div>
    <p><?=h((string)($selected['description']?:'No description.'))?></p>

    <?php if($selected['status']==='draft'):?>
      <form method="post" class="settingsForm">
        <?=csrf_field()?><input type="hidden" name="op" value="update"><input type="hidden" name="id" value="<?=h($selected['public_id'])?>">
        <label>Name<input name="name" maxlength="180" required value="<?=h($selected['name'])?>"></label>
        <label>Purpose<select name="purpose"><?php foreach($purposeOptions as $key=>$meta):?><option value="<?=h($key)?>" <?=$selected['purpose']===$key?'selected':''?>><?=h($meta['label'])?></option><?php endforeach?></select></label>
        <label>Corpus types<input name="corpus_types" value="<?=h(implode(', ',(array)($policy['corpus_types']??[])))?>"></label>
        <label>Maximum items<input type="number" name="max_items" min="1" max="10000" value="<?=h((string)($policy['max_items']??1000))?>"></label>
        <label>Description<textarea name="description" rows="3"><?=h((string)($selected['description']??''))?></textarea></label>
        <button class="button secondary" type="submit">Update draft</button>
      </form>
      <div class="card">
        <span class="eyebrow">PREVIEW</span><h3>Current governed candidates</h3>
        <div class="cognitiveCardMeta"><span><small>Eligible now</small><?=h((string)$preview['eligible_total'])?></span><span><small>Selected by max</small><?=h((string)$preview['selected_count'])?></span><span><small>Selected bytes</small><?=h(number_format((int)$preview['selected_bytes']))?></span></div>
        <?php if(!$preview['sample']):?><p class="empty">No current corpus items satisfy this purpose and policy.</p><?php else:?><div class="unifiedActivityList"><?php foreach($preview['sample'] as $row):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($row['corpus_type'])?></span><strong><?=h($row['source_object_type'])?> · <?=h($row['source_object_public_id'])?></strong></div></div><p class="meta">Eligibility: <?=h($row['eligibility_reason'])?> · content <?=h(substr((string)$row['content_hash'],0,12))?>… · provenance <?=h(substr((string)$row['provenance_hash'],0,12))?>…</p></div></article><?php endforeach?></div><?php endif?>
      </div>
      <form method="post" onsubmit="return confirm('Freeze this dataset? Its selection policy and item snapshots will become immutable.');">
        <?=csrf_field()?><input type="hidden" name="op" value="freeze"><input type="hidden" name="id" value="<?=h($selected['public_id'])?>"><button class="button" type="submit" <?=$preview['selected_count']<1?'disabled':''?>>Freeze dataset</button>
      </form>
    <?php elseif($selected['status']==='frozen'):?>
      <div class="notice <?=$useStatus['usable']?'success':'error'?>"><strong><?=$useStatus['usable']?'CURRENTLY USABLE':'CURRENT USE BLOCKED'?></strong><br><?=h(str_replace('_',' ',(string)$useStatus['reason']))?> · <?=h((string)$useStatus['invalid_items'])?> invalid item<?=((int)$useStatus['invalid_items']===1?'':'s')?>.</div>
      <div class="inlineActions">
        <form method="post" action="/admin/dataset-export.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($selected['public_id'])?>"><input type="hidden" name="mode" value="manifest"><button class="button secondary" type="submit">Export manifest JSON</button></form>
        <form method="post" action="/admin/dataset-export.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($selected['public_id'])?>"><input type="hidden" name="mode" value="data"><button class="button" type="submit" <?=!$useStatus['usable']?'disabled':''?>>Export approved data JSON</button></form>
        <form method="post" onsubmit="return confirm('Retire this frozen dataset? Historical manifest data remains auditable.');"><?=csrf_field()?><input type="hidden" name="op" value="retire"><input type="hidden" name="id" value="<?=h($selected['public_id'])?>"><button class="button secondary" type="submit">Retire dataset</button></form>
      </div>
      <p class="meta">A manifest export is always available for audit. Full data export is blocked when current rights, consent, corpus state, or manifest integrity no longer validates.</p>
    <?php elseif($selected['status']==='retired'):?>
      <div class="notice"><strong>RETIRED</strong><br>This dataset cannot be used or exported with text. Its immutable manifest remains available for audit.</div>
      <form method="post" action="/admin/dataset-export.php"><?=csrf_field()?><input type="hidden" name="id" value="<?=h($selected['public_id'])?>"><input type="hidden" name="mode" value="manifest"><button class="button secondary" type="submit">Export retired manifest JSON</button></form>
    <?php endif?>
  </section>

  <?php if(in_array($selected['status'],['frozen','retired'],true)):?>
  <section class="card">
    <span class="eyebrow">FROZEN ITEMS</span><h2>Snapshot contents</h2>
    <?php if(!$items):?><p class="empty">No frozen item snapshots.</p><?php else:?><div class="unifiedActivityList"><?php foreach($items as $row):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge">#<?=h((string)((int)$row['position']+1))?></span><strong><?=h($row['source_object_type'])?> · <?=h($row['source_object_public_id'])?></strong></div></div><p class="meta"><?=h($row['corpus_type'])?><?=!empty($row['contributor_name'])?' · contributor '.h($row['contributor_name']):''?> · item <?=h(substr((string)$row['item_hash'],0,14))?>…</p></div></article><?php endforeach?></div><?php endif?>
  </section>
  <?php endif?>

  <section class="card">
    <span class="eyebrow">AUDIT EVENTS</span><h2>Dataset history</h2>
    <?php if(!$events):?><p class="empty">No dataset events.</p><?php else:?><div class="unifiedActivityList"><?php foreach($events as $e):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($e['event_type'])?></span><strong><?=h((string)($e['actor_name']?:'System'))?></strong></div><time><?=h($e['created_at'])?></time></div></div></article><?php endforeach?></div><?php endif?>
  </section>
  <?php endif?>
</main></body></html>