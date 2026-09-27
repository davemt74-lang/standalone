<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
if(!research_missions_ready($pdo)){header('Location: /upgrade.php?from=research-missions');exit;}
if(function_exists('research_agent_ensure_default'))research_agent_ensure_default($pdo,$u);

$agents=research_agent_list($pdo,$u,50);
$selectedAgentId=trim((string)($_GET['agent']??($agents[0]['public_id']??'')));$selectedAgent=null;
foreach($agents as $agent)if(hash_equals((string)$agent['public_id'],$selectedAgentId)){$selectedAgent=$agent;break;}
if(!$selectedAgent&&$agents){$selectedAgent=$agents[0];$selectedAgentId=(string)$selectedAgent['public_id'];}

$missions=$selectedAgent?research_mission_list($pdo,$u,$selectedAgentId,60):[];
$missionSummary=$selectedAgent?research_mission_summary($pdo,$u,$selectedAgentId):['missions'=>0,'statuses'=>[],'criteria'=>['total'=>0,'satisfied'=>0],'subquestions'=>['total'=>0,'answered'=>0]];
$selectedMissionId=trim((string)($_GET['mission']??($missions[0]['public_id']??'')));
$selectedMission=$selectedMissionId!==''?research_mission_detail($pdo,$u,$selectedMissionId):null;
if(!$selectedMission&&$missions){$selectedMissionId=(string)$missions[0]['public_id'];$selectedMission=research_mission_detail($pdo,$u,$selectedMissionId);}
$programs=$selectedAgent?research_program_list($pdo,$u,$selectedAgentId,100):[];
$csrf=csrf_token();

