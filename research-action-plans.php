<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');
$error='';$success='';$scope=(string)($_GET['scope']??'all');$planId=trim((string)($_GET['action_plan']??$_POST['action_plan_id']??''));
$selected=$planId!==''?research_action_plan_detail($pdo,$u,$planId):null;
$selectedProject=$selected?project_access($pdo,(int)$u['id'],(string)$selected['project_public_id']):null;
$canWrite=$selectedProject&&project_can_write($selectedProject);
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$op=(string)($_POST['op']??'');
    try{
        if(!$selected)throw new RuntimeException('Action Plan not found.');
        if(!$canWrite)throw new RuntimeException('Write access to this Research project is required.');
        if($op==='set_status'){
            $status=(string)($_POST['status']??'');research_action_plan_set_status($pdo,$u,(string)$selected['public_id'],$status,false);
            header('Location: /research-action-plans.php?action_plan='.rawurlencode((string)$selected['public_id']).'&updated=1');exit;
        }
        throw new RuntimeException('Unknown Action Plan Command Center action.');
    }catch(Throwable $e){$error=$e->getMessage();}
}
if(isset($_GET['updated']))$success='Action Plan status updated.';
$center=research_action_plan_command_center($pdo,$u,$scope,200);
if($planId!==''&&!$selected){http_response_code(404);$error='Action Plan not found.';}
$allowed=[];
if($selected&&$canWrite){
  $map=['draft'=>['proposed','cancelled','archived'],'proposed'=>['draft','active','cancelled','archived'],'active'=>['paused','completed','cancelled'],'paused'=>['active','completed','cancelled'],'completed'=>['archived'],'cancelled'=>['archived'],'archived'=>[]];
  $allowed=$map[(string)$selected['status']]??[];
}
$selectedCognition=null;$selectedReview=null;
if($selected){
  if(function_exists('research_action_plan_cognition_ready')&&research_action_plan_cognition_ready($pdo)){try{$selectedCognition=research_action_plan_cognition_snapshot($pdo,$u,(string)$selected['public_id']);}catch(Throwable $ignored){}}
  $selectedReview=research_action_plan_review_overview($pdo,$u,(string)$selected['public_id']);
}
$stats=(array)($center['stats']??[]);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Action Plan Command Center · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css?v=72.0"></head><body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-action-plan-command-center">
<header class="topbar"><a class="brand" href="/home.php">Annotated</a><nav><a href="/home.php">Home</a><a href="/research.php">Research</a><a href="/research-decisions.php">Decisions</a><a href="/research-reviews.php">Review Center</a><a href="/settings.php">Settings</a></nav></header>
<main class="layout"><section>
<section class="researchLibraryToolbar"><nav class="researchLibraryTabs researchPrimaryActions"><a href="/research.php">Research Agents</a><a href="/research-programs.php">Programs</a><a href="/research-intelligence-command-center.php">Intelligence</a><a href="/research-decisions.php">Decisions</a><a class="active" href="/research-action-plans.php">Action Plans</a><a href="/research-reviews.php">Review Center</a></nav></section>
<header class="intelligenceCommandHero"><div><span class="eyebrow">PHASE 72 · EXECUTION GOVERNANCE</span><h1>Action Plan Command Center</h1><p>One permission-checked view across Action Plan ownership, execution state, strategic cognition, variances, source-Decision risk, and structured Team Review. Team Review is advisory; lifecycle changes remain explicit human actions.</p></div><small>Generated <?=h((string)$center['generated_at'])?></small></header>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<section class="intelligencePortfolioStats"><div><strong><?=h((string)($stats['total']??0))?></strong><span>Action Plans</span></div><div><strong><?=h((string)($stats['attention']??0))?></strong><span>Need attention</span></div><div><strong><?=h((string)($stats['active']??0))?></strong><span>Active</span></div><div><strong><?=h((string)($stats['decision_review']??0))?></strong><span>Decision review</span></div><div><strong><?=h((string)($stats['open_variances']??0))?></strong><span>Open variances</span></div><div><strong><?=h((string)($stats['open_reviews']??0))?></strong><span>Open reviews</span></div></section>
<nav class="reviewTabs"><?php foreach(['all'=>'All','attention'=>'Needs Attention','proposed'=>'Proposed','active'=>'Active','paused'=>'Paused','decision_review'=>'Decision Review','variances'=>'Variances','reviews'=>'Team Reviews','completed'=>'Completed'] as $key=>$label):?><a class="<?=$scope===$key?'active':''?>" href="/research-action-plans.php?scope=<?=h($key)?>"><?=h($label)?></a><?php endforeach?></nav>

