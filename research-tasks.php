<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
if(!research_tasks_ready($pdo)){header('Location: /upgrade.php?from=research-tasks');exit;}
if(function_exists('research_agent_ensure_default'))research_agent_ensure_default($pdo,$u);
$agents=research_agent_list($pdo,$u,50);
$selectedAgentId=trim((string)($_GET['agent']??($agents[0]['public_id']??'')));
$selectedAgent=null;foreach($agents as $agent)if(hash_equals((string)$agent['public_id'],$selectedAgentId)){$selectedAgent=$agent;break;}
if(!$selectedAgent&&$agents){$selectedAgent=$agents[0];$selectedAgentId=(string)$selectedAgent['public_id'];}
$plans=$selectedAgent?research_task_plan_list($pdo,$u,$selectedAgentId,100):[];
$summary=$selectedAgent?research_task_summary($pdo,$u,$selectedAgentId):['active'=>0,'waiting'=>0,'review'=>0,'complete'=>0,'tasks'=>[],'plans'=>[]];
$selectedPlanId=trim((string)($_GET['plan']??($plans[0]['public_id']??'')));$selectedPlan=$selectedPlanId!==''?research_task_plan_detail($pdo,$u,$selectedPlanId):null;
if(!$selectedPlan&&$plans){$selectedPlanId=(string)$plans[0]['public_id'];$selectedPlan=research_task_plan_detail($pdo,$u,$selectedPlanId);}
$csrf=csrf_token();
?><!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Research Tasks · Annotated</title>
<link rel="stylesheet" href="/assets/css/app.css?v=58.0">
</head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-tasks">
<main class="researchLibraryCanvas researchTasksCanvas">
  <section class="researchLibraryToolbar" aria-label="Research workspace tools">
    <nav class="researchLibraryTabs researchPrimaryActions">
      <a href="/research.php">Research Agents</a>
      <a href="/research-monitoring.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Monitoring</a>
      <a class="active" href="/research-tasks.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Tasks</a>
      <a href="/research-programs.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Programs</a>
      <a href="/research-portfolio.php">Portfolio</a>
      <a href="/research-publications.php">Living Research</a>
      <a href="/research-reviews.php">Review Center</a>
    </nav>
  </section>

  <header class="researchTasksHero">
    <div><span class="eyebrow">RESEARCH EXECUTION</span><h1>Plans, Tasks & Deliverables</h1><p>Turn research goals, evidence gaps, contradictions, and monitored changes into durable work with explicit completion gates and versioned deliverables.</p></div>
    <?php if($agents):?><label>Research Agent<select data-task-agent><?php foreach($agents as $agent):?><option value="<?=h((string)$agent['public_id'])?>" <?=$selectedAgentId===(string)$agent['public_id']?'selected':''?>><?=h((string)$agent['name'])?></option><?php endforeach?></select></label><?php endif?>
  </header>

  <?php if(!$agents):?>
    <section class="card empty"><h2>Create a Research Agent first.</h2><p>Plans and tasks belong to a Research Agent workspace.</p><a class="button" href="/research.php">Open Research Agents</a></section>
  <?php else:?>
  <section class="researchTasksStats">
    <div><strong><?=h((string)$summary['active'])?></strong><span>Active</span></div>
    <div><strong><?=h((string)$summary['waiting'])?></strong><span>Waiting / blocked</span></div>
    <div><strong><?=h((string)$summary['review'])?></strong><span>Ready for review</span></div>
    <div><strong><?=h((string)$summary['complete'])?></strong><span>Complete</span></div>
  </section>

  <section class="researchTasksGrid">
    <aside class="researchTasksCreate card">
      <span class="eyebrow">NEW PLAN</span><h2>Plan a deliverable</h2>
      <form data-plan-create>
        <label>Plan title<input name="title" required maxlength="255" placeholder="Competitor research"></label>
        <label>Objective<textarea name="objective" required rows="5" maxlength="16000" placeholder="What should this research accomplish?"></textarea></label>
        <div class="researchTasksFormSplit">
          <label>Priority<select name="priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="urgent">Urgent</option></select></label>
          <label>Due<input type="datetime-local" name="due_at"></label>
        </div>
        <label>Deliverable<select name="deliverable_type">
          <option value="research_brief">Research Brief</option><option value="competitive_analysis">Competitive Analysis</option><option value="due_diligence">Due-Diligence Report</option><option value="source_digest">Source Digest</option><option value="timeline">Timeline</option><option value="comparison">Comparison</option><option value="weekly_report">Weekly Report</option><option value="report">Report</option><option value="analysis">Analysis</option><option value="document">Document</option>
        </select></label>
        <label>Deliverable title<input name="deliverable_title" maxlength="255" placeholder="Defaults to plan title"></label>
        <button type="submit" class="button">Create plan</button>
      </form>
      <p class="meta">The Agent can add tasks later from evidence gaps and meaningful monitoring changes. You remain in control of review gates and finalization.</p>
    </aside>

    <section class="researchTasksPlans">
      <header><div><span class="eyebrow">PLANS</span><h2><?=h((string)$selectedAgent['name'])?></h2></div><small><?=h((string)count($plans))?> active / historical plan<?=count($plans)===1?'':'s'?></small></header>
      <?php if(!$plans):?><div class="card empty"><h3>No Research plans yet.</h3><p>Create a plan or ask the Agent to propose one in Agent Chat.</p></div><?php endif?>
      <div class="researchTaskPlanList">
      <?php foreach($plans as $plan):
        $total=(int)($plan['total_tasks']??0);$done=(int)($plan['complete_tasks']??0);$pct=$total?round(($done/$total)*100):0;
        $isSelected=$selectedPlanId===(string)$plan['public_id'];
      ?>
        <article class="researchTaskPlanCard card <?=$isSelected?'is-selected':''?>" data-plan-card="<?=h((string)$plan['public_id'])?>">
          <a class="researchTaskPlanOpen" href="/research-tasks.php?agent=<?=rawurlencode($selectedAgentId)?>&plan=<?=rawurlencode((string)$plan['public_id'])?>">
            <div><span><?=h(strtoupper((string)$plan['priority']))?></span><h3><?=h((string)$plan['title'])?></h3></div><strong><?=h(ucfirst((string)$plan['status']))?></strong>
          </a>
          <p><?=h(mb_substr((string)$plan['objective'],0,260))?></p>
          <div class="researchTaskProgress"><span style="width:<?=h((string)$pct)?>%"></span></div>
          <div class="researchTaskPlanMeta"><span><?=$done?> / <?=$total?> complete</span><span><?=h((string)($plan['blocked_tasks']??0))?> waiting</span><span><?=h((string)($plan['review_tasks']??0))?> review</span><?php if($plan['due_at']):?><span>Due <?=h((string)$plan['due_at'])?></span><?php endif?></div>
          <div class="researchTaskPlanActions">
            <?php if(!empty($plan['document_public_id'])):?><a href="/home.php?agent=<?=rawurlencode((string)$selectedAgent['conversation_public_id'])?>&doc=<?=rawurlencode((string)$plan['document_public_id'])?>">Open deliverable</a><?php endif?>
            <?php if($plan['status']==='active'):?><button type="button" data-plan-action="pause_plan">Pause</button><?php elseif($plan['status']==='paused'):?><button type="button" data-plan-action="resume_plan">Resume</button><?php endif?>
          </div>
        </article>
      <?php endforeach?>
      </div>
    </section>
  </section>

  <?php if($selectedPlan):?>
  <section class="researchTaskDetail card" data-plan-detail="<?=h((string)$selectedPlan['public_id'])?>">
    <header class="researchTaskDetailHeader">
      <div><span class="eyebrow">SELECTED PLAN · REVISION <?=h((string)$selectedPlan['current_revision'])?></span><h2><?=h((string)$selectedPlan['title'])?></h2></div>
      <div class="researchTaskDetailActions">
        <?php if($selectedPlan['deliverable']):?>
          <a href="/home.php?agent=<?=rawurlencode((string)$selectedPlan['conversation_public_id'])?>&doc=<?=rawurlencode((string)$selectedPlan['deliverable']['document_public_id'])?>">Open deliverable</a>
          <?php if($selectedPlan['deliverable']['status']==='needs_review'):?><button type="button" data-plan-action="resume_deliverable">Resume Agent updates</button><?php endif?>
          <?php if($selectedPlan['deliverable']['status']!=='finalized'):?><button type="button" data-plan-action="finalize_deliverable">Finalize deliverable</button><?php endif?>
        <?php endif?>
        <button type="button" data-plan-action="archive_plan">Archive plan</button>
      </div>
    </header>

    <details class="researchTaskPlanEditor">
      <summary>Edit plan</summary>
      <form data-plan-update>
        <label>Title<input name="title" value="<?=h((string)$selectedPlan['title'])?>" required maxlength="255"></label>
        <label>Objective<textarea name="objective" rows="5" maxlength="16000" required><?=h((string)$selectedPlan['objective'])?></textarea></label>
        <div class="researchTasksFormSplit">
          <label>Priority<select name="priority"><?php foreach(research_task_priorities() as $key=>$label):?><option value="<?=h($key)?>" <?=$selectedPlan['priority']===$key?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label>
          <label>Due<input type="datetime-local" name="due_at" value="<?=h($selectedPlan['due_at']?date('Y-m-d\TH:i',strtotime((string)$selectedPlan['due_at'])):'')?>"></label>
        </div>
        <label>Deliverable<select name="deliverable_type"><?php foreach(research_task_deliverable_types() as $key=>$label):?><option value="<?=h($key)?>" <?=$selectedPlan['deliverable_type']===$key?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label>
        <label>Deliverable title<input name="deliverable_title" value="<?=h((string)$selectedPlan['deliverable_title'])?>" maxlength="255"></label>
        <label>Revision reason<input name="reason" maxlength="1000" placeholder="Why did the plan change?"></label>
        <button type="submit">Save plan revision</button>
      </form>
    </details>

    <section class="researchTaskTaskList">
      <header><div><span class="eyebrow">TASKS</span><h3>Execution plan</h3></div></header>
      <?php foreach($selectedPlan['tasks'] as $task):?>
      <article class="researchTaskCard is-<?=h((string)$task['status'])?>" data-task-id="<?=h((string)$task['public_id'])?>">
        <header><div><span><?=h(strtoupper(str_replace('_',' ',(string)$task['task_type'])))?> · <?=h(strtoupper((string)$task['priority']))?></span><h4><?=h((string)$task['title'])?></h4></div><strong><?=h(ucfirst(str_replace('_',' ',(string)$task['status'])))?></strong></header>
        <?php if(trim((string)($task['description']??''))!==''):?><p><?=nl2br(h((string)$task['description']))?></p><?php endif?>
        <?php if(!empty($task['blocking_reason'])):?><p class="researchTaskBlocking"><?=h((string)$task['blocking_reason'])?></p><?php endif?>
        <?php if(!empty($task['execution_summary'])):?><div class="researchTaskResult"><span class="eyebrow">LATEST RESULT</span><p><?=nl2br(h((string)$task['execution_summary']))?></p></div><?php endif?>
        <?php if(!empty($task['gates'])):?><div class="researchTaskGates"><?php foreach($task['gates'] as $gate):?><div class="is-<?=h((string)$gate['status'])?>"><span><?=h(str_replace('_',' ',(string)$gate['gate_type']))?></span><small><?=h((string)($gate['detail']??'Not evaluated'))?></small><?php if($gate['status']==='failed'):?><button type="button" data-gate-waive="<?=h((string)$gate['gate_type'])?>">Waive</button><?php endif?></div><?php endforeach?></div><?php endif?>
        <div class="researchTaskActions">
          <?php if(!in_array($task['status'],['complete','done','archived','researching'],true)):?><button type="button" data-task-action="run_task">Run now</button><?php endif?>
          <?php if($task['status']==='review'):?><button type="button" data-task-action="approve">Approve</button><button type="button" data-task-action="reopen">Needs more work</button><?php endif?>
          <button type="button" data-task-edit-toggle>Edit</button>
        </div>
        <form class="researchTaskEditForm" data-task-update hidden>
          <label>Title<input name="title" value="<?=h((string)$task['title'])?>" required maxlength="255"></label>
          <label>Description<textarea name="description" rows="4" maxlength="12000"><?=h((string)$task['description'])?></textarea></label>
          <div class="researchTasksFormSplit">
            <label>Type<select name="task_type"><?php foreach(research_task_types() as $key=>$label):?><option value="<?=h($key)?>" <?=$task['task_type']===$key?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label>
            <label>Priority<select name="priority"><?php foreach(research_task_priorities() as $key=>$label):?><option value="<?=h($key)?>" <?=$task['priority']===$key?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label>
          </div>
          <label>Due<input type="datetime-local" name="due_at" value="<?=h($task['due_at']?date('Y-m-d\TH:i',strtotime((string)$task['due_at'])):'')?>"></label>
          <button type="submit">Save & requeue</button>
        </form>
      </article>
      <?php endforeach?>

      <details class="researchTaskAddTask">
        <summary>+ Add task</summary>
        <form data-task-add>
          <label>Task title<input name="title" required maxlength="255"></label>
          <label>Description<textarea name="description" rows="4" maxlength="12000"></textarea></label>
          <div class="researchTasksFormSplit">
            <label>Type<select name="task_type"><?php foreach(research_task_types() as $key=>$label):?><option value="<?=h($key)?>"><?=h($label)?></option><?php endforeach?></select></label>
            <label>Priority<select name="priority"><?php foreach(research_task_priorities() as $key=>$label):?><option value="<?=h($key)?>" <?=$key==='medium'?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label>
          </div>
          <label>Due<input type="datetime-local" name="due_at"></label>
          <?php if($selectedPlan['tasks']):?><fieldset><legend>Depends on</legend><?php foreach($selectedPlan['tasks'] as $existing):?><label class="researchTaskCheck"><input type="checkbox" name="depends_on[]" value="<?=h((string)$existing['public_id'])?>"> <?=h((string)$existing['title'])?></label><?php endforeach?></fieldset><?php endif?>
          <button type="submit">Add & queue task</button>
        </form>
      </details>
    </section>
  </section>
  <?php endif?>
  <?php endif?>
