<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$u=require_admin($pdo);
if(!data_evaluation_ready($pdo)){http_response_code(503);exit('Evaluation Harness requires the Phase 39 database upgrade.');}
$error='';$success='';$suiteId=trim((string)($_GET['suite']??$_POST['suite']??''));$runId=trim((string)($_GET['run']??$_POST['run']??''));$itemQ=trim((string)($_GET['item_q']??''));
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$op=(string)($_POST['op']??'');
    try{
        if($op==='create_suite'){
            $s=data_evaluation_suite_create($pdo,$u,$_POST);header('Location:/admin/evaluations.php?suite='.rawurlencode((string)$s['public_id']));exit;
        }elseif($op==='update_suite'){
            data_evaluation_suite_update($pdo,$u,$suiteId,$_POST);$success='Evaluation suite draft updated.';
        }elseif($op==='add_case'){
            data_evaluation_case_add($pdo,$u,$suiteId,$_POST);$success='Benchmark case added.';
        }elseif($op==='delete_case'){
            data_evaluation_case_delete($pdo,$u,$suiteId,(string)($_POST['case_id']??''));$success='Benchmark case removed.';
        }elseif($op==='activate'){
            data_evaluation_suite_activate($pdo,$u,$suiteId);$success='Evaluation suite activated and frozen for reproducible runs.';
        }elseif($op==='queue_run'){
            $run=data_evaluation_run_queue($pdo,$u,$suiteId);header('Location:/admin/evaluations.php?suite='.rawurlencode($suiteId).'&run='.rawurlencode((string)$run['public_id']));exit;
        }elseif($op==='set_baseline'){
            data_evaluation_set_baseline($pdo,$u,$suiteId,(string)($_POST['run_id']??''));$success='Completed run set as the suite regression baseline.';
        }elseif($op==='review'){
            data_evaluation_review_save($pdo,$u,(int)($_POST['result_id']??0),$_POST);$success='Human evaluation review saved.';
        }elseif($op==='requeue_run'){
            $rq=data_evaluation_run_requeue($pdo,$u,(string)($_POST['run_id']??''));$runId=(string)$rq['public_id'];$success='Evaluation run requeued.';
        }elseif($op==='retire'){
            data_evaluation_suite_retire($pdo,$u,$suiteId);$success='Evaluation suite retired.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$stats=data_evaluation_summary_global($pdo);
$suites=data_evaluation_suite_list($pdo,100);
$datasets=$pdo->query("SELECT id,public_id,name,version_number,item_count,manifest_hash FROM data_datasets WHERE status='frozen' AND purpose='evaluation' ORDER BY id DESC")->fetchAll();
$models=$pdo->query("SELECT m.id,m.display_name,m.model_name,p.label provider_label FROM ai_models m JOIN ai_providers p ON p.id=m.provider_id WHERE m.enabled=1 AND m.admin_enabled=1 AND p.enabled=1 ORDER BY p.label,m.display_name")->fetchAll();
$selected=$suiteId!==''?data_evaluation_suite_get($pdo,$suiteId):null;
$cases=$selected?data_evaluation_cases($pdo,(int)$selected['id']):[];
$runs=$selected?data_evaluation_runs($pdo,(int)$selected['id'],50):[];
$events=$selected?data_evaluation_events($pdo,(int)$selected['id'],80):[];
$run=$runId!==''?data_evaluation_run_get($pdo,$runId):null;if($run&&$selected&&(int)$run['suite_id']!==(int)$selected['id'])$run=null;
$runModelSnapshot=$run&&$run['model_snapshot_json']?json_decode((string)$run['model_snapshot_json'],true):null;
$results=$run&&$run['status']==='completed'?data_evaluation_results($pdo,(int)$run['id']):[];
$human=$run&&$run['status']==='completed'?data_evaluation_human_summary($pdo,(int)$run['id']):null;
$runIntegrity=$run&&$run['status']==='completed'?data_evaluation_run_integrity($pdo,$run):null;
$regression=$run&&$selected?data_evaluation_regression($pdo,$selected,$run):null;
$reviewsByResult=[];if($run){foreach(data_evaluation_reviews_for_run($pdo,(int)$run['id']) as $rv)$reviewsByResult[(int)$rv['result_id']]=$rv;}
$itemRows=[];
if($selected&&$selected['status']==='draft'){
    $sql='SELECT id,position,corpus_public_id,source_object_type,source_object_public_id,corpus_type,LEFT(normalized_text_snapshot,220) preview FROM data_dataset_items WHERE dataset_id=?';$params=[(int)$selected['dataset_id']];
    if($itemQ!==''){$sql.=' AND (corpus_public_id LIKE ? OR source_object_public_id LIKE ? OR normalized_text_snapshot LIKE ?)';$like='%'.$itemQ.'%';array_push($params,$like,$like,$like);}
    $sql.=' ORDER BY position LIMIT 200';$q=$pdo->prepare($sql);$q->execute($params);$itemRows=$q->fetchAll();
}
$types=data_evaluation_types();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Evaluation Harness · Annotated Admin</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><?=admin_ui_sidebar('evaluations')?>
<main class="panel article">
  <div class="pageTitle"><span class="eyebrow">EVALUATION HARNESS</span><h1>Dataset benchmarks & regression baselines</h1><p>Evaluate retrieval and configured inference models against frozen, rights-valid evaluation datasets. Automated scores are diagnostic; human review remains authoritative for quality judgments.</p></div>
  <?php if($error):?><div class="notice error"><?=h($error)?></div><?php endif?><?php if($success):?><div class="notice success"><?=h($success)?></div><?php endif?>

  <section class="healthGrid">
    <article class="card"><strong><?=h((string)$stats['suites'])?></strong><p class="meta">Suites</p></article>
    <article class="card"><strong><?=h((string)$stats['active_suites'])?></strong><p class="meta">Active suites</p></article>
    <article class="card"><strong><?=h((string)$stats['queued_runs'])?></strong><p class="meta">Queued runs</p></article>
    <article class="card"><strong><?=h((string)$stats['completed_runs'])?></strong><p class="meta">Completed runs</p></article>
    <article class="card"><strong><?=h((string)$stats['failed_runs'])?></strong><p class="meta">Failed runs</p></article>
    <article class="card"><strong><?=h((string)$stats['human_reviews'])?></strong><p class="meta">Human reviews</p></article>
  </section>

  <section class="card">
    <div class="caseHeader"><div><span class="eyebrow">CREATE SUITE</span><h2>New benchmark suite</h2></div><div class="inlineActions"><a class="button secondary" href="/admin/datasets.php">Dataset Registry</a><a class="button secondary" href="/admin/model-registry.php">Model Registry</a><a class="button secondary" href="/admin/post-training.php">Post-Training Readiness</a></div></div>
    <?php if(!$datasets):?><p class="empty">Create and freeze a dataset with purpose <strong>Evaluation</strong> before creating a benchmark suite.</p><?php else:?>
    <form method="post" class="settingsForm">
      <?=csrf_field()?><input type="hidden" name="op" value="create_suite">
      <label>Name<input name="name" maxlength="180" required placeholder="Annotated Retrieval Baseline"></label>
      <label>Evaluation dataset<select name="dataset_id" required><?php foreach($datasets as $d):?><option value="<?=h((string)$d['id'])?>"><?=h($d['name'])?> · v<?=h((string)$d['version_number'])?> · <?=h((string)$d['item_count'])?> items</option><?php endforeach?></select></label>
      <label>Benchmark type<select name="benchmark_type"><option value="retrieval">Retrieval benchmark</option><option value="model">Model response benchmark</option></select></label>
      <label>Top K<input type="number" name="top_k" min="1" max="20" value="5"></label>
      <label>Model for model benchmark<select name="model_id"><option value="">None / retrieval only</option><?php foreach($models as $m):?><option value="<?=h((string)$m['id'])?>"><?=h($m['display_name'])?> · <?=h($m['provider_label'])?></option><?php endforeach?></select></label>
      <label>Model benchmark instruction<textarea name="prompt_template" rows="4" placeholder="<?=h(data_evaluation_default_prompt())?>"></textarea></label>
      <label>Description<textarea name="description" rows="3"></textarea></label>
      <button class="button" type="submit">Create evaluation suite</button>
    </form>
    <?php endif?>
  </section>

  <section class="card">
    <span class="eyebrow">SUITES</span><h2>Benchmark registry</h2>
    <?php if(!$suites):?><p class="empty">No evaluation suites yet.</p><?php else:?><div class="unifiedActivityList">
      <?php foreach($suites as $s):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($s['status'])?></span><strong><a href="/admin/evaluations.php?suite=<?=rawurlencode((string)$s['public_id'])?>"><?=h($s['name'])?></a></strong></div><time><?=h($s['updated_at'])?></time></div><p class="meta"><?=h($types[$s['benchmark_type']]['label']??$s['benchmark_type'])?> · <?=h($s['dataset_name'])?> v<?=h((string)$s['dataset_version'])?><?=!empty($s['model_name'])?' · '.h($s['model_name']):''?></p></div></article><?php endforeach?>
    </div><?php endif?>
  </section>

  <?php if($selected):?>
  <section class="card">
    <div class="caseHeader"><div><span class="eyebrow">SUITE DETAIL</span><h2><?=h($selected['name'])?></h2></div><span class="badge"><?=h($selected['status'])?></span></div>
    <div class="cognitiveCardMeta">
      <span><small>Type</small><?=h($types[$selected['benchmark_type']]['label']??$selected['benchmark_type'])?></span>
      <span><small>Dataset</small><?=h($selected['dataset_name'])?> v<?=h((string)$selected['dataset_version'])?></span>
      <span><small>Cases</small><?=h((string)count($cases))?></span>
      <span><small>Top K</small><?=h((string)$selected['top_k'])?></span>
      <span><small>Config hash</small><?=h(substr((string)$selected['config_hash'],0,14))?>…</span>
      <span><small>Baseline</small><?=h($selected['baseline_run_id']?'Run #'.(string)$selected['baseline_run_id']:'None')?></span>
    </div>
    <p><?=h((string)($selected['description']?:'No description.'))?></p>

    <?php if($selected['status']==='draft'):?>
      <form method="post" class="settingsForm">
        <?=csrf_field()?><input type="hidden" name="op" value="update_suite"><input type="hidden" name="suite" value="<?=h($selected['public_id'])?>">
        <label>Name<input name="name" maxlength="180" required value="<?=h($selected['name'])?>"></label>
        <label>Benchmark type<select name="benchmark_type"><option value="retrieval" <?=$selected['benchmark_type']==='retrieval'?'selected':''?>>Retrieval benchmark</option><option value="model" <?=$selected['benchmark_type']==='model'?'selected':''?>>Model response benchmark</option></select></label>
        <label>Top K<input type="number" name="top_k" min="1" max="20" value="<?=h((string)$selected['top_k'])?>"></label>
        <label>Model<select name="model_id"><option value="">None / retrieval only</option><?php foreach($models as $m):?><option value="<?=h((string)$m['id'])?>" <?=((string)$selected['model_id']===(string)$m['id'])?'selected':''?>><?=h($m['display_name'])?> · <?=h($m['provider_label'])?></option><?php endforeach?></select></label>
        <label>Model benchmark instruction<textarea name="prompt_template" rows="4"><?=h((string)($selected['prompt_template']??''))?></textarea></label>
        <label>Description<textarea name="description" rows="3"><?=h((string)($selected['description']??''))?></textarea></label>
        <button class="button secondary" type="submit">Update suite draft</button>
      </form>

      <div class="card">
        <div class="caseHeader"><div><span class="eyebrow">BENCHMARK CASES</span><h3>Add expected evidence cases</h3></div><form method="get" class="inlineActions"><input type="hidden" name="suite" value="<?=h($selected['public_id'])?>"><input name="item_q" value="<?=h($itemQ)?>" placeholder="Search frozen dataset items"><button class="button secondary" type="submit">Search items</button></form></div>
        <form method="post" class="settingsForm">
          <?=csrf_field()?><input type="hidden" name="op" value="add_case"><input type="hidden" name="suite" value="<?=h($selected['public_id'])?>">
          <label>Case label<input name="label" maxlength="180" required placeholder="Phoenix expansion evidence"></label>
          <label>Benchmark question<textarea name="query_text" rows="3" required></textarea></label>
          <label>Expected frozen item<select name="expected_dataset_item_id" required><?php foreach($itemRows as $row):?><option value="<?=h((string)$row['id'])?>">#<?=h((string)((int)$row['position']+1))?> · <?=h($row['corpus_type'])?> · <?=h($row['source_object_public_id'])?> · <?=h(mb_substr((string)$row['preview'],0,90))?></option><?php endforeach?></select></label>
          <label>Reference answer (optional; used only for transparent lexical model metric)<textarea name="reference_answer" rows="3"></textarea></label>
          <label>Weight<input type="number" name="weight" min="0.01" max="100" step="0.01" value="1"></label>
          <label>Tags<input name="tags" placeholder="company, expansion, phoenix"></label>
          <button class="button" type="submit" <?=!$itemRows?'disabled':''?>>Add case</button>
        </form>
        <?php if(!$itemRows):?><p class="empty">No matching frozen dataset items were found.</p><?php endif?>
      </div>

      <?php if($cases):?><div class="unifiedActivityList"><?php foreach($cases as $c):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge">#<?=h((string)((int)$c['position']+1))?></span><strong><?=h($c['label'])?></strong></div><form method="post" onsubmit="return confirm('Delete this benchmark case?');"><?=csrf_field()?><input type="hidden" name="op" value="delete_case"><input type="hidden" name="suite" value="<?=h($selected['public_id'])?>"><input type="hidden" name="case_id" value="<?=h($c['public_id'])?>"><button class="button secondary" type="submit">Delete</button></form></div><p><?=h($c['query_text'])?></p><p class="meta">Expected: <?=h($c['expected_corpus_public_id'])?> · <?=h($c['expected_source_type'])?> <?=h($c['expected_source_public_id'])?> · case <?=h(substr((string)$c['case_hash'],0,12))?>…</p></div></article><?php endforeach?></div><?php endif?>

      <form method="post" onsubmit="return confirm('Activate this suite? Configuration and benchmark cases become immutable for reproducible future runs.');"><?=csrf_field()?><input type="hidden" name="op" value="activate"><input type="hidden" name="suite" value="<?=h($selected['public_id'])?>"><button class="button" type="submit" <?=!$cases?'disabled':''?>>Activate evaluation suite</button></form>
    <?php elseif($selected['status']==='active'):?>
      <div class="inlineActions">
        <form method="post"><?=csrf_field()?><input type="hidden" name="op" value="queue_run"><input type="hidden" name="suite" value="<?=h($selected['public_id'])?>"><button class="button" type="submit">Queue benchmark run</button></form>
        <form method="post" onsubmit="return confirm('Retire this suite? Existing runs and reviews remain auditable.');"><?=csrf_field()?><input type="hidden" name="op" value="retire"><input type="hidden" name="suite" value="<?=h($selected['public_id'])?>"><button class="button secondary" type="submit">Retire suite</button></form>
      </div>
      <p class="meta">Queued runs are processed by <code>php bin/evaluation-worker.php</code>. Model benchmarks send currently eligible frozen evaluation context to the selected configured inference provider. They do not create training jobs or modify model weights.</p>
    <?php else:?><p class="notice">This suite is retired. Historical runs, results, baselines, and human reviews remain available.</p><?php endif?>
  </section>

  <section class="card">
    <span class="eyebrow">RUNS</span><h2>Evaluation history</h2>
    <?php if(!$runs):?><p class="empty">No runs yet.</p><?php else:?><div class="unifiedActivityList"><?php foreach($runs as $rr):$sum=$rr['summary'];?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($rr['status'])?></span><strong><a href="/admin/evaluations.php?suite=<?=rawurlencode($selected['public_id'])?>&run=<?=rawurlencode($rr['public_id'])?>"><?=h($rr['public_id'])?></a></strong></div><time><?=h($rr['created_at'])?></time></div><p class="meta"><?=h($rr['benchmark_type'])?><?=!empty($rr['model_name'])?' · '.h($rr['model_name']):''?><?=is_array($sum)?' · pass '.h(number_format((float)$sum['automated_pass_rate']*100,1)).'% · '.h((string)$sum['case_count']).' cases':''?><?=((int)$selected['baseline_run_id']===(int)$rr['id'])?' · BASELINE':''?></p><?php if($rr['status']==='failed'):?><p class="error"><?=h((string)$rr['error_text'])?></p><?php endif?></div></article><?php endforeach?></div><?php endif?>
  </section>

  <?php if($run):?>
  <section class="card">
    <div class="caseHeader"><div><span class="eyebrow">RUN DETAIL</span><h2><?=h($run['public_id'])?></h2></div><span class="badge"><?=h($run['status'])?></span></div>
    <div class="cognitiveCardMeta">
      <span><small>Dataset manifest</small><?=h(substr((string)$run['dataset_manifest_hash'],0,14))?>…</span>
      <span><small>Suite config</small><?=h(substr((string)$run['suite_config_hash'],0,14))?>…</span>
      <span><small>Cases</small><?=h(substr((string)$run['cases_hash'],0,14))?>…</span>
      <span><small>Runner</small><?=h($run['runner'])?></span>
      <?php if(is_array($runModelSnapshot)):?><span><small>Queued model</small><?=h((string)($runModelSnapshot['display_name']??$runModelSnapshot['model_name']??'Model'))?> · <?=h((string)($runModelSnapshot['provider_label']??''))?></span><span><small>Endpoint fingerprint</small><?=h(substr((string)($runModelSnapshot['api_base_hash']??''),0,14))?>…</span><?php endif?>
      <?php if(!empty($run['run_hash'])):?><span><small>Run hash</small><?=h(substr((string)$run['run_hash'],0,14))?>…</span><?php endif?>
      <?php if(is_array($run['summary'])):?><span><small>Automated pass</small><?=h(number_format((float)$run['summary']['automated_pass_rate']*100,1))?>%</span><?php endif?>
      <?php if($human):?><span><small>Human reviews</small><?=h((string)$human['reviews'])?></span><?php endif?>
    </div>
    <?php if($run['status']==='queued'):?><p class="notice">Queued for the evaluation worker.</p><?php elseif($run['status']==='processing'):?><p class="notice">Evaluation worker is processing this run.</p><?php elseif($run['status']==='failed'):?><p class="error"><?=h((string)$run['error_text'])?></p><?php endif?>
    <?php $staleProcessing=$run['status']==='processing'&&!empty($run['started_at'])&&strtotime((string)$run['started_at'])<time()-900;if($run['status']==='failed'||$staleProcessing):?><form method="post" class="inlineActions"><?=csrf_field()?><input type="hidden" name="op" value="requeue_run"><input type="hidden" name="suite" value="<?=h($selected['public_id'])?>"><input type="hidden" name="run" value="<?=h($run['public_id'])?>"><input type="hidden" name="run_id" value="<?=h($run['public_id'])?>"><button class="button secondary" type="submit">Requeue run</button></form><?php endif?>
    <?php if($run['status']==='completed'):?>
      <div class="notice <?=$runIntegrity&&$runIntegrity['ok']?'success':'error'?>"><strong>RUN INTEGRITY <?=$runIntegrity&&$runIntegrity['ok']?'VALID':'FAILED'?></strong><br><?=h(str_replace('_',' ',(string)($runIntegrity['reason']??'unknown')))?><?=isset($runIntegrity['invalid_results'])?' · '.h((string)$runIntegrity['invalid_results']).' invalid result'.((int)$runIntegrity['invalid_results']===1?'':'s'):''?>.</div>
      <div class="inlineActions">
        <form method="post"><?=csrf_field()?><input type="hidden" name="op" value="set_baseline"><input type="hidden" name="suite" value="<?=h($selected['public_id'])?>"><input type="hidden" name="run_id" value="<?=h($run['public_id'])?>"><button class="button secondary" type="submit" <?=!($runIntegrity&&$runIntegrity['ok'])?'disabled':''?>><?=((int)$selected['baseline_run_id']===(int)$run['id'])?'Current baseline':'Set as regression baseline'?></button></form>
        <form method="post" action="/admin/evaluation-export.php"><?=csrf_field()?><input type="hidden" name="run_id" value="<?=h($run['public_id'])?>"><button class="button secondary" type="submit">Export benchmark JSON</button></form>
      </div>
      <?php if($regression):?><div class="notice <?=!($regression['available']??true)||$regression['regressed_metrics']>0?'error':'success'?>"><strong>BASELINE COMPARISON</strong><br><?php if(!($regression['available']??true)):?>Unavailable: <?=h(str_replace('_',' ',(string)$regression['reason']))?>.<?php else:?><?=h((string)$regression['regressed_metrics'])?> metric<?=((int)$regression['regressed_metrics']===1?'':'s')?> declined by more than 2 percentage points. Automated comparison is diagnostic, not a release verdict.<?php endif?></div><?php endif?>
      <?php if(is_array($run['summary'])&&!empty($run['summary']['metrics'])):$score=$run['summary']['metrics'];?>
      <div class="card"><span class="eyebrow">AUTOMATED SCORECARD</span><div class="cognitiveCardMeta">
        <span><small>Hit @1</small><?=h(number_format((float)($score['hit_at_1']??0)*100,1))?>%</span>
        <span><small>Hit @3</small><?=h(number_format((float)($score['hit_at_3']??0)*100,1))?>%</span>
        <span><small>MRR</small><?=h(number_format((float)($score['mrr']??0),3))?></span>
        <?php if(isset($score['expected_citation'])):?><span><small>Expected citation</small><?=h(number_format((float)$score['expected_citation']*100,1))?>%</span><span><small>Grounded token ratio</small><?=h(number_format((float)($score['grounded_token_ratio']??0)*100,1))?>%</span><span><small>Reference F1</small><?=h(number_format((float)($score['reference_token_f1']??0),3))?></span><span><small>Avg input tokens</small><?=h(number_format((float)($score['input_tokens']??0),0))?></span><span><small>Avg output tokens</small><?=h(number_format((float)($score['output_tokens']??0),0))?></span><?php endif?>
        <span><small>Avg latency</small><?=h(number_format((float)($score['latency_ms']??0),1))?> ms</span>
      </div><p class="meta">Automated model metrics are transparent heuristics. Human review remains the authoritative quality assessment.</p></div>
      <?php endif?>
      <?php if($human):?><div class="cognitiveCardMeta"><span><small>Human pass</small><?=h((string)$human['pass'])?></span><span><small>Human fail</small><?=h((string)$human['fail'])?></span><span><small>Needs work</small><?=h((string)$human['needs_work'])?></span><span><small>Avg relevance</small><?=h($human['avg_relevance']!==null?number_format($human['avg_relevance'],2):'—')?></span><span><small>Avg groundedness</small><?=h($human['avg_groundedness']!==null?number_format($human['avg_groundedness'],2):'—')?></span><span><small>Avg accuracy</small><?=h($human['avg_accuracy']!==null?number_format($human['avg_accuracy'],2):'—')?></span></div><?php endif?>
    <?php endif?>
  </section>

  <?php if($results):?><section class="card"><span class="eyebrow">CASE RESULTS</span><h2>Per-case evidence</h2><div class="unifiedActivityList">
    <?php foreach($results as $res):$rv=$reviewsByResult[(int)$res['id']]??null;?><article class="unifiedActivityItem"><div class="unifiedActivityMain">
      <div class="unifiedActivityHead"><div><span class="badge"><?=$res['passed']?'auto pass':'auto needs work'?></span><strong><?=h($res['label'])?></strong></div><span class="meta">Expected rank <?=h($res['rank_of_expected']!==null?(string)$res['rank_of_expected']:'—')?></span></div>
      <p><strong>Question:</strong> <?=h($res['query_text'])?></p>
      <p class="meta">Expected item: <?=h($res['expected_corpus_public_id'])?> · MRR <?=h(number_format((float)($res['metrics']['mrr']??0),3))?><?php if(isset($res['metrics']['expected_citation'])):?> · citation <?=h((string)$res['metrics']['expected_citation'])?> · grounded <?=h(number_format((float)$res['metrics']['grounded_token_ratio']*100,1))?>%<?php if(isset($res['metrics']['reference_token_f1'])):?> · reference F1 <?=h(number_format((float)$res['metrics']['reference_token_f1'],3))?><?php endif?><?php endif?></p>
      <?php if($res['response_text']):?><details><summary>Model response</summary><p><?=nl2br(h((string)$res['response_text']))?></p></details><?php endif?>
      <details><summary>Retrieved items</summary><?php foreach($res['retrieved'] as $hit):?><p class="meta">#<?=h((string)$hit['rank'])?> · <?=h($hit['corpus_public_id'])?> · score <?=h(number_format((float)$hit['score'],3))?></p><?php endforeach?></details>
      <form method="post" class="settingsForm">
        <?=csrf_field()?><input type="hidden" name="op" value="review"><input type="hidden" name="suite" value="<?=h($selected['public_id'])?>"><input type="hidden" name="run" value="<?=h($run['public_id'])?>"><input type="hidden" name="result_id" value="<?=h((string)$res['id'])?>">
        <label>Human decision<select name="decision"><option value="needs_work" <?=($rv['decision']??'')==='needs_work'?'selected':''?>>Needs work</option><option value="pass" <?=($rv['decision']??'')==='pass'?'selected':''?>>Pass</option><option value="fail" <?=($rv['decision']??'')==='fail'?'selected':''?>>Fail</option></select></label>
        <label>Relevance 1–5<input type="number" name="relevance_score" min="1" max="5" value="<?=h((string)($rv['relevance_score']??''))?>"></label>
        <label>Groundedness 1–5<input type="number" name="groundedness_score" min="1" max="5" value="<?=h((string)($rv['groundedness_score']??''))?>"></label>
        <label>Accuracy 1–5<input type="number" name="accuracy_score" min="1" max="5" value="<?=h((string)($rv['accuracy_score']??''))?>"></label>
        <label>Review note<textarea name="note" rows="2"><?=h((string)($rv['note']??''))?></textarea></label>
        <button class="button secondary" type="submit">Save human review</button>
      </form>
    </div></article><?php endforeach?>
  </div></section><?php endif?>
  <?php endif?>

  <section class="card">
    <span class="eyebrow">AUDIT EVENTS</span><h2>Evaluation history</h2>
    <?php if(!$events):?><p class="empty">No evaluation events.</p><?php else:?><div class="unifiedActivityList"><?php foreach($events as $e):?><article class="unifiedActivityItem"><div class="unifiedActivityMain"><div class="unifiedActivityHead"><div><span class="badge"><?=h($e['event_type'])?></span><strong><?=h((string)($e['actor_name']?:'Worker / system'))?></strong></div><time><?=h($e['created_at'])?></time></div><p class="meta"><?=!empty($e['run_public_id'])?'Run '.h($e['run_public_id']):'Suite event'?></p></div></article><?php endforeach?></div><?php endif?>
  </section>
  <?php endif?>

  <section class="card"><span class="eyebrow">RUNNER</span><h2>Evaluation worker</h2><p>Queued retrieval and model benchmarks are processed outside the web request with:</p><p><code>php bin/evaluation-worker.php 5</code></p><p class="meta">Model benchmarks call the selected configured inference model. They never create training jobs, fine-tune, or change model weights.</p></section>
</main></body></html>