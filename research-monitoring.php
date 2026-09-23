<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
if(!research_monitor_ready($pdo)){header('Location: /upgrade.php?from=research-monitoring');exit;}
if(function_exists('research_agent_ensure_default'))research_agent_ensure_default($pdo,$u);
$agents=research_agent_list($pdo,$u,50);
$selectedId=trim((string)($_GET['agent']??($agents[0]['public_id']??'')));
$selected=null;foreach($agents as $a)if(hash_equals((string)$a['public_id'],$selectedId)){$selected=$a;break;}
if(!$selected&&$agents){$selected=$agents[0];$selectedId=(string)$selected['public_id'];}
$watches=$selected?research_monitor_list($pdo,$u,$selectedId,100):[];
$summary=$selected?research_monitor_summary($pdo,$u,$selectedId):['watches'=>[],'candidates'=>[],'events_30d'=>[],'last_checked_at'=>null];
$events=$selected?research_monitor_events($pdo,$u,$selectedId,40):[];
$providerConfigured=trim((string)($config['research_monitoring']['discovery_command']??''))!=='';
$csrf=csrf_token();
?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Research Monitoring · Annotated</title>
<link rel="stylesheet" href="/assets/css/app.css?v=56.0">
</head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-monitoring">
<main class="researchLibraryCanvas researchMonitoringCanvas">
  <section class="researchLibraryToolbar" aria-label="Research workspace tools">
    <nav class="researchLibraryTabs researchPrimaryActions">
      <a href="/research.php">Research Agents</a>
      <a class="active" href="/research-monitoring.php">Monitoring</a>
      <a href="/research-portfolio.php">Portfolio</a>
      <a href="/research-publications.php">Living Research</a>
      <a href="/research-reviews.php">Review Center</a>
    </nav>
  </section>

  <header class="researchMonitoringHero">
    <div><span class="eyebrow">CONTINUOUS RESEARCH</span><h1>Monitoring</h1><p>Watch URLs, domains, topics, entities, claims, and search queries. New evidence flows into the same Research Library, retrieval index, living report, and Agent Chat.</p></div>
    <?php if($agents):?><label>Research Agent<select data-monitor-agent><?php foreach($agents as $agent):?><option value="<?=h((string)$agent['public_id'])?>" <?=$selectedId===(string)$agent['public_id']?'selected':''?>><?=h((string)$agent['name'])?></option><?php endforeach?></select></label><?php endif?>
  </header>

  <?php if(!$agents):?><section class="card empty"><h2>Create a Research Agent first.</h2><p>Monitoring belongs to a Research Agent workspace.</p><a class="button" href="/research.php">Open Research Agents</a></section>
  <?php else:?>
  <section class="researchMonitoringStats">
    <div><strong><?=h((string)(($summary['watches']['active']??0)))?></strong><span>Active watches</span></div>
    <div><strong><?=h((string)(($summary['candidates']['candidate']??0)))?></strong><span>Candidate sources</span></div>
    <div><strong><?=h((string)(($summary['candidates']['promoted']??0)))?></strong><span>Promoted sources</span></div>
    <div><strong><?=h((string)(($summary['events_30d']['high']??0)+($summary['events_30d']['important']??0)))?></strong><span>Meaningful changes · 30d</span></div>
  </section>

  <section class="researchMonitoringGrid">
    <aside class="researchMonitoringCreate card">
      <span class="eyebrow">NEW WATCH</span><h2>Monitor something</h2>
      <form data-monitor-create>
        <label>Type<select name="watch_type"><option value="url">URL</option><option value="domain">Domain</option><option value="topic">Topic</option><option value="entity">Entity</option><option value="claim">Claim ID</option><option value="query">Search query</option></select></label>
        <label>Target<input name="target" required maxlength="1000" placeholder="URL, domain, topic, entity, claim ID, or query"></label>
        <label>Cadence<select name="cadence"><option value="hourly">Hourly</option><option value="daily" selected>Daily</option><option value="weekly">Weekly</option><option value="manual">Manual only</option></select></label>
        <label>Alerts<select name="alert_level"><option value="important" selected>Important changes</option><option value="all">All changes</option></select></label>
        <label class="researchMonitoringCheck"><input type="checkbox" name="auto_promote"> Automatically add high-relevance discoveries to Research</label>
        <button type="submit" class="button">Create watch</button>
        <p class="meta"><?= $providerConfigured?'External discovery provider configured.':'URL monitoring and domain sitemap discovery are available now. Topic/entity/query discovery becomes active when an external discovery command is configured.'?></p>
      </form>
    </aside>

    <section class="researchMonitoringWatches">
      <header><div><span class="eyebrow">WATCHLIST</span><h2><?=h((string)$selected['name'])?></h2></div><small><?=!empty($summary['last_checked_at'])?'Last checked '.h((string)$summary['last_checked_at']):'Not checked yet'?></small></header>
      <?php if(!$watches):?><div class="card empty"><h3>No monitoring watches yet.</h3><p>Add a URL, domain, topic, entity, claim, or query.</p></div><?php endif?>
      <?php foreach($watches as $watch):$candidates=research_monitor_candidates($pdo,$u,(string)$watch['public_id'],6);?>
      <article class="researchMonitoringWatch card" data-watch-id="<?=h((string)$watch['public_id'])?>">
        <header><div><span><?=h(strtoupper((string)$watch['watch_type']))?></span><h3><?=h((string)$watch['target'])?></h3></div><strong class="is-<?=h((string)$watch['status'])?>"><?=h(ucfirst((string)$watch['status']))?></strong></header>
        <div class="researchMonitoringWatchMeta">
          <span><?=h(ucfirst((string)$watch['cadence']))?></span><span><?=h((string)$watch['alert_level'])?> alerts</span><span><?=!empty($watch['auto_promote'])?'Auto-promote on':'Review before promote'?></span>
          <span><?=!empty($watch['last_checked_at'])?'Checked '.h((string)$watch['last_checked_at']):'Never checked'?></span>
        </div>
        <?php if(!empty($watch['last_error'])):?><p class="researchMonitoringError"><?=h((string)$watch['last_error'])?></p><?php endif?>
        <div class="researchMonitoringWatchActions">
          <button type="button" data-monitor-action="run">Run now</button>
          <button type="button" data-monitor-action="<?=($watch['status']==='paused')?'resume':'pause'?>"><?=($watch['status']==='paused')?'Resume':'Pause'?></button>
          <button type="button" data-monitor-action="archive">Archive</button>
        </div>
        <?php if($candidates):?><details class="researchMonitoringCandidates"><summary>Discovery candidates (<?=h((string)count($candidates))?> shown)</summary><div>
          <?php foreach($candidates as $candidate):?><article data-candidate-id="<?=h((string)$candidate['public_id'])?>">
            <div><strong><?=h((string)($candidate['title']?:$candidate['canonical_url']))?></strong><small><?=h((string)$candidate['domain'])?> · relevance <?=h(number_format((float)$candidate['relevance_score'],0))?>%</small><p><?=h(mb_substr((string)($candidate['excerpt']??''),0,280))?></p></div>
            <span class="researchMonitoringCandidateStatus"><?=h((string)$candidate['status'])?></span>
            <?php if($candidate['status']==='candidate'):?><div><button type="button" data-candidate-action="promote">Add to Research</button><button type="button" data-candidate-action="ignore">Ignore</button></div><?php endif?>
          </article><?php endforeach?>
        </div></details><?php endif?>
      </article>
      <?php endforeach?>
    </section>
  </section>

  <section class="researchMonitoringEvents">
    <header><span class="eyebrow">RECENT CHANGES</span><h2>Monitoring history</h2></header>
    <?php if(!$events):?><div class="card empty"><p>No monitoring changes have been recorded yet.</p></div><?php else:?><div class="researchMonitoringEventList">
      <?php foreach($events as $event):?><article class="card is-<?=h((string)$event['importance'])?>">
        <div><span><?=h(str_replace('_',' ',strtoupper((string)$event['event_type'])))?></span><strong><?=h((string)$event['target'])?></strong></div>
        <p><?=h((string)$event['summary'])?></p><small><?=h((string)$event['occurred_at'])?></small>
      </article><?php endforeach?>
    </div><?php endif?>
  </section>
  <?php endif?>
