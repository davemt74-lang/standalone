<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
if(!research_report_studio_ready($pdo)){header('Location: /upgrade.php?from=research-reports');exit;}
if(function_exists('research_agent_ensure_default'))research_agent_ensure_default($pdo,$u);
$agents=research_agent_list($pdo,$u,50);
$selectedId=trim((string)($_REQUEST['agent']??($agents[0]['public_id']??'')));$selected=null;
foreach($agents as $a)if(hash_equals((string)$a['public_id'],$selectedId)){$selected=$a;break;}
if(!$selected&&$agents){$selected=$agents[0];$selectedId=(string)$selected['public_id'];}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    try{
        if(!$selected)throw new RuntimeException('Choose a Research Agent.');
        $op=(string)($_POST['op']??'run');
        if($op==='run'){
            $report=research_system_report_generate($pdo,$config,$u,$selectedId,(string)($_POST['report_type']??''),(string)($_POST['title']??''),false,null,$_POST,null,null,'user');
            header('Location: /research-reports.php?agent='.rawurlencode($selectedId).'&view=recent&report='.rawurlencode((string)$report['public_id']).'&created=1');exit;
        }
        if($op==='refresh'){
            $report=research_report_studio_refresh($pdo,$config,$u,$selectedId,(string)($_POST['report_id']??''));
            header('Location: /research-reports.php?agent='.rawurlencode($selectedId).'&view=recent&report='.rawurlencode((string)$report['public_id']).'&refreshed=1');exit;
        }
        if($op==='create_document'){
            $doc=research_report_studio_create_document($pdo,$u,$selectedId,(string)($_POST['report_id']??''),(array)($_POST['section_keys']??[]));
            header('Location: /research-reports.php?agent='.rawurlencode($selectedId).'&view=recent&report='.rawurlencode((string)($_POST['report_id']??'')).'&document=1');exit;
        }
        if($op==='archive'){
            research_system_report_archive($pdo,$u,(string)($_POST['report_id']??''),$selectedId);
            header('Location: /research-reports.php?agent='.rawurlencode($selectedId).'&view=recent');exit;
        }
        if($op==='save_preset'){
            $preset=research_report_studio_preset_save($pdo,$u,$selectedId,$_POST);
            header('Location: /research-reports.php?agent='.rawurlencode($selectedId).'&view=presets&preset='.rawurlencode((string)$preset['public_id']).'&saved=1');exit;
        }
        if($op==='run_preset'){
            $report=research_report_studio_run_preset($pdo,$config,$u,$selectedId,(string)($_POST['preset_id']??''),false);
            header('Location: /research-reports.php?agent='.rawurlencode($selectedId).'&view=recent&report='.rawurlencode((string)$report['public_id']).'&created=1');exit;
        }
        if($op==='archive_preset'){
            research_report_studio_preset_archive($pdo,$u,$selectedId,(string)($_POST['preset_id']??''));
            header('Location: /research-reports.php?agent='.rawurlencode($selectedId).'&view=presets');exit;
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$types=research_system_report_types();
$view=strtolower(trim((string)($_GET['view']??'run')));if(!in_array($view,['run','recent','presets'],true))$view='run';
$configuredType=trim((string)($_GET['type']??'research_brief'));if(!isset($types[$configuredType]))$configuredType='research_brief';$definition=$types[$configuredType];
$reports=$selected?research_system_report_list($pdo,$u,$selectedId,100):[];
$presets=$selected?research_report_studio_preset_list($pdo,$u,$selectedId):[];
$choices=$selected?research_report_studio_scope_choices($pdo,$u,$selectedId):['sources'=>[],'claims'=>[],'findings'=>[],'entities'=>[],'folders'=>[]];
$reportId=trim((string)($_GET['report']??''));$active=$reportId!==''?research_system_report_access($pdo,$u,$reportId):null;
if($active&&$selectedId!==''&&!hash_equals((string)$active['agent_public_id'],$selectedId))$active=null;
$freshness=$active?research_report_studio_freshness($pdo,$config,$u,$active):null;
$previous=$active?research_report_studio_previous_run($pdo,$u,$active):null;
$compareId=trim((string)($_GET['compare']??($previous['public_id']??'')));$comparison=null;
if($active&&$compareId!==''&&$compareId!==(string)$active['public_id']){try{$comparison=research_report_studio_compare($pdo,$config,$u,$compareId,(string)$active['public_id']);}catch(Throwable $ignored){}}
$conversation=(string)($selected['conversation_public_id']??'');
$selectOptions=function(array $rows): string{$html='';foreach($rows as $r)$html.='<option value="'.h((string)$r['public_id']).'">'.h((string)$r['label']).'</option>';return $html;};
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Report Studio · Annotated</title><link rel="stylesheet" href="/assets/css/app.css?v=59.0"></head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-reports">
<main class="researchLibraryCanvas researchReportsCanvas researchReportStudio">
  <section class="researchLibraryToolbar"><nav class="researchLibraryTabs researchPrimaryActions">
    <a href="/research.php">Research Agents</a>
    <a href="/research-agent-knowledge.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Knowledge</a>
    <a class="active" href="/research-reports.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Reports</a>
    <a href="/research-monitoring.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Monitoring</a>
    <a href="/research-tasks.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Tasks</a>
    <a href="/research-programs.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Programs</a>
  </nav></section>
  <header class="researchReportsHero">
    <div><span class="eyebrow">RESEARCH AGENT · REPORT STUDIO</span><h1><?=h((string)($selected['name']??'Report Studio'))?></h1><p>Run predefined processors against this Research Agent's project data. A Report Run is a preserved view of the research at one point in time; create an editable Research Document only when you want one.</p></div>
    <?php if($agents):?><label>Research Agent<select onchange="location.href='/research-reports.php?agent='+encodeURIComponent(this.value)"><?php foreach($agents as $a):?><option value="<?=h((string)$a['public_id'])?>" <?=$selectedId===(string)$a['public_id']?'selected':''?>><?=h((string)$a['name'])?></option><?php endforeach?></select></label><?php endif?>
  </header>
  <?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
  <?php if(isset($_GET['created'])):?><div class="success">Report Run created. No Research Document was created.</div><?php endif?>
  <?php if(isset($_GET['refreshed'])):?><div class="success">Fresh Report Run created from the prior configuration.</div><?php endif?>
  <?php if(isset($_GET['document'])):?><div class="success">Research Document created from this Report Run.</div><?php endif?>
  <?php if(isset($_GET['saved'])):?><div class="success">Report preset saved for this Research Agent.</div><?php endif?>
  <?php if(!$selected):?><section class="card empty"><h2>Create a Research Agent first.</h2><a class="button" href="/research.php">Open Research Agents</a></section>
  <?php else:?>
  <nav class="reportStudioTabs">
    <a class="<?=$view==='run'?'active':''?>" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=run">Run Report</a>
    <a class="<?=$view==='recent'?'active':''?>" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=recent">Recent Reports <span><?=h((string)count($reports))?></span></a>
    <a class="<?=$view==='presets'?'active':''?>" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=presets">Saved Presets <span><?=h((string)count($presets))?></span></a>
  </nav>

  <?php if($view==='run'):?>
    <section class="researchSystemReportTypes">
      <?php foreach($types as $key=>$type):?><article class="card researchSystemReportType <?=$configuredType===$key?'is-active':''?>">
        <span class="eyebrow"><?=h(strtoupper((string)$type['category']))?></span><h2><?=h((string)$type['label'])?></h2><p><?=h((string)$type['description'])?></p>
        <small>Default depth: <?=h((string)$type['default_depth'])?> · <?=h((string)count((array)$type['sections']))?> sections</small>
        <a class="button secondary" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=run&type=<?=h(rawurlencode($key))?>">Configure</a>
      </article><?php endforeach?>
    </section>
    <section class="card reportStudioBuilder">
      <div class="sectionHeadWeb"><div><span class="eyebrow">REPORT STUDIO</span><h2><?=h((string)$definition['label'])?></h2></div></div>
      <form method="post" class="reportStudioForm">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($selectedId)?>"><input type="hidden" name="report_type" value="<?=h($configuredType)?>">
        <div class="reportStudioFieldGrid">
          <label>Report title <input type="text" name="title" maxlength="240" placeholder="<?=h((string)$selected['project_title'].' — '.$definition['label'])?>"></label>
          <label>Depth <select name="depth"><option value="quick">Quick</option><option value="standard" <?=$definition['default_depth']==='standard'?'selected':''?>>Standard</option><option value="deep" <?=$definition['default_depth']==='deep'?'selected':''?>>Deep</option></select></label>
          <label class="span2">Focus / topic <input type="text" name="focus_query" maxlength="500" placeholder="Optional topic, company, question, or phrase"></label>
          <label>From <input type="date" name="date_from"></label><label>Through <input type="date" name="date_to"></label>
        </div>
        <details class="reportStudioScope"><summary>Scope this report</summary><div class="reportStudioFieldGrid">
          <label>Folders <select name="folder_ids[]" multiple size="5"><?=$selectOptions($choices['folders'])?></select></label>
          <label>Sources <select name="source_ids[]" multiple size="5"><?=$selectOptions($choices['sources'])?></select></label>
          <label>Claims <select name="claim_ids[]" multiple size="5"><?=$selectOptions($choices['claims'])?></select></label>
          <label>Findings <select name="finding_ids[]" multiple size="5"><?=$selectOptions($choices['findings'])?></select></label>
          <label>Entities <select name="entity_ids[]" multiple size="5"><?=$selectOptions($choices['entities'])?></select></label>
        </div></details>
        <fieldset class="reportStudioSections"><legend>Sections</legend><p>Leave all unchecked to use the complete predefined report.</p>
          <div><?php foreach((array)$definition['sections'] as $section):?><label><input type="checkbox" name="include_sections[]" value="<?=h((string)$section)?>"> <?=h(ucwords(str_replace('_',' ',$section)))?></label><?php endforeach?></div>
        </fieldset>
        <div class="reportStudioActions"><button class="button" type="submit" name="op" value="run">Run report</button><label class="presetName">Preset name <input type="text" name="name" maxlength="190" placeholder="e.g. Weekly competitor brief"></label><label class="presetProgram">Research Program <select name="program_id"><option value="">None — manual preset</option><?=$selectOptions($choices['programs'])?></select></label><button class="button secondary" type="submit" name="op" value="save_preset">Save preset</button></div>
      </form>
    </section>
  <?php elseif($view==='presets'):?>
    <section class="researchReportsHistory"><div class="sectionHeadWeb"><div><span class="eyebrow">SAVED PRESETS</span><h2>Reusable report configurations</h2></div><a class="button" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=run">New preset</a></div>
    <?php if(!$presets):?><div class="card empty">No saved presets for this Research Agent yet.</div><?php endif?>
    <div class="researchReportsList"><?php foreach($presets as $p):?><article class="card researchReportRow"><div><span class="eyebrow"><?=h(strtoupper((string)($types[$p['report_type']]['label']??$p['report_type'])))?></span><h3><?=h((string)$p['name'])?></h3><small><?=h((string)($p['parameters']['depth']??'standard'))?> depth<?php if(!empty($p['program_title'])):?> · Program: <?=h((string)$p['program_title'])?><?php endif?><?php if(!empty($p['last_run_at'])):?> · Last run <?=h((string)$p['last_run_at'])?><?php endif?></small></div><div class="inlineActions">
      <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($selectedId)?>"><input type="hidden" name="preset_id" value="<?=h((string)$p['public_id'])?>"><button class="button" name="op" value="run_preset">Run</button><button class="button secondary" name="op" value="archive_preset">Archive</button></form>
    </div></article><?php endforeach?></div></section>
  <?php else:?>
    <section class="researchReportsHistory">
      <div class="sectionHeadWeb"><div><span class="eyebrow">REPORT RUNS</span><h2>History for <?=h((string)$selected['name'])?></h2></div><a class="button" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=run">Run report</a></div>
      <?php if(!$reports):?><div class="card empty">No Report Runs yet.</div><?php endif?>
      <div class="researchReportsList"><?php foreach($reports as $r):?><article class="card researchReportRow <?=$active&&$active['public_id']===$r['public_id']?'is-active':''?>">
        <div><span class="eyebrow"><?=h(strtoupper((string)$r['type_label']))?> · <?=h(strtoupper((string)($r['freshness_state']??'current')))?></span><h3><?=h((string)$r['title'])?></h3><small>Data state <?=h(substr((string)$r['input_state_hash'],0,12))?> · <?=h((string)$r['created_at'])?> · <?=!empty($r['document_public_id'])?'Document created':'Report only'?></small></div>
        <nav><a href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=recent&report=<?=h(rawurlencode((string)$r['public_id']))?>">Open</a><?php if(!empty($r['document_public_id'])):?><a href="/home.php?agent=<?=h(rawurlencode($conversation))?>&doc=<?=h(rawurlencode((string)$r['document_public_id']))?>">Document</a><?php endif?></nav>
      </article><?php endforeach?></div>
    </section>
    <?php if($active):?>
      <section class="reportRunViewer">
        <header class="card reportRunHeader"><div><span class="eyebrow">REPORT RUN · <?=h(strtoupper((string)($freshness['state']??$active['freshness_state']??'current')))?></span><h2><?=h((string)$active['title'])?></h2><p><?=h((string)($active['rendered_summary']??''))?></p><small>Generated <?=h((string)$active['created_at'])?> · Data state <code><?=h(substr((string)$active['input_state_hash'],0,16))?></code> · <?=h((string)($active['generation_mode']??'user'))?></small></div>
        <div class="inlineActions"><?php if($conversation!==''):?><a class="button secondary" href="/home.php?agent=<?=h(rawurlencode($conversation))?>&agent_context_type=report&agent_context_id=<?=h(rawurlencode((string)$active['public_id']))?>">Ask Agent</a><?php endif?>
          <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($selectedId)?>"><input type="hidden" name="report_id" value="<?=h((string)$active['public_id'])?>"><button class="button" name="op" value="refresh">Refresh</button></form>
        </div></header>
        <article class="card reportRunBody"><?= (string)($active['rendered_html']??'') ?></article>
        <section class="reportRunTools">
          <article class="card"><span class="eyebrow">CREATE DOCUMENT</span><h3>Turn this Report Run into an editable Research Document</h3>
            <?php if(!empty($active['document_public_id'])):?><p>A Research Document has already been created from this run.</p><a class="button" href="/home.php?agent=<?=h(rawurlencode($conversation))?>&doc=<?=h(rawurlencode((string)$active['document_public_id']))?>">Open document</a>
            <?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($selectedId)?>"><input type="hidden" name="report_id" value="<?=h((string)$active['public_id'])?>"><p>Select sections or leave all unchecked to include the whole report.</p><div class="reportSectionPicker"><?php foreach((array)$active['sections'] as $section):?><label><input type="checkbox" name="section_keys[]" value="<?=h((string)$section['key'])?>"> <?=h((string)$section['label'])?></label><?php endforeach?></div><button class="button" name="op" value="create_document">Create Document</button></form><?php endif?>
          </article>
          <article class="card"><span class="eyebrow">PROVENANCE</span><h3>Run details</h3><div class="researchReportMetrics"><?php foreach((array)$active['metrics'] as $k=>$v):?><span><strong><?=h((string)$v)?></strong><small><?=h(str_replace('_',' ',$k))?></small></span><?php endforeach?></div><p><?=h((string)count((array)$active['evidence_refs']))?> recorded Research references.</p></article>
        </section>
        <?php if($comparison):?><section class="card reportComparison"><span class="eyebrow">WHAT CHANGED</span><h3>Compared with <?=h((string)$comparison['older']['title'])?> · <?=h((string)$comparison['older']['created_at'])?></h3>
          <div class="reportCompareGrid"><?php foreach((array)$comparison['diff'] as $bucket=>$d):?><?php if($bucket==='material_change_count'||!is_array($d))continue;?><div><strong><?=h(ucwords(str_replace('_',' ',$bucket)))?></strong><span>+<?=h((string)$d['added_count'])?> added · <?=h((string)$d['changed_count'])?> changed · -<?=h((string)$d['removed_count'])?> removed</span></div><?php endforeach?></div>
          <p><strong><?=h((string)($comparison['diff']['material_change_count']??0))?></strong> material Claim/Finding/Source changes.</p>
        </section><?php elseif($previous):?><div class="card"><a href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=recent&report=<?=h(rawurlencode((string)$active['public_id']))?>&compare=<?=h(rawurlencode((string)$previous['public_id']))?>">Compare with previous <?=h((string)$previous['type_label']??'run')?></a></div><?php endif?>
        <form method="post" class="reportArchiveForm"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($selectedId)?>"><input type="hidden" name="report_id" value="<?=h((string)$active['public_id'])?>"><button class="button secondary" name="op" value="archive">Archive Report Run</button></form>
      </section>
    <?php endif?>
  <?php endif?>
  <?php endif?>
</main></body></html>