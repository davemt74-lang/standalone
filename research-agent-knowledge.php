<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
if(!research_system_reports_ready($pdo)){header('Location: /upgrade.php?from=research-agent-knowledge');exit;}
if(function_exists('research_agent_ensure_default'))research_agent_ensure_default($pdo,$u);

$agents=research_agent_list($pdo,$u,50);
$ctx=research_agent_shell_resolve($pdo,$u,trim((string)($_GET['agent']??'')),$agents);
$agents=$ctx['agents'];$selected=$ctx['agent'];$selectedId=(string)$ctx['agent_id'];
$view=research_agent_knowledge_view((string)($_GET['view']??'library'));

$data=$selected?research_system_report_knowledge($pdo,$config,$u,$selectedId):null;
$s=(array)($data['snapshot']??[]);$workspace=(array)($s['workspace']??[]);
$claims=(array)($s['claims']??[]);$findings=(array)($s['findings']??[]);$entities=(array)($s['entities']??[]);
$gaps=(array)($workspace['gaps']??[]);$conflicts=(array)($workspace['conflicts']??[]);$risks=(array)($workspace['source_risks']??[]);
$events=(array)($s['monitoring']['events']??[]);$evidence=(array)($s['recent_evidence']??[]);$counts=(array)($workspace['counts']??[]);
$supported=array_values(array_filter($claims,fn($x)=>($x['status']??'')==='supported'));
$conversation=(string)($selected['conversation_public_id']??'');$project=(string)($selected['project_public_id']??'');

