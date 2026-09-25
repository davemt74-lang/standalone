<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';require_once dirname(__DIR__).'/app/admin-agent.php';$admin=require_admin($pdo);
if(!admin_agent_ready($pdo)){http_response_code(503);exit('Admin Agent requires the conversation runtime.');}
$threads=admin_agent_threads($pdo,$admin,50);$requested=trim((string)($_GET['thread']??''));$active=$requested!==''?admin_agent_thread_access($pdo,$admin,$requested):null;if(!$active&&$threads)$active=admin_agent_thread_access($pdo,$admin,(string)$threads[0]['public_id']);
$model=ai_setting_model_id($pdo,'admin',false);$profile=admin_ops_operator_profile($pdo,$admin);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Agent · Annotated</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><?=admin_ui_sidebar('assistant')?>
<main class="panel adminAgentPage" data-admin-agent-root data-api="/api/admin-agent.php" data-csrf="<?=h(csrf_token())?>" data-thread="<?=h((string)($active['public_id']??''))?>">
  <header class="adminAgentHeader">
    <div><span class="eyebrow">ADMIN V2.70 · ADMIN AGENT</span><h1>Admin Agent</h1><p>Ask about Annotated operations, accounts, billing, usage, support, Customer Success, security, platform configuration, releases and system health.</p></div>
    <div class="adminAgentHeaderMeta"><span><?=h((string)($profile['role_name']??$profile['role_key']??'Admin'))?></span><span><?=h($model?'Admin model configured':'Admin model not configured')?></span></div>
  </header>
  <div class="adminAgentWorkspace">
    <aside class="adminAgentThreads" aria-label="Admin Agent conversations">
      <button type="button" class="adminAgentNewChat" data-admin-agent-new>+ New chat</button>
      <nav data-admin-agent-thread-list>
      <?php foreach($threads as $thread):$selected=$active&&$active['public_id']===$thread['public_id'];?><a href="/admin/assistant.php?thread=<?=rawurlencode((string)$thread['public_id'])?>" class="<?=$selected?'active':''?>" <?=$selected?'aria-current="page"':''?>><strong><?=h((string)($thread['title']?:'Admin Agent'))?></strong><?php if(!empty($thread['last_message'])):?><small><?=h((string)$thread['last_message'])?></small><?php endif?></a><?php endforeach?>
      </nav>
    </aside>
    <section class="adminAgentCanvas" aria-label="Admin Agent chat">
      <div class="adminAgentMessages" data-admin-agent-messages role="log" aria-live="polite">
        <section class="adminAgentWelcome" data-admin-agent-welcome>
          <span class="adminAgentOrb" aria-hidden="true">A</span><h2>How can I help manage Annotated?</h2>
          <p>I can inspect the authorized Admin context, find operational records, explain issues, and prepare supported governed actions for review.</p>
          <div class="adminAgentQuickPrompts">
            <button type="button" data-admin-agent-quick="What needs my attention right now?">What needs my attention?</button>
            <button type="button" data-admin-agent-quick="Check billing, dunning and AI usage for problems.">Billing & usage</button>
            <button type="button" data-admin-agent-quick="Check system health, workers and release readiness.">System health</button>
            <button type="button" data-admin-agent-quick="Summarize support and Customer Success risks that need follow-up.">Customer risk</button>
          </div>
        </section>
      </div>
      <div class="adminAgentComposerDock">
        <form class="adminAgentComposer" data-admin-agent-form>
          <textarea rows="1" maxlength="12000" data-admin-agent-input placeholder="Message Admin Agent…" aria-label="Message Admin Agent"></textarea>
          <button type="submit" data-admin-agent-send aria-label="Send message">↑</button>
        </form>
        <div class="adminAgentComposerHint"><span data-admin-agent-status>Admin Agent can inspect authorized Admin data. Governed changes require review.</span><span>Enter to send · Shift+Enter for a new line</span></div>
      </div>
    </section>
  </div>
</main>
<script src="/assets/js/admin-agent.js?v=2.70" defer></script></body></html>
