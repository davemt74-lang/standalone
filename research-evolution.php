<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
if(!research_longitudinal_ready($pdo)){header('Location: /upgrade.php?from=research-evolution');exit;}
if(function_exists('research_agent_ensure_default'))research_agent_ensure_default($pdo,$u);
$agents=research_agent_list($pdo,$u,50);$selectedId=trim((string)($_REQUEST['agent']??($agents[0]['public_id']??'')));$selected=null;
foreach($agents as $a)if(hash_equals((string)$a['public_id'],$selectedId)){$selected=$a;break;}
if(!$selected&&$agents){$selected=$agents[0];$selectedId=(string)$selected['public_id'];}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    try{
        if(!$selected)throw new RuntimeException('Choose a Research Agent.');
        $op=(string)($_POST['op']??'capture');
        if($op==='capture'){
            $result=research_longitudinal_capture($pdo,$u,$selectedId,'manual',null);
            header('Location: /research-evolution.php?agent='.rawurlencode($selectedId).'&captured='.(!empty($result['created'])?'1':'0'));exit;
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$days=(int)($_GET['days']??30);if(!in_array($days,[7,30,90,365],true))$days=30;
$since=date('Y-m-d H:i:s',strtotime('-'.$days.' days'));
$latest=$selected?research_longitudinal_latest_snapshot($pdo,$u,$selectedId):null;
$summary=$selected?research_longitudinal_summary($pdo,$u,$selectedId,$since):['changes'=>[],'milestones'=>[],'trends'=>[],'emerging'=>[],'materiality'=>[],'change_counts'=>[],'object_counts'=>[]];
$snapshots=$selected?research_longitudinal_snapshot_list($pdo,$u,$selectedId,40):[];
$changes=(array)$summary['changes'];$milestones=(array)$summary['milestones'];$trends=(array)$summary['trends'];$emerging=(array)$summary['emerging'];
$atRisk=array_values(array_filter($changes,fn($x)=>in_array((string)$x['change_type'],['weakened','disputed','removed'],true)));
$strengthening=array_values(array_filter($changes,fn($x)=>in_array((string)$x['change_type'],['strengthened','verified'],true)));
$resolved=array_values(array_filter($changes,fn($x)=>(string)$x['change_type']==='resolved'));
$currentQuestions=$latest?array_values((array)($latest['state']['open_question']??[])):[];
$currentContradictions=$latest?array_values((array)($latest['state']['contradiction']??[])):[];
$conversation=(string)($selected['conversation_public_id']??'');
$objectTitle=function(array $c): string{$row=$c['after']??$c['before']??[];return research_longitudinal_object_title((string)$c['object_type'],(array)$row);};
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Research Evolution · Annotated</title><link rel="stylesheet" href="/assets/css/app.css?v=59.0"></head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-evolution">
<main class="researchLibraryCanvas researchEvolutionCanvas">
  <section class="researchLibraryToolbar"><nav class="researchLibraryTabs researchPrimaryActions">
    <a href="/research.php">Research Agents</a>
    <a href="/research-agent-knowledge.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Knowledge</a>
    <a class="active" href="/research-evolution.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Evolution</a>
    <a href="/research-reports.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Reports</a>
    <a href="/research-monitoring.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Monitoring</a>
    <a href="/research-tasks.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Tasks</a>
    <a href="/research-programs.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Programs</a>
  </nav></section>
  <header class="researchEvolutionHero">
    <div><span class="eyebrow">LONGITUDINAL RESEARCH INTELLIGENCE</span><h1><?=h((string)($selected['name']??'Research Evolution'))?></h1><p>See how this Research Agent’s knowledge has changed over time—what strengthened, weakened, became disputed or verified, which questions were resolved, and which themes keep moving.</p></div>
    <?php if($agents):?><label>Research Agent<select onchange="location.href='/research-evolution.php?agent='+encodeURIComponent(this.value)"><?php foreach($agents as $a):?><option value="<?=h((string)$a['public_id'])?>" <?=$selectedId===(string)$a['public_id']?'selected':''?>><?=h((string)$a['name'])?></option><?php endforeach?></select></label><?php endif?>
  </header>
  <?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
  <?php if(isset($_GET['captured'])):?><div class="success"><?=$_GET['captured']==='1'?'Current Research state captured.':'Current state already matches the latest longitudinal snapshot.'?></div><?php endif?>
  <?php if(!$selected):?><section class="card empty"><h2>Create a Research Agent first.</h2><a class="button" href="/research.php">Open Research Agents</a></section>
  <?php else:?>
    <section class="researchEvolutionActions">
      <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($selectedId)?>"><button class="button" name="op" value="capture"><?=$latest?'Capture current state':'Create baseline'?></button></form>
      <?php if($conversation!==''):?><a class="button secondary" href="/home.php?agent=<?=h(rawurlencode($conversation))?>">Ask Agent / Catch me up</a><?php endif?>
      <a class="button secondary" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=run&type=research_evolution">Run Evolution Brief</a>
      <a class="button secondary" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=run&type=what_changed">Run What Changed Brief</a>
    </section>
    <?php if(!$latest):?><section class="card empty researchEvolutionEmpty"><h2>No longitudinal baseline yet</h2><p>Create the baseline now, or let the next Research Program cycle capture it automatically. The baseline does not label existing knowledge as newly introduced.</p></section>
    <?php else:?>
      <nav class="researchEvolutionWindow"><?php foreach([7,30,90,365] as $d):?><a class="<?=$days===$d?'active':''?>" href="/research-evolution.php?agent=<?=h(rawurlencode($selectedId))?>&days=<?=$d?>"><?=$d===365?'1 year':$d.' days'?></a><?php endforeach?></nav>
      <section class="researchKnowledgeStats researchEvolutionStats">
        <div><strong><?=h((string)count($changes))?></strong><span>Changes</span></div>
        <div><strong><?=h((string)($summary['materiality']['high']??0))?></strong><span>High materiality</span></div>
        <div><strong><?=h((string)count($milestones))?></strong><span>Milestones</span></div>
        <div><strong><?=h((string)count($strengthening))?></strong><span>Strengthened / verified</span></div>
        <div><strong><?=h((string)count($atRisk))?></strong><span>At risk</span></div>
        <div><strong><?=h((string)count($currentQuestions))?></strong><span>Open questions</span></div>
      </section>
      <section class="researchEvolutionGrid">
        <article class="card"><span class="eyebrow">WHAT CHANGED</span><h2>Material Research changes</h2>
          <?php $material=array_values(array_filter($changes,fn($x)=>($x['materiality']??'')==='high'));if(!$material):?><p>No high-materiality changes in this window.</p><?php endif?><ul class="researchEvolutionList"><?php foreach(array_slice($material,0,20) as $x):?><li><strong><?=h($objectTitle($x))?></strong><span><?=h(ucwords(str_replace('_',' ',(string)$x['change_type'])))?> · <?=h((string)$x['reason'])?></span><small><?=h((string)$x['occurred_at'])?> · <?=h(ucwords(str_replace('_',' ',(string)$x['object_type'])))?></small></li><?php endforeach?></ul>
        </article>
        <article class="card"><span class="eyebrow">CONFIDENCE</span><h2>Strengthening & weakening</h2>
          <?php if(!$strengthening&&!$atRisk):?><p>No confidence movement in this window.</p><?php endif?><ul class="researchEvolutionList"><?php foreach(array_slice(array_merge($atRisk,$strengthening),0,20) as $x):?><li><strong><?=h($objectTitle($x))?></strong><span><?=h(strtoupper((string)$x['change_type']))?> · <?=h((string)$x['reason'])?></span><small><?=h((string)$x['occurred_at'])?></small></li><?php endforeach?></ul>
        </article>
        <article class="card"><span class="eyebrow">OPEN QUESTIONS</span><h2>Current unresolved questions</h2>
          <?php if(!$currentQuestions):?><p>No open longitudinal questions in the latest state.</p><?php endif?><ul class="researchEvolutionList"><?php foreach(array_slice($currentQuestions,0,18) as $q):?><li><strong><?=h((string)$q['title'])?></strong><span><?=h((string)$q['detail'])?></span><small><?=h((string)$q['priority'])?></small></li><?php endforeach?></ul>
        </article>
        <article class="card"><span class="eyebrow">CONTRADICTIONS</span><h2>Current disputed knowledge</h2>
          <?php if(!$currentContradictions):?><p>No current longitudinal contradictions.</p><?php endif?><ul class="researchEvolutionList"><?php foreach(array_slice($currentContradictions,0,18) as $q):?><li><strong><?=h((string)$q['title'])?></strong><span><?=h((string)$q['detail'])?></span><small><?=h((string)$q['priority'])?></small></li><?php endforeach?></ul>
        </article>
        <article class="card"><span class="eyebrow">MILESTONES</span><h2>Important moments</h2>
          <?php if(!$milestones):?><p>No milestones in this window.</p><?php endif?><ol class="researchEvolutionList"><?php foreach(array_slice($milestones,0,20) as $m):?><li><strong><?=h((string)$m['title'])?></strong><span><?=h((string)$m['summary'])?></span><small><?=h(ucwords(str_replace('_',' ',(string)$m['milestone_type'])))?> · <?=h((string)$m['occurred_at'])?></small></li><?php endforeach?></ol>
        </article>
        <article class="card"><span class="eyebrow">TREND INTELLIGENCE</span><h2>Repeatedly changing knowledge</h2>
          <?php $persistent=array_values(array_filter($trends,fn($x)=>(int)($x['changes']??0)>=2));if(!$persistent):?><p>No object has changed repeatedly in this window yet.</p><?php endif?><ul class="researchEvolutionList"><?php foreach(array_slice($persistent,0,20) as $t):?><?php $lc=(array)($t['latest']??[]);$row=$lc['after']??$lc['before']??[];?><li><strong><?=h(research_longitudinal_object_title((string)$t['object_type'],(array)$row))?></strong><span><?=h((string)$t['changes'])?> changes · strengthened <?=h((string)$t['strengthened'])?> · weakened <?=h((string)$t['weakened'])?> · disputed <?=h((string)$t['disputed'])?> · verified <?=h((string)$t['verified'])?></span></li><?php endforeach?></ul>
        </article>
      </section>
      <section class="card researchEvolutionLedger"><div class="sectionHeadWeb"><div><span class="eyebrow">RESEARCH CHANGE LEDGER</span><h2>Append-only history</h2><p>Changes are derived from consecutive authoritative project-state snapshots. Report Runs and Documents do not create ledger changes by themselves.</p></div></div>
        <?php if(!$changes):?><div class="empty">No state changes have been recorded in this window.</div><?php endif?>
        <div class="researchEvolutionLedgerRows"><?php foreach(array_slice($changes,0,100) as $x):?><article><span class="researchEvolutionBadge <?=h((string)$x['materiality'])?>"><?=h(strtoupper((string)$x['change_type']))?></span><div><strong><?=h($objectTitle($x))?></strong><p><?=h((string)$x['reason'])?></p><small><?=h((string)$x['occurred_at'])?> · <?=h(ucwords(str_replace('_',' ',(string)$x['object_type'])))?> · snapshot <?=h(substr((string)$x['snapshot_public_id'],0,12))?></small></div></article><?php endforeach?></div>
      </section>
      <section class="card researchEvolutionSnapshots"><div class="sectionHeadWeb"><div><span class="eyebrow">STATE HISTORY</span><h2>Longitudinal snapshots</h2></div></div><div class="researchEvolutionSnapshotRows"><?php foreach($snapshots as $s):?><div><strong><?=h((string)$s['captured_at'])?></strong><span><?=h(ucwords(str_replace('_',' ',(string)$s['trigger_type'])))?></span><code><?=h(substr((string)$s['state_hash'],0,14))?></code><small><?=h((string)array_sum((array)$s['counts']))?> tracked objects</small></div><?php endforeach?></div></section>
      <section class="researchEvolutionReports"><a class="card" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=run&type=confidence_contradictions"><strong>Confidence & Contradictions Brief</strong><span>Track Claim strength, verification, disputes, and contradiction history.</span></a><a class="card" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=run&type=open_questions_evolution"><strong>Open Questions Brief</strong><span>Process newly opened, persistent, changed, and resolved evidence gaps.</span></a><a class="card" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=run&type=entity_theme_evolution"><strong>Entity & Theme Evolution</strong><span>See changing entities, relationships, and emerging recurring themes.</span></a></section>
    <?php endif?>
  <?php endif?>
</main></body></html>