<?php if($selected):$strategic=(string)($selectedCognition['strategic_state']??'');$latest=$selectedReview['latest']??null;?>
<article class="card">
<div class="reviewHeader"><div><div class="reviewBadges"><span class="badge"><?=h(strtoupper((string)$selected['status']))?></span><span class="badge">REVISION <?=h((string)$selected['current_revision'])?></span><?php if($strategic!==''):?><span class="badge"><?=h(strtoupper(str_replace('_',' ',$strategic)))?></span><?php endif?><?php if($latest):?><span class="badge"><?=h(strtoupper(str_replace('_',' ',(string)$latest['consensus'])))?></span><?php endif?></div><h2><?=h((string)$selected['title'])?></h2><p class="meta"><?=h((string)$selected['project_title'])?> · owner <?=h((string)($selected['owner_display_name']?:$selected['owner_username']))?> · source Decision <?=h((string)$selected['decision_title'])?></p></div><a href="/research-action-plans.php?scope=<?=h($scope)?>">Close</a></div>
<p><strong>Objective:</strong> <?=nl2br(h((string)$selected['objective']))?></p><p><strong>Expected result:</strong> <?=nl2br(h((string)$selected['expected_result']))?></p>
<?php if(!empty($selected['source_stale'])):?><div class="error"><strong>Source Decision changed.</strong> This Action Plan remains pinned to Decision revision <?=h((string)$selected['source_decision_revision'])?> and cannot be treated as current Decision authority.</div><?php endif?>
<div class="inlineActions"><a class="button secondary" href="/research-reviews.php?type=action_plan&subject=<?=h(rawurlencode((string)$selected['public_id']))?>">Request Team Review</a><a class="button secondary" href="/research-project.php?id=<?=h(rawurlencode((string)$selected['project_public_id']))?>">Open Research</a><a class="button secondary" href="/research-decisions.php?decision=<?=h(rawurlencode((string)$selected['decision_public_id']))?>">Open Decision</a></div>
<?php if($canWrite&&$allowed):?><form method="post" class="inlineForm"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="op" value="set_status"><input type="hidden" name="action_plan_id" value="<?=h((string)$selected['public_id'])?>"><label>Explicit Action Plan action <select name="status"><?php foreach($allowed as $st):?><option value="<?=h($st)?>"><?=h(ucfirst($st))?></option><?php endforeach?></select></label><button>Apply status</button></form><?php endif?>
<?php if($selectedCognition):?>
<div class="reviewMetrics"><span><strong><?=h((string)count((array)$selectedCognition['milestones']))?></strong> milestones</span><span><strong><?=h((string)count((array)$selectedCognition['tasks']))?></strong> tasks</span><span><strong><?=h((string)count((array)$selectedCognition['open_variances']))?></strong> open variances</span><span><strong><?=h((string)($selectedReview['open']??0))?></strong> open reviews</span></div>
<?php $reasons=array_values(array_unique(array_merge((array)$selectedCognition['decision_review_reasons'],(array)$selectedCognition['attention_reasons'])));if($reasons):?><h3>Attention</h3><ul><?php foreach($reasons as $reason):?><li><?=h((string)$reason)?></li><?php endforeach?></ul><?php endif?>
<?php if(!empty($selectedCognition['open_variances'])):?><h3>Open variances</h3><?php foreach($selectedCognition['open_variances'] as $v):?><div class="card"><div class="meta"><?=h(strtoupper((string)$v['severity']))?> · <?=h(strtoupper(str_replace('_',' ',(string)$v['variance_type'])))?></div><strong><?=h((string)$v['summary'])?></strong><?php if(!empty($v['impact'])):?><p><?=nl2br(h((string)$v['impact']))?></p><?php endif?></div><?php endforeach?><?php endif?>
<?php endif?>
</article>
<?php endif?>

<section><div class="sectionHeadWeb"><div><span class="eyebrow">EXECUTION PORTFOLIO</span><h2><?=h(ucwords(str_replace('_',' ',$scope)))?></h2></div></div>
<?php if(empty($center['action_plans'])):?><div class="card empty"><h3>No Action Plans in this view.</h3><p>Action Plans appear here when accepted or reopened Decisions are handed into governed execution.</p></div><?php endif?>
<?php foreach(($center['action_plans']??[]) as $p):$cmd=$p['command'];$latest=$cmd['review']['latest']??null;?>
<article class="card commandCenterRow"><div><div class="reviewBadges"><span class="badge"><?=h(strtoupper((string)$p['status']))?></span><span class="badge"><?=h(strtoupper(str_replace('_',' ',(string)$cmd['strategic_state'])))?></span><?php if($cmd['attention']):?><span class="badge">NEEDS ATTENTION</span><?php endif?><?php if($latest):?><span class="badge"><?=h(strtoupper(str_replace('_',' ',(string)$latest['consensus'])))?></span><?php endif?></div><strong><?=h((string)$p['title'])?></strong><small><?=h((string)$p['project_title'])?> · owner <?=h((string)($p['owner_display_name']?:$p['owner_username']))?><?php if($cmd['attention_reasons']):?> · <?=h(implode(' · ',array_slice($cmd['attention_reasons'],0,3)))?><?php endif?></small></div><div class="inlineActions"><a href="/research-action-plans.php?scope=<?=h($scope)?>&action_plan=<?=h(rawurlencode((string)$p['public_id']))?>">Open</a><a href="<?=h((string)$cmd['review_url'])?>">Review</a></div></article>
<?php endforeach?></section>
</section><aside><div class="card stickyReviewRail"><h3>Execution governance</h3><p class="meta">Team Review is pinned to exact Action Plan strategic state and remains advisory. A completed review never activates, pauses, completes, cancels, resolves a variance, or changes the source Decision.</p><a href="/research-reviews.php">Open Review Center</a></div></aside></main></body></html>
