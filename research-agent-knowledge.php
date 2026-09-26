<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
if(!research_system_reports_ready($pdo)){header('Location: /upgrade.php?from=research-agent-knowledge');exit;}
if(function_exists('research_agent_ensure_default'))research_agent_ensure_default($pdo,$u);
$agents=research_agent_list($pdo,$u,50);$selectedId=trim((string)($_GET['agent']??($agents[0]['public_id']??'')));$selected=null;
foreach($agents as $a)if(hash_equals((string)$a['public_id'],$selectedId)){$selected=$a;break;}
if(!$selected&&$agents){$selected=$agents[0];$selectedId=(string)$selected['public_id'];}
$data=$selected?research_system_report_knowledge($pdo,$config,$u,$selectedId):null;$s=$data['snapshot']??[];$workspace=(array)($s['workspace']??[]);
$claims=(array)($s['claims']??[]);$findings=(array)($s['findings']??[]);$entities=(array)($s['entities']??[]);$gaps=(array)($workspace['gaps']??[]);$conflicts=(array)($workspace['conflicts']??[]);
$risks=(array)($workspace['source_risks']??[]);$next=(array)($workspace['next_actions']??[]);$tasks=(array)($s['tasks']['items']??[]);$programs=(array)($s['programs']['items']??[]);
$events=(array)($s['monitoring']['events']??[]);$evidence=(array)($s['recent_evidence']??[]);$reports=(array)($data['reports']??[]);$conversation=(string)($selected['conversation_public_id']??'');
$supported=array_values(array_filter($claims,fn($x)=>($x['status']??'')==='supported'));$needsReview=array_values(array_filter($tasks,fn($x)=>in_array((string)($x['status']??''),['review','waiting','failed'],true)));
$counts=(array)($workspace['counts']??[]);
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Research Agent Knowledge · Annotated</title><link rel="stylesheet" href="/assets/css/app.css?v=59.0"></head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-agent-knowledge">
<main class="researchLibraryCanvas researchKnowledgeCanvas">
  <section class="researchLibraryToolbar"><nav class="researchLibraryTabs researchPrimaryActions">
    <a href="/research.php">Research Agents</a><a class="active" href="/research-agent-knowledge.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Knowledge</a>
    <a href="/research-reports.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Reports</a>
    <a href="/research-monitoring.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Monitoring</a>
    <a href="/research-tasks.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Tasks</a><a href="/research-programs.php<?= $selectedId!==''?'?agent='.rawurlencode($selectedId):''?>">Programs</a>
  </nav></section>
  <header class="researchKnowledgeHero">
    <div><span class="eyebrow">RESEARCH AGENT KNOWLEDGE</span><h1><?=h((string)($selected['name']??'Research Agent'))?></h1><p>A transparent view of what this Agent knows, what evidence supports it, what remains uncertain, what changed, and what the Agent is working on.</p></div>
    <?php if($agents):?><label>Research Agent<select onchange="location.href='/research-agent-knowledge.php?agent='+encodeURIComponent(this.value)"><?php foreach($agents as $a):?><option value="<?=h((string)$a['public_id'])?>" <?=$selectedId===(string)$a['public_id']?'selected':''?>><?=h((string)$a['name'])?></option><?php endforeach?></select></label><?php endif?>
  </header>
  <?php if(!$selected):?><section class="card empty"><h2>Create a Research Agent first.</h2><a class="button" href="/research.php">Open Research Agents</a></section><?php else:?>
  <section class="researchKnowledgeStats">
    <div><strong><?=h((string)($counts['sources']??count((array)($s['sources']??[]))))?></strong><span>Sources</span></div>
    <div><strong><?=h((string)($counts['annotations']??0))?></strong><span>Annotations</span></div>
    <div><strong><?=h((string)count($claims))?></strong><span>Claims</span></div>
    <div><strong><?=h((string)count($findings))?></strong><span>Findings</span></div>
    <div><strong><?=h((string)count($entities))?></strong><span>Entities</span></div>
    <div><strong><?=h((string)($s['retrieval_index']['document_count']??0))?></strong><span>Indexed objects</span></div>
  </section>
  <div class="researchKnowledgeActions"><?php if($conversation!==''):?><a class="button" href="/home.php?agent=<?=h(rawurlencode($conversation))?>">Ask Agent</a><a class="button secondary" href="/home.php?agent=<?=h(rawurlencode($conversation))?>&workspace=library">Open Library</a><a class="button secondary" href="/home.php?agent=<?=h(rawurlencode($conversation))?>&workspace=desktop">Open Desktop</a><?php endif?><a class="button secondary" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>">Create report</a></div>

  <section class="researchKnowledgeGrid">
    <article class="card"><span class="eyebrow">WHAT I KNOW</span><h2>Strongest Findings</h2>
      <?php if(!$findings):?><p>No Findings have been recorded yet.</p><?php endif?><ul><?php foreach(array_slice($findings,0,10) as $x):?><li><a href="/research-finding.php?id=<?=h(rawurlencode((string)$x['public_id']))?>"><strong><?=h((string)$x['title'])?></strong></a><span><?=h((string)$x['summary'])?></span></li><?php endforeach?></ul>
    </article>
    <article class="card"><span class="eyebrow">SUPPORTED KNOWLEDGE</span><h2>Verified Claims</h2>
      <?php if(!$supported):?><p>No Claims are currently marked supported.</p><?php endif?><ul><?php foreach(array_slice($supported,0,12) as $x):?><li><a href="/research-claim.php?id=<?=h(rawurlencode((string)$x['public_id']))?>"><?=h((string)$x['statement'])?></a><small><?=h((string)($x['evidence_count']??0))?> evidence item(s)</small></li><?php endforeach?></ul>
    </article>
    <article class="card"><span class="eyebrow">OPEN QUESTIONS</span><h2>Evidence gaps</h2>
      <?php if(!$gaps):?><p>No structured evidence gaps are currently detected.</p><?php endif?><ul><?php foreach(array_slice($gaps,0,12) as $x):?><li><strong><?=h((string)($x['title']??'Evidence gap'))?></strong><span><?=h((string)($x['detail']??''))?></span><?php if(!empty($x['claim_id'])):?><a href="/research-claim.php?id=<?=h(rawurlencode((string)$x['claim_id']))?>">Open Claim</a><?php endif?></li><?php endforeach?></ul>
    </article>
    <article class="card"><span class="eyebrow">DISPUTED</span><h2>Contradictions</h2>
      <?php if(!$conflicts):?><p>No structured contradictions are currently detected.</p><?php endif?><ul><?php foreach(array_slice($conflicts,0,12) as $x):?><li><strong><?=h((string)($x['title']??'Conflict'))?></strong><span><?=h((string)($x['detail']??''))?></span><?php if(!empty($x['claim_id'])):?><a href="/research-claim.php?id=<?=h(rawurlencode((string)$x['claim_id']))?>">Inspect evidence</a><?php endif?></li><?php endforeach?></ul>
    </article>
    <article class="card"><span class="eyebrow">KNOWLEDGE GRAPH</span><h2>People, organizations & topics</h2>
      <?php if(!$entities):?><p>No structured entities have been identified yet.</p><?php endif?><ul><?php foreach(array_slice($entities,0,16) as $x):?><li><a href="/research-entity.php?id=<?=h(rawurlencode((string)$x['public_id']))?>"><strong><?=h((string)$x['canonical_name'])?></strong></a><small><?=h((string)$x['entity_type'])?> · <?=h((string)($x['mention_count']??0))?> mentions · <?=h((string)($x['relation_count']??0))?> relationships</small></li><?php endforeach?></ul>
    </article>
    <article class="card"><span class="eyebrow">SOURCE HEALTH</span><h2>Evidence risks</h2>
      <?php if(!$risks):?><p>No source risks are currently detected.</p><?php endif?><ul><?php foreach(array_slice($risks,0,12) as $x):?><li><strong><?=h((string)($x['title']??$x['domain']??'Source'))?></strong><span><?=h((string)($x['latest_diff']??''))?></span><?php if(!empty($x['source_public_id'])):?><a href="/source.php?id=<?=h(rawurlencode((string)$x['source_public_id']))?>">Open Source</a><?php endif?></li><?php endforeach?></ul>
    </article>
    <article class="card"><span class="eyebrow">WHAT CHANGED</span><h2>Monitoring intelligence</h2>
      <?php if(!$events):?><p>No recent monitoring events are available.</p><?php endif?><ul><?php foreach(array_slice($events,0,12) as $x):?><li><strong><?=h((string)($x['summary']??$x['event_type']??'Monitoring event'))?></strong><small><?=h((string)($x['importance']??''))?> · <?=h((string)($x['occurred_at']??''))?></small></li><?php endforeach?></ul>
    </article>
    <article class="card"><span class="eyebrow">WORKING ON</span><h2>Active Research work</h2>
      <?php if(!$tasks&&!$programs):?><p>No active Tasks or Programs.</p><?php endif?><ul><?php foreach(array_slice($tasks,0,8) as $x):?><li><a href="/research-tasks.php?agent=<?=h(rawurlencode($selectedId))?>&task=<?=h(rawurlencode((string)$x['public_id']))?>"><?=h((string)$x['title'])?></a><small><?=h((string)$x['priority'])?> · <?=h((string)$x['status'])?></small></li><?php endforeach?><?php foreach(array_slice($programs,0,6) as $x):?><li><a href="/research-programs.php?agent=<?=h(rawurlencode($selectedId))?>&program=<?=h(rawurlencode((string)$x['public_id']))?>"><?=h((string)$x['title'])?></a><small>Program · <?=h((string)$x['status'])?> · <?=h((string)$x['cadence'])?></small></li><?php endforeach?></ul>
    </article>
    <article class="card"><span class="eyebrow">NEEDS YOU</span><h2>Attention</h2>
      <?php if(!$needsReview&&!$conflicts&&!$gaps):?><p>Nothing currently requires direct user review.</p><?php endif?><ul><?php foreach(array_slice($needsReview,0,8) as $x):?><li><a href="/research-tasks.php?agent=<?=h(rawurlencode($selectedId))?>&task=<?=h(rawurlencode((string)$x['public_id']))?>"><?=h((string)$x['title'])?></a><small><?=h((string)$x['status'])?></small></li><?php endforeach?></ul>
    </article>
    <article class="card"><span class="eyebrow">NEXT</span><h2>Suggested research actions</h2>
      <?php if(!$next):?><p>No deterministic next actions are currently suggested.</p><?php endif?><ol><?php foreach(array_slice($next,0,10) as $x):?><li><strong><?=h((string)($x['title']??'Next step'))?></strong><span><?=h((string)($x['reason']??$x['detail']??''))?></span></li><?php endforeach?></ol>
    </article>
  </section>

  <section class="researchKnowledgeEvidence"><div class="sectionHeadWeb"><div><span class="eyebrow">RECENT KNOWLEDGE</span><h2>Evidence & structured objects</h2></div></div>
    <div class="researchKnowledgeEvidenceGrid"><?php foreach($evidence as $x):?><?php $href=(string)($x['href']??'');if($href===''&&($x['object_type']??'')==='document')$href='/home.php?agent='.rawurlencode($conversation).'&doc='.rawurlencode((string)$x['public_id']);?>
      <article class="card"><span class="eyebrow"><?=h(strtoupper((string)$x['object_type']))?></span><h3><?=h((string)$x['title'])?></h3><?php if(!empty($x['snippet'])):?><p><?=h((string)$x['snippet'])?></p><?php endif?><small><?=h((string)($x['locator_label']??''))?></small><?php if($href!==''):?><a href="<?=h($href)?>">Open</a><?php endif?></article>
    <?php endforeach?></div>
  </section>

  <section class="researchKnowledgeReports"><div class="sectionHeadWeb"><div><span class="eyebrow">REPORT RUNS</span><h2>Processed views of this knowledge</h2></div><a class="button secondary" href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=run">Open Report Studio</a></div>
    <?php if(!$reports):?><div class="card empty">No Report Runs have been generated yet.</div><?php endif?><div class="researchKnowledgeEvidenceGrid"><?php foreach($reports as $r):?><article class="card"><span class="eyebrow"><?=h(strtoupper((string)$r['type_label']))?> · <?=h(strtoupper((string)($r['freshness_state']??'current')))?></span><h3><?=h((string)$r['title'])?></h3><small><?=h((string)$r['created_at'])?> · <?=!empty($r['document_public_id'])?'Document created':'Report only'?></small><a href="/research-reports.php?agent=<?=h(rawurlencode($selectedId))?>&view=recent&report=<?=h(rawurlencode((string)$r['public_id']))?>">Open Report Run</a><?php if(!empty($r['document_public_id'])):?><a href="/home.php?agent=<?=h(rawurlencode($conversation))?>&doc=<?=h(rawurlencode((string)$r['document_public_id']))?>">Open Document</a><?php endif?></article><?php endforeach?></div>
  </section>
  <?php endif?>
</main></body></html>