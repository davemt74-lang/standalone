<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
if(!research_system_reports_ready($pdo)){header('Location: /upgrade.php?from=research-reports');exit;}
if(function_exists('research_agent_ensure_default'))research_agent_ensure_default($pdo,$u);
$agents=research_agent_list($pdo,$u,50);
$selectedId=trim((string)($_REQUEST['agent']??($agents[0]['public_id']??'')));$selected=null;
foreach($agents as $a)if(hash_equals((string)$a['public_id'],$selectedId)){$selected=$a;break;}
if(!$selected&&$agents){$selected=$agents[0];$selectedId=(string)$selected['public_id'];}
$error='';$success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    try{
        $op=(string)($_POST['op']??'generate');
        if(!$selected)throw new RuntimeException('Choose a Research Agent.');
        if($op==='generate'){
            $report=research_system_report_generate($pdo,$config,$u,$selectedId,(string)($_POST['report_type']??''),(string)($_POST['title']??''),false);
            header('Location: /research-reports.php?agent='.rawurlencode($selectedId).'&report='.rawurlencode((string)$report['public_id']).'&created=1');exit;
        }
        if($op==='archive'){
            $report=research_system_report_archive($pdo,$u,(string)($_POST['report_id']??''),$selectedId);
            header('Location: /research-reports.php?agent='.rawurlencode($selectedId));exit;
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$types=research_system_report_types();$reports=$selected?research_system_report_list($pdo,$u,$selectedId,100):[];
$reportId=trim((string)($_GET['report']??''));$active=$reportId!==''?research_system_report_access($pdo,$u,$reportId):null;
$conversation=(string)($selected['conversation_public_id']??'');
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>System Reports · Annotated</title><link rel="stylesheet" href="/assets/css/app.css?v=59.0"></head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-reports">
<main class="researchLibraryCanvas researchReportsCanvas">
  <section class="researchLibraryToolbar"><nav class="researchLibraryTabs researchPrimaryActions">
    <a href="/research.php">Research Agents</a>
    <a href="/research-agent-knowledge.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Knowledge</a>
    <a class="active" href="/research-reports.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Reports</a>
    <a href="/research-monitoring.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Monitoring</a>
    <a href="/research-tasks.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Tasks</a>
    <a href="/research-programs.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Programs</a>
  </nav></section>
  <header class="researchReportsHero">
    <div><span class="eyebrow">RESEARCH AGENT · SYSTEM REPORTS</span><h1>Process the same research in different ways</h1><p>System Reports transform the Research Agent's current authorized evidence and structured knowledge into versioned Research Docs. They never replace the underlying Sources, Claims, Findings, or provenance.</p></div>
    <?php if($agents):?><label>Research Agent<select onchange="location.href='/research-reports.php?agent='+encodeURIComponent(this.value)"><?php foreach($agents as $a):?><option value="<?=h((string)$a['public_id'])?>" <?=$selectedId===(string)$a['public_id']?'selected':''?>><?=h((string)$a['name'])?></option><?php endforeach?></select></label><?php endif?>
  </header>
  <?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
  <?php if(isset($_GET['created'])):?><div class="success">System Report created and added to the Research Agent workspace.</div><?php endif?>
  <?php if(!$selected):?><section class="card empty"><h2>Create a Research Agent first.</h2><a class="button" href="/research.php">Open Research Agents</a></section>
  <?php else:?>
  <section class="researchSystemReportTypes">
    <?php foreach($types as $key=>$type):?>
    <article class="card researchSystemReportType">
      <span class="eyebrow"><?=h(strtoupper((string)$type['category']))?></span>
      <h2><?=h((string)$type['label'])?></h2><p><?=h((string)$type['description'])?></p>
      <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($selectedId)?>"><input type="hidden" name="op" value="generate"><input type="hidden" name="report_type" value="<?=h($key)?>">
        <label>Optional title<input type="text" name="title" maxlength="240" placeholder="<?=h((string)$selected['project_title'].' — '.$type['label'])?>"></label>
        <button class="button" type="submit">Create report</button>
      </form>
    </article>
    <?php endforeach?>
  </section>
  <section class="researchReportsHistory">
    <div class="sectionHeadWeb"><div><span class="eyebrow">REPORT HISTORY</span><h2><?=h((string)$selected['name'])?></h2></div><div class="inlineActions"><a class="button secondary" href="/research-agent-knowledge.php?agent=<?=h(rawurlencode($selectedId))?>">Knowledge</a><?php if($conversation!==''):?><a class="button secondary" href="/home.php?agent=<?=h(rawurlencode($conversation))?>&workspace=library">Library</a><?php endif?></div></div>
    <?php if(!$reports):?><div class="card empty">No System Reports yet. Choose a report type above to process the current Research Agent data.</div><?php endif?>
    <div class="researchReportsList"><?php foreach($reports as $r):?>
      <article class="card researchReportRow <?=$active&&$active['public_id']===$r['public_id']?'is-active':''?>">
        <div><span class="eyebrow"><?=h(strtoupper((string)$r['type_label']))?></span><h3><?=h((string)$r['title'])?></h3><small>Data state <?=h(substr((string)$r['input_state_hash'],0,12))?> · Doc v<?=h((string)$r['document_revision'])?> · <?=h((string)$r['created_at'])?></small></div>
        <nav><a href="/home.php?agent=<?=h(rawurlencode($conversation))?>&doc=<?=h(rawurlencode((string)$r['document_public_id']))?>">Open report</a><a href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&report=<?=h(rawurlencode((string)$r['public_id']))?>">Details</a></nav>
      </article>
    <?php endforeach?></div>
  </section>
  <?php if($active):?>
  <aside class="card researchReportDetail"><span class="eyebrow">REPORT PROVENANCE</span><h2><?=h((string)$active['title'])?></h2>
    <p>Type: <?=h((string)($types[$active['report_type']]['label']??$active['report_type']))?><br>Data-state hash: <code><?=h((string)$active['input_state_hash'])?></code></p>
    <div class="researchReportMetrics"><?php foreach((array)$active['metrics'] as $k=>$v):?><span><strong><?=h((string)$v)?></strong><small><?=h(str_replace('_',' ',$k))?></small></span><?php endforeach?></div>
    <p><?=h((string)count((array)$active['evidence_refs']))?> authoritative Research references were recorded with this report.</p>
    <div class="inlineActions"><a class="button" href="/home.php?agent=<?=h(rawurlencode($conversation))?>&doc=<?=h(rawurlencode((string)$active['document_public_id']))?>">Open versioned document</a></div>
  </aside>
  <?php endif?>
  <?php endif?>
</main></body></html>