</main>
<script>
(()=>{
 const csrf=<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 const agent=<?=json_encode($selectedId,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
 async function post(action,data={}){const r=await fetch('/api/research-monitoring.php?action='+encodeURIComponent(action),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j?.error?.message||'Monitoring request failed.');return j.data;}
 document.querySelector('[data-monitor-agent]')?.addEventListener('change',e=>{location.href='/research-monitoring.php?agent='+encodeURIComponent(e.target.value);});
 document.querySelector('[data-monitor-create]')?.addEventListener('submit',async e=>{e.preventDefault();const f=new FormData(e.currentTarget);try{await post('create',{agent_id:agent,watch_type:f.get('watch_type'),target:f.get('target'),cadence:f.get('cadence'),alert_level:f.get('alert_level'),auto_promote:f.get('auto_promote')==='on'});location.reload();}catch(err){alert(err.message);}});
 document.querySelectorAll('[data-watch-id]').forEach(card=>card.querySelectorAll('[data-monitor-action]').forEach(btn=>btn.addEventListener('click',async()=>{try{await post(btn.dataset.monitorAction,{watch_id:card.dataset.watchId});location.reload();}catch(err){alert(err.message);}})));
 document.querySelectorAll('[data-candidate-id]').forEach(card=>card.querySelectorAll('[data-candidate-action]').forEach(btn=>btn.addEventListener('click',async()=>{try{await post(btn.dataset.candidateAction,{candidate_id:card.dataset.candidateId});location.reload();}catch(err){alert(err.message);}})));
})();
</script>
</body></html>
