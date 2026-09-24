<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);$error='';$success='';$sourceId=trim((string)($_GET['source_id']??$_POST['source_id']??''));$rights=$sourceId!==''?data_source_rights($pdo,$sourceId):null;
if(!data_attribution_ready($pdo)){http_response_code(503);exit('Data & Attribution requires the Phase 37 database upgrade.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();try{$rights=data_source_rights_set($pdo,$u,$sourceId,$_POST);$success='Source rights classification saved.';}catch(Throwable $e){$error=$e->getMessage();}
}
$stats=data_global_summary($pdo);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Data Governance · Annotated Admin</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><?=admin_ui_sidebar('data_attribution')?>
<main class="panel article">
  <div class="pageTitle"><span class="eyebrow">DATA GOVERNANCE</span><h1>Data & Attribution</h1><p>Govern source rights, corpus eligibility, contribution lineage, and response attribution without changing authoritative Annotated objects.</p></div>
  <?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif?><?php if($success):?><div class="notice success"><?=h($success)?></div><?php endif?>
  <section class="healthGrid">
    <article class="card"><strong><?=h((string)$stats['contributions'])?></strong><p class="meta">Contribution states</p></article>
    <article class="card"><strong><?=h((string)$stats['edges'])?></strong><p class="meta">Active provenance edges</p></article>
    <article class="card"><strong><?=h((string)$stats['corpus_active'])?></strong><p class="meta">Active derived corpus items</p></article>
    <article class="card"><strong><?=h((string)$stats['corpus_training'])?></strong><p class="meta">Training-eligible corpus items</p></article>
    <article class="card"><strong><?=h((string)$stats['response_lineage'])?></strong><p class="meta">AI responses with lineage</p></article>
    <article class="card"><strong><?=h((string)$stats['attributions'])?></strong><p class="meta">Response attributions</p></article>
  </section>
  <?php if(function_exists('data_dataset_ready')&&data_dataset_ready($pdo)):$datasetStats=data_dataset_summary($pdo);?>
  <section class="card">
    <div class="caseHeader"><div><span class="eyebrow">DATASET REGISTRY</span><h2>Frozen datasets</h2></div><a class="button secondary" href="/admin/datasets.php">Open Dataset Registry</a></div>
    <div class="cognitiveCardMeta"><span><small>Datasets</small><?=h((string)$datasetStats['datasets'])?></span><span><small>Drafts</small><?=h((string)$datasetStats['drafts'])?></span><span><small>Frozen</small><?=h((string)$datasetStats['frozen'])?></span><span><small>Frozen items</small><?=h((string)$datasetStats['frozen_items'])?></span></div>
    <p class="meta">Dataset Registry turns governed corpus items into reproducible snapshots. Rights and consent remain authoritative for whether a frozen dataset may still be used or exported with text.</p>
  </section>
  <?php endif?>

  <section class="card">
    <span class="eyebrow">SOURCE RIGHTS</span><h2>Review a Source</h2>
    <form method="get" class="inlineActions"><input name="source_id" value="<?=h($sourceId)?>" placeholder="Source public ID" required><button class="button secondary" type="submit">Load source</button></form>
    <?php if($sourceId!==''&&!$rights):?><p class="error">Source not found.</p><?php endif?>
    <?php if($rights):?>
      <p><strong><?=h($rights['title']?:$rights['domain'])?></strong><br><span class="meta"><?=h($rights['canonical_url'])?></span></p>
      <form method="post" class="settingsForm">
        <?=csrf_field()?><input type="hidden" name="source_id" value="<?=h($sourceId)?>">
        <label>Rights class<select name="rights_class"><?php foreach(['unknown','user_owned','licensed','public_domain','open_license','permission_granted','restricted'] as $v):?><option value="<?=h($v)?>" <?=($rights['rights_class']??'unknown')===$v?'selected':''?>><?=h(ucwords(str_replace('_',' ',$v)))?></option><?php endforeach?></select></label>
        <label>License code<input name="license_code" value="<?=h((string)($rights['license_code']??''))?>" placeholder="CC-BY-4.0, contract ID, etc."></label>
        <label>Rights holder<input name="rights_holder" value="<?=h((string)($rights['rights_holder']??''))?>"></label>
        <label>Policy / permission URL<input type="url" name="policy_url" value="<?=h((string)($rights['policy_url']??''))?>"></label>
        <label class="settingToggle"><input type="checkbox" name="retrieval_allowed" value="1" <?=!empty($rights['retrieval_allowed'])?'checked':''?>><span><strong>Retrieval allowed</strong><small>Source may participate in shared retrieval when storage/context are also allowed.</small></span></label>
        <label class="settingToggle"><input type="checkbox" name="excerpt_storage_allowed" value="1" <?=!empty($rights['excerpt_storage_allowed'])?'checked':''?>><span><strong>Excerpt storage allowed</strong><small>Derived corpus may store a bounded text representation.</small></span></label>
        <label class="settingToggle"><input type="checkbox" name="model_context_allowed" value="1" <?=!empty($rights['model_context_allowed'])?'checked':''?>><span><strong>Model context allowed</strong><small>Approved source text may be supplied as shared model context.</small></span></label>
        <label class="settingToggle"><input type="checkbox" name="training_allowed" value="1" <?=!empty($rights['training_allowed'])?'checked':''?>><span><strong>Training allowed</strong><small>Approved source text may enter a future versioned training dataset.</small></span></label>
        <label class="settingToggle"><input type="checkbox" name="commercial_training_allowed" value="1" <?=!empty($rights['commercial_training_allowed'])?'checked':''?>><span><strong>Commercial training allowed</strong><small>Requires training permission.</small></span></label>
        <button class="button" type="submit">Save source rights</button>
      </form>
      <p class="meta">Unknown and restricted classifications force all reuse permissions off. Source text is never made training eligible merely because it is visible on the public web.</p>
    <?php endif?>
  </section>
</main></body></html>