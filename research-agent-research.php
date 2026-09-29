<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
$ctx=research_agent_shell_resolve($pdo,$u,trim((string)($_GET['agent']??'')));$agents=$ctx['agents'];$selected=$ctx['agent'];$selectedId=(string)$ctx['agent_id'];
$missions=[];$taskSummary=['active'=>0,'waiting'=>0,'review'=>0,'complete'=>0];$decisions=[];$plans=[];$programs=[];$loadError='';
if($selected){
    try{
        if(function_exists('research_missions_ready')&&research_missions_ready($pdo))$missions=research_mission_list($pdo,$u,$selectedId,100);
        if(function_exists('research_tasks_ready')&&research_tasks_ready($pdo))$taskSummary=research_task_summary($pdo,$u,$selectedId);
        if(function_exists('research_decisions_ready')&&research_decisions_ready($pdo))$decisions=research_decision_list($pdo,$u,$selectedId,100);
        if(function_exists('research_action_plans_ready')&&research_action_plans_ready($pdo))$plans=research_action_plan_list($pdo,$u,$selectedId,null,100);
        if(function_exists('research_programs_ready')&&research_programs_ready($pdo))$programs=research_program_list($pdo,$u,$selectedId,100);
    }catch(Throwable $e){$loadError=$e->getMessage();}
}
$countStatus=function(array $rows,array $statuses): int {return count(array_filter($rows,fn($row)=>in_array((string)($row['status']??''),$statuses,true)));};
$missionActive=$countStatus($missions,['draft','active','running','paused']);
$decisionOpen=$countStatus($decisions,['draft','proposed','accepted','deferred','reopened']);
$planOpen=$countStatus($plans,['draft','active','paused','blocked']);
$programActive=$countStatus($programs,['active','paused']);
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Research · <?=h((string)($selected['name']??'Research Agent'))?> · Annotated</title><link rel="stylesheet" href="/assets/css/app.css?v=74.2"></head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-agent-research" data-research-agent-id="<?=h($selectedId)?>">
<main class="researchLibraryCanvas researchAgentResearchCanvas">
  <?php if($selected):?><?=research_agent_shell_render($selected,$agents,'research',['workspace_controls'=>true])?><?php endif?>
  <header class="researchAgentResearchHero">
    <div><span class="eyebrow">RESEARCH</span><h1><?=h((string)($selected['name']??'Research Agent'))?></h1><p>One view of what this Agent is researching, deciding, following through on, and repeating. The existing Mission, Task, Decision, Action Plan, and Program engines remain authoritative underneath.</p></div>
  </header>
  <?php if(!$selected):?><section class="card empty"><h2>Create a Research Agent first.</h2><p>Research work is organized around a Research Agent.</p><a class="button" href="/research.php">Open Research Agents</a></section>
  <?php else:?>
    <?php if($loadError!==''):?><div class="error"><?=h($loadError)?></div><?php endif?>
    <section class="researchAgentResearchOverview" aria-label="Research Agent work">
      <a class="card researchAgentResearchCard" href="/research-missions.php?agent=<?=h(rawurlencode($selectedId))?>">
        <span class="eyebrow">MISSIONS</span><strong><?=h((string)count($missions))?></strong><h2>What are we trying to learn or decide?</h2><p><?=h((string)$missionActive)?> open or active mission(s). Missions remain the research objective.</p><span class="researchAgentResearchOpen">Open Missions →</span>
      </a>
      <a class="card researchAgentResearchCard" href="/research-tasks.php?agent=<?=h(rawurlencode($selectedId))?>">
        <span class="eyebrow">TASKS</span><strong><?=h((string)(($taskSummary['active']??0)+($taskSummary['waiting']??0)+($taskSummary['review']??0)))?></strong><h2>What work is happening now?</h2><p><?=h((string)($taskSummary['active']??0))?> active · <?=h((string)($taskSummary['waiting']??0))?> waiting · <?=h((string)($taskSummary['review']??0))?> review.</p><span class="researchAgentResearchOpen">Open Tasks →</span>
      </a>
      <a class="card researchAgentResearchCard" href="/research-decisions.php?agent=<?=h(rawurlencode($selectedId))?>">
        <span class="eyebrow">DECISIONS</span><strong><?=h((string)count($decisions))?></strong><h2>What did the research conclude?</h2><p><?=h((string)$decisionOpen)?> current Decision(s). Phase 71 remains the authoritative Decision Ledger and Outcome Memory.</p><span class="researchAgentResearchOpen">Open Decisions →</span>
      </a>
      <a class="card researchAgentResearchCard" href="/research-action-plans.php?agent=<?=h(rawurlencode($selectedId))?>">
        <span class="eyebrow">FOLLOW-THROUGH</span><strong><?=h((string)count($plans))?></strong><h2>What happens after a Decision?</h2><p><?=h((string)$planOpen)?> open Action Plan(s). Existing Phase 72 lifecycle, variance, review, and outcome handoff stay intact.</p><span class="researchAgentResearchOpen">Open Follow-through →</span>
      </a>
      <a class="card researchAgentResearchCard" href="/research-programs.php?agent=<?=h(rawurlencode($selectedId))?>">
        <span class="eyebrow">RECURRING</span><strong><?=h((string)count($programs))?></strong><h2>What should this Agent keep researching?</h2><p><?=h((string)$programActive)?> active or paused Program(s). Programs remain the recurring research engine.</p><span class="researchAgentResearchOpen">Open Recurring Research →</span>
      </a>
    </section>
    <section class="card researchAgentResearchGuide"><span class="eyebrow">SIMPLIFIED WORKFLOW</span><h2>Mission → Tasks → Decision → Follow-through</h2><p>Recurring Research keeps the loop current. Monitoring, evidence retrieval, reviews, outcome memory, and Agent cognition continue to run through their existing engines without becoming separate primary destinations.</p></section>
  <?php endif?>
</main>
<script src="/assets/js/research-agent-unified-shell.js?v=74.2"></script>
</body></html>
