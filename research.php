<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/research-home-ui.php';
$u=require_user($pdo);
$researchAgents=[];
try{
    if(function_exists('research_agent_ensure_default'))research_agent_ensure_default($pdo,$u);
    if(function_exists('research_agent_list'))$researchAgents=research_agent_list($pdo,$u,50);
}catch(Throwable $e){}
$home=research_home_dashboard($pdo,$u,$researchAgents);
$summary=$home['summary'];
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Research · Annotated</title>
<link rel="stylesheet" href="/assets/css/app.css?v=75.1">
</head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research">
<main class="researchHomeCanvas" data-research-home data-csrf="<?=h(csrf_token())?>">
  <header class="researchHomeHero">
    <div>
      <span class="eyebrow">RESEARCH</span>
      <h1>Your research workspace</h1>
      <p>Open a Research Agent, continue recent work, or move into Portfolios for organization-level intelligence.</p>
    </div>
    <div class="researchHomeHeroActions">
      <button type="button" class="button" data-research-agent-add>+ New Research Agent</button>
      <a class="button secondary" href="/research-intelligence-portfolios.php">Portfolios</a>
    </div>
  </header>

  <section class="researchHomeStats" aria-label="Research overview">
    <a href="#recent-agents"><strong><?=h((string)$summary['agents'])?></strong><span>Research Agents</span></a>
    <a href="#teams"><strong><?=h((string)$summary['teams'])?></strong><span>Teams</span></a>
    <a href="#portfolios"><strong><?=h((string)$summary['portfolios'])?></strong><span>Portfolios</span></a>
    <a href="/research-intelligence-portfolios.php?view=overview"><strong><?=h((string)$summary['attention'])?></strong><span>Needs attention</span></a>
  </section>

  <section class="researchHomeSection" data-research-favorites-section hidden>
    <header class="researchHomeSectionHead">
      <div><span class="eyebrow">FAVORITES</span><h2>Pinned Research Agents</h2></div>
      <small>Favorites are kept on this device.</small>
    </header>
    <div class="researchHomeAgentGrid" data-research-favorites-grid></div>
  </section>

  <section class="researchHomeSection" id="recent-agents">
    <header class="researchHomeSectionHead">
      <div><span class="eyebrow">RECENT</span><h2>Research Agents</h2></div>
      <span><?=h((string)$summary['personal_agents'])?> personal · <?=h((string)$summary['team_agents'])?> team</span>
    </header>
    <div class="researchHomeAgentGrid" data-research-recent-grid>
      <?php foreach($researchAgents as $agent):?>
      <article class="researchHomeAgentCard" data-research-agent-card data-agent-id="<?=h((string)$agent['public_id'])?>">
        <a class="researchHomeAgentMain" href="<?=h(research_agent_shell_href($agent,'chat'))?>">
          <div class="researchHomeAgentTop">
            <span class="researchHomeAgentIcon<?=!empty($agent['profile_image_url'])?' hasImage':''?>" aria-hidden="true"><?php if(!empty($agent['profile_image_url'])):?><img src="<?=h((string)$agent['profile_image_url'])?>" alt=""><?php else:?><?=h(mb_strtoupper(mb_substr((string)$agent['name'],0,1)))?><?php endif?></span>
            <div>
              <small><?=h(research_home_agent_scope($agent))?></small>
              <h3><?=h((string)$agent['name'])?></h3>
            </div>
          </div>
          <?php if(trim((string)($agent['description']??''))!==''):?><p><?=h(mb_substr(trim((string)$agent['description']),0,180))?></p><?php endif?>
          <div class="researchHomeAgentState">
            <span><?=h(ucfirst((string)($agent['monitoring_cadence']??'manual')))?> monitoring</span>
            <span><?=h(research_home_time_label((string)($agent['activity_at']??$agent['updated_at']??'')))?></span>
          </div>
          <?php if(trim((string)($agent['last_message']??''))!==''):?><blockquote><?=h((string)$agent['last_message'])?></blockquote><?php endif?>
        </a>
        <footer>
          <button type="button" class="researchHomeFavorite" data-research-favorite aria-pressed="false" title="Add to favorites">☆</button><a class="researchHomeFavorite researchHomeEditAgent" href="/research-agent-edit.php?agent=<?=rawurlencode((string)$agent['public_id'])?>" title="Edit Research Agent">Edit</a><button type="button" class="researchHomeFavorite" data-agent-profile-edit data-agent-id="<?=h((string)$agent['public_id'])?>" data-agent-name="<?=h((string)$agent['name'])?>" data-agent-description="<?=h((string)($agent['description']??''))?>" data-agent-image="<?=h((string)($agent['profile_image_url']??''))?>" data-agent-visibility="<?=h((string)($agent['visibility']??'private'))?>" title="Edit Agent profile">Profile</button>
          <nav aria-label="<?=h((string)$agent['name'])?> shortcuts">
            <a href="<?=h(research_agent_shell_href($agent,'knowledge'))?>">Knowledge</a>
            <a href="<?=h(research_agent_shell_href($agent,'research'))?>">Research</a>
            <a href="<?=h(research_agent_shell_href($agent,'reports'))?>">Reports</a>
          </nav>
        </footer>
      </article>
      <?php endforeach?>
    </div>
    <div class="researchHomeEmpty" data-research-no-agents hidden>
      <strong>No Research Agents yet.</strong>
      <p>Use + New Research Agent above to start a dedicated research workspace.</p>
    </div>
  </section>

  <div class="researchHomeColumns">
    <section class="researchHomeSection" id="teams">
      <header class="researchHomeSectionHead"><div><span class="eyebrow">TEAMS</span><h2>Shared research</h2></div><a href="/teams.php">View Teams</a></header>
      <div class="researchHomeCompactList">
        <?php if(empty($home['teams'])):?><p class="researchHomeEmptyLine">No Team Research Agents yet.</p><?php endif?>
        <?php foreach(array_slice((array)$home['teams'],0,6) as $team):?>
        <a href="/teams.php" class="researchHomeCompactRow">
          <div><strong><?=h((string)$team['name'])?></strong><small><?=h((string)$team['agent_count'])?> Research Agent<?=((int)$team['agent_count']===1?'':'s')?></small></div>
          <span><?=h(research_home_time_label((string)($team['latest_activity_at']??'')))?> →</span>
        </a>
        <?php endforeach?>
      </div>
    </section>

    <section class="researchHomeSection" id="portfolios">
      <header class="researchHomeSectionHead"><div><span class="eyebrow">PORTFOLIOS</span><h2>Organization intelligence</h2></div><a href="/research-intelligence-portfolios.php">View all</a></header>
      <div class="researchHomeCompactList">
        <?php if(empty($home['portfolios'])):?><p class="researchHomeEmptyLine">No Portfolios yet. Build one from existing Research Programs.</p><?php endif?>
        <?php foreach(array_slice((array)$home['portfolios'],0,6) as $portfolio):?>
        <a href="/research-intelligence-portfolios.php?portfolio=<?=rawurlencode((string)$portfolio['public_id'])?>" class="researchHomeCompactRow">
          <div><strong><?=h((string)$portfolio['title'])?></strong><small><?=!empty($portfolio['team_name'])?h((string)$portfolio['team_name']):'Personal'?> · <?=h((string)($portfolio['program_count']??0))?> Programs</small></div>
          <span><?=h(research_home_time_label((string)($portfolio['latest_briefing_at']??$portfolio['updated_at']??'')))?> →</span>
        </a>
        <?php endforeach?>
      </div>
    </section>
  </div>

  <section class="researchHomeSection">
    <header class="researchHomeSectionHead"><div><span class="eyebrow">ACTIVITY</span><h2>Continue where you left off</h2></div></header>
    <div class="researchHomeActivityList">
      <?php foreach((array)$home['activity'] as $item):?>
      <a href="/home.php?agent=<?=rawurlencode((string)$item['conversation_public_id'])?>" class="researchHomeActivityRow">
        <span class="researchHomeActivityIcon" aria-hidden="true">✦</span>
        <div><strong><?=h((string)$item['name'])?></strong><small><?=h((string)$item['scope'])?><?php if($item['summary']!==''):?> · <?=h((string)$item['summary'])?><?php endif?></small></div>
        <time><?=h(research_home_time_label((string)$item['activity_at']))?></time>
      </a>
      <?php endforeach?>
    </div>
  </section>
</main>
<script src="/assets/js/workspace-state.js?v=34.0"></script>
<script src="/assets/js/research-home.js?v=75.2"></script>
</body>
</html>
