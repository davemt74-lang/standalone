<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
$ctx=research_agent_shell_resolve($pdo,$u,trim((string)($_GET['agent']??'')));$agents=$ctx['agents'];$selected=$ctx['agent'];$selectedId=(string)$ctx['agent_id'];
$view=research_agent_research_view((string)($_GET['view']??'missions'));
$items=[];$summary=[];$loadError='';$engineLinks=[];
if($selected){
    $engineLinks=research_agent_research_engine_links($selected);
    try{
        if($view==='missions'&&function_exists('research_missions_ready')&&research_missions_ready($pdo)){
            $items=research_mission_list($pdo,$u,$selectedId,100);$summary=research_mission_summary($pdo,$u,$selectedId);
        }elseif($view==='tasks'&&function_exists('research_tasks_ready')&&research_tasks_ready($pdo)){
            $items=research_task_plan_list($pdo,$u,$selectedId,100);$summary=research_task_summary($pdo,$u,$selectedId);
        }elseif($view==='decisions'&&function_exists('research_decisions_ready')&&research_decisions_ready($pdo)){
            $items=research_decision_list($pdo,$u,$selectedId,100);$summary=research_decision_summary($pdo,$u,$selectedId);
        }elseif($view==='follow_through'&&function_exists('research_action_plans_ready')&&research_action_plans_ready($pdo)){
            $items=research_action_plan_list($pdo,$u,$selectedId,null,100);$statuses=[];foreach($items as $row){$st=(string)($row['status']??'unknown');$statuses[$st]=($statuses[$st]??0)+1;}$summary=['total'=>count($items),'statuses'=>$statuses];
        }elseif($view==='recurring'&&function_exists('research_programs_ready')&&research_programs_ready($pdo)){
            $items=research_program_list($pdo,$u,$selectedId,100);$summary=research_program_summary($pdo,$u,$selectedId);
        }
    }catch(Throwable $e){$loadError=$e->getMessage();}
}
$views=research_agent_research_views();$meta=$views[$view]??$views['missions'];
$statusLabel=static fn(string $v): string=>ucwords(str_replace('_',' ',$v));
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h((string)$meta['label'])?> · <?=h((string)($selected['name']??'Research Agent'))?> · Annotated</title><link rel="stylesheet" href="/assets/css/app.css?v=74.4"></head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-agent-research" data-research-agent-id="<?=h($selectedId)?>" data-research-view="<?=h($view)?>">
<main class="researchLibraryCanvas researchUnifiedCanvas">
  <?php if($selected):?><?=research_agent_shell_render($selected,$agents,'research',['workspace_controls'=>true])?><?php endif?>
  <header class="researchUnifiedHero">
    <div><span class="eyebrow">RESEARCH</span><h1><?=h((string)($selected['name']??'Research Agent'))?></h1><p>One Research workspace for objectives, execution, decisions, follow-through, and recurring work. Existing lifecycle engines remain authoritative underneath.</p></div>
  </header>
  <?php if(!$selected):?><section class="card empty"><h2>Create a Research Agent first.</h2><p>Research work is organized around a Research Agent.</p><a class="button" href="/research.php">Open Research Agents</a></section>
  <?php else:?>
    <?=research_agent_research_render_nav($selectedId,$view)?>
    <?php if($loadError!==''):?><div class="error"><?=h($loadError)?></div><?php endif?>
    <section class="researchUnifiedIntro card">
      <div><span class="eyebrow"><?=h(strtoupper((string)$meta['label']))?></span><h2><?=h((string)$meta['label'])?></h2><p><?=h((string)$meta['description'])?></p></div>
      <div class="researchUnifiedActions"><?php foreach(($engineLinks[$view]??[]) as $link):?><?php if((string)$link['href']!==''):?><a class="button secondary" href="<?=h((string)$link['href'])?>"><?=h((string)$link['label'])?></a><?php endif?><?php endforeach?></div>
    </section>

    <?php if($view==='missions'):?>
      <section class="researchUnifiedStats">
        <div><strong><?=h((string)($summary['missions']??count($items)))?></strong><span>Total Missions</span></div>
        <div><strong><?=h((string)($summary['statuses']['active']??0))?></strong><span>Active</span></div>
        <div><strong><?=h((string)($summary['statuses']['review']??0))?></strong><span>In review</span></div>
        <div><strong><?=h((string)($summary['criteria']['satisfied']??0))?> / <?=h((string)($summary['criteria']['total']??0))?></strong><span>Criteria satisfied</span></div>
      </section>
      <section class="researchUnifiedList">
      <?php if(!$items):?><div class="card empty"><h3>No Missions yet.</h3><p>Create a Mission when the Agent has a concrete question or outcome to pursue.</p><a class="button" href="/research-missions.php?agent=<?=h(rawurlencode($selectedId))?>">Create Mission</a></div><?php endif?>
      <?php foreach($items as $row):?>
        <a class="card researchUnifiedRow" href="<?=h(research_agent_research_item_href('missions',$selectedId,$row))?>">
          <div class="researchUnifiedRowMain"><div class="researchUnifiedBadges"><span><?=h($statusLabel((string)($row['status']??'')))?></span><span><?=h(strtoupper((string)($row['priority']??'medium')))?></span></div><h3><?=h((string)($row['title']??'Untitled Mission'))?></h3><p><?=h(mb_substr((string)($row['research_question']??$row['objective']??''),0,280))?></p></div>
          <div class="researchUnifiedRowMetrics"><span><strong><?=h((string)($row['subquestion_answered']??0))?> / <?=h((string)($row['subquestion_count']??0))?></strong>questions</span><span><strong><?=h((string)($row['criteria_satisfied']??0))?> / <?=h((string)($row['criteria_count']??0))?></strong>criteria</span></div>
        </a>
      <?php endforeach?></section>

    <?php elseif($view==='tasks'):?>
      <section class="researchUnifiedStats">
        <div><strong><?=h((string)($summary['active']??0))?></strong><span>Active</span></div><div><strong><?=h((string)($summary['waiting']??0))?></strong><span>Waiting / blocked</span></div><div><strong><?=h((string)($summary['review']??0))?></strong><span>Review</span></div><div><strong><?=h((string)($summary['complete']??0))?></strong><span>Complete</span></div>
      </section>
      <section class="researchUnifiedList">
      <?php if(!$items):?><div class="card empty"><h3>No Research plans yet.</h3><p>Plans and Tasks execute Mission work and evidence follow-up.</p><a class="button" href="/research-tasks.php?agent=<?=h(rawurlencode($selectedId))?>">Create Plan</a></div><?php endif?>
      <?php foreach($items as $row):$total=(int)($row['total_tasks']??0);$done=(int)($row['complete_tasks']??0);?>
        <a class="card researchUnifiedRow" href="<?=h(research_agent_research_item_href('tasks',$selectedId,$row))?>">
          <div class="researchUnifiedRowMain"><div class="researchUnifiedBadges"><span><?=h($statusLabel((string)($row['status']??'')))?></span><span><?=h(strtoupper((string)($row['priority']??'medium')))?></span></div><h3><?=h((string)($row['title']??'Untitled Plan'))?></h3><p><?=h(mb_substr((string)($row['objective']??''),0,280))?></p></div>
          <div class="researchUnifiedRowMetrics"><span><strong><?=$done?> / <?=$total?></strong>complete</span><span><strong><?=h((string)($row['blocked_tasks']??0))?></strong>waiting</span><span><strong><?=h((string)($row['review_tasks']??0))?></strong>review</span></div>
        </a>
      <?php endforeach?></section>

    <?php elseif($view==='decisions'):?>
      <section class="researchUnifiedStats">
        <div><strong><?=h((string)($summary['total']??count($items)))?></strong><span>Decisions</span></div><div><strong><?=h((string)($summary['statuses']['proposed']??0))?></strong><span>Proposed</span></div><div><strong><?=h((string)($summary['statuses']['accepted']??0))?></strong><span>Accepted</span></div><div><strong><?=h((string)($summary['statuses']['reopened']??0))?></strong><span>Reopened</span></div>
      </section>
      <section class="researchUnifiedList">
      <?php if(!$items):?><div class="card empty"><h3>No Decisions yet.</h3><p>Decision Memory appears here when research is handed into the Decision Ledger.</p><a class="button" href="/research-decisions.php">Open Decision Command Center</a></div><?php endif?>
      <?php foreach($items as $row):?>
        <a class="card researchUnifiedRow" href="<?=h(research_agent_research_item_href('decisions',$selectedId,$row))?>">
          <div class="researchUnifiedRowMain"><div class="researchUnifiedBadges"><span><?=h($statusLabel((string)($row['status']??'')))?></span><span><?=h($statusLabel((string)($row['decision_type']??'decision')))?></span><?php if(!empty($row['reconsiderations'])):?><span>RECONSIDERATION</span><?php endif?></div><h3><?=h((string)($row['title']??'Untitled Decision'))?></h3><p><?=h(mb_substr((string)($row['statement']??$row['rationale']??''),0,280))?></p></div>
          <div class="researchUnifiedRowMetrics"><span><strong><?=h((string)count((array)($row['outcomes']??[])))?></strong>outcomes</span><span><strong><?=h((string)count((array)($row['reconsiderations']??[])))?></strong>reviews</span><?php if(($row['confidence']??null)!==null):?><span><strong><?=h((string)round(((float)$row['confidence'])*100))?>%</strong>confidence</span><?php endif?></div>
        </a>
      <?php endforeach?></section>

    <?php elseif($view==='follow_through'):?>
      <section class="researchUnifiedStats">
        <div><strong><?=h((string)($summary['total']??count($items)))?></strong><span>Action Plans</span></div><div><strong><?=h((string)($summary['statuses']['active']??0))?></strong><span>Active</span></div><div><strong><?=h((string)($summary['statuses']['paused']??0))?></strong><span>Paused</span></div><div><strong><?=h((string)($summary['statuses']['completed']??0))?></strong><span>Completed</span></div>
      </section>
      <section class="researchUnifiedList">
      <?php if(!$items):?><div class="card empty"><h3>No Follow-through yet.</h3><p>Accepted Decisions can hand off into governed Action Plans without creating a second execution authority.</p><a class="button" href="/research-decisions.php">Open Decisions</a></div><?php endif?>
      <?php foreach($items as $row):?>
        <a class="card researchUnifiedRow" href="<?=h(research_agent_research_item_href('follow_through',$selectedId,$row))?>">
          <div class="researchUnifiedRowMain"><div class="researchUnifiedBadges"><span><?=h($statusLabel((string)($row['status']??'')))?></span><span><?=h(strtoupper((string)($row['priority']??'medium')))?></span><?php if(!empty($row['source_stale'])):?><span>SOURCE CHANGED</span><?php endif?></div><h3><?=h((string)($row['title']??'Untitled Action Plan'))?></h3><p><?=h(mb_substr((string)($row['objective']??$row['expected_result']??''),0,280))?></p></div>
          <div class="researchUnifiedRowMetrics"><span><strong><?=h((string)($row['decision_title']??'Decision'))?></strong>source</span><?php if(!empty($row['due_on'])):?><span><strong><?=h((string)$row['due_on'])?></strong>due</span><?php endif?></div>
        </a>
      <?php endforeach?></section>

    <?php else:?>
      <section class="researchUnifiedStats">
        <div><strong><?=h((string)($summary['active']??0))?></strong><span>Active Programs</span></div><div><strong><?=h((string)($summary['paused']??0))?></strong><span>Paused</span></div><div><strong><?=h((string)($summary['review_tasks']??0))?></strong><span>Tasks in review</span></div><div><strong><?=h((string)($summary['failed_runs']??0))?></strong><span>Failed runs</span></div>
      </section>
      <section class="researchUnifiedList">
      <?php if(!$items):?><div class="card empty"><h3>No Recurring Research yet.</h3><p>Programs keep this Agent current while preserving every run, delta, deliverable, and review boundary.</p><a class="button" href="/research-programs.php?agent=<?=h(rawurlencode($selectedId))?>">Create Recurring Research</a></div><?php endif?>
      <?php foreach($items as $row):?>
        <a class="card researchUnifiedRow" href="<?=h(research_agent_research_item_href('recurring',$selectedId,$row))?>">
          <div class="researchUnifiedRowMain"><div class="researchUnifiedBadges"><span><?=h($statusLabel((string)($row['status']??'')))?></span><span><?=h($statusLabel((string)($row['cadence']??'manual')))?></span></div><h3><?=h((string)($row['title']??'Untitled Program'))?></h3><p><?=h(mb_substr((string)($row['objective']??''),0,280))?></p></div>
          <div class="researchUnifiedRowMetrics"><span><strong><?=h((string)($row['completed_runs']??0))?></strong>completed</span><span><strong><?=h((string)($row['failed_runs']??0))?></strong>failed</span><?php if(!empty($row['next_run_at'])):?><span><strong><?=h((string)$row['next_run_at'])?></strong>next run</span><?php endif?></div>
        </a>
      <?php endforeach?></section>
      <section class="card researchUnifiedCompatibility"><span class="eyebrow">COMPATIBILITY</span><h3>Programs are the primary recurring Research model.</h3><p>Phase 18 Automations remain available for existing scheduled and watch-triggered workflows, but they are no longer a separate primary Research destination.</p><a href="/research-automations.php">Open legacy Automations</a></section>
    <?php endif?>
  <?php endif?>
</main>
<script src="/assets/js/research-agent-unified-shell.js?v=74.4"></script>
</body></html>
