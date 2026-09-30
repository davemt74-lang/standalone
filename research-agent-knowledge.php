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
$memoryReady=(bool)($selected&&function_exists('research_memory_ready')&&research_memory_ready($pdo)&&research_retrieval_ready($pdo));
$memoryQuery=mb_substr(trim((string)($_GET['memory_q']??'')),0,190);
$memoryPage=max(1,(int)($_GET['memory_page']??1));$memoryPerPage=50;$memoryOffset=($memoryPage-1)*$memoryPerPage;
$memoryItems=$memoryReady?research_memory_catalog($pdo,$u,$project,$memoryPerPage,$memoryOffset,$memoryQuery):[];
$memoryTotal=$memoryReady?research_memory_catalog_count($pdo,$u,$project,$memoryQuery):0;
$memoryPages=max(1,(int)ceil($memoryTotal/$memoryPerPage));if($memoryPage>$memoryPages)$memoryPage=$memoryPages;
$memorySummary=$memoryReady?research_memory_summary($pdo,$u,$project):['total'=>0,'excluded'=>0,'corrected'=>0,'used'=>0];
$changeObjectTitle=static function(array $change): string {
    $row=(array)($change['after']??$change['before']??[]);
    if(function_exists('research_longitudinal_object_title'))return research_longitudinal_object_title((string)($change['object_type']??''),$row);
    return (string)($row['title']??$row['statement']??$row['canonical_name']??'Research object');
};
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Research Agent Knowledge · Annotated</title><link rel="stylesheet" href="/assets/css/app.css?v=74.3"></head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-agent-knowledge" data-knowledge-view="<?=h($view)?>" data-memory-agent="<?=h($selectedId)?>" data-memory-csrf="<?=h(csrf_token())?>">
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
    <section class="card researchMemoryManager" data-research-memory-manager>
      <header class="researchMemoryHeader">
        <div><span class="eyebrow">AGENT MEMORY</span><h2>Knowledge management</h2><p>Control what this Agent may retrieve without deleting the underlying evidence. Corrections are shown to the Agent as explicit user-provided notes while original source material remains preserved for provenance.</p></div>
      </header>
      <?php if(!$memoryReady):?>
        <div class="researchMemoryUnavailable">Agent Memory controls will be available after the latest database upgrade and retrieval index are ready.</div>
      <?php else:?>
        <div class="researchMemoryStats">
          <div><strong><?=h((string)$memorySummary['total'])?></strong><span>Knowledge items</span></div>
          <div><strong><?=h((string)$memorySummary['used'])?></strong><span>Used by Agent</span></div>
          <div><strong><?=h((string)$memorySummary['corrected'])?></strong><span>Corrected</span></div>
          <div><strong><?=h((string)$memorySummary['excluded'])?></strong><span>Excluded</span></div>
        </div>
        <form class="researchMemorySearch" method="get" action="/research-agent-knowledge.php">
          <input type="hidden" name="agent" value="<?=h($selectedId)?>">
          <input type="hidden" name="view" value="library">
          <label><span>Find knowledge</span><input type="search" name="memory_q" value="<?=h($memoryQuery)?>" placeholder="Title, type, or knowledge ID"></label>
          <button type="submit" class="button secondary">Search</button>
          <?php if($memoryQuery!==''):?><a class="button secondary" href="<?=h(research_agent_knowledge_href($selectedId,'library'))?>">Clear</a><?php endif?>
        </form>
        <div class="researchMemoryLegend"><span>Privacy reflects the underlying Source / Annotation / Research workspace permission.</span><span>Retrieval controls affect future Agent context immediately.</span></div>
        <?php if(!$memoryItems):?><div class="researchMemoryEmpty">No indexed knowledge is available yet. Open Library or add Research evidence, then allow the retrieval worker to index it.</div><?php endif?>
        <div class="researchMemoryList">
          <?php foreach($memoryItems as $memory):?>
          <details class="researchMemoryItem" data-memory-item data-object-type="<?=h((string)$memory['object_type'])?>" data-object-id="<?=h((string)$memory['public_id'])?>">
            <summary>
              <span class="researchMemoryType"><?=h(strtoupper(str_replace('_',' ',(string)$memory['object_type'])))?></span>
              <span class="researchMemoryTitle"><?=h((string)$memory['title'])?></span>
              <span class="researchMemoryBadges">
                <span><?=h(ucfirst((string)$memory['privacy_state']))?></span>
                <span><?=h(ucwords(str_replace('_',' ',(string)$memory['source_kind'])))?></span>
                <?php if($memory['retrieval_state']==='exclude'):?><span class="isExcluded">Excluded</span><?php elseif($memory['retrieval_state']==='include'):?><span class="isIncluded">Included</span><?php else:?><span>Inherited</span><?php endif?>
                <?php if(trim((string)$memory['correction_text'])!==''):?><span class="isCorrected">Corrected</span><?php endif?>
              </span>
              <span class="researchMemoryUse"><?=h((string)$memory['usage_count'])?> use<?=((int)$memory['usage_count']===1?'':'s')?><?=!empty($memory['last_used_at'])?' · '.h((string)$memory['last_used_at']):''?></span>
            </summary>
            <div class="researchMemoryEditor">
              <div class="researchMemoryMeta">
                <div><span>Source / provenance</span><strong><?=h(ucwords(str_replace('_',' ',(string)$memory['source_kind'])))?></strong></div>
                <div><span>Privacy</span><strong><?=h(ucfirst((string)$memory['privacy_state']))?></strong></div>
                <div><span>Last changed</span><strong><?=h((string)($memory['updated_at']??'—'))?></strong></div>
                <div><span>Last used by Agent</span><strong><?=h((string)($memory['last_used_at']??'Never'))?></strong></div>
              </div>
              <label>Agent retrieval
                <select data-memory-state>
                  <option value="inherit" <?=$memory['retrieval_state']==='inherit'?'selected':''?>>Inherit source access</option>
                  <option value="include" <?=$memory['retrieval_state']==='include'?'selected':''?>>Explicitly include</option>
                  <option value="exclude" <?=$memory['retrieval_state']==='exclude'?'selected':''?>>Exclude from Agent</option>
                </select>
              </label>
              <label>User correction
                <textarea data-memory-correction rows="4" maxlength="12000" placeholder="Add a correction or clarification the Agent should use while preserving the original evidence."><?=h((string)$memory['correction_text'])?></textarea>
              </label>
              <div class="researchMemoryActions">
                <button type="button" data-memory-save>Save memory settings</button>
                <button type="button" class="button secondary" data-memory-history>View history</button>
                <span data-memory-status aria-live="polite"></span>
              </div>
              <div class="researchMemoryHistory" data-memory-history-panel hidden></div>
            </div>
          </details>
          <?php endforeach?>
        </div>
        <?php if($memoryPages>1):?>
        <nav class="researchMemoryPagination" aria-label="Agent Memory pages">
          <?php if($memoryPage>1):?><a class="button secondary" href="<?=h(research_agent_knowledge_href($selectedId,'library',['memory_q'=>$memoryQuery,'memory_page'=>$memoryPage-1]))?>">← Previous</a><?php endif?>
          <span>Page <?=h((string)$memoryPage)?> of <?=h((string)$memoryPages)?> · <?=h((string)$memoryTotal)?> matching item<?=($memoryTotal===1?'':'s')?></span>
          <?php if($memoryPage<$memoryPages):?><a class="button secondary" href="<?=h(research_agent_knowledge_href($selectedId,'library',['memory_q'=>$memoryQuery,'memory_page'=>$memoryPage+1]))?>">Next →</a><?php endif?>
        </nav>
        <?php endif?>
      <?php endif?>
    </section>
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
</main><script src="/assets/js/research-agent-unified-shell.js?v=74.3"></script><script src="/assets/js/research-agent-memory.js?v=79.3"></script>
</body></html>