</main>
<script>
(()=>{
 const csrf=<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const agent=<?=json_encode($selectedAgentId,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const plan=<?=json_encode($selectedPlanId,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 async function post(action,data={}){const r=await fetch('/api/research-tasks.php?action='+encodeURIComponent(action),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j?.error?.message||'Research Task request failed.');return j.data;}
 function formObject(form){const f=new FormData(form),o={};for(const [k,v] of f.entries()){if(k.endsWith('[]')){const key=k.slice(0,-2);(o[key]??=[]).push(v);}else o[k]=v;}return o;}
 document.querySelector('[data-task-agent]')?.addEventListener('change',e=>location.href='/research-tasks.php?agent='+encodeURIComponent(e.target.value));
 document.querySelector('[data-plan-create]')?.addEventListener('submit',async e=>{e.preventDefault();const data=formObject(e.currentTarget);data.agent_id=agent;try{const x=await post('create_plan',data);location.href='/research-tasks.php?agent='+encodeURIComponent(agent)+'&plan='+encodeURIComponent(x.plan.public_id);}catch(err){alert(err.message);}});
 document.querySelector('[data-plan-update]')?.addEventListener('submit',async e=>{e.preventDefault();const data=formObject(e.currentTarget);data.plan_id=plan;try{await post('update_plan',data);location.reload();}catch(err){alert(err.message);}});
 document.querySelector('[data-task-add]')?.addEventListener('submit',async e=>{e.preventDefault();const data=formObject(e.currentTarget);data.plan_id=plan;try{await post('add_task',data);location.reload();}catch(err){alert(err.message);}});
 document.querySelectorAll('[data-plan-action]').forEach(btn=>btn.addEventListener('click',async()=>{const card=btn.closest('[data-plan-card],[data-plan-detail]');const planId=card?.dataset.planCard||card?.dataset.planDetail||plan;if(!planId)return;try{await post(btn.dataset.planAction,{plan_id:planId});location.reload();}catch(err){alert(err.message);}}));
 document.querySelectorAll('[data-task-id]').forEach(card=>{
   const taskId=card.dataset.taskId;
   card.querySelector('[data-task-edit-toggle]')?.addEventListener('click',()=>{const form=card.querySelector('[data-task-update]');if(form)form.hidden=!form.hidden;});
   card.querySelector('[data-task-update]')?.addEventListener('submit',async e=>{e.preventDefault();const data=formObject(e.currentTarget);data.task_id=taskId;try{await post('update_task',data);location.reload();}catch(err){alert(err.message);}});
   card.querySelectorAll('[data-task-action]').forEach(btn=>btn.addEventListener('click',async()=>{try{if(btn.dataset.taskAction==='run_task')await post('run_task',{task_id:taskId});else await post('review_task',{task_id:taskId,approve:btn.dataset.taskAction==='approve'});location.reload();}catch(err){alert(err.message);}}));
   card.querySelectorAll('[data-gate-waive]').forEach(btn=>btn.addEventListener('click',async()=>{if(!confirm('Waive this completion gate? The waiver will be recorded in the task audit history.'))return;try{await post('waive_gate',{task_id:taskId,gate_type:btn.dataset.gateWaive});location.reload();}catch(err){alert(err.message);}}));
 });
})();
</script>
</body></html>
