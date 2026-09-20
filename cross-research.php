<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');
$error='';$success='';$projectFilter=trim((string)($_GET['project']??$_POST['project']??''));
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$action=(string)($_POST['action']??'');
    try{
        if(!cross_research_ready($pdo))throw new RuntimeException('Run the Phase 19 database upgrade first.');
        if($action==='accept'){cross_research_accept($pdo,$u,(string)($_POST['key']??''),(string)($_POST['relation']??''));$success='Relationship accepted.';}
        elseif($action==='reject'){if(!cross_research_reject($pdo,$u,(string)($_POST['key']??'')))throw new RuntimeException('Suggestion is no longer available.');$success='Suggestion dismissed.';}
        elseif($action==='restore'){if(!cross_research_restore_decision($pdo,$u,(string)($_POST['key']??'')))throw new RuntimeException('Decision not found.');$success='Suggestion restored.';}
        elseif($action==='project_link'){cross_research_create_project_link($pdo,$u,(string)($_POST['source_project']??''),(string)($_POST['target_project']??''),(string)($_POST['relation']??'related'),(string)($_POST['rationale']??''));$success='Project relationship added.';}
        elseif($action==='delete_link'){if(!cross_research_delete_link($pdo,$u,(string)($_POST['link_id']??'')))throw new RuntimeException('Relationship not found.');$success='Relationship removed.';}
    }catch(Throwable $e){$error=$e->getMessage();}
}
$ready=cross_research_ready($pdo);$projects=cross_research_accessible_projects($pdo,$u,60);$focus=null;if($projectFilter!==''){$focus=project_access($pdo,(int)$u['id'],$projectFilter);if(!$focus){$error=$error?:'That Research project is unavailable.';$projectFilter='';}}
$suggestions=$ready?cross_research_suggestions($pdo,$u,$projectFilter?:null,160,false):[];$links=$ready?cross_research_links($pdo,$u,$projectFilter?:null,120):[];$threads=$ready?cross_research_entity_threads($pdo,$u,$projectFilter?:null,40):[];
$decided=[];if($ready){$q=$pdo->prepare("SELECT suggestion_key,decision,link_public_id,updated_at FROM cross_research_decisions WHERE user_id=? ORDER BY updated_at DESC LIMIT 100");$q->execute([$u['id']]);$decided=$q->fetchAll();}
$sections=['conflicts'=>[],'projects'=>[],'evidence'=>[],'entities'=>[],'claims'=>[],'opportunities'=>[]];
foreach($suggestions as $s){
    if(in_array($s['type'],['evidence_label_conflict','claim_status_difference'],true))$sections['conflicts'][]=$s;
    elseif($s['type']==='related_projects')$sections['projects'][]=$s;
    elseif(in_array($s['type'],['shared_source','shared_annotation','shared_claim_evidence'],true))$sections['evidence'][]=$s;
    elseif($s['type']==='same_entity')$sections['entities'][]=$s;
    elseif($s['type']==='claim_overlap')$sections['claims'][]=$s;
    elseif($s['type']==='evidence_reuse')$sections['opportunities'][]=$s;
}
$relationTypes=cross_research_relation_types();$projectRelationTypes=cross_research_project_relation_types();
function cross_research_page_object_links(array $s): array {
    $out=[];$type=(string)$s['object_type'];$a=(string)$s['source_object_public_id'];$b=(string)$s['target_object_public_id'];
    if($type==='claim'){$out[]=['label'=>'Claim A','href'=>'/research-claim.php?id='.rawurlencode($a)];if($b!==$a)$out[]=['label'=>'Claim B','href'=>'/research-claim.php?id='.rawurlencode($b)];}
    elseif($type==='entity'){$out[]=['label'=>'Entity A','href'=>'/entity.php?id='.rawurlencode($a)];if($b!==$a)$out[]=['label'=>'Entity B','href'=>'/entity.php?id='.rawurlencode($b)];}
    elseif($type==='source')$out[]=['label'=>'Source','href'=>'/source.php?id='.rawurlencode($a)];
    elseif($type==='annotation')$out[]=['label'=>'Annotation','href'=>'/annotation.php?id='.rawurlencode($a)];
    return $out;
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Related Research · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="crossResearchLayout">
<section class="crossResearchMain">
<div class="pageTitle"><span class="eyebrow">PHASE 19 · CROSS-RESEARCH INTELLIGENCE</span><h1>Related Research</h1><p>Find explainable connections across Research projects you can currently access. Suggestions come from exact Sources, Annotations, Entities, evidence records, and bounded Claim overlap—not from a hidden global ranker.</p></div>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<div class="crossResearchToolbar card">
<form method="get" class="inlineForm"><label>Project<select name="project"><option value="">All accessible Research</option><?php foreach($projects as $p):?><option value="<?=h((string)$p['public_id'])?>" <?=$projectFilter===$p['public_id']?'selected':''?>><?=h((string)$p['title'])?></option><?php endforeach?></select></label><button class="button secondary">Filter</button><?php if($projectFilter!==''):?><a href="/cross-research.php">Clear</a><?php endif?></form>
<a class="button secondary" href="/research-automations.php">Automate cross-project review</a>
</div>
<?php if(!$ready):?><div class="card empty"><h2>Cross-Research Intelligence needs migration 026.</h2><p>Existing Research remains unchanged until the upgrade runs.</p><?php if(($u['role']??'')==='admin'):?><a class="button" href="/upgrade.php">Run database upgrade</a><?php endif?></div><?php elseif(count($projects)<2):?><div class="card empty"><h2>Two Research projects are needed.</h2><p>Cross-Research Intelligence only compares projects you can currently access.</p><a class="button" href="/research.php">Open Research</a></div><?php else:?>
<div class="crossResearchSummary">
<div class="card"><strong><?=h((string)count($suggestions))?></strong><span>Current suggestions</span></div>
<div class="card"><strong><?=h((string)count($sections['conflicts']))?></strong><span>Needs attention</span></div>
<div class="card"><strong><?=h((string)count($links))?></strong><span>Accepted links</span></div>
<div class="card"><strong><?=h((string)count($threads))?></strong><span>Entity threads</span></div>
</div>
<?php
$labels=[
 'conflicts'=>['Needs attention','Same or overlapping Research evidence is being treated differently across accessible projects.'],
 'projects'=>['Related projects','Project-level relationships aggregated from multiple factual signals.'],
 'evidence'=>['Shared evidence','Exact Sources, Annotations, or Source Versions reused across projects.'],
 'entities'=>['Same entities','Normalized Entity identities appearing in multiple projects.'],
 'claims'=>['Claim overlap','Deterministic wording overlap that may indicate duplicate or related Claims.'],
 'opportunities'=>['Evidence reuse opportunities','Evidence from a Source already used elsewhere. Review before attaching anything.']
];
foreach($labels as $sectionKey=>$label):$items=$sections[$sectionKey];if(!$items)continue;?>
<section class="crossResearchSection"><div class="sectionHeadWeb"><div><span class="eyebrow"><?=h(strtoupper($label[0]))?></span><h2><?=h($label[0])?></h2><p><?=h($label[1])?></p></div><span class="badge"><?=h((string)count($items))?></span></div>
<div class="crossResearchCards">
<?php foreach($items as $s):$objectLinks=cross_research_page_object_links($s);?>
<article class="card crossResearchCard <?=$s['priority']==='high'?'crossResearchHigh':''?>" id="suggestion-<?=h(substr((string)$s['key'],0,12))?>">
<header><div><div class="crossResearchBadges"><span class="badge"><?=h(str_replace('_',' ',ucfirst((string)$s['type'])))?></span><span class="badge"><?=h((string)$s['score'])?> signal</span></div><h3><?=h((string)$s['title'])?></h3></div><span class="meta"><?=h((string)$s['created_at'])?></span></header>
<p><?=h((string)$s['body'])?></p>
<div class="crossResearchProjects"><a href="/research-project.php?id=<?=h((string)$s['source_project_public_id'])?>"><?=h((string)$s['source_project_title'])?></a><span>↔</span><a href="/research-project.php?id=<?=h((string)$s['target_project_public_id'])?>"><?=h((string)$s['target_project_title'])?></a></div>
<details class="crossResearchWhy"><summary>Why Annotated connected these</summary><ul><?php foreach($s['reasons'] as $reason):?><li><?=h((string)$reason)?></li><?php endforeach?></ul></details>
<div class="inlineActions crossResearchActions">
<?php foreach($objectLinks as $ol):?><a class="button secondary" href="<?=h($ol['href'])?>"><?=h($ol['label'])?></a><?php endforeach?>
<a class="button secondary" href="/home.php?cross_research_agent=<?=h((string)$s['key'])?>">Ask Agent</a>
<form method="post" class="crossResearchAccept"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="accept"><input type="hidden" name="key" value="<?=h((string)$s['key'])?>"><input type="hidden" name="project" value="<?=h($projectFilter)?>"><select name="relation" aria-label="Relationship"><?php foreach($relationTypes as $key=>$labelText):?><option value="<?=h($key)?>" <?=$key===$s['suggested_relation']?'selected':''?>><?=h($labelText)?></option><?php endforeach?></select><button>Accept relationship</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="key" value="<?=h((string)$s['key'])?>"><input type="hidden" name="project" value="<?=h($projectFilter)?>"><button class="button secondary">Dismiss</button></form>
</div>
</article>
<?php endforeach?>
</div></section>
<?php endforeach?>
<?php if(!$suggestions):?><div class="card empty"><h2>No undecided Cross-Research suggestions right now.</h2><p>Accepted relationships remain below. New evidence or project changes can create new explainable suggestions later.</p></div><?php endif?>
<section class="crossResearchSection"><div class="sectionHeadWeb"><div><span class="eyebrow">ACCEPTED</span><h2>Cross-Research links</h2><p>Explicit relationships you chose to keep. These links never merge projects or move evidence.</p></div><span class="badge"><?=h((string)count($links))?></span></div>
<?php if(!$links):?><div class="card empty">No accepted Cross-Research links yet.</div><?php endif?>
<div class="crossResearchLinks"><?php foreach($links as $l):?><article class="card"><div class="crossResearchBadges"><span class="badge"><?=h((string)$relationTypes[$l['relation_type']])?></span><span class="badge"><?=h(ucfirst((string)$l['object_type']))?></span></div><h3><?=h((string)$l['source_project_title'])?> ↔ <?=h((string)$l['target_project_title'])?></h3><?php if(trim((string)$l['rationale'])!==''):?><p><?=h((string)$l['rationale'])?></p><?php endif?><div class="inlineActions"><a href="/research-project.php?id=<?=h((string)$l['source_project_public_id'])?>">First project</a><a href="/research-project.php?id=<?=h((string)$l['target_project_public_id'])?>">Second project</a><form method="post" onsubmit="return confirm('Remove this Cross-Research relationship?')"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete_link"><input type="hidden" name="link_id" value="<?=h((string)$l['public_id'])?>"><input type="hidden" name="project" value="<?=h($projectFilter)?>"><button class="button secondary">Remove</button></form></div></article><?php endforeach?></div>
</section>
<section class="crossResearchSection"><div class="sectionHeadWeb"><div><span class="eyebrow">ENTITY THREADS</span><h2>Entities across Research</h2><p>Accessible project history grouped by exact normalized Entity identity.</p></div><span class="badge"><?=h((string)count($threads))?></span></div>
<div class="entityThreadGrid"><?php foreach($threads as $thread):?><article class="card entityThread"><header><div><span class="badge"><?=h(ucfirst((string)$thread['entity_type']))?></span><h3><?=h((string)$thread['canonical_name'])?></h3></div><span class="meta"><?=h((string)$thread['project_count'])?> projects · <?=h((string)$thread['mention_count'])?> mentions</span></header><div class="entityThreadProjects"><?php foreach($thread['projects'] as $tp):?><a href="/entity.php?id=<?=h((string)$tp['entity_public_id'])?>"><?=h((string)$tp['title'])?> <small><?=h((string)$tp['mentions'])?> mentions</small></a><?php endforeach?></div></article><?php endforeach?></div>
</section>
<?php endif?>
</section>
<aside class="crossResearchRail">
<div class="card stickyCrossResearch"><span class="eyebrow">CONNECT PROJECTS</span><h3>Add an explicit project relationship</h3><p class="meta">Use this when you already know how two projects relate. It creates a personal Cross-Research link only.</p>
<?php if($ready&&count($projects)>=2):?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="project_link"><input type="hidden" name="project" value="<?=h($projectFilter)?>">
<label>First project<select name="source_project" required><?php foreach($projects as $p):?><option value="<?=h((string)$p['public_id'])?>"><?=h((string)$p['title'])?></option><?php endforeach?></select></label>
<label>Second project<select name="target_project" required><?php foreach($projects as $i=>$p):?><option value="<?=h((string)$p['public_id'])?>" <?=$i===1?'selected':''?>><?=h((string)$p['title'])?></option><?php endforeach?></select></label>
<label>Relationship<select name="relation"><?php foreach($projectRelationTypes as $key=>$labelText):?><option value="<?=h($key)?>"><?=h($labelText)?></option><?php endforeach?></select></label>
<label>Note<textarea name="rationale" rows="4" maxlength="1000" placeholder="Why these projects are related"></textarea></label><button>Add relationship</button></form><?php endif?></div>
<div class="card"><h3>How Phase 19 works</h3><p class="meta">Suggestions are recalculated from current accessible Research. Rejected suggestions are private to you. Accepted links stay explicit, but disappear from your view immediately if you lose access to either project.</p></div>
</aside>
</main></body></html>