$missionPct=function(array $mission): int{
    $parts=[];
    $questions=(int)($mission['subquestion_count']??0);if($questions>0)$parts[]=100*((int)($mission['subquestion_answered']??0)/$questions);
    $criteria=(int)($mission['criteria_count']??0);if($criteria>0)$parts[]=100*((int)($mission['criteria_satisfied']??0)/$criteria);
    if((string)($mission['status']??'')==='completed')return 100;
    return $parts?(int)round(array_sum($parts)/count($parts)):0;
};
$eventLabel=fn(string $v)=>ucwords(str_replace('_',' ',$v));
$evidence=[];
if($selectedMission&&!empty($selectedMission['plan']['tasks']))foreach($selectedMission['plan']['tasks'] as $task)foreach((array)($task['evidence_refs']??[]) as $ref)$evidence[]=['task'=>$task['title']]+$ref;
?><!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Research Missions · Annotated</title>
<link rel="stylesheet" href="/assets/css/app.css?v=59.0">
</head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-missions">
<main class="researchLibraryCanvas researchMissionsCanvas">
  <section class="researchLibraryToolbar" aria-label="Research workspace tools">
    <nav class="researchLibraryTabs researchPrimaryActions">
      <a href="/research.php">Research Agents</a>
      <a href="/research-agent-knowledge.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Knowledge</a>
      <a href="/research-reports.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Reports</a>
      <a href="/research-monitoring.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Monitoring</a>
      <a class="active" href="/research-missions.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Missions</a>
      <a href="/research-tasks.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Tasks</a>
      <a href="/research-programs.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Programs</a>
      <a href="/research-evolution.php<?= $selectedAgentId!==''?'?agent='.rawurlencode($selectedAgentId):''?>">Evolution</a>
      <a href="/research-portfolio.php">Portfolio</a>
    </nav>
  </section>

  <header class="researchMissionsHero">
    <div>
      <span class="eyebrow">OUTCOME-DRIVEN RESEARCH</span>
      <h1>Research Missions</h1>
      <p>Turn a question into a durable Agent-run research objective with explicit success criteria, sub-questions, governed execution, evidence, progress, and change response.</p>
    </div>
    <?php if($agents):?><label>Research Agent<select data-mission-agent><?php foreach($agents as $agent):?><option value="<?=h((string)$agent['public_id'])?>" <?=$selectedAgentId===(string)$agent['public_id']?'selected':''?>><?=h((string)$agent['name'])?></option><?php endforeach?></select></label><?php endif?>
  </header>

  <?php if(!$agents):?>
    <section class="card empty"><h2>Create a Research Agent first.</h2><p>Missions belong to an existing Research Agent workspace.</p><a class="button" href="/research.php">Open Research Agents</a></section>
  <?php else:?>
    <section class="researchMissionsStats">
      <div><strong><?=h((string)($missionSummary['missions']??0))?></strong><span>Total Missions</span></div>
      <div><strong><?=h((string)($missionSummary['statuses']['active']??0))?></strong><span>Active</span></div>
      <div><strong><?=h((string)($missionSummary['statuses']['review']??0))?></strong><span>In review</span></div>
      <div><strong><?=h((string)($missionSummary['subquestions']['answered']??0))?> / <?=h((string)($missionSummary['subquestions']['total']??0))?></strong><span>Questions answered</span></div>
      <div><strong><?=h((string)($missionSummary['criteria']['satisfied']??0))?> / <?=h((string)($missionSummary['criteria']['total']??0))?></strong><span>Criteria satisfied</span></div>
    </section>

    <section class="researchMissionsTopGrid">
      <aside class="researchMissionsCreate card">
        <span class="eyebrow">NEW MISSION</span><h2>Define the outcome</h2>
        <form data-mission-create>
          <label>Mission title<input name="title" maxlength="255" required placeholder="Evaluate expansion into a new market"></label>
          <label>Primary research question<textarea name="research_question" rows="3" maxlength="16000" required placeholder="What do we need to know or decide?"></textarea></label>
          <label>Objective<textarea name="objective" rows="4" maxlength="16000" required placeholder="Describe the outcome this Mission should produce."></textarea></label>
          <label>Success definition<textarea name="success_definition" rows="3" maxlength="16000" placeholder="What would make this Mission complete enough to review?"></textarea></label>
          <label>Priority<select name="priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="urgent">Urgent</option></select></label>
          <label>Success criteria <small>One per line</small><textarea name="criteria_lines" rows="4" placeholder="Primary question answered&#10;At least two independent sources support material claims&#10;Key risks are addressed"></textarea></label>
          <label>Sub-questions <small>One per line</small><textarea name="subquestion_lines" rows="5" placeholder="What is current demand?&#10;What are the major risks?&#10;What evidence could reverse the conclusion?"></textarea></label>
          <button class="button" type="submit">Create Mission</button>
        </form>
        <p class="meta">Creating a Mission does not start autonomous work. Build and review its Plan first, then explicitly start execution.</p>
      </aside>

      <section class="researchMissionsList">
        <header><div><span class="eyebrow">MISSIONS</span><h2><?=h((string)$selectedAgent['name'])?></h2></div><small><?=h((string)count($missions))?> Mission<?=count($missions)===1?'':'s'?></small></header>
        <?php if(!$missions):?><div class="card empty"><h3>No Missions yet.</h3><p>Create an outcome-driven Mission to organize research around a concrete question.</p></div><?php endif?>
        <div class="researchMissionCardGrid">
        <?php foreach($missions as $mission):$pct=$missionPct($mission);$isSelected=$selectedMissionId===(string)$mission['public_id'];?>
          <article class="researchMissionCard card <?=$isSelected?'is-selected':''?>">
            <a href="/research-missions.php?agent=<?=rawurlencode($selectedAgentId)?>&mission=<?=rawurlencode((string)$mission['public_id'])?>">
              <div><span><?=h(strtoupper((string)$mission['priority']))?></span><h3><?=h((string)$mission['title'])?></h3></div>
              <strong><?=h(ucfirst((string)$mission['status']))?></strong>
            </a>
            <p><?=h(mb_substr((string)$mission['research_question'],0,240))?></p>
            <div class="researchMissionProgress"><span style="width:<?=h((string)$pct)?>%"></span></div>
            <div class="researchMissionMeta"><span><?=$pct?>% complete</span><span><?=h((string)$mission['subquestion_answered'])?> / <?=h((string)$mission['subquestion_count'])?> questions</span><span><?=h((string)$mission['criteria_satisfied'])?> / <?=h((string)$mission['criteria_count'])?> criteria</span></div>
          </article>
        <?php endforeach?>
        </div>
      </section>
    </section>

    <?php if($selectedMission):$progress=(array)$selectedMission['progress'];$plan=(array)($selectedMission['plan']??[]);$program=(array)($selectedMission['program']??[]);?>
    <section class="researchMissionCommandCenter" data-mission-detail="<?=h((string)$selectedMission['public_id'])?>">
      <header class="researchMissionCommandHeader card">
        <div>
          <span class="eyebrow">MISSION · REVISION <?=h((string)$selectedMission['current_revision'])?></span>
          <h2><?=h((string)$selectedMission['title'])?></h2>
          <p class="researchMissionQuestion"><?=h((string)$selectedMission['research_question'])?></p>
          <p><?=nl2br(h((string)$selectedMission['objective']))?></p>
        </div>
        <div class="researchMissionCommandActions">
          <a class="button secondary" href="/research-reviews.php?type=mission&subject=<?=rawurlencode((string)$selectedMission['public_id'])?>">Request team review</a>
          <a class="button secondary" href="/research-reports.php?agent=<?=rawurlencode($selectedAgentId)?>&view=run&type=mission_brief&focus_query=<?=rawurlencode((string)$selectedMission['title'])?>">Run Mission Brief</a>
          <a class="button secondary" href="/research-reports.php?agent=<?=rawurlencode($selectedAgentId)?>&view=run&type=mission_review&focus_query=<?=rawurlencode((string)$selectedMission['title'])?>">Run Review Brief</a>
          <?php if(empty($selectedMission['plan_public_id'])):?><button type="button" class="button" data-mission-action="create_plan">Build Mission Plan</button>
          <?php elseif(($plan['status']??'')==='paused'):?><button type="button" class="button" data-mission-action="start">Start Mission</button>
          <?php elseif(($plan['status']??'')==='active'):?><button type="button" data-mission-action="pause">Pause execution</button><?php endif?>
          <?php if(($selectedMission['status']??'')==='review'&&!empty($progress['completion_readiness']['ready'])):?><button type="button" class="button" data-mission-status="completed">Mark Mission complete</button><?php endif?>
          <?php if(in_array((string)$selectedMission['status'],['completed','cancelled'],true)):?><button type="button" data-mission-status="active">Reactivate</button><?php endif?>
          <?php if(!in_array((string)$selectedMission['status'],['cancelled','archived'],true)):?><button type="button" data-mission-status="cancelled">Cancel</button><?php endif?>
          <?php if((string)$selectedMission['status']!=='archived'):?><button type="button" data-mission-status="archived">Archive</button><?php endif?>
        </div>
      </header>

      <section class="researchMissionProgressGrid">
        <article class="card researchMissionProgressHero"><span class="eyebrow">MISSION PROGRESS</span><strong><?=h((string)($progress['percent_complete']??0))?>%</strong><div class="researchMissionProgress"><span style="width:<?=h((string)($progress['percent_complete']??0))?>%"></span></div><p><?=!empty($progress['completion_readiness']['ready'])?'Ready for human completion review.':'Still has unresolved work before completion review.'?></p></article>
        <article class="card"><span class="eyebrow">TASKS</span><strong><?=h((string)($progress['plan']['completed']??0))?> / <?=h((string)($progress['plan']['total']??0))?></strong><p><?=h((string)($progress['plan']['blocked']??0))?> blocked · <?=h((string)($progress['plan']['review']??0))?> review</p></article>
        <article class="card"><span class="eyebrow">SUB-QUESTIONS</span><strong><?=h((string)($progress['subquestions']['answered']??0))?> / <?=h((string)($progress['subquestions']['total']??0))?></strong><p><?=h((string)($progress['subquestions']['blocked']??0))?> blocked</p></article>
        <article class="card"><span class="eyebrow">SUCCESS CRITERIA</span><strong><?=h((string)(($progress['criteria']['satisfied']??0)+($progress['criteria']['waived']??0)))?> / <?=h((string)($progress['criteria']['total']??0))?></strong><p><?=h((string)($progress['criteria']['failed']??0))?> failed</p></article>
        <article class="card"><span class="eyebrow">CONFIDENCE</span><strong><?=($progress['confidence']['average']??null)!==null?h((string)round(((float)$progress['confidence']['average'])*100)).'%':'—'?></strong><p><?=h((string)($progress['confidence']['rated']??0))?> / <?=h((string)($progress['confidence']['total']??0))?> questions rated</p></article>
        <article class="card"><span class="eyebrow">EVIDENCE FLAGS</span><strong><?=h((string)(($progress['evidence_flags']['open_contradictions']??0)+($progress['evidence_flags']['open_evidence_gaps']??0)))?></strong><p><?=h((string)($progress['evidence_flags']['open_contradictions']??0))?> contradictions · <?=h((string)($progress['evidence_flags']['open_evidence_gaps']??0))?> gaps</p></article>
      </section>

      <?php if(!empty($progress['blockers'])||!empty($progress['completion_readiness']['reasons'])):?>
      <section class="card researchMissionAttention"><span class="eyebrow">NEEDS ATTENTION</span><h3>What is preventing completion?</h3>
        <?php if(!empty($progress['completion_readiness']['reasons'])):?><ul><?php foreach($progress['completion_readiness']['reasons'] as $reason):?><li><?=h((string)$reason)?></li><?php endforeach?></ul><?php endif?>
        <?php foreach((array)$progress['blockers'] as $blocker):?><div class="researchMissionBlocker"><strong><?=h((string)$blocker['title'])?></strong><span><?=h($eventLabel((string)$blocker['type']))?> · <?=h((string)$blocker['status'])?></span><?php if(!empty($blocker['detail'])):?><p><?=h((string)$blocker['detail'])?></p><?php endif?></div><?php endforeach?>
      </section>
      <?php endif?>

      <section class="researchMissionWorkspaceGrid">
        <article class="card researchMissionQuestions"><span class="eyebrow">SUB-QUESTIONS</span><h3>What still needs an answer?</h3>
          <?php if(empty($selectedMission['subquestions'])):?><p>No explicit sub-questions.</p><?php endif?>
          <?php foreach((array)$selectedMission['subquestions'] as $sub):?><div class="researchMissionQuestionRow is-<?=h((string)$sub['status'])?>"><div><strong><?=h((string)$sub['question'])?></strong><?php if(!empty($sub['answer_summary'])):?><p><?=h((string)$sub['answer_summary'])?></p><?php endif?></div><span><?=h($eventLabel((string)$sub['status']))?><?=($sub['confidence']??null)!==null?' · '.h((string)round(((float)$sub['confidence'])*100)).'%':''?></span></div><?php endforeach?>
        </article>

        <article class="card researchMissionCriteria"><span class="eyebrow">SUCCESS CRITERIA</span><h3>Definition of done</h3>
          <?php if(!empty($selectedMission['success_definition'])):?><p class="researchMissionSuccessDefinition"><?=nl2br(h((string)$selectedMission['success_definition']))?></p><?php endif?>
          <?php foreach((array)$selectedMission['criteria'] as $criterion):?><div class="researchMissionCriterionRow is-<?=h((string)$criterion['status'])?>"><div><strong><?=h((string)$criterion['label'])?></strong><?php if(!empty($criterion['description'])):?><p><?=h((string)$criterion['description'])?></p><?php endif?></div><span><?=h($eventLabel((string)$criterion['status']))?></span></div><?php endforeach?>
        </article>
      </section>

      <?php if($plan):?>
      <section class="card researchMissionPlanPanel">
        <header><div><span class="eyebrow">MISSION PLAN</span><h3><?=h((string)$plan['title'])?></h3><p><?=h((string)$plan['objective'])?></p></div><div class="researchMissionPanelLinks"><a href="/research-tasks.php?agent=<?=rawurlencode($selectedAgentId)?>&plan=<?=rawurlencode((string)$plan['public_id'])?>">Open in Tasks</a><?php if(!empty($plan['deliverable']['document_public_id'])):?><a href="/home.php?agent=<?=rawurlencode((string)$selectedAgent['conversation_public_id'])?>&doc=<?=rawurlencode((string)$plan['deliverable']['document_public_id'])?>">Open deliverable</a><?php endif?></div></header>
        <div class="researchMissionTaskGraph">
          <?php foreach((array)($plan['tasks']??[]) as $idx=>$task):?><article class="researchMissionTaskNode is-<?=h((string)$task['status'])?>"><span><?=($idx+1)?></span><div><strong><?=h((string)$task['title'])?></strong><small><?=h($eventLabel((string)$task['task_type']))?> · <?=h($eventLabel((string)$task['status']))?></small><?php if(!empty($task['execution_summary'])):?><p><?=h(mb_substr((string)$task['execution_summary'],0,420))?></p><?php endif?></div></article><?php endforeach?>
        </div>
      </section>
      <?php endif?>

      <section class="researchMissionWorkspaceGrid">
        <article class="card researchMissionEvidence"><span class="eyebrow">EVIDENCE</span><h3>Evidence attached to Mission tasks</h3>
          <?php if(!$evidence):?><p>No Task evidence references yet.</p><?php endif?>
          <?php foreach(array_slice($evidence,0,80) as $ref):?><div><strong><?=h((string)$ref['task'])?></strong><span><?=h(strtoupper((string)$ref['ref_type']))?> · <?=h((string)$ref['ref_public_id'])?></span><?php if(!empty($ref['locator'])):?><small><?=h((string)$ref['locator'])?></small><?php endif?></div><?php endforeach?>
        </article>

        <article class="card researchMissionAnswer"><span class="eyebrow">PRIMARY ANSWER</span><h3>Current synthesis</h3>
          <?php if(!empty($progress['primary_answer']['summary'])):?><p><?=nl2br(h((string)$progress['primary_answer']['summary']))?></p><?php else:?><p>The synthesis task has not produced a current answer yet.</p><?php endif?>
        </article>
      </section>

      <section class="card researchMissionProgramPanel">
        <header><div><span class="eyebrow">CHANGE RESPONSE</span><h3>Research Program</h3><p>Use the existing Program scheduler to keep this Mission current after the initial research cycle.</p></div></header>
        <?php if($program):?>
          <div class="researchMissionProgramBound">
            <div><strong><?=h((string)$program['title'])?></strong><span><?=h($eventLabel((string)$program['status']))?> · <?=h($eventLabel((string)$program['cadence']))?> · materiality <?=h((string)$program['materiality_threshold'])?></span><small><?=!empty($program['next_run_at'])?'Next run '.h((string)$program['next_run_at']):'No run scheduled'?></small></div>
            <div class="researchMissionProgramActions"><?php if(($program['status']??'')==='active'):?><button data-program-status="paused">Pause watch</button><?php elseif(($program['status']??'')==='paused'):?><button data-program-status="active">Start watch</button><?php endif?><a href="/research-programs.php?agent=<?=rawurlencode($selectedAgentId)?>&program=<?=rawurlencode((string)$program['public_id'])?>">Open Program</a><button data-mission-action="unbind_program">Unbind</button></div>
          </div>
        <?php else:?>
          <form class="researchMissionProgramBind" data-program-bind>
            <label>Use existing Program<select name="program_id"><option value="">Create a new paused Mission Watch Program</option><?php foreach($programs as $candidate):?><option value="<?=h((string)$candidate['public_id'])?>"><?=h((string)$candidate['title'])?> · <?=h((string)$candidate['status'])?></option><?php endforeach?></select></label>
            <label>Cadence for a new watch<select name="cadence"><option value="hourly">Hourly</option><option value="daily" selected>Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="manual">Manual</option></select></label>
            <button type="submit">Bind Program</button>
          </form>
        <?php endif?>
      </section>

      <section class="researchMissionWorkspaceGrid researchMissionHistoryGrid">
        <article class="card"><span class="eyebrow">MISSION HISTORY</span><h3>Append-only events</h3><div class="researchMissionHistory"><?php foreach((array)$selectedMission['events'] as $event):?><div><strong><?=h($eventLabel((string)$event['event_type']))?></strong><span><?=h((string)$event['created_at'])?> · <?=h((string)$event['actor_type'])?></span></div><?php endforeach?></div></article>
        <article class="card"><span class="eyebrow">CONFIGURATION HISTORY</span><h3>Mission revisions</h3><div class="researchMissionHistory"><?php foreach((array)$selectedMission['versions'] as $version):?><div><strong>Revision <?=h((string)$version['revision_number'])?></strong><span><?=h((string)$version['created_at'])?><?=!empty($version['change_reason'])?' · '.h((string)$version['change_reason']):''?></span></div><?php endforeach?></div></article>
      </section>
    </section>
    <?php endif?>
  <?php endif?>
