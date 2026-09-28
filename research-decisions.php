<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');
$error='';$success='';$scope=(string)($_GET['scope']??'all');$decisionId=trim((string)($_GET['decision']??$_POST['decision_id']??''));
$selected=$decisionId!==''?research_decision_detail($pdo,$u,$decisionId):null;
$selectedProject=$selected?project_access($pdo,(int)$u['id'],(string)$selected['project_public_id']):null;
$canWrite=$selectedProject&&project_can_write($selectedProject);
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$op=(string)($_POST['op']??'');
    try{
        if(!$selected)throw new RuntimeException('Decision not found.');
        if(!$canWrite)throw new RuntimeException('Write access to this Research project is required.');
        if($op==='set_status'){
            $status=(string)($_POST['status']??'');research_decision_set_status($pdo,$u,(string)$selected['public_id'],$status,false);
            header('Location: /research-decisions.php?decision='.rawurlencode((string)$selected['public_id']).'&updated=1');exit;
        }
        throw new RuntimeException('Unknown Decision Command Center action.');
    }catch(Throwable $e){$error=$e->getMessage();}
}
if(isset($_GET['updated']))$success='Decision status updated.';
$center=research_decision_command_center($pdo,$u,$scope,200);
if($decisionId!==''&&!$selected){http_response_code(404);$error='Decision not found.';}
$allowed=[];
if($selected&&$canWrite){
  $map=[
    'draft'=>['proposed','accepted','rejected','deferred','archived'],'proposed'=>['draft','accepted','rejected','deferred','archived'],
    'accepted'=>['reopened','superseded','archived'],'rejected'=>['reopened','superseded','archived'],'deferred'=>['reopened','accepted','rejected','archived'],
    'reopened'=>['proposed','accepted','rejected','deferred','superseded','archived'],'superseded'=>['reopened','archived'],'archived'=>['reopened']
  ];$allowed=$map[(string)$selected['status']]??[];
}
$stats=(array)($center['stats']??[]);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Decision Command Center · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css?v=71.0"></head><body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-decision-command-center">
<header class="topbar"><a class="brand" href="/home.php">Annotated</a><nav><a href="/home.php">Home</a><a href="/research.php">Research</a><a href="/research-reviews.php">Review Center</a><a href="/settings.php">Settings</a></nav></header>
<main class="layout"><section>
<section class="researchLibraryToolbar"><nav class="researchLibraryTabs researchPrimaryActions"><a href="/research.php">Research Agents</a><a href="/research-programs.php">Programs</a><a href="/research-intelligence-command-center.php">Intelligence</a><a class="active" href="/research-decisions.php">Decisions</a><a href="/research-action-plans.php">Action Plans</a><a href="/research-reviews.php">Review Center</a></nav></section>
<header class="intelligenceCommandHero"><div><span class="eyebrow">PHASE 71 · DECISION MEMORY</span><h1>Decision Command Center</h1><p>One permission-checked view across Decisions, outcomes, reconsideration signals, and structured human review. Team review is advisory; Decision state changes only through explicit human action.</p></div><small>Generated <?=h((string)$center['generated_at'])?></small></header>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<section class="intelligencePortfolioStats"><div><strong><?=h((string)($stats['total']??0))?></strong><span>Decisions</span></div><div><strong><?=h((string)($stats['attention']??0))?></strong><span>Need attention</span></div><div><strong><?=h((string)($stats['proposed']??0))?></strong><span>Proposed</span></div><div><strong><?=h((string)($stats['reopened']??0))?></strong><span>Reopened</span></div><div><strong><?=h((string)($stats['with_outcomes']??0))?></strong><span>With outcomes</span></div><div><strong><?=h((string)($stats['open_reviews']??0))?></strong><span>Open reviews</span></div></section>
<nav class="reviewTabs"><?php foreach(['all'=>'All','attention'=>'Needs Attention','proposed'=>'Proposed','reopened'=>'Reopened','decided'=>'Decided','outcomes'=>'Outcomes','reviews'=>'Team Reviews'] as $key=>$label):?><a class="<?=$scope===$key?'active':''?>" href="/research-decisions.php?scope=<?=h($key)?>"><?=h($label)?></a><?php endforeach?></nav>

