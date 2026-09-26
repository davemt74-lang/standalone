<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
if(!research_programs_ready($pdo)){header('Location: /upgrade.php?from=research-programs');exit;}
if(function_exists('research_agent_ensure_default'))research_agent_ensure_default($pdo,$u);
$agents=research_agent_list($pdo,$u,50);
$selectedAgentId=trim((string)($_GET['agent']??($agents[0]['public_id']??'')));$selectedAgent=null;
foreach($agents as $agent)if(hash_equals((string)$agent['public_id'],$selectedAgentId)){$selectedAgent=$agent;break;}
if(!$selectedAgent&&$agents){$selectedAgent=$agents[0];$selectedAgentId=(string)$selectedAgent['public_id'];}
$programs=$selectedAgent?research_program_list($pdo,$u,$selectedAgentId,100):[];
$summary=$selectedAgent?research_program_summary($pdo,$u,$selectedAgentId):['active'=>0,'paused'=>0,'failed_runs'=>0,'review_tasks'=>0,'next_run_at'=>null];
$selectedProgramId=trim((string)($_GET['program']??($programs[0]['public_id']??'')));$selectedProgram=$selectedProgramId!==''?research_program_detail($pdo,$u,$selectedProgramId):null;
if(!$selectedProgram&&$programs){$selectedProgramId=(string)$programs[0]['public_id'];$selectedProgram=research_program_detail($pdo,$u,$selectedProgramId);}
$selectedRun=null;$selectedDeltas=[];$requestedRun=trim((string)($_GET['run']??''));
if($requestedRun!==''){$selectedRun=research_program_run_row($pdo,$u,$requestedRun);if($selectedRun&&$selectedProgram&&$selectedRun['program_public_id']===$selectedProgram['public_id'])$selectedDeltas=research_program_run_deltas($pdo,$u,$requestedRun,150);else $selectedRun=null;}
if(!$selectedRun&&$selectedProgram&&!empty($selectedProgram['runs'])){$selectedRun=$selectedProgram['runs'][0];$selectedDeltas=research_program_run_deltas($pdo,$u,(string)$selectedRun['public_id'],150);}
$timezone=(string)($u['timezone_name']??'UTC');try{research_automation_timezone($timezone);}catch(Throwable $e){$timezone='UTC';}
$csrf=csrf_token();
?><!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Research Programs · Annotated</title>
<link rel="stylesheet" href="/assets/css/app.css?v=67.0">
</head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-programs">
<main class="researchLibraryCanvas researchProgramsCanvas">
  <section class="researchLibraryToolbar" aria-label="Research workspace tools">
    <nav class="researchLibraryTabs researchPrimaryActions">
      <a href="/research.php">Research Agents</a>
      <a href="/research-agent-knowledge.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Knowledge</a>
      <a href="/research-reports.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Reports</a>
      <a href="/research-monitoring.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Monitoring</a>
      <a href="/research-tasks.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Tasks</a>
      <a class="active" href="/research-programs.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Programs</a>
      <a href="/research-portfolio.php">Project Portfolio</a>
      <a href="/research-intelligence-portfolios.php">Intelligence Portfolios</a>
      <a href="/research-publications.php">Publishing</a>
      <a href="/research-reviews.php">Review Center</a>
    </nav>
  </section>

  <header class="researchProgramsHero">
    <div><span class="eyebrow">RECURRING INTELLIGENCE</span><h1>Research Programs</h1><p>Maintain ongoing intelligence operations. Each material cycle creates a fresh Phase 57 plan and versioned deliverable while preserving prior runs, deltas, evidence, and review history.</p></div>
    <?php if($agents):?><label>Research Agent<select data-program-agent><?php foreach($agents as $agent):?><option value="<?=h((string)$agent['public_id'])?>" <?=$selectedAgentId===(string)$agent['public_id']?'selected':''?>><?=h((string)$agent['name'])?></option><?php endforeach?></select></label><?php endif?>
  </header>

  <?php if(!$agents):?>
  <section class="card empty"><h2>Create a Research Agent first.</h2><p>Recurring Programs belong to a Research Agent workspace.</p><a class="button" href="/research.php">Open Research Agents</a></section>
  <?php else:?>

  <section class="researchProgramsStats">
    <div><strong><?=h((string)$summary['active'])?></strong><span>Active programs</span></div>
    <div><strong><?=h((string)$summary['paused'])?></strong><span>Paused</span></div>
    <div><strong><?=h((string)$summary['review_tasks'])?></strong><span>Tasks ready for review</span></div>
    <div><strong><?=h((string)$summary['failed_runs'])?></strong><span>Failed runs · 30d</span></div>
  </section>

  <section class="researchProgramsGrid">
    <aside class="researchProgramsCreate card">
      <span class="eyebrow">NEW PROGRAM</span><h2>Start recurring research</h2>
      <form data-program-create>
        <label>Program title<input name="title" required maxlength="255" placeholder="Weekly competitor intelligence"></label>
        <label>Objective<textarea name="objective" required rows="5" maxlength="16000" placeholder="What should this Program continuously learn and report?"></textarea></label>
        <div class="researchProgramsFormSplit">
          <label>Cadence<select name="cadence" data-program-cadence><option value="hourly">Hourly</option><option value="daily">Daily</option><option value="weekly" selected>Weekly</option><option value="monthly">Monthly</option><option value="manual">Manual only</option></select></label>
          <label>Priority<select name="priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="urgent">Urgent</option></select></label>
        </div>
        <div class="researchProgramsFormSplit">
          <label>Run time<input type="time" name="run_time_local" value="09:00"></label>
          <label>Timezone<input name="timezone_name" value="<?=h($timezone)?>" maxlength="64"></label>
        </div>
        <div class="researchProgramsFormSplit">
          <label data-weekday-field>Weekday<select name="weekday"><option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option><option value="6">Saturday</option><option value="0">Sunday</option></select></label>
          <label data-monthday-field hidden>Day of month<input type="number" name="day_of_month" min="1" max="28" value="1"></label>
        </div>
        <label>Deliverable<select name="deliverable_type"><?php foreach(research_task_deliverable_types() as $key=>$label):?><option value="<?=h($key)?>" <?=$key==='weekly_report'?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label>
        <label>Focus topics <input name="topics" placeholder="competitors, pricing, product launches"></label>

        <details class="researchProgramsAdvanced">
          <summary>Run policy & budgets</summary>
          <label>Quiet runs<select name="quiet_mode"><?php foreach(research_program_quiet_modes() as $key=>$label):?><option value="<?=h($key)?>"><?=h($label)?></option><?php endforeach?></select></label>
          <label>Materiality<select name="materiality_threshold"><?php foreach(research_program_materiality_levels() as $key=>$label):?><option value="<?=h($key)?>" <?=$key==='important'?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label>
          <label>After downtime<select name="catch_up_mode"><?php foreach(research_program_catch_up_modes() as $key=>$label):?><option value="<?=h($key)?>"><?=h($label)?></option><?php endforeach?></select></label>
          <div class="researchProgramsFormSplit"><label>Concurrent runs<input type="number" name="max_concurrent_runs" min="1" max="3" value="1"></label><label>Runs / month<input type="number" name="monthly_run_limit" min="1" max="1000" value="31"></label></div>
          <div class="researchProgramsFormSplit"><label>Token budget / run<input type="number" name="token_budget_per_run" min="1000" max="2000000" value="60000"></label><label>Max tasks / run<input type="number" name="max_tasks_per_run" min="1" max="30" value="12"></label></div>
        </details>
        <button type="submit" class="button">Create Program</button>
      </form>
      <p class="meta">Programs use the existing Research scheduler and Phase 57 execution system. Quiet cycles can be recorded without generating another report.</p>
    </aside>

    <section class="researchProgramsList">
      <header><div><span class="eyebrow">PROGRAMS</span><h2><?=h((string)$selectedAgent['name'])?></h2></div><small><?=!empty($summary['next_run_at'])?'Next run '.h((string)$summary['next_run_at']):'No scheduled run'?></small></header>
      <?php if(!$programs):?><div class="card empty"><h3>No recurring Programs yet.</h3><p>Create one here or ask the Research Agent to create a recurring intelligence Program.</p></div><?php endif?>
      <?php foreach($programs as $program):$selected=$selectedProgramId===(string)$program['public_id'];?>
      <article class="researchProgramCard card <?=$selected?'is-selected':''?>" data-program-card="<?=h((string)$program['public_id'])?>">
        <a class="researchProgramOpen" href="/research-programs.php?agent=<?=rawurlencode($selectedAgentId)?>&program=<?=rawurlencode((string)$program['public_id'])?>">
          <div><span><?=h(strtoupper((string)$program['cadence']))?> · <?=h(strtoupper((string)$program['priority']))?></span><h3><?=h((string)$program['title'])?></h3></div><strong><?=h(ucfirst((string)$program['status']))?></strong>
        </a>
        <p><?=h(mb_substr((string)$program['objective'],0,260))?></p>
        <div class="researchProgramMeta">
          <span><?=h((string)$program['completed_runs'])?> completed</span><span><?=h((string)$program['quiet_runs'])?> quiet</span><span><?=h((string)$program['failed_runs'])?> failed</span><span><?=h((string)$program['active_runs'])?> active</span>
        </div>
        <div class="researchProgramMeta"><span><?=!empty($program['next_run_at'])?'Next '.h((string)$program['next_run_at']):'Manual / paused'?></span><span><?=h((string)$program['token_budget_per_run'])?> tokens/run</span></div>
        <div class="researchProgramActions">
          <button type="button" data-program-action="run_now">Run now</button>
          <?php if($program['status']==='active'):?><button type="button" data-program-action="pause">Pause</button><?php else:?><button type="button" data-program-action="resume">Resume</button><?php endif?>
        </div>
      </article>
      <?php endforeach?>
    </section>
  </section>

  <?php if($selectedProgram):$scope=research_program_scope($selectedProgram);?>
  <section class="researchProgramDetail card" data-program-detail="<?=h((string)$selectedProgram['public_id'])?>">
    <header class="researchProgramDetailHeader">
      <div><span class="eyebrow">PROGRAM · REVISION <?=h((string)$selectedProgram['current_revision'])?></span><h2><?=h((string)$selectedProgram['title'])?></h2><p><?=h((string)$selectedProgram['objective'])?></p></div>
      <div class="researchProgramDetailActions">
        <button type="button" data-program-action="run_now">Run now</button>
        <?php if($selectedProgram['status']==='active'):?><button type="button" data-program-action="pause">Pause</button><?php elseif($selectedProgram['status']==='paused'):?><button type="button" data-program-action="resume">Resume</button><?php endif?>
        <button type="button" data-program-action="archive">Archive</button>
      </div>
    </header>

    <section class="researchProgramPolicy">
      <div><strong><?=h(ucfirst((string)$selectedProgram['cadence']))?></strong><span>Cadence</span></div>
      <div><strong><?=h((string)($selectedProgram['next_run_at']?:'Manual'))?></strong><span>Next run</span></div>
      <div><strong><?=h((string)$selectedProgram['materiality_threshold'])?></strong><span>Materiality</span></div>
      <div><strong><?=h((string)$selectedProgram['quiet_mode'])?></strong><span>Quiet policy</span></div>
    </section>

    <details class="researchProgramEditor">
      <summary>Edit Program</summary>
      <form data-program-update>
        <label>Title<input name="title" required maxlength="255" value="<?=h((string)$selectedProgram['title'])?>"></label>
        <label>Objective<textarea name="objective" rows="5" required maxlength="16000"><?=h((string)$selectedProgram['objective'])?></textarea></label>
        <div class="researchProgramsFormSplit"><label>Cadence<select name="cadence" data-program-cadence><?php foreach(research_program_cadences() as $key=>$label):?><option value="<?=h($key)?>" <?=$selectedProgram['cadence']===$key?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label><label>Priority<select name="priority"><?php foreach(research_task_priorities() as $key=>$label):?><option value="<?=h($key)?>" <?=$selectedProgram['priority']===$key?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label></div>
        <div class="researchProgramsFormSplit"><label>Run time<input type="time" name="run_time_local" value="<?=h(substr((string)$selectedProgram['run_time_local'],0,5))?>"></label><label>Timezone<input name="timezone_name" value="<?=h((string)$selectedProgram['timezone_name'])?>" maxlength="64"></label></div>
        <div class="researchProgramsFormSplit"><label data-weekday-field>Weekday<select name="weekday"><?php foreach(['1'=>'Monday','2'=>'Tuesday','3'=>'Wednesday','4'=>'Thursday','5'=>'Friday','6'=>'Saturday','0'=>'Sunday'] as $key=>$label):?><option value="<?=$key?>" <?=(string)$selectedProgram['weekday']===(string)$key?'selected':''?>><?=$label?></option><?php endforeach?></select></label><label data-monthday-field>Day of month<input type="number" name="day_of_month" min="1" max="28" value="<?=h((string)($selectedProgram['day_of_month']?:1))?>"></label></div>
        <label>Deliverable<select name="deliverable_type"><?php foreach(research_task_deliverable_types() as $key=>$label):?><option value="<?=h($key)?>" <?=$selectedProgram['deliverable_type']===$key?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label>
        <label>Focus topics<input name="topics" value="<?=h(implode(', ',(array)$scope['topics']))?>"></label>
        <div class="researchProgramsFormSplit"><label>Quiet runs<select name="quiet_mode"><?php foreach(research_program_quiet_modes() as $key=>$label):?><option value="<?=h($key)?>" <?=$selectedProgram['quiet_mode']===$key?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label><label>Materiality<select name="materiality_threshold"><?php foreach(research_program_materiality_levels() as $key=>$label):?><option value="<?=h($key)?>" <?=$selectedProgram['materiality_threshold']===$key?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label></div>
        <label>After downtime<select name="catch_up_mode"><?php foreach(research_program_catch_up_modes() as $key=>$label):?><option value="<?=h($key)?>" <?=$selectedProgram['catch_up_mode']===$key?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label>
        <div class="researchProgramsFormSplit"><label>Concurrent runs<input type="number" name="max_concurrent_runs" min="1" max="3" value="<?=h((string)$selectedProgram['max_concurrent_runs'])?>"></label><label>Runs / month<input type="number" name="monthly_run_limit" min="1" max="1000" value="<?=h((string)$selectedProgram['monthly_run_limit'])?>"></label></div>
        <div class="researchProgramsFormSplit"><label>Token budget / run<input type="number" name="token_budget_per_run" min="1000" max="2000000" value="<?=h((string)$selectedProgram['token_budget_per_run'])?>"></label><label>Max tasks / run<input type="number" name="max_tasks_per_run" min="1" max="30" value="<?=h((string)$selectedProgram['max_tasks_per_run'])?>"></label></div>
        <label>Revision reason<input name="reason" maxlength="1000" placeholder="Why did the Program change?"></label>
        <button type="submit">Save Program revision</button>
      </form>
    </details>

    <section class="researchProgramMemory">
      <span class="eyebrow">PROGRAM CONTINUITY</span><p><?=h((string)$selectedProgram['memory']['text'])?></p>
    </section>

    <section class="researchProgramRuns">
      <header><div><span class="eyebrow">RUN HISTORY</span><h3>Recurring intelligence cycles</h3></div></header>
      <?php if(empty($selectedProgram['runs'])):?><div class="empty">No runs yet.</div><?php endif?>
      <div class="researchProgramRunList">
      <?php foreach($selectedProgram['runs'] as $run):?>
        <article class="researchProgramRun is-<?=h((string)$run['status'])?>">
          <a href="/research-programs.php?agent=<?=rawurlencode($selectedAgentId)?>&program=<?=rawurlencode((string)$selectedProgram['public_id'])?>&run=<?=rawurlencode((string)$run['public_id'])?>">
            <div><strong><?=h(ucfirst((string)$run['status']))?></strong><span><?=h(str_replace('_',' ',(string)$run['trigger_type']))?></span></div>
            <small><?=h((string)($run['scheduled_for']?:$run['created_at']))?></small>
          </a>
          <div class="researchProgramRunMeta"><span><?=h((string)$run['material_change_count'])?> material changes</span><span><?=h((string)$run['tokens_used'])?> tokens</span><?php if(!empty($run['quiet_suppressed'])):?><span>Quiet run</span><?php endif?></div>
          <?php if(!empty($run['plan_public_id'])):?><div class="researchProgramRunActions"><a href="/research-tasks.php?agent=<?=rawurlencode($selectedAgentId)?>&plan=<?=rawurlencode((string)$run['plan_public_id'])?>">Open plan</a><?php if(!empty($run['document_public_id'])):?><a href="/home.php?agent=<?=rawurlencode((string)$selectedAgent['conversation_public_id'])?>&doc=<?=rawurlencode((string)$run['document_public_id'])?>">Open deliverable</a><?php endif?></div><?php endif?>
        </article>
      <?php endforeach?>
      </div>
    </section>

    <?php if($selectedRun):?>
    <section class="researchProgramDeltaPanel">
      <header><div><span class="eyebrow">SELECTED RUN</span><h3><?=h((string)$selectedRun['public_id'])?></h3></div><strong><?=h(ucfirst((string)$selectedRun['status']))?></strong></header>
      <?php if(!empty($selectedRun['summary'])):?><p><?=nl2br(h((string)$selectedRun['summary']))?></p><?php endif?>
      <div class="researchProgramDeltaList">
        <?php if(!$selectedDeltas):?><div class="empty">No structured deltas recorded for this run.</div><?php endif?>
        <?php foreach($selectedDeltas as $delta):?><article class="is-<?=h((string)$delta['importance'])?>"><div><span><?=h(strtoupper((string)$delta['importance']))?></span><strong><?=h(str_replace('_',' ',(string)$delta['delta_type']))?></strong></div><p><?=h((string)$delta['summary'])?></p><?php if(!empty($delta['ref_public_id'])):?><small><?=h((string)$delta['ref_type'])?> · <?=h((string)$delta['ref_public_id'])?></small><?php endif?></article><?php endforeach?>
      </div>
    </section>
    <?php endif?>
  </section>
  <?php endif?>
  <?php endif?>
