<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/research-library.php';
$u=require_user($pdo);
$researchAgents=[];
try{
    if(function_exists('research_agent_ensure_default'))research_agent_ensure_default($pdo,$u);
    if(function_exists('research_agent_list'))$researchAgents=research_agent_list($pdo,$u,30);
}catch(Throwable $e){}
foreach($researchAgents as &$agent){
    $agent['chat_feed']=function_exists('research_agent_chat_feed')
        ?research_agent_chat_feed($pdo,$u,(string)$agent['public_id'],6)
        :[];
}
unset($agent);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Research · Annotated</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research">
<main class="researchLibraryCanvas">
  <section class="researchLibraryToolbar" aria-label="Research workspace tools">
    <nav class="researchLibraryTabs researchPrimaryActions">
      <a class="active" href="/research.php">Research Agents <span><?=h((string)count($researchAgents))?></span></a>
      <a href="/research-portfolio.php">Portfolio</a>
      <a href="/research-publications.php">Living Research</a>
      <a href="/research-reviews.php">Review Center</a>
    </nav>
    <details class="researchAdvancedTools">
      <summary>Advanced Research tools</summary>
      <div class="researchAdvancedMenu">
        <a href="/research-network.php">Research Network</a>
        <a href="/research-citations.php">Citations</a>
        <a href="/research-audit.php">Audit Ledger</a>
        <a href="/research-provenance.php">Provenance</a>
        <a href="/research-verification.php">Verification</a>
        <a href="/research-evidence-packs.php">Evidence Packs</a>
        <a href="/research-outcomes.php">Decision Memory</a>
        <a href="/cross-research.php">Related Research</a>
        <a href="/research-automations.php">Automations</a>
      </div>
    </details>
  </section>

  <section class="researchAgentLibrarySection" aria-label="Research Agents">
    <header class="researchAgentLibraryHead">
      <div><span class="eyebrow">RESEARCH AGENTS</span><h2>Your active agents</h2></div>
      <button type="button" class="button secondary researchAgentLibraryAdd" data-research-agent-add>+ New Research Agent</button>
    </header>
    <div class="researchAgentLibraryGrid">
      <?php foreach($researchAgents as $agent):?>
      <article class="researchAgentLibraryCard">
        <header>
          <div class="researchAgentLibraryIcon" aria-hidden="true">✦</div>
          <div class="researchAgentLibraryIdentity">
            <span><?=!empty($agent['is_default'])?'Default Agent':h($agent['team_name']?:'Research Agent')?></span>
            <h3><?=h($agent['name'])?></h3>
          </div>
          <span class="researchAgentLibraryStatus"><?=h(ucfirst((string)$agent['status']))?></span>
        </header>
        <?php if(trim((string)($agent['description']??''))!==''):?><p><?=h($agent['description'])?></p><?php endif?>
        <div class="researchAgentLibraryMeta">
          <span><strong><?=h(ucfirst((string)$agent['monitoring_cadence']))?></strong><small>Monitoring</small></span>
          <span><strong><?=h($agent['last_message']!==''?'Active':'Ready')?></strong><small>Agent state</small></span>
        </div>
        <div class="researchAgentLibraryChatFeed" aria-label="<?=h($agent['name'])?> chat feed">
          <?php if(empty($agent['chat_feed'])):?><div class="researchAgentLibraryChatEmpty">No conversation yet. Open the Agent to start researching.</div>
          <?php else:?><?php foreach((array)$agent['chat_feed'] as $message):?>
            <div class="researchAgentLibraryChatMessage <?=($message['sender_type']??'')==='agent'?'is-agent':'is-user'?>">
              <strong><?=h((string)$message['speaker'])?></strong>
              <p><?=h((string)$message['body'])?></p>
            </div>
          <?php endforeach?><?php endif?>
        </div>
        <footer>
          <a class="researchAgentLibraryOpen" href="/home.php?agent=<?=h(rawurlencode((string)$agent['conversation_public_id']))?>">Open Agent Chat</a>
          <a href="/research-project.php?id=<?=h(rawurlencode((string)$agent['project_public_id']))?>">Workspace</a>
        </footer>
      </article>
      <?php endforeach?>
    </div>
  </section>

</main>
<script src="/assets/js/workspace-state.js?v=34.0"></script>
<script src="/assets/js/research-agent-shell.js?v=50.0"></script>
</body>
</html>
