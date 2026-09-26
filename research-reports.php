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
$deliveryReady=function_exists('research_intelligence_delivery_ready')&&research_intelligence_delivery_ready($pdo);$error='';
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
            research_report_studio_create_document($pdo,$u,$selectedId,(string)($_POST['report_id']??''),(array)($_POST['section_keys']??[]));
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
        if($op==='create_subscription'){
            if(!$deliveryReady)throw new RuntimeException('Research Intelligence Delivery requires the latest database upgrade.');
            $sub=research_report_subscription_create($pdo,$u,$selectedId,$_POST,false);
            header('Location: /research-reports.php?agent='.rawurlencode($selectedId).'&view=subscriptions&subscription='.rawurlencode((string)$sub['public_id']).'&subscribed=1');exit;
        }
        if($op==='update_subscription'){
            $sub=research_report_subscription_update($pdo,$u,(string)($_POST['subscription_id']??''),$_POST,false);
            header('Location: /research-reports.php?agent='.rawurlencode($selectedId).'&view=subscriptions&subscription='.rawurlencode((string)$sub['public_id']).'&updated=1');exit;
        }
        if($op==='set_subscription_status'){
            $sub=research_report_subscription_set_status($pdo,$u,(string)($_POST['subscription_id']??''),(string)($_POST['status']??''),false);
            header('Location: /research-reports.php?agent='.rawurlencode($selectedId).'&view=subscriptions&subscription='.rawurlencode((string)$sub['public_id']).'&updated=1');exit;
        }
        if($op==='deliver_now'){
            $delivery=research_intelligence_delivery_run_manual($pdo,$config,$u,(string)($_POST['subscription_id']??''));
            $deliveryId=(string)($delivery['delivery_id']??'');
            header('Location: /research-reports.php?agent='.rawurlencode($selectedId).'&view=inbox'.($deliveryId!==''?'&delivery='.rawurlencode($deliveryId):'').'&delivered=1');exit;
        }
        if($op==='mark_delivery_viewed'){
            $delivery=research_report_delivery_mark_viewed($pdo,$u,(string)($_POST['delivery_id']??''));
            header('Location: /research-reports.php?agent='.rawurlencode($selectedId).'&view=inbox&delivery='.rawurlencode((string)$delivery['public_id']).'&reviewed=1');exit;
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$types=research_system_report_types();
$views=['run','recent','presets'];if($deliveryReady)$views=array_merge($views,['subscriptions','inbox']);
$view=strtolower(trim((string)($_GET['view']??'run')));if(!in_array($view,$views,true))$view='run';
$configuredType=trim((string)($_GET['type']??'research_brief'));if(!isset($types[$configuredType]))$configuredType='research_brief';$definition=$types[$configuredType];
$reports=$selected?research_system_report_list($pdo,$u,$selectedId,100):[];
$presets=$selected?research_report_studio_preset_list($pdo,$u,$selectedId):[];
$choices=$selected?research_report_studio_scope_choices($pdo,$u,$selectedId):['sources'=>[],'claims'=>[],'findings'=>[],'entities'=>[],'folders'=>[],'programs'=>[]];
$subscriptions=$deliveryReady&&$selected?research_report_subscription_list($pdo,$u,$selectedId,100,false):[];
$deliveries=$deliveryReady&&$selected?research_report_delivery_list($pdo,$u,$selectedId,100,true):[];
$newDeliveryCount=count(array_filter($deliveries,fn($d)=>($d['status']??'')==='delivered'));
$reportId=trim((string)($_GET['report']??''));$active=$reportId!==''?research_system_report_access($pdo,$u,$reportId):null;
if($active&&$selectedId!==''&&!hash_equals((string)$active['agent_public_id'],$selectedId))$active=null;
$freshness=$active?research_report_studio_freshness($pdo,$config,$u,$active):null;
$previous=$active?research_report_studio_previous_run($pdo,$u,$active):null;
$compareId=trim((string)($_GET['compare']??($previous['public_id']??'')));$comparison=null;
if($active&&$compareId!==''&&$compareId!==(string)$active['public_id']){try{$comparison=research_report_studio_compare($pdo,$config,$u,$compareId,(string)$active['public_id']);}catch(Throwable $ignored){}}
$selectedSubscription=trim((string)($_GET['subscription']??''));$activeSubscription=$selectedSubscription!==''&&$deliveryReady?research_report_subscription_access($pdo,$u,$selectedSubscription):null;
$selectedDelivery=trim((string)($_GET['delivery']??''));$activeDelivery=$selectedDelivery!==''&&$deliveryReady?research_report_delivery_access($pdo,$u,$selectedDelivery):null;
$presetChoice=trim((string)($_GET['preset']??''));$programChoice=trim((string)($_GET['program']??''));
if($presetChoice!==''&&$programChoice===''){foreach($presets as $p)if((string)$p['public_id']===$presetChoice&&!empty($p['program_public_id'])){$programChoice=(string)$p['program_public_id'];break;}}
$conversation=(string)($selected['conversation_public_id']??'');
$selectOptions=function(array $rows,string $selectedValue=''): string{$html='';foreach($rows as $r){$value=(string)$r['public_id'];$html.='<option value="'.h($value).'"'.($selectedValue!==''&&hash_equals($selectedValue,$value)?' selected':'').'>'.h((string)$r['label']).'</option>';}return $html;};
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
    <div><span class="eyebrow">RESEARCH AGENT · REPORT STUDIO</span><h1><?=h((string)($selected['name']??'Report Studio'))?></h1><p>Run reports, subscribe to recurring intelligence from this Research Agent, review material changes, and create an editable Research Document only when you choose.</p></div>
    <?php if($agents):?><label>Research Agent<select onchange="location.href='/research-reports.php?agent='+encodeURIComponent(this.value)"><?php foreach($agents as $a):?><option value="<?=h((string)$a['public_id'])?>" <?=$selectedId===(string)$a['public_id']?'selected':''?>><?=h((string)$a['name'])?></option><?php endforeach?></select></label><?php endif?>
  </header>
  <?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
  <?php if(isset($_GET['created'])):?><div class="success">Report Run created. No Research Document was created.</div><?php endif?>
  <?php if(isset($_GET['refreshed'])):?><div class="success">Fresh Report Run created from the prior configuration.</div><?php endif?>
  <?php if(isset($_GET['document'])):?><div class="success">Research Document created from this Report Run.</div><?php endif?>
  <?php if(isset($_GET['saved'])):?><div class="success">Report preset saved for this Research Agent.</div><?php endif?>
  <?php if(isset($_GET['subscribed'])):?><div class="success">Report subscription is active. Its schedule comes from the selected Research Program.</div><?php endif?>
  <?php if(isset($_GET['updated'])):?><div class="success">Report subscription updated.</div><?php endif?>
  <?php if(isset($_GET['delivered'])):?><div class="success">Manual intelligence delivery processed.</div><?php endif?>
  <?php if(isset($_GET['reviewed'])):?><div class="success">Intelligence delivery marked reviewed.</div><?php endif?>
  <?php if(!$selected):?><section class="card empty"><h2>Create a Research Agent first.</h2><a class="button" href="/research.php">Open Research Agents</a></section>
  <?php else:?>
  <nav class="reportStudioTabs">
    <a class="<?=$view==='run'?'active':''?>" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=run">Run Report</a>
    <a class="<?=$view==='recent'?'active':''?>" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=recent">Recent Reports <span><?=h((string)count($reports))?></span></a>
    <a class="<?=$view==='presets'?'active':''?>" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=presets">Saved Presets <span><?=h((string)count($presets))?></span></a>
    <?php if($deliveryReady):?><a class="<?=$view==='subscriptions'?'active':''?>" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=subscriptions">Subscriptions <span><?=h((string)count($subscriptions))?></span></a>
    <a class="<?=$view==='inbox'?'active':''?>" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=inbox">Intelligence Inbox <span><?=h((string)$newDeliveryCount)?></span></a><?php endif?>
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
      <?php if($deliveryReady):?><a class="button secondary" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=subscriptions&preset=<?=h(rawurlencode((string)$p['public_id']))?><?=!empty($p['program_public_id'])?'&program='.h(rawurlencode((string)$p['program_public_id'])):''?>">Subscribe</a><?php endif?>
    </div></article><?php endforeach?></div></section>
  <?php elseif($view==='subscriptions'&&$deliveryReady):?>
    <section class="reportSubscriptionLayout">
      <article class="card reportSubscriptionBuilder"><span class="eyebrow">INTELLIGENCE SUBSCRIPTION</span><h2>Deliver this Agent’s research when it matters</h2><p>The Research Program owns the schedule. This subscription decides when its saved Report preset becomes an intelligence delivery.</p>
        <?php if(!$presets||empty($choices['programs'])):?><div class="empty">Create a saved Report preset and Research Program before subscribing.</div>
        <?php else:?><form method="post" class="reportSubscriptionForm">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($selectedId)?>">
          <label>Subscription name <input name="name" maxlength="190" placeholder="e.g. Weekly competitive intelligence"></label>
          <label>Saved Report preset <select name="preset_id" required><option value="">Choose preset</option><?php foreach($presets as $p):?><option value="<?=h((string)$p['public_id'])?>" <?=$presetChoice===(string)$p['public_id']?'selected':''?>><?=h((string)$p['name'])?> · <?=h((string)($types[$p['report_type']]['label']??$p['report_type']))?></option><?php endforeach?></select></label>
          <label>Research Program / schedule <select name="program_id" required><option value="">Choose Program</option><?=$selectOptions($choices['programs'],$programChoice)?></select></label>
          <label>Delivery policy <select name="delivery_policy"><?php foreach(research_report_subscription_policies() as $key=>$label):?><option value="<?=h($key)?>" <?=$key==='material_change_only'?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label>
          <label>Stale after <select name="stale_after_hours"><option value="24">24 hours</option><option value="72">3 days</option><option value="168" selected>7 days</option><option value="336">14 days</option><option value="720">30 days</option></select></label>
          <div class="reportSubscriptionChecks">
            <label><input type="hidden" name="notify_in_app" value="0"><input type="checkbox" name="notify_in_app" value="1" checked> In-app notification</label>
            <label><input type="hidden" name="notify_agent_chat" value="0"><input type="checkbox" name="notify_agent_chat" value="1" checked> Agent Chat update</label>
            <label><input type="hidden" name="include_summary" value="0"><input type="checkbox" name="include_summary" value="1" checked> Include report summary</label>
            <label><input type="hidden" name="include_comparison" value="0"><input type="checkbox" name="include_comparison" value="1" checked> Include change comparison</label>
          </div>
          <button class="button" name="op" value="create_subscription">Create subscription</button>
        </form><?php endif?>
      </article>
      <section class="researchReportsHistory"><div class="sectionHeadWeb"><div><span class="eyebrow">SUBSCRIPTIONS</span><h2><?=h((string)$selected['name'])?></h2></div></div>
        <?php if(!$subscriptions):?><div class="card empty">No Report subscriptions for this Research Agent yet.</div><?php endif?>
        <div class="researchReportsList"><?php foreach($subscriptions as $s):?><article class="card researchReportRow <?=$activeSubscription&&$activeSubscription['public_id']===$s['public_id']?'is-active':''?>">
          <div><span class="eyebrow"><?=h(strtoupper((string)$s['status']))?> · <?=h(strtoupper(str_replace('_',' ',(string)$s['delivery_policy'])))?></span><h3><?=h((string)$s['name'])?></h3><small><?=h((string)$s['preset_name'])?> · <?=h((string)$s['program_title'])?> · <?=h((string)$s['program_cadence'])?><?php if(!empty($s['program_next_run_at'])):?> · Next <?=h((string)$s['program_next_run_at'])?><?php endif?><?php if(!empty($s['last_delivered_at'])):?> · Last delivered <?=h((string)$s['last_delivered_at'])?><?php endif?></small></div>
          <div class="inlineActions"><a href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=subscriptions&subscription=<?=h(rawurlencode((string)$s['public_id']))?>">Configure</a>
            <?php if($s['status']!=='archived'):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($selectedId)?>"><input type="hidden" name="subscription_id" value="<?=h((string)$s['public_id'])?>"><button class="button secondary" name="op" value="deliver_now">Deliver now</button></form><?php endif?>
          </div>
        </article><?php endforeach?></div>
      </section>
    </section>
    <?php if($activeSubscription):?><section class="card reportSubscriptionEditor"><span class="eyebrow">SUBSCRIPTION SETTINGS</span><h2><?=h((string)$activeSubscription['name'])?></h2>
      <form method="post" class="reportSubscriptionForm"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($selectedId)?>"><input type="hidden" name="subscription_id" value="<?=h((string)$activeSubscription['public_id'])?>">
        <label>Name <input name="name" maxlength="190" value="<?=h((string)$activeSubscription['name'])?>"></label>
        <label>Delivery policy <select name="delivery_policy"><?php foreach(research_report_subscription_policies() as $key=>$label):?><option value="<?=h($key)?>" <?=$key===$activeSubscription['delivery_policy']?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label>
        <label>Stale after hours <input type="number" min="1" max="8760" name="stale_after_hours" value="<?=h((string)$activeSubscription['stale_after_hours'])?>"></label>
        <div class="reportSubscriptionChecks">
          <?php foreach(['notify_in_app'=>'In-app notification','notify_agent_chat'=>'Agent Chat update','include_summary'=>'Include summary','include_comparison'=>'Include comparison'] as $key=>$label):?><label><input type="hidden" name="<?=h($key)?>" value="0"><input type="checkbox" name="<?=h($key)?>" value="1" <?=!empty($activeSubscription[$key])?'checked':''?>> <?=h($label)?></label><?php endforeach?>
        </div><button class="button" name="op" value="update_subscription">Save settings</button>
      </form>
      <div class="inlineActions reportSubscriptionLifecycle"><?php if($activeSubscription['status']==='active'):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($selectedId)?>"><input type="hidden" name="subscription_id" value="<?=h((string)$activeSubscription['public_id'])?>"><input type="hidden" name="status" value="paused"><button class="button secondary" name="op" value="set_subscription_status">Pause</button></form><?php elseif($activeSubscription['status']==='paused'):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($selectedId)?>"><input type="hidden" name="subscription_id" value="<?=h((string)$activeSubscription['public_id'])?>"><input type="hidden" name="status" value="active"><button class="button" name="op" value="set_subscription_status">Resume</button></form><?php endif?>
        <?php if($activeSubscription['status']!=='archived'):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($selectedId)?>"><input type="hidden" name="subscription_id" value="<?=h((string)$activeSubscription['public_id'])?>"><input type="hidden" name="status" value="archived"><button class="button secondary" name="op" value="set_subscription_status">Archive</button></form><?php endif?></div>
      <p class="reportSubscriptionNote">Scheduled execution is inherited from <a href="/research-programs.php?agent=<?=h(rawurlencode($selectedId))?>&program=<?=h(rawurlencode((string)$activeSubscription['program_public_id']))?>"><?=h((string)$activeSubscription['program_title'])?></a>. Phase 69 does not create another scheduler.</p>
    </section><?php endif?>
  <?php elseif($view==='inbox'&&$deliveryReady):?>
    <section class="researchReportsHistory"><div class="sectionHeadWeb"><div><span class="eyebrow">INTELLIGENCE INBOX</span><h2>What this Research Agent delivered</h2><p>Delivered, suppressed, and failed cycles are preserved so you can see both signal and intentional silence.</p></div></div>
      <?php if(!$deliveries):?><div class="card empty">No intelligence deliveries yet. A linked Research Program cycle or “Deliver now” will create the first delivery.</div><?php endif?>
      <div class="researchReportsList"><?php foreach($deliveries as $d):?><article class="card researchReportRow reportDeliveryRow <?=$activeDelivery&&$activeDelivery['public_id']===$d['public_id']?'is-active':''?>">
        <div><span class="eyebrow"><?=h(strtoupper((string)$d['status']))?><?=((int)$d['material_change_count']>0?' · '.h((string)$d['material_change_count']).' MATERIAL CHANGE'.((int)$d['material_change_count']===1?'':'S'):'' )?></span><h3><?=h((string)($d['report_title']?:$d['preset_name']?:'Research intelligence cycle'))?></h3><p><?=h((string)($d['summary']?:str_replace('_',' ',(string)$d['reason_code'])))?></p><small><?=h((string)$d['program_title'])?> · <?=h(str_replace('_',' ',(string)$d['trigger_type']))?> · <?=h((string)$d['created_at'])?></small></div>
        <div class="inlineActions"><a href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=inbox&delivery=<?=h(rawurlencode((string)$d['public_id']))?>">Details</a><?php if(!empty($d['report_public_id'])):?><a class="button secondary" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=recent&report=<?=h(rawurlencode((string)$d['report_public_id']))?>">Open Report</a><?php endif?><?php if($d['status']==='delivered'):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($selectedId)?>"><input type="hidden" name="delivery_id" value="<?=h((string)$d['public_id'])?>"><button class="button secondary" name="op" value="mark_delivery_viewed">Mark reviewed</button></form><?php endif?></div>
      </article><?php endforeach?></div>
    </section>
    <?php if($activeDelivery):?><section class="card reportDeliveryDetail"><span class="eyebrow">DELIVERY DETAIL · <?=h(strtoupper((string)$activeDelivery['status']))?></span><h2><?=h((string)($activeDelivery['report_title']?:$activeDelivery['preset_name']))?></h2><p><?=h((string)$activeDelivery['summary'])?></p>
      <div class="researchReportMetrics"><span><strong><?=h((string)$activeDelivery['material_change_count'])?></strong><small>material changes</small></span><span><strong><?=h((string)($activeDelivery['channels']['in_app']??false?'Yes':'No'))?></strong><small>in-app delivered</small></span><span><strong><?=h((string)($activeDelivery['channels']['agent_chat']??false?'Yes':'No'))?></strong><small>Agent Chat delivered</small></span></div>
      <?php if(!empty($activeDelivery['comparison'])):?><div class="reportCompareGrid"><?php foreach($activeDelivery['comparison'] as $bucket=>$delta):?><?php if($bucket==='material_change_count'||!is_array($delta))continue;?><div><strong><?=h(ucwords(str_replace('_',' ',$bucket)))?></strong><span>+<?=h((string)($delta['added_count']??0))?> added · <?=h((string)($delta['changed_count']??0))?> changed · -<?=h((string)($delta['removed_count']??0))?> removed</span></div><?php endforeach?></div><?php endif?>
      <div class="inlineActions"><?php if(!empty($activeDelivery['report_public_id'])):?><a class="button" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=recent&report=<?=h(rawurlencode((string)$activeDelivery['report_public_id']))?>">Open Report Run</a><?php endif?><?php if(!empty($activeDelivery['program_public_id'])):?><a class="button secondary" href="/research-programs.php?agent=<?=h(rawurlencode($selectedId))?>&program=<?=h(rawurlencode((string)$activeDelivery['program_public_id']))?>">Open Program</a><?php endif?><?php if($conversation!==''&&!empty($activeDelivery['report_public_id'])):?><a class="button secondary" href="/home.php?agent=<?=h(rawurlencode($conversation))?>&agent_context_type=report&agent_context_id=<?=h(rawurlencode((string)$activeDelivery['report_public_id']))?>">Ask Agent what changed</a><?php endif?></div>
    </section><?php endif?>
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