$verification=['available'=>false,'total_claims'=>count($claims),'needs_attention'=>0,'contested'=>0,'stale'=>0,'reviewed_current'=>0];
if($selected&&$project!==''&&function_exists('research_verification_ready')&&research_verification_ready($pdo)){
    $verification=research_verification_project_summary($pdo,$u,$project,120);
}
$monitorSummary=['watches'=>[],'candidates'=>[],'events_30d'=>[],'last_checked_at'=>null];
$monitorEvents=[];
if($selected&&function_exists('research_monitor_ready')&&research_monitor_ready($pdo)){
    $monitorSummary=research_monitor_summary($pdo,$u,$selectedId);
    $monitorEvents=research_monitor_events($pdo,$u,$selectedId,30);
}
$evolution=['changes'=>[],'milestones'=>[],'trends'=>[],'materiality'=>[]];
$latestEvolution=null;
if($selected&&function_exists('research_longitudinal_ready')&&research_longitudinal_ready($pdo)){
    $latestEvolution=research_longitudinal_latest_snapshot($pdo,$u,$selectedId);
    $evolution=research_longitudinal_summary($pdo,$u,$selectedId,date('Y-m-d H:i:s',strtotime('-30 days')));
}
$changes=(array)($evolution['changes']??[]);
$materialChanges=array_values(array_filter($changes,fn($x)=>($x['materiality']??'')==='high'));
$strengthening=array_values(array_filter($changes,fn($x)=>in_array((string)($x['change_type']??''),['strengthened','verified'],true)));
$atRisk=array_values(array_filter($changes,fn($x)=>in_array((string)($x['change_type']??''),['weakened','disputed','removed'],true)));
$engineLinks=$selected?research_agent_knowledge_engine_links($selected):['library'=>[],'insights'=>[],'changes'=>[]];
$changeObjectTitle=static function(array $change): string {
    $row=(array)($change['after']??$change['before']??[]);
    if(function_exists('research_longitudinal_object_title'))return research_longitudinal_object_title((string)($change['object_type']??''),$row);
    return (string)($row['title']??$row['statement']??$row['canonical_name']??'Research object');
};
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Research Agent Knowledge · Annotated</title><link rel="stylesheet" href="/assets/css/app.css?v=74.3"></head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-agent-knowledge" data-knowledge-view="<?=h($view)?>">
<main class="researchLibraryCanvas researchKnowledgeCanvas researchKnowledgeUnifiedCanvas">
  <?php if($selected):?><?=research_agent_shell_render($selected,$agents,'knowledge',['workspace_controls'=>true])?><?php endif?>
  <header class="researchKnowledgeHero researchKnowledgeUnifiedHero">
    <div><span class="eyebrow">RESEARCH AGENT KNOWLEDGE</span><h1><?=h((string)($selected['name']??'Research Agent'))?></h1><p>One knowledge system for captured evidence, structured insight, source intelligence, and change over time.</p></div>
  </header>
  <?php if(!$selected):?><section class="card empty"><h2>Create a Research Agent first.</h2><a class="button" href="/research.php">Open Research Agents</a></section><?php else:?>
  <?=research_agent_knowledge_render_nav($selectedId,$view)?>

  <?php if($view==='library'):?>
    <section class="researchKnowledgeStats researchKnowledgeUnifiedStats">
      <div><strong><?=h((string)($counts['sources']??count((array)($s['sources']??[]))))?></strong><span>Sources</span></div>
      <div><strong><?=h((string)($counts['annotations']??0))?></strong><span>Annotations</span></div>
      <div><strong><?=h((string)count($evidence))?></strong><span>Recent evidence</span></div>
      <div><strong><?=h((string)($s['retrieval_index']['document_count']??0))?></strong><span>Indexed objects</span></div>
    </section>
    <section class="researchKnowledgeUnifiedActions" aria-label="Library actions">
      <?php foreach($engineLinks['library'] as $link):?><?php if($link['href']!==''):?><a class="button secondary" href="<?=h((string)$link['href'])?>"><?=h((string)$link['label'])?></a><?php endif?><?php endforeach?>
      <?php if($conversation!==''):?><a class="button" href="/home.php?agent=<?=h(rawurlencode($conversation))?>">Ask Agent</a><?php endif?>
    </section>
    <section class="card researchKnowledgeUnifiedIntro"><span class="eyebrow">LIBRARY</span><h2>Captured knowledge</h2><p>Files, documents, recordings, bookmarks, annotations, Sources and imported research remain in the existing Library/Desktop workspace. Retrieval indexing and evidence capture stay automatic underneath this view.</p></section>
    <section class="researchKnowledgeEvidence">
      <div class="sectionHeadWeb"><div><span class="eyebrow">RECENT KNOWLEDGE</span><h2>Evidence & structured objects</h2></div></div>
      <?php if(!$evidence):?><div class="card empty">No recent evidence has been captured yet.</div><?php endif?>
      <div class="researchKnowledgeEvidenceGrid"><?php foreach(array_slice($evidence,0,30) as $x):?><?php
        $href=(string)($x['href']??'');
        if($href===''&&($x['object_type']??'')==='document'&&$conversation!=='')$href='/home.php?agent='.rawurlencode($conversation).'&doc='.rawurlencode((string)$x['public_id']);
      ?><article class="card"><span class="eyebrow"><?=h(strtoupper((string)($x['object_type']??'evidence')))?></span><h3><?=h((string)($x['title']??'Evidence'))?></h3><?php if(!empty($x['snippet'])):?><p><?=h((string)$x['snippet'])?></p><?php endif?><small><?=h((string)($x['locator_label']??''))?></small><?php if($href!==''):?><a href="<?=h($href)?>">Open</a><?php endif?></article><?php endforeach?></div>
    </section>

  <?php elseif($view==='insights'):?>
    <section class="researchKnowledgeStats researchKnowledgeUnifiedStats">
      <div><strong><?=h((string)count($claims))?></strong><span>Claims</span></div>
      <div><strong><?=h((string)count($findings))?></strong><span>Findings</span></div>
      <div><strong><?=h((string)count($entities))?></strong><span>Entities</span></div>
      <div><strong><?=h((string)($verification['needs_attention']??0))?></strong><span>Need verification</span></div>
      <div><strong><?=h((string)count($conflicts))?></strong><span>Contradictions</span></div>
      <div><strong><?=h((string)count($risks))?></strong><span>Source risks</span></div>
    </section>
    <section class="researchKnowledgeUnifiedActions" aria-label="Insight inspectors">
      <?php foreach($engineLinks['insights'] as $link):?><?php if($link['href']!==''):?><a class="button secondary" href="<?=h((string)$link['href'])?>"><?=h((string)$link['label'])?></a><?php endif?><?php endforeach?>
    </section>
    <section class="researchKnowledgeGrid researchKnowledgeInsightsGrid">
      <article class="card"><span class="eyebrow">FINDINGS</span><h2>Strongest Findings</h2>
        <?php if(!$findings):?><p>No Findings have been recorded yet.</p><?php endif?><ul><?php foreach(array_slice($findings,0,12) as $x):?><li><a href="/research-finding.php?id=<?=h(rawurlencode((string)$x['public_id']))?>"><strong><?=h((string)$x['title'])?></strong></a><span><?=h((string)$x['summary'])?></span></li><?php endforeach?></ul>
      </article>
      <article class="card"><span class="eyebrow">CLAIMS</span><h2>Supported knowledge</h2>
        <?php if(!$supported):?><p>No Claims are currently marked supported.</p><?php endif?><ul><?php foreach(array_slice($supported,0,12) as $x):?><li><a href="/research-claim.php?id=<?=h(rawurlencode((string)$x['public_id']))?>"><?=h((string)$x['statement'])?></a><small><?=h((string)($x['evidence_count']??0))?> evidence item(s)</small></li><?php endforeach?></ul>
      </article>
      <article class="card"><span class="eyebrow">ENTITIES</span><h2>People, organizations & topics</h2>
        <?php if(!$entities):?><p>No structured entities have been identified yet.</p><?php endif?><ul><?php foreach(array_slice($entities,0,16) as $x):?><li><a href="/research-entity.php?id=<?=h(rawurlencode((string)$x['public_id']))?>"><strong><?=h((string)$x['canonical_name'])?></strong></a><small><?=h((string)$x['entity_type'])?> · <?=h((string)($x['mention_count']??0))?> mentions · <?=h((string)($x['relation_count']??0))?> relationships</small></li><?php endforeach?></ul>
      </article>
      <article class="card"><span class="eyebrow">VERIFICATION</span><h2>Evidence review state</h2>
        <div class="researchKnowledgeMiniStats"><div><strong><?=h((string)($verification['needs_attention']??0))?></strong><span>Need attention</span></div><div><strong><?=h((string)($verification['contested']??0))?></strong><span>Contested</span></div><div><strong><?=h((string)($verification['stale']??0))?></strong><span>Stale / mixed</span></div><div><strong><?=h((string)($verification['reviewed_current']??0))?></strong><span>Human-reviewed</span></div></div>
        <p>Verification remains an evidence-state review engine, not a truth score.</p>
      </article>
      <article class="card"><span class="eyebrow">OPEN QUESTIONS</span><h2>Evidence gaps</h2>
        <?php if(!$gaps):?><p>No structured evidence gaps are currently detected.</p><?php endif?><ul><?php foreach(array_slice($gaps,0,12) as $x):?><li><strong><?=h((string)($x['title']??'Evidence gap'))?></strong><span><?=h((string)($x['detail']??''))?></span><?php if(!empty($x['claim_id'])):?><a href="/research-claim.php?id=<?=h(rawurlencode((string)$x['claim_id']))?>">Open Claim</a><?php endif?></li><?php endforeach?></ul>
      </article>
      <article class="card"><span class="eyebrow">CONTRADICTIONS</span><h2>Disputed knowledge</h2>
        <?php if(!$conflicts):?><p>No structured contradictions are currently detected.</p><?php endif?><ul><?php foreach(array_slice($conflicts,0,12) as $x):?><li><strong><?=h((string)($x['title']??'Conflict'))?></strong><span><?=h((string)($x['detail']??''))?></span><?php if(!empty($x['claim_id'])):?><a href="/research-claim.php?id=<?=h(rawurlencode((string)$x['claim_id']))?>">Inspect evidence</a><?php endif?></li><?php endforeach?></ul>
      </article>
      <article class="card"><span class="eyebrow">SOURCE INTELLIGENCE</span><h2>Evidence risks</h2>
        <?php if(!$risks):?><p>No source risks are currently detected.</p><?php endif?><ul><?php foreach(array_slice($risks,0,12) as $x):?><li><strong><?=h((string)($x['title']??$x['domain']??'Source'))?></strong><span><?=h((string)($x['latest_diff']??''))?></span><?php if(!empty($x['source_public_id'])):?><a href="/source.php?id=<?=h(rawurlencode((string)$x['source_public_id']))?>">Open Source</a><?php endif?></li><?php endforeach?></ul>
      </article>
      <article class="card"><span class="eyebrow">PROVENANCE</span><h2>Lineage & reproducibility</h2><p>Exact Source Versions, Annotations, evidence relationships, Findings, Reports, citations, reviews, hashes, and Evidence Packs stay available through the advanced inspectors above.</p></article>
    </section>

  <?php else:?>
    <section class="researchKnowledgeStats researchKnowledgeUnifiedStats">
      <div><strong><?=h((string)(($monitorSummary['watches']['active']??0)))?></strong><span>Active watches</span></div>
      <div><strong><?=h((string)(($monitorSummary['candidates']['candidate']??0)))?></strong><span>Candidate sources</span></div>
      <div><strong><?=h((string)(($monitorSummary['events_30d']['high']??0)+($monitorSummary['events_30d']['important']??0)))?></strong><span>Meaningful changes · 30d</span></div>
      <div><strong><?=h((string)count($changes))?></strong><span>Knowledge changes · 30d</span></div>
      <div><strong><?=h((string)count($strengthening))?></strong><span>Strengthened / verified</span></div>
      <div><strong><?=h((string)count($atRisk))?></strong><span>At risk</span></div>
    </section>
    <section class="researchKnowledgeUnifiedActions" aria-label="Change tools">
      <?php foreach($engineLinks['changes'] as $link):?><?php if($link['href']!==''):?><a class="button secondary" href="<?=h((string)$link['href'])?>"><?=h((string)$link['label'])?></a><?php endif?><?php endforeach?>
      <a class="button secondary" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=run&type=what_changed">Run What Changed Brief</a>
    </section>
    <section class="researchKnowledgeGrid researchKnowledgeChangesGrid">
      <article class="card"><span class="eyebrow">WHAT CHANGED</span><h2>Monitoring intelligence</h2>
        <?php $mergedEvents=$monitorEvents?:$events;if(!$mergedEvents):?><p>No recent monitoring events are available.</p><?php endif?><ul><?php foreach(array_slice($mergedEvents,0,16) as $x):?><li><strong><?=h((string)($x['summary']??$x['title']??$x['event_type']??'Monitoring event'))?></strong><small><?=h((string)($x['importance']??''))?><?=!empty($x['occurred_at'])?' · '.h((string)$x['occurred_at']):''?></small></li><?php endforeach?></ul>
      </article>
      <article class="card"><span class="eyebrow">EVOLUTION</span><h2>Material Research changes</h2>
        <?php if(!$latestEvolution):?><p>No longitudinal baseline yet. The existing Evolution engine can create and preserve the baseline.</p><?php elseif(!$materialChanges):?><p>No high-materiality changes in the last 30 days.</p><?php endif?><ul><?php foreach(array_slice($materialChanges,0,16) as $x):?><li><strong><?=h($changeObjectTitle($x))?></strong><span><?=h(ucwords(str_replace('_',' ',(string)($x['change_type']??'changed'))))?> · <?=h((string)($x['reason']??''))?></span><small><?=h((string)($x['occurred_at']??''))?></small></li><?php endforeach?></ul>
      </article>
      <article class="card"><span class="eyebrow">CONFIDENCE MOVEMENT</span><h2>Strengthening & weakening</h2>
        <?php if(!$strengthening&&!$atRisk):?><p>No confidence movement in the last 30 days.</p><?php endif?><ul><?php foreach(array_slice(array_merge($atRisk,$strengthening),0,16) as $x):?><li><strong><?=h($changeObjectTitle($x))?></strong><span><?=h(strtoupper((string)($x['change_type']??'changed')))?> · <?=h((string)($x['reason']??''))?></span><small><?=h((string)($x['occurred_at']??''))?></small></li><?php endforeach?></ul>
      </article>
      <article class="card"><span class="eyebrow">SOURCE CHANGES</span><h2>Source intelligence</h2>
        <?php if(!$risks):?><p>No current source-change risks are detected.</p><?php endif?><ul><?php foreach(array_slice($risks,0,16) as $x):?><li><strong><?=h((string)($x['title']??$x['domain']??'Source'))?></strong><span><?=h((string)($x['latest_diff']??''))?></span><?php if(!empty($x['source_public_id'])):?><a href="/source.php?id=<?=h(rawurlencode((string)$x['source_public_id']))?>">Inspect Source</a><?php endif?></li><?php endforeach?></ul>
      </article>
    </section>
    <section class="card researchKnowledgeUnifiedIntro"><span class="eyebrow">CHANGES</span><h2>Monitoring and evolution are one surface</h2><p>Watches, candidate discovery, monitoring events, source-change detection, longitudinal snapshots, change history and confidence movement keep their existing workers, APIs and append-only histories. This view only packages their current state together.</p></section>
  <?php endif?>

  <?php endif?>
</main><script src="/assets/js/research-agent-unified-shell.js?v=74.3"></script>
</body></html>
