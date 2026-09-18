<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$viewer=current_user($pdo);$id=(string)($_GET['id']??'');$e=search_discovery_entity($pdo,$id,$viewer);if(!$e){http_response_code(404);exit('Entity not found.');}
$title=(string)$e['canonical_name'];?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($title)?> · Annotated</title><meta name="description" content="<?=h(public_discovery_meta_description((string)($e['description']?:'Public research entity on Annotated.'),180))?>"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<header class="topbar"><a class="brand" href="/">Annotated</a><nav><a href="/explore.php">Explore</a><a href="/search.php">Search</a><?php if($viewer):?><a href="/research.php">Research</a><?php endif?></nav></header>
<main class="discoveryShell entityPage"><div class="pageTitle"><span class="eyebrow"><?=h(strtoupper((string)$e['entity_type']))?></span><h1><?=h($title)?></h1><?php if($e['description']):?><p><?=nl2br(h((string)$e['description']))?></p><?php endif?><?php if($e['aliases']):?><p class="meta">Also known as <?=h(implode(', ',$e['aliases']))?></p><?php endif?></div>
<section><div class="sectionHeadWeb"><div><span class="eyebrow">PUBLIC GRAPH</span><h2>Mentions & evidence</h2></div><a href="/search.php?q=<?=urlencode($title)?>">Search this entity</a></div>
<?php if(!$e['mentions']):?><div class="card">No currently accessible public mentions.</div><?php endif?>
<div class="entityMentions"><?php foreach($e['mentions'] as $m):?><a class="card sourceCard" href="<?=h($m['url'])?>"><div class="meta"><?=h(ucwords(str_replace('_',' ',(string)$m['object_type'])))?> · weight <?=h((string)$m['mention_weight'])?></div><h3><?=h((string)($m['label']?:$title))?></h3><?php if($m['excerpt']):?><p><?=h(mb_substr((string)$m['excerpt'],0,600))?></p><?php endif?></a><?php endforeach?></div></section>
</main></body></html>