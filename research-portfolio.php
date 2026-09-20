<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');
$error='';$success='';$ready=research_portfolio_ready($pdo);
$filters=['attention'=>trim((string)($_GET['attention']??$_POST['attention']??'')),'access'=>trim((string)($_GET['access']??$_POST['access']??'')),'q'=>trim((string)($_GET['q']??$_POST['q']??'')),'pinned'=>(bool)($_GET['pinned']??$_POST['pinned']??false)];
if($_SERVER['REQUEST_METHOD']==='POST'){require_csrf();try{
    if(!$ready)throw new RuntimeException('Run the Phase 23 database upgrade first.');
    if(($_POST['op']??'')==='pin'){research_portfolio_set_pin($pdo,$u,(string)($_POST['project_id']??''),!empty($_POST['set_pinned']));$success=!empty($_POST['set_pinned'])?'Project pinned.':'Project unpinned.';}
}catch(Throwable $e){$error=$e->getMessage();}}
$portfolio=$ready?research_portfolio_compose($pdo,$u,50):['ready'=>false,'projects'=>[],'summary'=>[]];$filtered=$ready?research_portfolio_filter($portfolio,$filters):$portfolio;
$summary=$portfolio['summary']??['total'=>0,'needs_attention'=>0,'watch'=>0,'clear'=>0,'pinned'=>0,'assigned_reviews'=>0,'unresolved_impact'=>0,'pending_agent_actions'=>0,'automation_failures'=>0];
function portfolio_state_label(string $state): string {return match($state){'needs_attention'=>'Needs Attention','watch'=>'Watch','clear'=>'Clear',default=>ucwords(str_replace('_',' ',$state))};}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Research Portfolio · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<header class="topbar"><a class="brand" href="/home.php">Annotated</a><nav><a href="/home.php">Home</a><a href="/research.php">Research</a><a href="/research-reviews.php">Reviews</a><a href="/research-impact.php">Impact</a><a href="/research-outcomes.php">Decision Memory</a><a href="/settings.php">Settings</a></nav></header>
<main class="portfolioLayout">
<section class="portfolioMain">
<div class="pageTitle"><span class="eyebrow">PHASE 23 · RESEARCH PORTFOLIO INTELLIGENCE</span><h1>Research Portfolio</h1><p>One deterministic command center across every Research project you can currently access. Attention states are derived from visible project signals—not from a hidden health score.</p><div class="inlineActions"><a class="button" href="/home.php?portfolio_agent=1">Ask Portfolio Agent</a><a class="button secondary" href="/research.php">Open Research</a><a class="button secondary" href="/research-automations.php">Automations</a></div></div>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<?php if(!$ready):?><div class="card empty"><h2>Research Portfolio needs migration 030.</h2><?php if(($u['role']??'')==='admin'):?><a class="button" href="/upgrade.php">Run database upgrade</a><?php endif?></div>
<?php else:?>
<div class="portfolioSummary">
<div class="card"><strong><?=h((string)$summary['total'])?></strong><span>Projects</span></div>
<div class="card portfolioSummaryAttention"><strong><?=h((string)$summary['needs_attention'])?></strong><span>Need attention</span></div>
<div class="card"><strong><?=h((string)$summary['assigned_reviews'])?></strong><span>Reviews assigned</span></div>
<div class="card"><strong><?=h((string)$summary['unresolved_impact'])?></strong><span>Unresolved impacts</span></div>
<div class="card"><strong><?=h((string)$summary['pending_agent_actions'])?></strong><span>Agent confirmations</span></div>
<div class="card"><strong><?=h((string)$summary['automation_failures'])?></strong><span>Automation failures</span></div>
</div>
<form method="get" class="card portfolioFilters">
<label>Search<input type="search" name="q" value="<?=h($filters['q'])?>" placeholder="Project name or description"></label>
<label>Attention<select name="attention"><option value="">All</option><option value="needs_attention" <?=$filters['attention']==='needs_attention'?'selected':''?>>Needs Attention</option><option value="watch" <?=$filters['attention']==='watch'?'selected':''?>>Watch</option><option value="clear" <?=$filters['attention']==='clear'?'selected':''?>>Clear</option></select></label>
<label>Access<select name="access"><option value="">All access</option><option value="owner" <?=$filters['access']==='owner'?'selected':''?>>Owner</option><option value="researcher" <?=$filters['access']==='researcher'?'selected':''?>>Researcher</option><option value="editor" <?=$filters['access']==='editor'?'selected':''?>>Editor</option><option value="viewer" <?=$filters['access']==='viewer'?'selected':''?>>Viewer</option></select></label>
<label class="portfolioCheck"><input type="checkbox" name="pinned" value="1" <?=$filters['pinned']?'checked':''?>>Pinned only</label><button>Apply filters</button><?php if($filters['attention']!==''||$filters['access']!==''||$filters['q']!==''||$filters['pinned']):?><a class="button secondary" href="/research-portfolio.php">Clear</a><?php endif?></form>
<div class="portfolioResultHead"><div><span class="eyebrow">ACCESSIBLE RESEARCH</span><h2><?=h((string)($filtered['filtered_count']??count($filtered['projects'])))?> project<?=($filtered['filtered_count']??count($filtered['projects']))===1?'':'s'?></h2></div><span class="meta">Generated <?=h((string)($portfolio['generated_at']??''))?></span></div>
<?php if(!$filtered['projects']):?><div class="card empty"><h2>No projects match these filters.</h2><p>Clear the filters or open Research to create or join another project.</p></div><?php endif?>
<div class="portfolioCards">
<?php foreach($filtered['projects'] as $item):$p=$item['project'];$counts=$item['workspace']['counts'];?>
<article class="card portfolioCard portfolioState<?=h(str_replace('_','',ucwords($item['attention_state'],'_')))?>">
<header class="portfolioCardHeader"><div><div class="portfolioBadges"><span class="badge"><?=h(portfolio_state_label((string)$item['attention_state']))?></span><span class="badge"><?=h(ucwords(str_replace('_',' ',(string)$p['access_role'])))?></span><?php if($p['pinned']):?><span class="badge">Pinned</span><?php endif?></div><h3><a href="/research-project.php?id=<?=h((string)$p['public_id'])?>"><?=h((string)$p['title'])?></a></h3><p><?=h(mb_substr((string)$p['description'],0,420))?></p></div>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="op" value="pin"><input type="hidden" name="project_id" value="<?=h((string)$p['public_id'])?>"><input type="hidden" name="set_pinned" value="<?=$p['pinned']?'0':'1'?>"><input type="hidden" name="attention" value="<?=h($filters['attention'])?>"><input type="hidden" name="access" value="<?=h($filters['access'])?>"><input type="hidden" name="q" value="<?=h($filters['q'])?>"><?php if($filters['pinned']):?><input type="hidden" name="pinned" value="1"><?php endif?><button class="button secondary"><?=$p['pinned']?'Unpin':'Pin'?></button></form></header>
<div class="portfolioProjectMetrics">
<span><strong><?=h((string)($counts['sources']??0))?></strong> sources</span><span><strong><?=h((string)($counts['claims']??0))?></strong> Claims</span><span><strong><?=h((string)($counts['findings']??0))?></strong> Findings</span><span><strong><?=h((string)($counts['open_tasks']??0))?></strong> open tasks</span><span><strong><?=h((string)$item['reviews']['open'])?></strong> reviews</span><span><strong><?=h((string)$item['impact']['unresolved'])?></strong> impacts</span>
</div>
<?php if($item['reasons']):?><div class="portfolioReasons"><h4>Why this project is <?=h(strtolower(portfolio_state_label((string)$item['attention_state'])))?></h4><?php foreach($item['reasons'] as $reason):?><a class="portfolioReason portfolioReason<?=h(ucfirst((string)$reason['level']))?>" href="<?=h((string)$reason['href'])?>"><span><strong><?=h((string)$reason['title'])?></strong><small><?=h((string)$reason['detail'])?></small></span><span class="badge"><?=h(strtoupper((string)$reason['level']))?></span></a><?php endforeach?></div><?php else:?><div class="portfolioClearMessage">No current attention signals were found in the deterministic project state.</div><?php endif?>
<div class="portfolioSystemRow">
<div><span class="meta">Automation</span><strong><?=h((string)$item['automation']['active'])?> active<?=($item['automation']['failing']||$item['automation']['recent_failed_runs'])?' · attention needed':''?></strong></div>
<div><span class="meta">Decision Memory</span><strong><?=h((string)$item['outcomes']['follow_up'])?> follow-up · <?=h((string)$item['outcomes']['reopened'])?> reopened</strong></div>
<div><span class="meta">Cross-Research</span><strong><?=h((string)$item['cross_research']['total'])?> suggestions · <?=h((string)$item['cross_research']['conflicts'])?> conflicts</strong></div>
<div><span class="meta">Agent</span><strong><?=h((string)$item['agent_actions']['pending'])?> pending confirmations</strong></div>
</div>
<div class="inlineActions portfolioActions"><a href="/research-project.php?id=<?=h((string)$p['public_id'])?>">Open Research</a><a href="/research-impact.php?project=<?=h((string)$p['public_id'])?>">Impact</a><a href="/research-reviews.php?scope=all">Reviews</a><a href="/cross-research.php?project=<?=h((string)$p['public_id'])?>">Related</a><a href="/research-outcomes.php?project=<?=h((string)$p['public_id'])?>">Decisions</a><a href="/home.php?portfolio_agent=1&project=<?=h((string)$p['public_id'])?>">Ask Agent</a></div>
</article>
<?php endforeach?></div>
<?php endif?>
</section>
<aside class="portfolioRail"><div class="card stickyPortfolioRail"><h3>How attention works</h3><p class="meta"><strong>Needs Attention</strong> means one or more explicit high-priority signals exist, such as unresolved Source impact, an overdue/stale review, conflicting evidence, a failed automation, a reopened outcome, or an Agent proposal waiting for confirmation.</p><p class="meta"><strong>Watch</strong> means follow-up work exists but no high-priority signal is present.</p><p class="meta"><strong>Clear</strong> means no current portfolio attention signal was found. It is not a quality score or guarantee that the Research is correct.</p></div>
<div class="card"><h3>Portfolio totals</h3><p class="meta"><?=h((string)$summary['review_objections'])?> unresolved review objection(s)<br><?=h((string)$summary['evidence_conflicts'])?> project evidence conflict signal(s)<br><?=h((string)$summary['pinned'])?> pinned project(s)</p></div></aside>
</main></body></html>