<?php if($selected):?>
<article class="card">
<div class="reviewHeader"><div><div class="reviewBadges"><span class="badge"><?=h(strtoupper((string)$selected['decision_type']))?></span><span class="badge"><?=h(strtoupper((string)$selected['status']))?></span><span class="badge">REVISION <?=h((string)$selected['current_revision'])?></span></div><h2><?=h((string)$selected['title'])?></h2><p class="meta"><?=h((string)$selected['project_title'])?> · <?=h((string)$selected['agent_name'])?></p></div><a href="/research-decisions.php?scope=<?=h($scope)?>">Close</a></div>
<p><strong>Statement:</strong> <?=nl2br(h((string)$selected['statement']))?></p><?php if(!empty($selected['rationale'])):?><p><strong>Rationale:</strong> <?=nl2br(h((string)$selected['rationale']))?></p><?php endif?>
<?php if($selected['confidence']!==null):?><p class="meta">Decision confidence <?=h(number_format((float)$selected['confidence'],2,'.',''))?></p><?php endif?>
<div class="inlineActions"><a class="button secondary" href="/research-reviews.php?type=decision&subject=<?=h(rawurlencode((string)$selected['public_id']))?>">Request Team Review</a><a class="button secondary" href="/research-project.php?id=<?=h(rawurlencode((string)$selected['project_public_id']))?>">Open Research</a></div>
<?php if($canWrite&&$allowed):?><form method="post" class="inlineForm"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="op" value="set_status"><input type="hidden" name="decision_id" value="<?=h((string)$selected['public_id'])?>"><label>Explicit Decision action <select name="status"><?php foreach($allowed as $st):?><option value="<?=h($st)?>"><?=h(ucfirst($st))?></option><?php endforeach?></select></label><button>Apply status</button></form><?php endif?>
<?php $sig=research_decision_reconsideration_signals($pdo,$u,(string)$selected['public_id']);$os=research_decision_outcome_summary($pdo,$u,(string)$selected['public_id']);$rv=research_decision_review_overview($pdo,$u,(string)$selected['public_id']);?>
<div class="reviewMetrics"><span><strong><?=h((string)count((array)$sig['signals']))?></strong> review signals</span><span><strong><?=h((string)($os['total']??0))?></strong> outcomes</span><span><strong><?=h((string)($rv['open']??0))?></strong> open reviews</span></div>
<?php if(!empty($selected['reconsiderations'])):?><h3>Reconsiderations</h3><?php foreach($selected['reconsiderations'] as $case):?><div class="card"><div class="meta"><?=h(strtoupper((string)$case['materiality']))?> · <?=h(strtoupper((string)$case['status']))?> · <?=h(strtoupper((string)$case['recommended_action']))?></div><strong><?=h((string)$case['title'])?></strong><p><?=nl2br(h((string)$case['reason']))?></p><a href="/research-reviews.php?type=decision_reconsideration&subject=<?=h(rawurlencode((string)$case['public_id']))?>">Request review of this reconsideration</a></div><?php endforeach?><?php endif?>
<?php if(!empty($selected['outcomes'])):?><h3>Outcome Memory</h3><?php foreach($selected['outcomes'] as $o):?><div class="card"><div class="meta"><?=h(strtoupper((string)$o['assessment']))?> · <?=h((string)$o['observed_at'])?> · FOLLOW-UP <?=h(strtoupper((string)$o['follow_up_state']))?></div><p><?=nl2br(h((string)$o['actual_summary']))?></p><?php if(!empty($o['lessons'])):?><p><strong>Lesson:</strong> <?=nl2br(h((string)$o['lessons']))?></p><?php endif?></div><?php endforeach?><?php endif?>
</article>
<?php endif?>

<section><div class="sectionHeadWeb"><div><span class="eyebrow">DECISION PORTFOLIO</span><h2><?=h(ucwords(str_replace('_',' ',$scope)))?></h2></div></div>
<?php if(empty($center['decisions'])):?><div class="card empty"><h3>No Decisions in this view.</h3><p>Decision Memory will appear here as Research Missions and evidence are handed into the Decision Ledger.</p></div><?php endif?>
<?php foreach(($center['decisions']??[]) as $d):$cmd=$d['command'];$latest=$cmd['review']['latest']??null;?>
<article class="card commandCenterRow"><div><div class="reviewBadges"><span class="badge"><?=h(strtoupper((string)$d['decision_type']))?></span><span class="badge"><?=h(strtoupper((string)$d['status']))?></span><?php if($cmd['attention']):?><span class="badge">NEEDS ATTENTION</span><?php endif?><?php if($latest):?><span class="badge"><?=h(strtoupper(str_replace('_',' ',(string)$latest['consensus'])))?></span><?php endif?></div><strong><?=h((string)$d['title'])?></strong><small><?=h((string)$d['project_title'])?> · revision <?=h((string)$d['current_revision'])?><?php if($cmd['attention_reasons']):?> · <?=h(implode(' · ',$cmd['attention_reasons']))?><?php endif?></small></div><div class="inlineActions"><a href="/research-decisions.php?scope=<?=h($scope)?>&decision=<?=h(rawurlencode((string)$d['public_id']))?>">Open</a><a href="<?=h((string)$cmd['review_url'])?>">Review</a></div></article>
<?php endforeach?></section>
</section><aside><div class="card stickyReviewRail"><h3>Decision governance</h3><p class="meta">Review responses are advisory and pinned to exact Decision state. A completed review does not automatically accept, reject, reopen, supersede, or defer a Decision.</p><a href="/research-reviews.php">Open Review Center</a></div></aside></main></body></html>
