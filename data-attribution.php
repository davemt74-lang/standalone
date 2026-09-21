<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
$error='';$success='';
if(!data_attribution_ready($pdo)){http_response_code(503);exit('Data & Attribution requires the Phase 37 database upgrade.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$op=(string)($_POST['op']??'');
    try{
        if($op==='preferences'){
            data_contributor_preferences_update($pdo,$u,[
                'allow_shared_retrieval'=>isset($_POST['allow_shared_retrieval']),
                'allow_evaluation'=>isset($_POST['allow_evaluation']),
                'allow_training'=>isset($_POST['allow_training']),
                'allow_commercial_training'=>isset($_POST['allow_commercial_training']),
                'attribution_required'=>isset($_POST['attribution_required']),
            ]);$success='Data contribution preferences updated.';
        }elseif($op==='sync'){
            $sync=data_attribution_sync_user($pdo,$u,500);$success='Contribution ledger synchronized. '.$sync['captured'].' contribution states checked.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
data_attribution_sync_user($pdo,$u,250);
$summary=data_contributor_summary($pdo,$u);$prefs=$summary['preferences'];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Data & Attribution · Annotated</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="panel article dataAttributionPage">
  <div class="pageTitle"><span class="eyebrow">DATA & ATTRIBUTION</span><h1>Your contribution ledger</h1><p>See how your Annotated work is attributed and control whether eligible public contributions may improve Annotated Intelligence. Public visibility does not automatically grant model-training permission.</p></div>
  <?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif?><?php if($success):?><div class="notice success"><?=h($success)?></div><?php endif?>

  <section class="healthGrid">
    <article class="card"><span class="eyebrow">CONTRIBUTIONS</span><h2><?=h((string)$summary['total_contributions'])?></h2><p class="meta">Ledger entries attributed to you.</p></article>
    <article class="card"><span class="eyebrow">REUSED IN ANSWERS</span><h2><?=h((string)$summary['response_uses'])?></h2><p class="meta">Recorded Agent response attributions.</p></article>
    <article class="card"><span class="eyebrow">ACTIVE CORPUS</span><h2><?=h((string)$summary['corpus_items'])?></h2><p class="meta">Eligible derived items. Production objects remain authoritative.</p></article>
    <article class="card"><span class="eyebrow">TRAINING ELIGIBLE</span><h2><?=h((string)$summary['training_eligible'])?></h2><p class="meta">Public derived items currently permitted for future training datasets.</p></article>
  </section>

  <section class="card">
    <div class="caseHeader"><div><span class="eyebrow">DEFAULT CONTRIBUTION POLICY</span><h2>How Annotated may reuse your eligible public work</h2></div></div>
    <p class="meta">These defaults apply only to supported public user-authored material such as public Annotation commentary and public Report summaries. Private and Team Research is not promoted into the shared corpus by these switches. Object-specific grants can override these defaults later.</p>
    <form method="post" class="settingsForm">
      <?=csrf_field()?><input type="hidden" name="op" value="preferences">
      <label class="settingToggle"><input type="checkbox" name="allow_shared_retrieval" value="1" <?=!empty($prefs['allow_shared_retrieval'])?'checked':''?>><span><strong>Shared intelligence retrieval</strong><small>Allow eligible public contributions to appear in Annotated's shared retrieval corpus.</small></span></label>
      <label class="settingToggle"><input type="checkbox" name="allow_evaluation" value="1" <?=!empty($prefs['allow_evaluation'])?'checked':''?>><span><strong>Model evaluation</strong><small>Allow eligible public contributions to be used in controlled quality/evaluation datasets.</small></span></label>
      <label class="settingToggle"><input type="checkbox" name="allow_training" value="1" <?=!empty($prefs['allow_training'])?'checked':''?>><span><strong>Improve Annotated models</strong><small>Allow eligible public contributions to be included in future versioned training datasets.</small></span></label>
      <label class="settingToggle"><input type="checkbox" name="allow_commercial_training" value="1" <?=!empty($prefs['allow_commercial_training'])?'checked':''?>><span><strong>Commercial model training</strong><small>Permit future commercial training use. This requires model-training permission above.</small></span></label>
      <label class="settingToggle"><input type="checkbox" name="attribution_required" value="1" <?=!empty($prefs['attribution_required'])?'checked':''?>><span><strong>Attribution required</strong><small>Keep your contributor identity attached to eligible corpus and response-lineage records.</small></span></label>
      <button class="button" type="submit">Save data preferences</button>
    </form>
  </section>

  <section class="card">
    <div class="caseHeader"><div><span class="eyebrow">LEDGER</span><h2>Contribution types</h2></div><form method="post"><?=csrf_field()?><input type="hidden" name="op" value="sync"><button class="button secondary" type="submit">Sync ledger</button></form></div>
    <?php if(!$summary['by_type']):?><p class="empty">No supported contributions have been recorded yet.</p><?php else:?><div class="cognitiveCardMeta"><?php foreach($summary['by_type'] as $type=>$count):?><span><small><?=h(ucwords(str_replace('_',' ',$type)))?></small><?=h((string)$count)?></span><?php endforeach?></div><?php endif?>
  </section>

  <section class="card">
    <span class="eyebrow">RECENT LINEAGE</span><h2>Recent contribution states</h2>
    <?php if(!$summary['recent']):?><p class="empty">No contribution states yet.</p><?php else:?><div class="unifiedActivityList"><?php foreach($summary['recent'] as $row):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($row['contribution_type'])?></span><strong><?=h($row['object_type'])?> · <?=h($row['object_public_id'])?></strong></div><time><?=h($row['created_at'])?></time></div><p class="meta"><?= $row['superseded_at']?'Superseded by a newer state · '.h($row['superseded_at']):'Current recorded state'?></p></div></article><?php endforeach?></div><?php endif?>
  </section>

  <section class="card">
    <span class="eyebrow">BOUNDARY</span><h2>What this does not mean</h2>
    <p>Annotated does not train directly from production activity. The path is <strong>production object → contribution ledger → rights/consent → derived corpus → versioned dataset → controlled model release</strong>. Revoking future use invalidates derived corpus eligibility; it does not rewrite historical audit records or pretend an already-trained model can be surgically untrained.</p>
  </section>
</main></body></html>