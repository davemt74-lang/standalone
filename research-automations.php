<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');$error='';$success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$action=(string)($_POST['action']??'');
    try{
        if(!research_automation_ready($pdo))throw new RuntimeException('Run the Phase 18 database upgrade first.');
        if($action==='create'){
            $row=research_automation_create($pdo,$u,$_POST);
            if(!empty($_POST['run_immediately'])){$run=research_automation_enqueue_manual($pdo,$u,(string)$row['public_id']);$success='Automation created and queued to run now.';}else $success='Automation created.';
        }elseif($action==='update'){
            research_automation_update($pdo,$u,(string)($_POST['automation_id']??''),$_POST);$success='Automation updated.';
        }elseif($action==='run'){
            research_automation_enqueue_manual($pdo,$u,(string)($_POST['automation_id']??''));$success='Automation queued to run now.';
        }elseif($action==='pause'){
            if(!research_automation_set_status($pdo,$u,(string)($_POST['automation_id']??''),'paused'))throw new RuntimeException('Automation not found.');$success='Automation paused.';
        }elseif($action==='resume'){
            if(!research_automation_set_status($pdo,$u,(string)($_POST['automation_id']??''),'active'))throw new RuntimeException('Automation not found.');$success='Automation resumed.';
        }elseif($action==='archive'){
            if(!research_automation_set_status($pdo,$u,(string)($_POST['automation_id']??''),'archived'))throw new RuntimeException('Automation not found.');$success='Automation archived.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$ready=research_automation_ready($pdo);$automations=$ready?research_automation_list($pdo,$u,100):[];$selected=trim((string)($_GET['id']??''));$runs=$ready?research_automation_run_list($pdo,$u,$selected!==''?$selected:null,60):[];
$q=$pdo->prepare("SELECT DISTINCT rp.public_id,rp.title,CASE WHEN rp.owner_user_id=? THEN 'owner' ELSE COALESCE(tm.role,'viewer') END access_role FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE rp.owner_user_id=? OR tm.user_id=? ORDER BY rp.updated_at DESC");$q->execute([$u['id'],$u['id'],$u['id'],$u['id']]);$projects=$q->fetchAll();
$watches=$ready&&proactive_intelligence_ready($pdo)?proactive_watch_list($pdo,$u):[];$workflowDefs=research_automation_workflows();$cadences=research_automation_cadences();$days=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Research Automations · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="layout automationLayout"><section>
<div class="pageTitle"><span class="eyebrow">STAGE 18 · RESEARCH AUTOMATION</span><h1>Recurring Agent workflows</h1><p>Schedule evidence-aware briefings, proposal-only Research reviews, or source refreshes. Automated reviews can prepare actions, but Stage 15 confirmation is still required before any Research write executes.</p></div>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<?php if(!$ready):?><div class="card empty"><h2>Research Automation needs the Phase 18 database upgrade.</h2><p>Existing Research, Agent Chat, Cognitive Feed, and proactive notifications continue to work until migration 025 is installed.</p><?php if(($u['role']??'')==='admin'):?><a class="button" href="/upgrade.php">Run database upgrade</a><?php endif?></div><?php else:?>
<div class="automationSummary">
  <div class="card"><strong><?=h((string)count(array_filter($automations,fn($a)=>$a['status']==='active')))?></strong><span>Active</span></div>
  <div class="card"><strong><?=h((string)count(array_filter($automations,fn($a)=>$a['trigger_type']==='watch_alert'&&$a['status']==='active')))?></strong><span>Watch-triggered</span></div>
  <div class="card"><strong><?=h((string)count(array_filter($runs,fn($r)=>$r['status']==='queued'||$r['status']==='processing')))?></strong><span>Queued / running</span></div>
</div>
<?php if(!$automations):?><div class="card empty"><h2>No automations yet.</h2><p>Create a recurring briefing, Research review, or source refresh from the form on the right.</p></div><?php endif?>
<div class="automationList">
<?php foreach($automations as $a):$def=$workflowDefs[$a['workflow_type']]??['label'=>$a['workflow_type'],'description'=>''];$schedule=$a['trigger_type']==='watch_alert'?'When watch alerts':($a['cadence']==='manual'?'Manual only':ucfirst((string)$a['cadence']));?>
<article class="card automationCard <?=$a['status']!=='active'?'automationPaused':''?>">
  <header class="automationCardHeader"><div><div class="automationBadges"><span class="badge"><?=h($def['label'])?></span><span class="badge"><?=h($schedule)?></span><span class="badge"><?=h(ucfirst((string)$a['status']))?></span></div><h2><?=h((string)$a['title'])?></h2><p class="meta"><?=h((string)$a['project_title'])?><?php if($a['trigger_type']==='watch_alert'):?> · Watch: <?=h((string)($a['watch_query']?:$a['watch_type']))?><?php endif?></p></div><a href="/research-automations.php?id=<?=h((string)$a['public_id'])?>#history">History</a></header>
  <?php if(trim((string)$a['prompt'])!==''):?><p><?=h(mb_substr((string)$a['prompt'],0,320))?><?=mb_strlen((string)$a['prompt'])>320?'…':''?></p><?php else:?><p class="meta"><?=h((string)$def['description'])?></p><?php endif?>
  <div class="automationStats"><span><small>Next run</small><?=h((string)($a['next_run_at']?:'On trigger / manual'))?></span><span><small>Last run</small><?=h((string)($a['last_run_at']?:'Never'))?></span><span><small>Runs</small><?=h((string)$a['run_count'])?></span><span><small>Failures</small><?=h((string)$a['failure_count'])?></span></div>
  <div class="inlineActions automationActions">
    <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="run"><input type="hidden" name="automation_id" value="<?=h((string)$a['public_id'])?>"><button>Run now</button></form>
    <?php if($a['status']==='active'):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="pause"><input type="hidden" name="automation_id" value="<?=h((string)$a['public_id'])?>"><button class="button secondary">Pause</button></form><?php elseif($a['status']==='paused'):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="resume"><input type="hidden" name="automation_id" value="<?=h((string)$a['public_id'])?>"><button class="button secondary">Resume</button></form><?php endif?>
    <?php if(!empty($a['conversation_public_id'])):?><a class="button secondary" href="/home.php?agent=<?=h((string)$a['conversation_public_id'])?>">Open Agent</a><?php endif?>
  </div>
  <details class="automationEdit"><summary>Edit automation</summary>
    <form method="post" class="stack automationForm" data-automation-form>
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="automation_id" value="<?=h((string)$a['public_id'])?>">
      <label>Name<input name="title" value="<?=h((string)$a['title'])?>" maxlength="190" required></label>
      <label>Project<select name="project_id" required><?php foreach($projects as $p):?><option value="<?=h((string)$p['public_id'])?>" <?=$p['public_id']===$a['project_public_id']?'selected':''?>><?=h((string)$p['title'])?> · <?=h((string)$p['access_role'])?></option><?php endforeach?></select></label>
      <label>Workflow<select name="workflow_type" data-workflow><?php foreach($workflowDefs as $key=>$wd):?><option value="<?=h($key)?>" <?=$key===$a['workflow_type']?'selected':''?>><?=h((string)$wd['label'])?></option><?php endforeach?></select></label>
      <label>Trigger<select name="trigger_type" data-trigger><option value="schedule" <?=$a['trigger_type']==='schedule'?'selected':''?>>Schedule</option><option value="watch_alert" <?=$a['trigger_type']==='watch_alert'?'selected':''?>>When a Research watch alerts</option></select></label>
      <div data-schedule-fields><label>Cadence<select name="cadence"><?php foreach($cadences as $key=>$label):?><option value="<?=h($key)?>" <?=$key===$a['cadence']?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label><label>Local run time<input type="time" name="run_time_local" value="<?=h(substr((string)$a['run_time_local'],0,5))?>"></label><label>Weekly day<select name="weekday"><?php foreach($days as $i=>$day):?><option value="<?=$i?>" <?=(int)$a['weekday']===$i?'selected':''?>><?=h($day)?></option><?php endforeach?></select></label><label>Timezone<input name="timezone_name" value="<?=h((string)$a['timezone_name'])?>" data-timezone></label></div>
      <div data-watch-fields><label>Research watch<select name="watch_id"><option value="">Choose a watch</option><?php foreach($watches as $w):?><option value="<?=h((string)$w['public_id'])?>" <?=$w['public_id']===$a['watch_public_id']?'selected':''?>><?=h(ucfirst((string)$w['watch_type']).' · '.(string)($w['query_text']?:$w['object_public_id']))?></option><?php endforeach?></select></label></div>
      <label data-prompt-field>Agent instructions<textarea name="prompt" rows="5" maxlength="4000"><?=h((string)$a['prompt'])?></textarea></label>
      <button>Save changes</button>
    </form>
    <form method="post" class="automationArchive" onsubmit="return confirm('Archive this automation? Its run history will remain available until project deletion.')"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="automation_id" value="<?=h((string)$a['public_id'])?>"><button class="button secondary">Archive automation</button></form>
  </details>
</article>
<?php endforeach?>
</div>
<section id="history" class="automationHistory"><div class="sectionHeadWeb"><div><span class="eyebrow">RUN HISTORY</span><h2><?=$selected!==''?'Selected automation':'Recent runs'?></h2></div><?php if($selected!==''):?><a href="/research-automations.php#history">Show all runs</a><?php endif?></div>
<?php if(!$runs):?><div class="card empty">No automation runs yet.</div><?php endif?>
<?php foreach($runs as $run):?><article class="card automationRun" id="run-<?=h((string)$run['public_id'])?>"><header><div><span class="badge"><?=h(ucfirst((string)$run['status']))?></span><strong><?=h((string)$run['automation_title'])?></strong></div><span class="meta"><?=h((string)$run['created_at'])?></span></header><p class="meta"><?=h(ucfirst(str_replace('_',' ',(string)$run['trigger_type'])))?> · <?=h((string)$run['project_title'])?><?php if((int)$run['proposal_count']>0):?> · <?=h((string)$run['proposal_count'])?> proposal<?=((int)$run['proposal_count']===1?'':'s')?> waiting for confirmation<?php endif?></p><?php if(trim((string)$run['output_text'])!==''):?><p><?=h(mb_substr((string)$run['output_text'],0,800))?><?=mb_strlen((string)$run['output_text'])>800?'…':''?></p><?php endif?><?php if(trim((string)$run['last_error'])!==''):?><div class="error"><?=h((string)$run['last_error'])?></div><?php endif?><div class="inlineActions"><?php if(!empty($run['conversation_public_id'])):?><a class="button secondary" href="/home.php?agent=<?=h((string)$run['conversation_public_id'])?>">Open Agent conversation</a><?php endif?><a href="/research-project.php?id=<?=h((string)$run['project_public_id'])?>">Open Research</a></div></article><?php endforeach?>
</section>
<?php endif?>
</section>
<aside class="automationCreateRail"><div class="card stickyAutomationCreate"><span class="eyebrow">NEW AUTOMATION</span><h3>Automate Research</h3><?php if(!$projects):?><p class="meta">Create or join a Research project before adding an automation.</p><a class="button" href="/research.php">Open Research</a><?php elseif($ready):?><form method="post" class="stack automationForm" data-automation-form><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="create">
<label>Name<input name="title" maxlength="190" placeholder="Daily project briefing" required></label>
<label>Project<select name="project_id" required><?php foreach($projects as $p):?><option value="<?=h((string)$p['public_id'])?>"><?=h((string)$p['title'])?> · <?=h((string)$p['access_role'])?></option><?php endforeach?></select></label>
<label>Workflow<select name="workflow_type" data-workflow><?php foreach($workflowDefs as $key=>$wd):?><option value="<?=h($key)?>"><?=h((string)$wd['label'])?></option><?php endforeach?></select></label>
<label>Trigger<select name="trigger_type" data-trigger><option value="schedule">Schedule</option><option value="watch_alert">When a Research watch alerts</option></select></label>
<div data-schedule-fields><label>Cadence<select name="cadence"><?php foreach($cadences as $key=>$label):?><option value="<?=h($key)?>" <?=$key==='daily'?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label><label>Local run time<input type="time" name="run_time_local" value="09:00"></label><label>Weekly day<select name="weekday"><?php foreach($days as $i=>$day):?><option value="<?=$i?>" <?=$i===1?'selected':''?>><?=h($day)?></option><?php endforeach?></select></label><label>Timezone<input name="timezone_name" value="UTC" data-timezone></label></div>
<div data-watch-fields><label>Research watch<select name="watch_id"><option value="">Choose a watch</option><?php foreach($watches as $w):?><option value="<?=h((string)$w['public_id'])?>"><?=h(ucfirst((string)$w['watch_type']).' · '.(string)($w['query_text']?:$w['object_public_id']))?></option><?php endforeach?></select></label><?php if(!$watches):?><p class="meta">Create a watch in Settings or from a Now card first.</p><?php endif?></div>
<label data-prompt-field>Agent instructions<textarea name="prompt" rows="5" maxlength="4000" placeholder="Leave blank to use the safe default for this workflow."></textarea></label>
<label><input type="checkbox" name="run_immediately" value="1"> Queue the first run immediately</label><button>Create automation</button></form><?php endif?></div>
<div class="card"><h3>Safety boundary</h3><p class="meta">Briefings are read-only. Reviews may create Stage 15 proposals, but no scheduled run can confirm or execute them. You remain the approval step for Research writes.</p></div></aside>
</main>
<script>
document.querySelectorAll('[data-automation-form]').forEach(form=>{
 const trigger=form.querySelector('[data-trigger]'),workflow=form.querySelector('[data-workflow]'),schedule=form.querySelector('[data-schedule-fields]'),watch=form.querySelector('[data-watch-fields]'),prompt=form.querySelector('[data-prompt-field]'),tz=form.querySelector('[data-timezone]');
 const sync=()=>{const watchMode=trigger?.value==='watch_alert';if(schedule)schedule.hidden=watchMode;if(watch)watch.hidden=!watchMode;if(prompt)prompt.hidden=workflow?.value==='source_refresh';};
 trigger?.addEventListener('change',sync);workflow?.addEventListener('change',sync);sync();
 if(tz&&(!tz.value||tz.value==='UTC')){try{const z=Intl.DateTimeFormat().resolvedOptions().timeZone;if(z)tz.value=z;}catch{}}
});
</script></body></html>