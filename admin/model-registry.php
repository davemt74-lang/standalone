<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if(!data_model_registry_ready($pdo)){http_response_code(503);exit('Model Registry requires the Phase 40 database upgrade.');}

$error='';$success='';
$registryId=trim((string)($_GET['registry']??$_POST['registry']??''));
$versionId=trim((string)($_GET['version']??$_POST['version']??''));
$compareIds=$_GET['compare']??[];if(!is_array($compareIds))$compareIds=[$compareIds];

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$op=(string)($_POST['op']??'');
    try{
        if($op==='create_registry'){
            $r=data_model_registry_create($pdo,$u,$_POST);header('Location:/admin/model-registry.php?registry='.rawurlencode((string)$r['public_id']));exit;
        }elseif($op==='create_version'){
            $v=data_model_version_create($pdo,$u,$registryId,$_POST);header('Location:/admin/model-registry.php?registry='.rawurlencode($registryId).'&version='.rawurlencode((string)$v['public_id']));exit;
        }elseif($op==='update_version'){
            data_model_version_update_experimental($pdo,$u,$versionId,$_POST);$success='Experimental model version updated.';
        }elseif($op==='bind_runtime'){
            data_model_version_bind_runtime($pdo,$u,$versionId,(int)($_POST['ai_model_id']??0));$success='Experimental model version runtime binding updated.';
        }elseif($op==='link_run'){
            data_model_link_evaluation($pdo,$u,$versionId,(string)($_POST['run_id']??''),(string)($_POST['note']??''));$success='Evaluation evidence linked.';
        }elseif($op==='unlink_run'){
            data_model_unlink_evaluation($pdo,$u,$versionId,(string)($_POST['run_id']??''));$success='Evaluation evidence unlinked.';
        }elseif($op==='transition'){
            data_model_transition($pdo,$u,$versionId,(string)($_POST['to_status']??''),(string)($_POST['note']??''));$success='Model lifecycle transition recorded.';
        }elseif($op==='rollback'){
            data_model_rollback($pdo,$u,$registryId,(string)($_POST['target_version_id']??''),(string)($_POST['note']??''));$success='Model Registry rollback completed.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$stats=data_model_summary($pdo);
$registries=data_model_registry_list($pdo,100);
$runtimeModels=$pdo->query("SELECT m.id,m.public_id,m.display_name,m.model_name,p.label provider_label,p.provider_type FROM ai_models m JOIN ai_providers p ON p.id=m.provider_id WHERE m.enabled=1 AND p.enabled=1 ORDER BY p.label,m.display_name")->fetchAll();
$registry=$registryId!==''?data_model_registry_get($pdo,$registryId):null;
$versions=$registry?data_model_versions($pdo,(int)$registry['id']):[];
$version=$versionId!==''?data_model_version_get($pdo,$versionId):null;
if($version&&$registry&&(int)$version['registry_id']!==(int)$registry['id'])$version=null;
$links=$version?data_model_evaluation_links($pdo,(int)$version['id']):[];
$gate=$version?data_model_gate_evaluate($pdo,$version):null;
$receipts=$version?data_model_receipts($pdo,(int)$version['id']):[];
$releaseDecisionRows=[];
if($version&&data_model_release_ready($pdo)){$q=$pdo->prepare("SELECT public_id,status,outcome,title,decision_hash,final_signature_hash,decided_at FROM data_model_release_decisions WHERE model_version_id=? ORDER BY id DESC");$q->execute([$version['id']]);$releaseDecisionRows=$q->fetchAll();}
$deploymentRows=[];
if($version&&data_model_deployment_ready($pdo)){$q=$pdo->prepare("SELECT public_id,status,current_traffic_percent,created_at,full_at,rolled_back_at FROM data_model_deployments WHERE model_version_id=? ORDER BY id DESC");$q->execute([$version['id']]);$deploymentRows=$q->fetchAll();}
$observabilityRows=[];$observabilityIncidents=[];
if($version&&data_model_observability_ready($pdo)){
    $q=$pdo->prepare("SELECT s.*,d.public_id deployment_public_id FROM data_model_health_snapshots s JOIN data_model_deployments d ON d.id=s.deployment_id WHERE s.model_version_id=? ORDER BY s.id DESC LIMIT 12");$q->execute([$version['id']]);$observabilityRows=$q->fetchAll();foreach($observabilityRows as &$orow)$orow['metrics']=json_decode((string)$orow['metrics_json'],true)?:[];unset($orow);
    $q=$pdo->prepare("SELECT i.*,d.public_id deployment_public_id FROM data_model_incidents i JOIN data_model_deployments d ON d.id=i.deployment_id WHERE i.model_version_id=? ORDER BY i.id DESC LIMIT 12");$q->execute([$version['id']]);$observabilityIncidents=$q->fetchAll();
}
$improvementRows=[];$regressionRows=[];
if($version&&data_model_improvement_ready($pdo)){
    $q=$pdo->prepare("SELECT * FROM data_model_improvement_cases WHERE model_version_id=? ORDER BY FIELD(status,'new','investigating','ready_for_evaluation','ready_for_training','resolved','no_action'),updated_at DESC LIMIT 20");$q->execute([$version['id']]);$improvementRows=$q->fetchAll();
    $regressionRows=data_model_improvement_regression_cases($pdo,(int)$version['id'],20);
}
$campaignRows=[];
if($version&&data_model_campaign_ready($pdo)){
    $q=$pdo->prepare("SELECT c.*,tj.output_model_version_id FROM data_model_improvement_campaigns c LEFT JOIN data_training_jobs tj ON tj.id=c.training_job_id WHERE c.registry_id=? AND (c.base_model_version_id=? OR tj.output_model_version_id=?) ORDER BY c.id DESC LIMIT 30");$q->execute([$version['registry_id'],$version['id'],$version['id']]);$campaignRows=$q->fetchAll();
}
$events=$registry?data_model_events($pdo,(int)$registry['id'],100):[];
$origins=data_model_origins();

$candidateRuns=[];
if($version&&$version['ai_model_id']&&!in_array($version['status'],['approved','active','deprecated','retired'],true)){
    $q=$pdo->prepare("SELECT r.public_id,r.id,r.run_hash,r.created_at,s.public_id suite_public_id,s.name suite_name,d.name dataset_name,d.version_number dataset_version FROM data_evaluation_runs r JOIN data_evaluation_suites s ON s.id=r.suite_id JOIN data_datasets d ON d.id=r.dataset_id WHERE r.status='completed' AND r.benchmark_type='model' AND r.model_id=? ORDER BY r.id DESC LIMIT 100");
    $q->execute([(int)$version['ai_model_id']]);
    foreach($q->fetchAll() as $row){$run=data_evaluation_run_get($pdo,(string)$row['public_id']);if(!$run)continue;$integrity=data_evaluation_run_integrity($pdo,$run);if($integrity['ok']){$row['integrity']=$integrity;$candidateRuns[]=$row;}}
}

$comparison=null;
if(count(array_values(array_filter($compareIds)))>=2){
    try{$comparison=data_model_comparison($pdo,$compareIds);}catch(Throwable $e){$error=$e->getMessage();}
}

if($version&&$releaseDecisionRows){
    // Phase 43 release decisions are advisory/human-governance records only; lifecycle controls remain below.
}
$allowedNext=[];
if($version){
    $allowedNext=match((string)$version['status']){
        'experimental'=>['candidate'],
        'candidate'=>['approved','retired'],
        'approved'=>['active','retired'],
        'active'=>['deprecated'],
        'deprecated'=>['retired'],
        default=>[],
    };
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Model Registry · Annotated Admin</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><?=admin_ui_sidebar('model_registry')?>
<main class="panel article">
  <div class="pageTitle"><span class="eyebrow">MODEL REGISTRY</span><h1>Candidate lifecycle & release governance</h1><p>Register model versions, attach integrity-valid Phase 39 evidence, apply explicit release gates, record approval receipts, activate governed versions, and roll back prior active versions. Registry status never silently rewrites AI task routing.</p></div>
  <div class="inlineActions"><a class="button secondary" href="/admin/evaluations.php">Evaluation Harness</a><a class="button secondary" href="/admin/ai.php">AI routing & providers</a><a class="button secondary" href="/admin/training.php">Training Registry</a><a class="button secondary" href="/admin/post-training.php">Post-Training Readiness</a><a class="button secondary" href="/admin/model-release.php">Release Decisions</a><a class="button secondary" href="/admin/model-deployment.php">Model Deployments</a><a class="button secondary" href="/admin/model-observability.php">Model Health</a><a class="button secondary" href="/admin/model-improvements.php">Model Improvements</a><a class="button secondary" href="/admin/model-campaigns.php">Improvement Campaigns</a></div>
  <?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif?><?php if($success):?><div class="notice success"><?=h($success)?></div><?php endif?>

  <section class="healthGrid">
    <article class="card"><strong><?=h((string)$stats['registries'])?></strong><p class="meta">Logical models</p></article>
    <article class="card"><strong><?=h((string)$stats['versions'])?></strong><p class="meta">Versions</p></article>
    <article class="card"><strong><?=h((string)$stats['candidates'])?></strong><p class="meta">Candidates</p></article>
    <article class="card"><strong><?=h((string)$stats['approved'])?></strong><p class="meta">Approved</p></article>
    <article class="card"><strong><?=h((string)$stats['active'])?></strong><p class="meta">Governed active</p></article>
    <article class="card"><strong><?=h((string)$stats['receipts'])?></strong><p class="meta">Promotion receipts</p></article>
  </section>

  <section class="card">
    <span class="eyebrow">CREATE MODEL</span><h2>New logical model registry</h2>
    <form method="post" class="settingsForm">
      <?=csrf_field()?><input type="hidden" name="op" value="create_registry">
      <label>Name<input name="name" maxlength="180" required placeholder="Annotated Research Model"></label>
      <label>Description<textarea name="description" rows="3" placeholder="Purpose of this logical model family."></textarea></label>
      <button class="button" type="submit">Create registry</button>
    </form>
  </section>

  <section class="card">
    <span class="eyebrow">REGISTRY</span><h2>Model families</h2>
    <?php if(!$registries):?><p class="empty">No governed model registries yet.</p><?php else:?><div class="unifiedActivityList">
      <?php foreach($registries as $r):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><strong><a href="/admin/model-registry.php?registry=<?=rawurlencode((string)$r['public_id'])?>"><?=h($r['name'])?></a></strong></div><span class="meta"><?=h((string)$r['version_count'])?> versions</span></div><p class="meta"><?=!empty($r['active_version_label'])?'Active: '.h($r['active_version_label']):'No governed active version'?></p></div></article><?php endforeach?>
    </div><?php endif?>
  </section>

  <?php if($version&&$releaseDecisionRows):?>
  <section class="card"><span class="eyebrow">PHASE 43 RELEASE DECISIONS</span><h2>Human release records for <?=h($version['version_label'])?></h2><div class="unifiedActivityList"><?php foreach($releaseDecisionRows as $rd):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($rd['status'])?></span><strong><a href="/admin/model-release.php?decision=<?=rawurlencode((string)$rd['public_id'])?>"><?=h($rd['title'])?></a></strong></div><span class="meta"><?=h((string)($rd['outcome']?:'—'))?></span></div><p class="meta"><?=!empty($rd['decision_hash'])?'Signed decision '.h(substr((string)$rd['decision_hash'],0,14)).'…':'Decision not yet signed'?></p></div></article><?php endforeach?></div><p class="meta">These records never perform the lifecycle transition shown on this page.</p></section>
  <section class="card"><span class="eyebrow">PHASE 44 DEPLOYMENTS</span><h2>Governed rollout history for <?=h($version['version_label'])?></h2><?php if(!$deploymentRows):?><p class="empty">No governed deployments for this version.</p><?php else:?><div class="unifiedActivityList"><?php foreach($deploymentRows as $dr):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($dr['status'])?></span><strong><a href="/admin/model-deployment.php?deployment=<?=rawurlencode((string)$dr['public_id'])?>">Deployment <?=h(substr((string)$dr['public_id'],0,12))?>…</a></strong></div><span class="meta"><?=h((string)$dr['current_traffic_percent'])?>% candidate traffic</span></div><p class="meta">Human-gated staged routing and rollback are controlled from the Phase 44 deployment workspace.</p></div></article><?php endforeach?></div><?php endif?></section>
  <?php endif?>

  <?php if($version&&data_model_observability_ready($pdo)):?>
  <section class="card"><span class="eyebrow">PHASE 45 PRODUCTION HEALTH</span><h2>Observed production behavior for <?=h($version['version_label'])?></h2>
    <div class="inlineActions"><a class="button secondary" href="/admin/model-observability.php">Open Model Health</a></div>
    <?php if(!$observabilityRows):?><p class="empty">No production health snapshots for this model version yet.</p><?php else:?><div class="unifiedActivityList"><?php foreach($observabilityRows as $oh):$om=$oh['metrics'];?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($oh['health_status'])?></span> <strong><a href="/admin/model-observability.php?deployment=<?=rawurlencode((string)$oh['deployment_public_id'])?>"><?=h($oh['route_key'])?> health</a></strong></div><span class="meta"><?=h($oh['created_at'])?></span></div><p class="meta">samples <?=h((string)($om['sample_count']??0))?> · failure <?=h(number_format((float)($om['failure_rate']??0)*100,1))?>% · p95 <?=h(isset($om['p95_latency_ms'])&&$om['p95_latency_ms']!==null?number_format((float)$om['p95_latency_ms'],1).' ms':'—')?> · outcome <?=h(isset($om['avg_outcome_score'])&&$om['avg_outcome_score']!==null?number_format((float)$om['avg_outcome_score'],2):'—')?></p></div></article><?php endforeach?></div><?php endif?>
    <?php if($observabilityIncidents):?><h3>Recent incidents</h3><div class="unifiedActivityList"><?php foreach($observabilityIncidents as $oi):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><span class="badge"><?=h($oi['severity'])?></span> <span class="badge"><?=h($oi['status'])?></span> <a href="/admin/model-observability.php?incident=<?=rawurlencode((string)$oi['public_id'])?>"><?=h($oi['title'])?></a></div></article><?php endforeach?></div><?php endif?>
    <p class="meta">Phase 45 presents observed evidence only. Deployment control and rollback remain explicit Phase 44 actions.</p>
  </section>
  <?php endif?>

  <?php if($version&&data_model_improvement_ready($pdo)):?>
  <section class="card"><span class="eyebrow">PHASE 46 IMPROVEMENT LOOP</span><h2>Production improvement lineage for <?=h($version['version_label'])?></h2><div class="inlineActions"><a class="button secondary" href="/admin/model-improvements.php">Open Model Improvements</a></div>
    <?php if(!$improvementRows):?><p class="empty">No clustered production improvement cases for this model version.</p><?php else:?><div class="unifiedActivityList"><?php foreach($improvementRows as $ic):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($ic['severity'])?></span> <span class="badge"><?=h($ic['status'])?></span> <strong><a href="/admin/model-improvements.php?case=<?=rawurlencode((string)$ic['public_id'])?>"><?=h($ic['title'])?></a></strong></div><span class="meta"><?=h((string)$ic['recurrence_count'])?> recurrence(s)</span></div><p class="meta"><?=h(str_replace('_',' ',$ic['classification']))?> · route <?=h($ic['route_key'])?> · evidence <?=h((string)$ic['evidence_count'])?></p></div></article><?php endforeach?></div><?php endif?>
    <h3>Regression coverage</h3><?php if(!$regressionRows):?><p class="empty">No published Phase 46 regression cases yet.</p><?php else:?><div class="unifiedActivityList"><?php foreach($regressionRows as $rr):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><strong><?=h($rr['label'])?></strong><p class="meta">route <?=h($rr['route_key'])?> · case <?=h(substr((string)$rr['case_hash'],0,16))?>…</p></div></article><?php endforeach?></div><?php endif?>
    <p class="meta">Phase 46 can publish human-sanitized examples into governed dataset eligibility. It cannot launch evaluation/training or change model lifecycle/routing.</p>
  </section>
  <?php endif?>

  <?php if($version&&data_model_campaign_ready($pdo)):?>
  <section class="card"><span class="eyebrow">PHASE 47 IMPROVEMENT CAMPAIGNS</span><h2>Closed-loop remediation lineage for <?=h($version['version_label'])?></h2><div class="inlineActions"><a class="button secondary" href="/admin/model-campaigns.php">Open Improvement Campaigns</a></div>
    <?php if(!$campaignRows):?><p class="empty">No Phase 47 campaign currently uses this version as its governed base or output.</p><?php else:?><div class="unifiedActivityList"><?php foreach($campaignRows as $mc):$role=((int)$mc['base_model_version_id']===(int)$version['id'])?'base':'output';?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($mc['status'])?></span> <span class="badge"><?=h($role)?></span> <strong><a href="/admin/model-campaigns.php?campaign=<?=rawurlencode((string)$mc['public_id'])?>"><?=h($mc['title'])?></a></strong></div><span class="meta"><?=h($mc['updated_at'])?></span></div><p class="meta"><?=h(str_replace('_',' ',$mc['strategy']))?><?=!empty($mc['plan_hash'])?' · plan '.h(substr((string)$mc['plan_hash'],0,14)).'…':' · plan not locked'?></p></div></article><?php endforeach?></div><?php endif?>
    <p class="meta">Phase 47 orchestrates governed handoffs only. Dataset freeze, evaluation execution, training queue/submission, release decisions, deployment, rollback, and AI routing remain owned by their existing phases.</p>
  </section>
  <?php endif?>

  <?php if($registry):?>
  <section class="card">
    <div class="caseHeader"><div><span class="eyebrow">MODEL FAMILY</span><h2><?=h($registry['name'])?></h2></div><?php if($registry['active_version_label']):?><span class="badge">active <?=h($registry['active_version_label'])?></span><?php endif?></div>
    <p><?=h((string)($registry['description']?:'No description.'))?></p>
    <p class="meta">Governed active status is a release-governance record. Existing <a href="/admin/ai.php">AI task routing</a> remains independently controlled.</p>

    <div class="card">
      <span class="eyebrow">REGISTER VERSION</span><h3>New experimental version</h3>
      <form method="post" class="settingsForm">
        <?=csrf_field()?><input type="hidden" name="op" value="create_version"><input type="hidden" name="registry" value="<?=h($registry['public_id'])?>">
        <label>Version label<input name="version_label" maxlength="120" required placeholder="2026.09-candidate-1"></label>
        <label>Origin<select name="origin"><?php foreach($origins as $k=>$label):?><option value="<?=h($k)?>"><?=h($label)?></option><?php endforeach?></select></label>
        <label>Runtime model<select name="ai_model_id"><option value="">None / artifact only</option><?php foreach($runtimeModels as $m):?><option value="<?=h((string)$m['id'])?>"><?=h($m['display_name'])?> · <?=h($m['provider_label'])?> · <?=h($m['model_name'])?></option><?php endforeach?></select></label>
        <label>Architecture<input name="architecture" maxlength="180" placeholder="Hosted API / transformer / etc."></label>
        <label>Intended use<textarea name="intended_use" rows="3"></textarea></label>
        <label>License<input name="license_code" maxlength="120"></label>
        <label>Source URI<input name="source_uri" maxlength="1000"></label>
        <label>Context window<input type="number" name="context_window" min="1"></label>
        <label>Artifact reference<input name="artifact_ref" maxlength="1000" placeholder="future self-hosted/model artifact URI"></label>
        <label>Artifact SHA-256<input name="artifact_hash" maxlength="64" placeholder="optional 64-character hash"></label>
        <label>Required model benchmark runs<input type="number" name="required_model_runs" min="1" max="100" value="1"></label>
        <label>Required human reviews<input type="number" name="required_human_reviews" min="1" max="1000" value="1"></label>
        <label>Minimum human pass rate<input type="number" name="minimum_human_pass_rate" min="0" max="1" step="0.01" value="0.5"></label>
        <label>Required Phase 39 suite public IDs<input name="required_suite_public_ids" placeholder="comma-separated, optional"></label>
        <label><input type="checkbox" name="require_integrity" value="1" checked> Require evaluation integrity</label>
        <label><input type="checkbox" name="require_no_regression" value="1"> Require regression baseline comparison</label>
        <label>Maximum regressed metrics<input type="number" name="max_regressed_metrics" min="0" max="50" value="0"></label>
        <button class="button" type="submit">Register experimental version</button>
      </form>
    </div>

    <div class="unifiedActivityList">
      <?php foreach($versions as $v):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($v['status'])?></span><strong><a href="/admin/model-registry.php?registry=<?=rawurlencode($registry['public_id'])?>&version=<?=rawurlencode($v['public_id'])?>"><?=h($v['version_label'])?></a></strong></div><span class="meta"><?=h((string)$v['evaluation_count'])?> eval links</span></div><p class="meta"><?=h($origins[$v['origin']]??$v['origin'])?><?=!empty($v['ai_model_name'])?' · '.h($v['ai_model_name']).' · '.h($v['provider_label']):''?><?=((int)$registry['active_version_id']===(int)$v['id'])?' · GOVERNED ACTIVE':''?></p></div></article><?php endforeach?>
    </div>

    <?php if(count($versions)>=2):?>
    <form method="get" class="settingsForm">
      <input type="hidden" name="registry" value="<?=h($registry['public_id'])?>">
      <span class="eyebrow">COMPARE</span><h3>Equivalent-benchmark evidence comparison</h3>
      <?php foreach($versions as $v):?><label><input type="checkbox" name="compare[]" value="<?=h($v['public_id'])?>" <?=in_array($v['public_id'],$compareIds,true)?'checked':''?>> <?=h($v['version_label'])?> · <?=h($v['status'])?></label><?php endforeach?>
      <button class="button secondary" type="submit">Compare selected versions</button>
    </form>
    <?php endif?>
  </section>

  <?php if($comparison):?>
  <section class="card">
    <span class="eyebrow">MODEL COMPARISON</span><h2>Equivalent benchmark evidence</h2>
    <p class="meta">This view presents comparable evidence only. It does not choose or rank a winner.</p>
    <?php if(!$comparison['common_benchmarks']):?><p class="empty">The selected versions do not yet share an integrity-valid equivalent benchmark definition.</p><?php else:?>
      <?php foreach($comparison['common_benchmarks'] as $cmp):?><div class="card"><h3>Benchmark <?=h(substr((string)$cmp['benchmark_fingerprint'],0,14))?>…</h3><p class="meta">Dataset manifest <?=h(substr((string)$cmp['dataset_manifest_hash'],0,14))?>… · case definition <?=h(substr((string)$cmp['case_definition_hash'],0,14))?>…</p><div class="unifiedActivityList">
        <?php foreach($cmp['versions'] as $cv):$sum=$cv['summary'];$human=$cv['human'];?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><strong><?=h($cv['version']['version_label'])?></strong><p class="meta">Suite <?=h((string)($cv['suite']['name']??'—'))?> · run <?=h((string)$cv['run']['public_id'])?> · pass <?=h(number_format((float)($sum['automated_pass_rate']??0)*100,1))?>% · citation <?=h(number_format((float)($sum['metrics']['expected_citation']??0)*100,1))?>% · grounded <?=h(number_format((float)($sum['metrics']['grounded_token_ratio']??0)*100,1))?>% · latency <?=h(number_format((float)($sum['metrics']['latency_ms']??0),1))?> ms</p><p class="meta">Human reviews <?=h((string)$human['reviews'])?> · pass <?=h((string)$human['pass'])?> · fail <?=h((string)$human['fail'])?> · accuracy <?=h($human['avg_accuracy']!==null?number_format($human['avg_accuracy'],2):'—')?></p></div></article><?php endforeach?>
      </div></div><?php endforeach?>
    <?php endif?>
  </section>
  <?php endif?>

  <?php if($version):?>
  <section class="card">
    <div class="caseHeader"><div><span class="eyebrow">MODEL VERSION</span><h2><?=h($version['version_label'])?></h2></div><span class="badge"><?=h($version['status'])?></span></div>
    <div class="cognitiveCardMeta">
      <span><small>Origin</small><?=h($origins[$version['origin']]??$version['origin'])?></span>
      <span><small>Runtime model</small><?=h((string)($version['ai_model_name']?:'Artifact only'))?></span>
      <span><small>Provider</small><?=h((string)($version['provider_label']?:'—'))?></span>
      <span><small>Gate policy</small><?=h(substr((string)$version['gate_policy_hash'],0,14))?>…</span>
      <span><small>Linked evaluations</small><?=h((string)count($links))?></span>
    </div>
    <p><?=h((string)($version['intended_use']?:'No intended-use statement.'))?></p>
    <p class="meta">Architecture: <?=h((string)($version['architecture']?:'—'))?> · License: <?=h((string)($version['license_code']?:'—'))?> · Context: <?=h($version['context_window']?number_format((int)$version['context_window']):'—')?></p>

    <?php if($version['status']==='experimental'):?>
      <form method="post" class="settingsForm">
        <?=csrf_field()?><input type="hidden" name="op" value="update_version"><input type="hidden" name="registry" value="<?=h($registry['public_id'])?>"><input type="hidden" name="version" value="<?=h($version['public_id'])?>">
        <label>Architecture<input name="architecture" value="<?=h((string)$version['architecture'])?>"></label>
        <label>Intended use<textarea name="intended_use" rows="3"><?=h((string)$version['intended_use'])?></textarea></label>
        <label>License<input name="license_code" value="<?=h((string)$version['license_code'])?>"></label>
        <label>Source URI<input name="source_uri" value="<?=h((string)$version['source_uri'])?>"></label>
        <label>Context window<input type="number" name="context_window" min="1" value="<?=h((string)$version['context_window'])?>"></label>
        <label>Artifact reference<input name="artifact_ref" value="<?=h((string)$version['artifact_ref'])?>"></label>
        <label>Artifact SHA-256<input name="artifact_hash" maxlength="64" value="<?=h((string)$version['artifact_hash'])?>"></label>
        <label>Required model benchmark runs<input type="number" name="required_model_runs" min="1" max="100" value="<?=h((string)$version['gate_policy']['required_model_runs'])?>"></label>
        <label>Required human reviews<input type="number" name="required_human_reviews" min="1" max="1000" value="<?=h((string)$version['gate_policy']['required_human_reviews'])?>"></label>
        <label>Minimum human pass rate<input type="number" name="minimum_human_pass_rate" min="0" max="1" step="0.01" value="<?=h((string)$version['gate_policy']['minimum_human_pass_rate'])?>"></label>
        <label>Required Phase 39 suite public IDs<input name="required_suite_public_ids" value="<?=h(implode(', ',(array)$version['gate_policy']['required_suite_public_ids']))?>"></label>
        <label><input type="checkbox" name="require_integrity" value="1" <?=$version['gate_policy']['require_integrity']?'checked':''?>> Require evaluation integrity</label>
        <label><input type="checkbox" name="require_no_regression" value="1" <?=$version['gate_policy']['require_no_regression']?'checked':''?>> Require regression baseline comparison</label>
        <label>Maximum regressed metrics<input type="number" name="max_regressed_metrics" min="0" max="50" value="<?=h((string)$version['gate_policy']['max_regressed_metrics'])?>"></label>
        <button class="button secondary" type="submit">Update experimental version</button>
      </form>
      <form method="post" class="settingsForm">
        <?=csrf_field()?><input type="hidden" name="op" value="bind_runtime"><input type="hidden" name="registry" value="<?=h($registry['public_id'])?>"><input type="hidden" name="version" value="<?=h($version['public_id'])?>">
        <label>Runtime model binding<select name="ai_model_id" required><?php foreach($runtimeModels as $m):?><option value="<?=h((string)$m['id'])?>" <?=((int)($version['ai_model_id']??0)===(int)$m['id'])?'selected':''?>><?=h($m['display_name'])?> · <?=h($m['provider_label'])?> · <?=h($m['model_name'])?></option><?php endforeach?></select></label>
        <button class="button secondary" type="submit">Bind experimental runtime model</button>
      </form>
    <?php endif?>

    <div class="card">
      <span class="eyebrow">RELEASE GATES</span><h3><?=$gate['pass']?'Gates satisfied':'Gates not satisfied'?></h3>
      <div class="cognitiveCardMeta">
        <span><small>Valid model runs</small><?=h((string)$gate['evidence']['valid_model_runs'])?> / <?=h((string)$gate['policy']['required_model_runs'])?></span>
        <span><small>Human reviews</small><?=h((string)$gate['evidence']['human_reviews'])?> / <?=h((string)$gate['policy']['required_human_reviews'])?></span>
        <span><small>Human pass rate</small><?=h(number_format((float)$gate['evidence']['human_pass_rate']*100,1))?>%</span>
        <span><small>Integrity failures</small><?=h((string)$gate['evidence']['integrity_failures'])?></span>
        <span><small>Regression comparisons</small><?=h((string)$gate['evidence']['regression_comparisons'])?></span>
        <span><small>Regressed metrics</small><?=h((string)$gate['evidence']['regressed_metrics'])?></span>
      </div>
      <?php foreach($gate['checks'] as $name=>$check):?><p class="meta"><?=h(str_replace('_',' ',$name))?>: <strong><?=!empty($check['pass'])?'PASS':'BLOCKED'?></strong></p><?php endforeach?>
    </div>

    <?php if(!in_array($version['status'],['approved','active','deprecated','retired'],true)):?>
    <div class="card">
      <span class="eyebrow">EVALUATION EVIDENCE</span><h3>Link Phase 39 model benchmark</h3>
      <?php if(!$candidateRuns):?><p class="empty">No integrity-valid completed model benchmark runs match this runtime model yet.</p><?php else:?><form method="post" class="settingsForm">
        <?=csrf_field()?><input type="hidden" name="op" value="link_run"><input type="hidden" name="registry" value="<?=h($registry['public_id'])?>"><input type="hidden" name="version" value="<?=h($version['public_id'])?>">
        <label>Evaluation run<select name="run_id"><?php foreach($candidateRuns as $rr):?><option value="<?=h($rr['public_id'])?>"><?=h($rr['suite_name'])?> · <?=h($rr['dataset_name'])?> v<?=h((string)$rr['dataset_version'])?> · <?=h($rr['public_id'])?></option><?php endforeach?></select></label>
        <label>Evidence note<textarea name="note" rows="2"></textarea></label>
        <button class="button secondary" type="submit">Link evaluation evidence</button>
      </form><?php endif?>
    </div>
    <?php endif?>

    <?php if($links):?><div class="unifiedActivityList"><?php foreach($links as $l):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge">evaluation</span><strong><a href="/admin/evaluations.php?suite=<?=rawurlencode((string)$l['suite_public_id'])?>&run=<?=rawurlencode((string)$l['run_public_id'])?>"><?=h($l['suite_name'])?> · <?=h($l['run_public_id'])?></a></strong></div><?php if(!in_array($version['status'],['approved','active','deprecated','retired'],true)):?><form method="post"><?=csrf_field()?><input type="hidden" name="op" value="unlink_run"><input type="hidden" name="registry" value="<?=h($registry['public_id'])?>"><input type="hidden" name="version" value="<?=h($version['public_id'])?>"><input type="hidden" name="run_id" value="<?=h($l['run_public_id'])?>"><button class="button secondary" type="submit">Unlink</button></form><?php endif?></div><p class="meta">Dataset <?=h($l['dataset_name'])?> · run <?=h(substr((string)$l['run_hash'],0,14))?>…</p></div></article><?php endforeach?></div><?php endif?>

    <?php if($allowedNext):?>
    <div class="card">
      <span class="eyebrow">LIFECYCLE</span><h3>Explicit status transition</h3>
      <p class="meta">Approval and activation re-evaluate current gates and create immutable promotion receipts. No score automatically changes status.</p>
      <?php foreach($allowedNext as $next):?><form method="post" class="inlineActions" onsubmit="return confirm('Transition this model version from <?=h($version['status'])?> to <?=h($next)?>?');"><?=csrf_field()?><input type="hidden" name="op" value="transition"><input type="hidden" name="registry" value="<?=h($registry['public_id'])?>"><input type="hidden" name="version" value="<?=h($version['public_id'])?>"><input type="hidden" name="to_status" value="<?=h($next)?>"><input name="note" placeholder="Decision note / rationale"><button class="button <?=$next==='approved'||$next==='active'?'':'secondary'?>" type="submit" <?=(in_array($next,['approved','active'],true)&&!$gate['pass'])?'disabled':''?>>Move to <?=h($next)?></button></form><?php endforeach?>
    </div>
    <?php endif?>

    <?php
      $rollbackTargets=array_filter($versions,fn($v)=>(int)$v['id']!==(int)($registry['active_version_id']??0)&&in_array($v['status'],['approved','deprecated'],true)&&data_model_was_active($pdo,(int)$v['id']));
      if($registry['active_version_id']&&$rollbackTargets):
    ?>
    <div class="card">
      <span class="eyebrow">ROLLBACK</span><h3>Reactivate a prior governed version</h3>
      <p class="meta">Rollback requires that the target was previously active and still satisfies its current release gates.</p>
      <form method="post" class="settingsForm" onsubmit="return confirm('Roll back the governed active model version?');">
        <?=csrf_field()?><input type="hidden" name="op" value="rollback"><input type="hidden" name="registry" value="<?=h($registry['public_id'])?>">
        <label>Prior version<select name="target_version_id"><?php foreach($rollbackTargets as $rv):?><option value="<?=h($rv['public_id'])?>"><?=h($rv['version_label'])?> · <?=h($rv['status'])?></option><?php endforeach?></select></label>
        <label>Rollback note<textarea name="note" rows="2" required></textarea></label>
        <button class="button secondary" type="submit">Rollback governed active version</button>
      </form>
    </div>
    <?php endif?>

    <section class="card">
      <span class="eyebrow">PROMOTION RECEIPTS</span><h3>Immutable decision history</h3>
      <?php if(!$receipts):?><p class="empty">No lifecycle receipts yet.</p><?php else:?><div class="unifiedActivityList"><?php foreach($receipts as $rec):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($rec['from_status'])?> → <?=h($rec['to_status'])?></span><strong><?=h($rec['actor_name'])?></strong></div><time><?=h($rec['created_at'])?></time></div><p class="meta">Integrity <strong><?=!empty($rec['integrity']['ok'])?'VALID':'FAILED'?></strong> · receipt <?=h(substr((string)$rec['receipt_hash'],0,16))?>… · gate <?=h(substr((string)$rec['gate_snapshot_hash'],0,12))?>… · evidence <?=h(substr((string)$rec['evidence_snapshot_hash'],0,12))?>…</p><?php if($rec['note']):?><p><?=h($rec['note'])?></p><?php endif?></div></article><?php endforeach?></div><?php endif?>
    </section>
  </section>
  <?php endif?>

  <section class="card">
    <span class="eyebrow">AUDIT EVENTS</span><h2>Model governance history</h2>
    <?php if(!$events):?><p class="empty">No model governance events.</p><?php else:?><div class="unifiedActivityList"><?php foreach($events as $e):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($e['event_type'])?></span><strong><?=h((string)($e['version_label']?:$registry['name']))?></strong></div><time><?=h($e['created_at'])?></time></div><p class="meta"><?=h((string)($e['actor_name']?:'System'))?></p></div></article><?php endforeach?></div><?php endif?>
  </section>
  <?php endif?>

  <section class="card"><span class="eyebrow">BOUNDARY</span><h2>No training or automatic promotion</h2><p>Phase 40 registers and governs model versions. It does not fine-tune models, create training jobs, modify weights, or automatically select a winner from benchmark scores. Existing AI task routing stays separately controlled in AI Admin.</p></section>
</main></body></html>