</main>

<script>
(()=>{
 const csrf=<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const agent=<?=json_encode($selectedAgentId,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const selectedProgram=<?=json_encode($selectedProgramId,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 async function post(action,data={}){const r=await fetch('/api/research-programs.php?action='+encodeURIComponent(action),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j?.error?.message||'Research Program request failed.');return j.data;}
 function formData(form){const f=new FormData(form),o={};for(const [k,v] of f.entries())o[k]=v;if(typeof o.topics==='string')o.scope={topics:o.topics.split(',').map(v=>v.trim()).filter(Boolean),include_annotations:true,include_workspace:true};delete o.topics;return o;}
 function syncCadence(form){const select=form.querySelector('[data-program-cadence]'),week=form.querySelector('[data-weekday-field]'),month=form.querySelector('[data-monthday-field]');if(!select)return;week.hidden=select.value!=='weekly';month.hidden=select.value!=='monthly';}
 document.querySelector('[data-program-agent]')?.addEventListener('change',e=>location.href='/research-programs.php?agent='+encodeURIComponent(e.target.value));
 document.querySelectorAll('form').forEach(form=>{const sel=form.querySelector('[data-program-cadence]');if(sel){sel.addEventListener('change',()=>syncCadence(form));syncCadence(form);}});
 document.querySelector('[data-program-create]')?.addEventListener('submit',async e=>{e.preventDefault();const data=formData(e.currentTarget);data.agent_id=agent;try{const x=await post('create',data);location.href='/research-programs.php?agent='+encodeURIComponent(agent)+'&program='+encodeURIComponent(x.program.public_id);}catch(err){alert(err.message);}});
 document.querySelector('[data-program-update]')?.addEventListener('submit',async e=>{e.preventDefault();const data=formData(e.currentTarget);data.program_id=selectedProgram;try{await post('update',data);location.reload();}catch(err){alert(err.message);}});
 document.querySelectorAll('[data-program-card],[data-program-detail]').forEach(card=>card.querySelectorAll('[data-program-action]').forEach(btn=>btn.addEventListener('click',async()=>{const programId=card.dataset.programCard||card.dataset.programDetail||selectedProgram;if(!programId)return;if(btn.dataset.programAction==='archive'&&!confirm('Archive this Research Program? Historical runs and deliverables will remain available.'))return;try{await post(btn.dataset.programAction,{program_id:programId});location.reload();}catch(err){alert(err.message);}})));
})();
</script>
</body></html>
