<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');$error='';$success='';
$projectFilter=trim((string)($_GET['project']??$_POST['project']??''));$decisionFilter=trim((string)($_GET['decision']??$_POST['decision_filter']??''));
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$action=(string)($_POST['action']??'');
    try{
        if(!research_outcomes_ready($pdo))throw new RuntimeException('Run the Phase 20 database upgrade first.');
        if($action==='record'){research_outcome_manual($pdo,$u,$_POST);$success='Decision recorded.';}
        elseif($action==='feedback'){
            research_outcome_feedback_set($pdo,$u,(string)($_POST['outcome_id']??''),($_POST['usefulness']??'')!==''?(string)$_POST['usefulness']:null,(string)($_POST['follow_up_state']??'none'),(string)($_POST['comment']??''));$success='Outcome feedback updated.';
        }elseif($action==='sync'){research_outcome_sync($pdo,$u);$success='Decision Memory refreshed from authoritative product events.';}
    }catch(Throwable $e){$error=$e->getMessage();}
}
$ready=research_outcomes_ready($pdo);if($ready){try{research_outcome_sync($pdo,$u);}catch(Throwable $e){$error=$error?:$e->getMessage();}}
$projects=function_exists('cross_research_accessible_projects')?cross_research_accessible_projects($pdo,$u,80):[];
$outcomes=$ready?research_outcome_list($pdo,$u,$projectFilter?:null,$decisionFilter?:null,160):[];$summary=$ready?research_outcome_summary($pdo,$u,$projectFilter?:null):['total'=>0,'decisions'=>[],'sources'=>[],'helpful'=>0,'not_helpful'=>0,'follow_up'=>0,'reopened'=>0];
$labels=research_outcome_decision_labels();
function outcome_ref_href(string $type,string $id): ?string {
    return match($type){
      'project','research','research_project'=>'/research-project.php?id='.rawurlencode($id),
      'source'=>'/source.php?id='.rawurlencode($id),
      'annotation'=>'/annotation.php?id='.rawurlencode($id),
      'claim'=>'/research-claim.php?id='.rawurlencode($id),
      'finding'=>'/research-finding.php?id='.rawurlencode($id),
      'entity'=>'/entity.php?id='.rawurlencode($id),
      'automation'=>'/research-automations.php?id='.rawurlencode($id),
      'cross_research_link'=>'/cross-research.php',
      'conversation'=>'/home.php?agent='.rawurlencode($id),
      default=>null,
    };
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Decision Memory · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<header class="topbar"><a class="brand" href="/home.php">Annotated</a><nav><a href="/research.php">Research</a><a href="/cross-research.php">Related Research</a><a href="/research-automations.php">Automations</a></nav></header>
<main class="outcomeLayout"><section class="outcomeMain">
<div class="pageTitle"><span class="eyebrow">PHASE 20 · OUTCOME LEARNING & DECISION MEMORY</span><h1>Decision Memory</h1><p>An inspectable record of what was suggested, what you decided, what ran, and what happened next. Annotated uses explicit product events and your direct feedback—never a hidden behavioral profile.</p></div>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<?php if(!$ready):?><div class="card empty"><h2>Decision Memory needs migration 027.</h2><p>Existing Research remains unchanged until the upgrade runs.</p><?php if(($u['role']??'')==='admin'):?><a class="button" href="/upgrade.php">Run database upgrade</a><?php endif?></div><?php else:?>
<div class="outcomeSummary">
  <div class="card"><strong><?=h((string)$summary['total'])?></strong><span>Visible outcomes</span></div>
  <div class="card"><strong><?=h((string)$summary['helpful'])?></strong><span>Marked helpful</span></div>
  <div class="card"><strong><?=h((string)$summary['follow_up'])?></strong><span>Needs follow-up</span></div>
  <div class="card"><strong><?=h((string)$summary['reopened'])?></strong><span>Reopened</span></div>
</div>
<div class="card outcomeFilters"><form method="get" class="inlineForm"><label>Project<select name="project"><option value="">All accessible Research</option><?php foreach($projects as $p):?><option value="<?=h((string)$p['public_id'])?>" <?=$projectFilter===$p['public_id']?'selected':''?>><?=h((string)$p['title'])?></option><?php endforeach?></select></label><label>Decision<select name="decision"><option value="">All decisions</option><?php foreach($labels as $key=>$label):?><option value="<?=h($key)?>" <?=$decisionFilter===$key?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label><button class="button secondary">Filter</button><?php if($projectFilter!==''||$decisionFilter!==''):?><a href="/research-outcomes.php">Clear</a><?php endif?></form><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="sync"><button class="button secondary">Refresh memory</button></form></div>
<?php if(!$outcomes):?><div class="card empty"><h2>No Decision Memory events match this view.</h2><p>Executed/rejected Agent proposals, automation outcomes, Cross-Research decisions, dismissed Cognitive Feed observations, and manual notes will appear here.</p></div><?php endif?>
<div class="outcomeTimeline">
<?php foreach($outcomes as $o):?>
<article class="card outcomeCard" id="outcome-<?=h((string)$o['public_id'])?>">
<header><div><div class="outcomeBadges"><span class="badge"><?=h((string)($labels[$o['decision_type']]??ucfirst(str_replace('_',' ',(string)$o['decision_type']))))?></span><span class="badge"><?=h(ucwords(str_replace('_',' ',(string)$o['source_type'])))?></span><?php if(!empty($o['is_manual'])):?><span class="badge">Manual</span><?php endif?></div><h2><?=h((string)$o['title'])?></h2></div><span class="meta"><?=h((string)$o['occurred_at'])?></span></header>
<?php if(!empty($o['project_title'])):?><p class="meta"><a href="/research-project.php?id=<?=h((string)$o['project_public_id'])?>"><?=h((string)$o['project_title'])?></a></p><?php endif?>
<?php if(!empty($o['summary'])):?><p><?=nl2br(h((string)$o['summary']))?></p><?php endif?><?php if(!empty($o['note'])):?><div class="outcomeNote"><strong>Decision note</strong><p><?=nl2br(h((string)$o['note']))?></p></div><?php endif?>
<?php if(!empty($o['refs'])):?><div class="outcomeRefs"><?php foreach($o['refs'] as $ref):$href=outcome_ref_href((string)$ref['ref_type'],(string)$ref['ref_public_id']);if(!$href)continue;?><a class="button secondary" href="<?=h($href)?>"><?=h(ucwords(str_replace('_',' ',(string)$ref['ref_type'])))?></a><?php endforeach?></div><?php endif?>
<form method="post" class="outcomeFeedback"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="feedback"><input type="hidden" name="outcome_id" value="<?=h((string)$o['public_id'])?>">
<label>Was this useful?<select name="usefulness"><option value="">Not rated</option><option value="helpful" <?=$o['usefulness']==='helpful'?'selected':''?>>Helpful</option><option value="not_helpful" <?=$o['usefulness']==='not_helpful'?'selected':''?>>Not helpful</option></select></label>
<label>What happened next?<select name="follow_up_state"><option value="none" <?=$o['follow_up_state']==='none'||empty($o['follow_up_state'])?'selected':''?>>No follow-up state</option><option value="follow_up" <?=$o['follow_up_state']==='follow_up'?'selected':''?>>Needs follow-up</option><option value="resolved" <?=$o['follow_up_state']==='resolved'?'selected':''?>>Resolved</option><option value="reopened" <?=$o['follow_up_state']==='reopened'?'selected':''?>>Reopened</option></select></label>
<label class="outcomeComment">Outcome note<input name="comment" maxlength="1000" value="<?=h((string)($o['feedback_comment']??''))?>" placeholder="What happened after this decision?"></label><button>Save outcome</button></form>
</article>
<?php endforeach?>
</div>
<?php endif?>
</section>
<aside class="outcomeRail">
<div class="card stickyOutcome"><span class="eyebrow">RECORD A DECISION</span><h3>Add something Annotated cannot infer</h3><p class="meta">Use manual Decision Memory for offline decisions, calls, meetings, or judgment calls that happened outside an Annotated action.</p><?php if($ready):?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="record">
<label>Project<select name="project_id"><option value="">No project</option><?php foreach($projects as $p):?><option value="<?=h((string)$p['public_id'])?>"><?=h((string)$p['title'])?></option><?php endforeach?></select></label>
<label>Decision<select name="decision_type"><?php foreach($labels as $key=>$label):?><option value="<?=h($key)?>" <?=$key==='recorded'?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label>
<label>Title<input name="title" maxlength="255" required placeholder="We decided to verify the vendor claim"></label>
<label>Summary<textarea name="summary" rows="4" maxlength="1200" placeholder="What was decided?"></textarea></label>
<label>Linked object type<select name="object_type"><option value="">None</option><option value="claim">Claim</option><option value="finding">Finding</option><option value="source">Source</option><option value="annotation">Annotation</option><option value="entity">Entity</option><option value="automation">Automation</option></select></label>
<label>Linked object ID<input name="object_public_id" maxlength="64" placeholder="Optional Annotated public ID"></label>
<label>Decision note<textarea name="note" rows="5" maxlength="8000" placeholder="Why this decision was made, what evidence mattered, or what should happen next."></textarea></label><button>Record decision</button></form><?php endif?></div>
<div class="card"><h3>How learning works</h3><p class="meta">Decision Memory does not train a private psychological profile or silently change rankings. It exposes counts, explicit outcomes, and current follow-up states. Agent context may summarize these visible records so it can answer “what did we decide?” and “what happened next?”</p></div>
<?php if($ready&&$summary['decisions']):?><div class="card"><h3>Decision counts</h3><?php foreach($summary['decisions'] as $key=>$count):?><p class="outcomeStatRow"><span><?=h((string)($labels[$key]??ucfirst($key)))?></span><strong><?=h((string)$count)?></strong></p><?php endforeach?></div><?php endif?>
</aside></main></body></html>