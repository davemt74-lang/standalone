<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if(!data_model_release_ready($pdo)){http_response_code(503);exit('Model Release Decision Workspace requires the Phase 43 database upgrade.');}
$error='';$success='';
$decisionId=trim((string)($_GET['decision']??$_POST['decision']??''));
$packetId=trim((string)($_GET['packet']??$_POST['packet']??''));

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$op=(string)($_POST['op']??'');
    try{
        if($op==='create'){
            $d=data_model_release_decision_create($pdo,$u,$packetId,$_POST);
            header('Location:/admin/model-release.php?decision='.rawurlencode((string)$d['public_id']));exit;
        }elseif($op==='update_draft'){
            data_model_release_update_draft($pdo,$u,$decisionId,$_POST);$success='Release decision draft updated.';
        }elseif($op==='check'){
            data_model_release_check_update($pdo,$u,$decisionId,(string)($_POST['check_key']??''),(string)($_POST['status']??'pending'),(string)($_POST['note']??''));$success='Risk checklist item updated. Existing reviewer signatures may now be stale if the signed snapshot changed.';
        }elseif($op==='open_review'){
            data_model_release_open_review($pdo,$u,$decisionId);$success='Release decision opened for independent review.';
        }elseif($op==='review'){
            data_model_release_review_submit($pdo,$u,$decisionId,(string)($_POST['recommendation']??''),(string)($_POST['note']??''));$success='Independent review signed.';
        }elseif($op==='finalize'){
            data_model_release_finalize($pdo,$u,$decisionId,(string)($_POST['outcome']??''),(string)($_POST['rationale']??''));$success='Final human release decision signed. Model lifecycle and routing remain unchanged.';
        }elseif($op==='archive'){
            data_model_release_archive($pdo,$u,$decisionId);$success='Signed release decision archived.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$stats=data_model_release_summary($pdo);
$decisions=data_model_release_decisions($pdo,100);
$eligible=data_model_release_eligible_packets($pdo,100);
$selectedPacket=$packetId!==''?data_model_release_packet_by_public($pdo,$packetId):null;
$selectedPlan=$selectedPacket?data_post_training_plan_get($pdo,(string)$selectedPacket['plan_public_id']):null;
$decision=$decisionId!==''?data_model_release_decision_get($pdo,$decisionId):null;
$checklist=$decision?data_model_release_checklist($pdo,(int)$decision['id']):[];
$reviews=$decision?data_model_release_reviews($pdo,(int)$decision['id']):[];
$signatures=$decision?data_model_release_signatures($pdo,(int)$decision['id']):[];
$events=$decision?data_model_release_events($pdo,(int)$decision['id'],100):[];
$context=$decision?data_model_release_current_context($pdo,$decision):null;
$plans=$decision?data_model_release_plans_complete($decision):null;
$checkSummary=$decision?data_model_release_checklist_summary($pdo,$decision):null;
$reviewSummary=$decision?data_model_release_review_summary($pdo,$decision):null;
$subjectHash=$decision?data_model_release_subject_hash($pdo,$decision):null;
$integrity=$decision&&in_array($decision['status'],['decision_recorded','archived'],true)?data_model_release_decision_integrity($pdo,$decision):null;
$finalProceed=$decision&&$decision['status']==='in_review'?data_model_release_finalize_checks($pdo,$decision,'proceed_to_governed_release'):null;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Model Release Decision · Annotated Admin</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="panel article">
  <div class="pageTitle">
    <span class="eyebrow">MODEL RELEASE DECISION</span>
    <h1>Human release review & signed decision</h1>
    <p>Review a Phase 42 readiness packet, document risk, rollout and rollback plans, collect independent reviewer signatures, and record a signed human decision. This workspace does <strong>not</strong> promote, approve, activate, or route models automatically.</p>
  </div>
  <div class="inlineActions"><a class="button secondary" href="/admin/post-training.php">Post-Training Readiness</a><a class="button secondary" href="/admin/model-registry.php">Model Registry</a><a class="button secondary" href="/admin/ai.php">AI routing & providers</a></div>
  <?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif?><?php if($success):?><div class="notice success"><?=h($success)?></div><?php endif?>

  <section class="healthGrid">
    <article class="card"><strong><?=h((string)$stats['decisions'])?></strong><p class="meta">Decision records</p></article>
    <article class="card"><strong><?=h((string)$stats['draft'])?></strong><p class="meta">Drafts</p></article>
    <article class="card"><strong><?=h((string)$stats['in_review'])?></strong><p class="meta">In review</p></article>
    <article class="card"><strong><?=h((string)$stats['recorded'])?></strong><p class="meta">Signed decisions</p></article>
    <article class="card"><strong><?=h((string)$stats['proceed'])?></strong><p class="meta">Proceed decisions</p></article>
    <article class="card"><strong><?=h((string)($stats['hold']+$stats['reject']))?></strong><p class="meta">Hold / reject</p></article>
  </section>

  <section class="card">
    <span class="eyebrow">NEW DECISION</span><h2>Select a current Phase 42 readiness packet</h2>
    <?php if(!$eligible):?><p class="empty">No unused, integrity-valid ready packet is currently eligible for a release decision.</p><?php else:?><div class="unifiedActivityList">
      <?php foreach($eligible as $row):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($row['model_status'])?></span><strong><?=h($row['version_label'])?></strong></div><a class="button secondary" href="/admin/model-release.php?packet=<?=rawurlencode((string)$row['public_id'])?>">Select packet</a></div><p class="meta"><?=h($row['registry_name'])?> · packet <?=h($row['public_id'])?> · <?=h(substr((string)$row['packet_hash'],0,14))?>…</p></div></article><?php endforeach?>
    </div><?php endif?>

    <?php if($selectedPacket&&$selectedPlan):?>
    <div class="card">
      <span class="eyebrow">CREATE RELEASE DECISION</span><h3><?=h($selectedPlan['output_version_label'])?></h3>
      <p class="meta">Readiness packet <?=h($selectedPacket['public_id'])?> · integrity <?=data_post_training_packet_integrity($selectedPacket)['ok']?'VALID':'FAILED'?> · Phase 42 plan <?=h($selectedPlan['status'])?></p>
      <form method="post" class="settingsForm">
        <?=csrf_field()?><input type="hidden" name="op" value="create"><input type="hidden" name="packet" value="<?=h($selectedPacket['public_id'])?>">
        <label>Decision title<input name="title" maxlength="190" value="Release decision · <?=h($selectedPlan['output_version_label'])?>" required></label>
        <label>Decision summary<textarea name="decision_summary" rows="3" placeholder="What release is being considered and why?"></textarea></label>
        <label>Independent reviewers required<input type="number" name="required_reviewers" min="1" max="5" value="1"></label>
        <label>Rollout strategy<select name="rollout_strategy"><option value="shadow">Shadow</option><option value="canary" selected>Canary</option><option value="limited">Limited</option><option value="full">Full</option></select></label>
        <label>Target tasks / routing scope<textarea name="target_tasks" rows="3" required placeholder="Which Agent/model tasks should use this version if the separate routing change is approved?"></textarea></label>
        <label>Initial traffic percent<input type="number" name="initial_traffic_percent" min="0" max="100" value="10"></label>
        <label>Monitoring window, minutes<input type="number" name="monitoring_window_minutes" min="15" max="10080" value="120"></label>
        <label>Success criteria<textarea name="success_criteria" rows="3" required></textarea></label>
        <label>Monitoring / release owner<input name="monitoring_owner" maxlength="255" required></label>
        <label>Change window<input name="change_window" maxlength="500" placeholder="Planned maintenance/release window"></label>
        <label>Routing notes<textarea name="routing_notes" rows="3"></textarea></label>
        <label>Rollback model version<input name="rollback_model_version_public_id" maxlength="40" value="<?=h($selectedPlan['baseline_version_public_id'])?>" required></label>
        <label>Rollback trigger conditions<textarea name="rollback_trigger_conditions" rows="3" required></textarea></label>
        <label>Rollback steps<textarea name="rollback_steps" rows="3" required></textarea></label>
        <label>Recovery target, minutes<input type="number" name="recovery_target_minutes" min="1" max="10080" value="30"></label>
        <label>Rollback owner<input name="rollback_owner" maxlength="255" required></label>
        <label>Rollback validation steps<textarea name="rollback_validation_steps" rows="3" required></textarea></label>
        <button class="button" type="submit">Create decision workspace</button>
      </form>
    </div>
    <?php endif?>
  </section>

  <section class="card">
    <span class="eyebrow">DECISIONS</span><h2>Release decision history</h2>
    <?php if(!$decisions):?><p class="empty">No release decisions yet.</p><?php else:?><div class="unifiedActivityList">
      <?php foreach($decisions as $row):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($row['status'])?></span><strong><a href="/admin/model-release.php?decision=<?=rawurlencode((string)$row['public_id'])?>"><?=h($row['title'])?></a></strong></div><time><?=h($row['updated_at'])?></time></div><p class="meta"><?=h($row['registry_name'])?> · <?=h($row['model_version_label'])?> · outcome <?=h((string)($row['outcome']?:'—'))?> · reviewers <?=h((string)$row['review_count'])?> · checklist <?=h((string)$row['required_pass_count'])?>/<?=h((string)$row['required_check_count'])?></p></div></article><?php endforeach?>
    </div><?php endif?>
  </section>

  <?php if($decision):?>
  <section class="card">
    <div class="caseHeader"><div><span class="eyebrow">DECISION DETAIL</span><h2><?=h($decision['title'])?></h2></div><span class="badge"><?=h($decision['status'])?></span></div>
    <div class="cognitiveCardMeta">
      <span><small>Model</small><?=h($decision['model_version_label'])?> · <?=h($decision['model_status'])?></span>
      <span><small>Baseline</small><?=h($decision['baseline_version_label'])?></span>
      <span><small>Packet</small><?=h($decision['packet_public_id'])?></span>
      <span><small>Creator</small><?=h($decision['creator_name'])?></span>
      <span><small>Required reviewers</small><?=h((string)$decision['required_reviewers'])?></span>
      <span><small>Subject hash</small><?=h(substr((string)$subjectHash,0,14))?>…</span>
    </div>
    <?php if($decision['decision_summary']):?><p><?=h($decision['decision_summary'])?></p><?php endif?>
    <div class="notice <?=$context&&$context['pass']?'success':'error'?>"><strong>CURRENT RELEASE CONTEXT <?=$context&&$context['pass']?'VALID':'BLOCKED'?></strong><br>Readiness packet, model identity, baseline identity and rollback target are revalidated before a final decision can be signed.</div>
    <?php foreach(($context['checks']??[]) as $name=>$ok):?><p class="meta"><?=h(str_replace('_',' ',$name))?>: <strong><?=$ok?'PASS':'BLOCKED'?></strong></p><?php endforeach?>

    <?php if($decision['status']==='draft'):?>
    <section class="card">
      <span class="eyebrow">RELEASE PLAN</span><h3>Rollout & rollback</h3>
      <form method="post" class="settingsForm">
        <?=csrf_field()?><input type="hidden" name="op" value="update_draft"><input type="hidden" name="decision" value="<?=h($decision['public_id'])?>">
        <label>Decision title<input name="title" maxlength="190" value="<?=h($decision['title'])?>" required></label>
        <label>Decision summary<textarea name="decision_summary" rows="3"><?=h((string)$decision['decision_summary'])?></textarea></label>
        <label>Independent reviewers required<input type="number" name="required_reviewers" min="1" max="5" value="<?=h((string)$decision['required_reviewers'])?>"></label>
        <label>Rollout strategy<select name="rollout_strategy"><?php foreach(['shadow','canary','limited','full'] as $v):?><option value="<?=h($v)?>" <?=$decision['deployment_plan']['rollout_strategy']===$v?'selected':''?>><?=h(ucfirst($v))?></option><?php endforeach?></select></label>
        <label>Target tasks / routing scope<textarea name="target_tasks" rows="3"><?=h((string)$decision['deployment_plan']['target_tasks'])?></textarea></label>
        <label>Initial traffic percent<input type="number" name="initial_traffic_percent" min="0" max="100" value="<?=h((string)$decision['deployment_plan']['initial_traffic_percent'])?>"></label>
        <label>Monitoring window, minutes<input type="number" name="monitoring_window_minutes" min="15" max="10080" value="<?=h((string)$decision['deployment_plan']['monitoring_window_minutes'])?>"></label>
        <label>Success criteria<textarea name="success_criteria" rows="3"><?=h((string)$decision['deployment_plan']['success_criteria'])?></textarea></label>
        <label>Monitoring / release owner<input name="monitoring_owner" value="<?=h((string)$decision['deployment_plan']['monitoring_owner'])?>"></label>
        <label>Change window<input name="change_window" value="<?=h((string)$decision['deployment_plan']['change_window'])?>"></label>
        <label>Routing notes<textarea name="routing_notes" rows="3"><?=h((string)$decision['deployment_plan']['routing_notes'])?></textarea></label>
        <label>Rollback model version<input name="rollback_model_version_public_id" value="<?=h((string)$decision['rollback_plan']['target_model_version_public_id'])?>"></label>
        <label>Rollback trigger conditions<textarea name="rollback_trigger_conditions" rows="3"><?=h((string)$decision['rollback_plan']['trigger_conditions'])?></textarea></label>
        <label>Rollback steps<textarea name="rollback_steps" rows="3"><?=h((string)$decision['rollback_plan']['rollback_steps'])?></textarea></label>
        <label>Recovery target, minutes<input type="number" name="recovery_target_minutes" min="1" max="10080" value="<?=h((string)$decision['rollback_plan']['recovery_target_minutes'])?>"></label>
        <label>Rollback owner<input name="rollback_owner" value="<?=h((string)$decision['rollback_plan']['rollback_owner'])?>"></label>
        <label>Rollback validation steps<textarea name="rollback_validation_steps" rows="3"><?=h((string)$decision['rollback_plan']['validation_steps'])?></textarea></label>
        <button class="button secondary" type="submit">Update release plan</button>
      </form>
      <p class="meta">Deployment plan complete: <strong><?=$plans&&$plans['deployment_complete']?'YES':'NO'?></strong> · rollback plan complete: <strong><?=$plans&&$plans['rollback_complete']?'YES':'NO'?></strong></p>
    </section>
    <?php endif?>

    <section class="card">
      <span class="eyebrow">MODEL-RISK CHECKLIST</span><h3>Human attestations</h3>
      <p class="meta">For a Proceed decision, every required item must be marked PASS. Updating an item after a reviewer signed changes the subject hash and makes that review stale until re-signed.</p>
      <div class="unifiedActivityList">
        <?php foreach($checklist as $item):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($item['category'])?></span><strong><?=h($item['label'])?></strong></div><span class="badge"><?=h($item['status'])?></span></div><p><?=h((string)$item['description'])?></p><?php if($item['reviewer_name']):?><p class="meta">Reviewed by <?=h($item['reviewer_name'])?> · <?=h((string)$item['reviewed_at'])?></p><?php endif?>
        <?php if(in_array($decision['status'],['draft','in_review'],true)):?><form method="post" class="settingsForm"><?=csrf_field()?><input type="hidden" name="op" value="check"><input type="hidden" name="decision" value="<?=h($decision['public_id'])?>"><input type="hidden" name="check_key" value="<?=h($item['check_key'])?>"><label>Status<select name="status"><option value="pending" <?=$item['status']==='pending'?'selected':''?>>Pending</option><option value="pass" <?=$item['status']==='pass'?'selected':''?>>Pass</option><option value="fail" <?=$item['status']==='fail'?'selected':''?>>Fail</option><?php if(!(int)$item['required']):?><option value="not_applicable" <?=$item['status']==='not_applicable'?'selected':''?>>Not applicable</option><?php endif?></select></label><label>Note<textarea name="note" rows="2"><?=h((string)$item['note'])?></textarea></label><button class="button secondary" type="submit">Save checklist item</button></form><?php endif?>
        </div></article><?php endforeach?>
      </div>
      <p class="meta">Required checklist: <?=h((string)$checkSummary['passed'])?> / <?=h((string)$checkSummary['required'])?> pass · failed <?=h((string)$checkSummary['failed'])?> · pending <?=h((string)$checkSummary['pending'])?></p>
    </section>

    <?php if($decision['status']==='draft'):?>
      <form method="post" onsubmit="return confirm('Open this release decision for independent review? Rollout and rollback plan fields become immutable.');"><?=csrf_field()?><input type="hidden" name="op" value="open_review"><input type="hidden" name="decision" value="<?=h($decision['public_id'])?>"><button class="button" type="submit" <?=!($context&&$context['pass']&&$plans&&$plans['pass'])?'disabled':''?>>Open independent review</button></form>
    <?php endif?>

    <section class="card">
      <span class="eyebrow">INDEPENDENT REVIEW</span><h3>Signed reviewer recommendations</h3>
      <p class="meta">Current reviews <?=h((string)count($reviewSummary['current']))?> / <?=h((string)$reviewSummary['required'])?> required · stale <?=h((string)count($reviewSummary['stale']))?> · proceed <?=h((string)$reviewSummary['counts']['proceed'])?> · hold <?=h((string)$reviewSummary['counts']['hold'])?> · reject <?=h((string)$reviewSummary['counts']['reject'])?>.</p>
      <?php if(!$reviews):?><p class="empty">No independent reviewer has signed yet.</p><?php else:?><div class="unifiedActivityList"><?php foreach($reviews as $rv):$ri=data_model_release_review_integrity($pdo,$decision,$rv);?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($rv['recommendation'])?></span><strong><?=h($rv['reviewer_name'])?></strong></div><span class="badge"><?=$ri['ok']?'CURRENT':'STALE'?></span></div><p><?=h((string)($rv['note']?:'No reviewer note.'))?></p><p class="meta">Snapshot <?=h(substr((string)$rv['decision_snapshot_hash'],0,14))?>… · signature <?=h(substr((string)$rv['signature_hash'],0,14))?>… · <?=h($rv['signed_at'])?></p></div></article><?php endforeach?></div><?php endif?>
      <?php if($decision['status']==='in_review'&&(int)$u['id']!==(int)$decision['created_by_user_id']):?>
      <form method="post" class="settingsForm"><?=csrf_field()?><input type="hidden" name="op" value="review"><input type="hidden" name="decision" value="<?=h($decision['public_id'])?>"><label>Recommendation<select name="recommendation"><option value="proceed">Proceed</option><option value="hold">Hold</option><option value="reject">Reject</option></select></label><label>Reviewer note<textarea name="note" rows="3" required></textarea></label><button class="button" type="submit">Sign independent review</button></form>
      <?php elseif($decision['status']==='in_review'):?><div class="notice">You created this decision. Another administrator must sign the independent review.</div><?php endif?>
    </section>

    <?php if($decision['status']==='in_review'):?>
    <section class="card">
      <span class="eyebrow">FINAL HUMAN DECISION</span><h3>Record signed outcome</h3>
      <div class="notice <?=$finalProceed&&$finalProceed['pass']?'success':'error'?>"><strong>PROCEED GATE <?=$finalProceed&&$finalProceed['pass']?'READY':'BLOCKED'?></strong><br>A Proceed outcome requires current lineage, complete deployment/rollback plans, all required checklist items passing, and enough current independent Proceed reviews with no current Hold/Reject recommendation.</div>
      <?php foreach(($finalProceed['checks']??[]) as $name=>$ok):?><p class="meta"><?=h(str_replace('_',' ',$name))?>: <strong><?=$ok?'PASS':'BLOCKED'?></strong></p><?php endforeach?>
      <form method="post" class="settingsForm" onsubmit="return confirm('Sign and record this final human release decision? This records the decision only; it will not change model lifecycle or production routing.');"><?=csrf_field()?><input type="hidden" name="op" value="finalize"><input type="hidden" name="decision" value="<?=h($decision['public_id'])?>"><label>Outcome<select name="outcome"><option value="proceed_to_governed_release">Proceed to governed release</option><option value="hold">Hold</option><option value="reject">Reject</option></select></label><label>Final rationale<textarea name="rationale" rows="5" required></textarea></label><button class="button" type="submit">Sign final decision</button></form>
    </section>
    <?php endif?>

    <?php if(in_array($decision['status'],['decision_recorded','archived'],true)):?>
    <section class="card">
      <span class="eyebrow">SIGNED DECISION</span><h3><?=h(str_replace('_',' ',ucwords((string)$decision['outcome'],'_')))?></h3>
      <div class="notice <?=$integrity&&$integrity['ok']?'success':'error'?>"><strong>DECISION INTEGRITY <?=$integrity&&$integrity['ok']?'VALID':'FAILED'?></strong><br>Decision <?=h(substr((string)$decision['decision_hash'],0,18))?>… · signature <?=h(substr((string)$decision['final_signature_hash'],0,18))?>… · signed by <?=h((string)$decision['final_signer_name'])?> at <?=h((string)$decision['final_signed_at'])?>.</div>
      <p><?=nl2br(h((string)$decision['rationale']))?></p>
      <p class="meta">The signed record documents a human decision. Current model status is still <strong><?=h($decision['model_status'])?></strong>. Any lifecycle or routing action remains separate.</p>
      <div class="inlineActions">
        <form method="post" action="/admin/model-release-export.php"><?=csrf_field()?><input type="hidden" name="decision_id" value="<?=h($decision['public_id'])?>"><button class="button secondary" type="submit">Export signed decision JSON</button></form>
        <?php if($decision['outcome']==='proceed_to_governed_release'):?><a class="button" href="/admin/model-deployment.php?release=<?=rawurlencode((string)$decision['public_id'])?>">Open Governed Deployment</a><a class="button secondary" href="/admin/model-registry.php?registry=<?=rawurlencode((string)$decision['registry_public_id'])?>&version=<?=rawurlencode((string)$decision['model_version_public_id'])?>">Review Model Registry</a><?php endif?>
        <a class="button secondary" href="/admin/ai.php">Review baseline AI routing</a>
      </div>
      <?php if($decision['status']==='decision_recorded'):?><form method="post" onsubmit="return confirm('Archive this signed decision?');"><?=csrf_field()?><input type="hidden" name="op" value="archive"><input type="hidden" name="decision" value="<?=h($decision['public_id'])?>"><button class="button secondary" type="submit">Archive signed decision</button></form><?php endif?>
    </section>
    <?php endif?>

    <section class="card">
      <span class="eyebrow">SIGNATURES</span><h3>Cryptographic-style audit hashes</h3>
      <?php if(!$signatures):?><p class="empty">No signatures yet.</p><?php else:?><div class="unifiedActivityList"><?php foreach($signatures as $sig):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($sig['signature_type'])?></span><strong><?=h($sig['signer_name'])?></strong></div><time><?=h($sig['signed_at'])?></time></div><p class="meta">Subject <?=h(substr((string)$sig['subject_hash'],0,14))?>… · signature <?=h(substr((string)$sig['signature_hash'],0,14))?>…</p></div></article><?php endforeach?></div><?php endif?>
    </section>

    <section class="card">
      <span class="eyebrow">AUDIT EVENTS</span><h3>Release decision history</h3>
      <?php if(!$events):?><p class="empty">No release-decision events.</p><?php else:?><div class="unifiedActivityList"><?php foreach($events as $e):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($e['event_type'])?></span><strong><?=h((string)($e['actor_name']?:'System'))?></strong></div><time><?=h($e['created_at'])?></time></div></div></article><?php endforeach?></div><?php endif?>
    </section>
  </section>
  <?php endif?>
</main></body></html>