</main>
<script>
(()=>{
 const csrf=<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const agent=<?=json_encode($selectedAgentId,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const mission=<?=json_encode($selectedMissionId,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 async function post(action,data={}){const r=await fetch('/api/research-missions.php?action='+encodeURIComponent(action),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j?.error?.message||'Research Mission request failed.');return j.data;}
 function formObject(form){const f=new FormData(form),o={};for(const [k,v] of f.entries())o[k]=v;return o;}
 document.querySelector('[data-mission-agent]')?.addEventListener('change',e=>location.href='/research-missions.php?agent='+encodeURIComponent(e.target.value));
 document.querySelector('[data-mission-create]')?.addEventListener('submit',async e=>{e.preventDefault();const data=formObject(e.currentTarget);data.agent_id=agent;data.success_criteria=(data.criteria_lines||'').split(/\r?\n/).map(v=>v.trim()).filter(Boolean).map(label=>({label}));data.subquestions=(data.subquestion_lines||'').split(/\r?\n/).map(v=>v.trim()).filter(Boolean).map(question=>({question,priority:data.priority}));delete data.criteria_lines;delete data.subquestion_lines;try{const x=await post('create',data);location.href='/research-missions.php?agent='+encodeURIComponent(agent)+'&mission='+encodeURIComponent(x.mission.public_id);}catch(err){alert(err.message);}});
 document.querySelectorAll('[data-mission-action]').forEach(btn=>btn.addEventListener('click',async()=>{if(!mission)return;const action=btn.dataset.missionAction;if((action==='unbind_program'||action==='pause')&&!confirm(action==='unbind_program'?'Unbind this Research Program from the Mission? The Program itself will remain intact.':'Pause Mission execution?'))return;try{await post(action,{mission_id:mission});location.reload();}catch(err){alert(err.message);}}));
 document.querySelectorAll('[data-mission-status]').forEach(btn=>btn.addEventListener('click',async()=>{if(!mission)return;const status=btn.dataset.missionStatus;if((status==='cancelled'||status==='archived')&&!confirm('Set this Mission to '+status+'?'))return;try{await post('set_status',{mission_id:mission,status});location.reload();}catch(err){alert(err.message);}}));
 document.querySelector('[data-program-bind]')?.addEventListener('submit',async e=>{e.preventDefault();const data=formObject(e.currentTarget);data.mission_id=mission;try{await post('bind_program',data);location.reload();}catch(err){alert(err.message);}});
 document.querySelectorAll('[data-program-status]').forEach(btn=>btn.addEventListener('click',async()=>{try{await post('set_program_status',{mission_id:mission,status:btn.dataset.programStatus});location.reload();}catch(err){alert(err.message);}}));
})();
</script>
</body></html>
