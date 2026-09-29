<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';require_once __DIR__.'/app/public-discovery.php';require_once __DIR__.'/app/annotation-ui.php';$viewer=current_user($pdo);$d=public_discovery_explore($pdo,$viewer);$intel=search_explore_intelligence($pdo,$viewer);
$recommendations=[];foreach(($intel['recommendations']??[]) as $row){$full=public_discovery_annotation($pdo,(string)($row['public_id']??''),$viewer);if($full)$recommendations[]=$full;}$intel['recommendations']=$recommendations;$peopleRecommendations=$viewer?profile_network_suggested_people($pdo,$viewer,6):profile_network_discover_people($pdo,null,'',6);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Explore · Annotated</title><meta name="description" content="Explore public annotations, active sources, media captures, discussions, and published Research on Annotated."><link rel="canonical" href="<?=h(public_discovery_absolute_url($config,'/explore.php'))?>"><meta property="og:title" content="Explore Annotated"><meta property="og:description" content="See what people are annotating, discussing, and researching around the web."><link rel="stylesheet" href="/assets/css/app.css?v=76.0"></head><body data-workspace-surface="explore">
<header class="topbar"><a class="brand" href="/">Annotated</a><nav><?php if($viewer):?><a href="/home.php">Home</a><?php endif?><a href="/explore.php">Explore</a><a href="/search.php">Search</a><?php if($viewer):?><a href="/research.php">Research</a><a href="/saved.php">Saved</a><a href="/notifications.php">Notifications</a><?php else:?><a href="/login.php">Log in</a><a href="/register.php">Sign up</a><?php endif?></nav></header>
<main class="discoveryShell exploreDashboard">
  <header class="exploreDashboardHead">
    <div><span class="eyebrow">EXPLORE</span><h1>Discover</h1><p>People, sources, conversations, and published Research gaining attention across Annotated.</p></div>
    <nav><a href="/people.php">People</a><a href="/live.php">Live rooms</a></nav>
  </header>

  <section class="exploreDashboardSection">
    <div class="sectionHeadWeb compact"><div><span class="eyebrow">PEOPLE & RESEARCH</span><h2>Researchers to discover</h2></div><a href="/people.php">Browse people</a></div>
    <div class="explorePeopleStrip"><?php foreach($peopleRecommendations as $person):?><a class="explorePersonCard" href="<?=h(profile_path((string)$person['username']))?>"><div class="profileDiscoveryIdentity"><?php if(!empty($person['profile_image_url'])):?><img src="<?=h((string)$person['profile_image_url'])?>" alt=""><?php else:?><span><?=h(mb_strtoupper(mb_substr((string)$person['display_name'],0,1)))?></span><?php endif?><div><strong><?=h((string)$person['display_name'])?></strong><small>@<?=h((string)$person['username'])?></small></div></div><?php if(!empty($person['recommendation_reasons'])):?><p><?=h(implode(' · ',array_slice($person['recommendation_reasons'],0,2)))?></p><?php elseif(!empty($person['topics'])):?><p><?=h(implode(' · ',array_map(fn($t)=>(string)$t['canonical_name'],array_slice($person['topics'],0,2))))?></p><?php endif?><footer><span><?=h((string)($person['report_count']??0))?> Research</span><span><?=h((string)($person['annotation_count']??0))?> annotations</span></footer></a><?php endforeach?><?php if(!$peopleRecommendations):?><div class="exploreEmptyCard">Researchers will appear as public activity grows.</div><?php endif?></div>
  </section>

  <div class="exploreDashboardColumns">
    <section class="exploreDashboardSection">
      <div class="sectionHeadWeb compact"><div><span class="eyebrow">TRENDING · 7 DAYS</span><h2>Sources gaining attention</h2></div><a href="/search.php?sort=discussed&q=source">View all</a></div>
      <div class="exploreSourceList"><?php foreach(array_slice($intel['trending'],0,6) as $s):?><a href="/source.php?id=<?=h($s['public_id'])?>"><div><small><?=h($s['domain'])?> · <?=h((string)$s['contributor_count'])?> contributors</small><strong><?=h($s['title']?:$s['domain'])?></strong></div><span><?=h((string)$s['annotation_count'])?> annotations</span></a><?php endforeach?><?php if(!$intel['trending']):?><div class="exploreEmptyCard">Trending sources will appear as public activity accumulates.</div><?php endif?></div>
    </section>

    <section class="exploreDashboardSection">
      <div class="sectionHeadWeb compact"><div><span class="eyebrow">TOPICS & ENTITIES · 30 DAYS</span><h2>Growing research threads</h2></div></div>
      <div class="exploreTopicGrid"><?php foreach(array_slice($intel['topics'],0,10) as $t):?><a href="/entity.php?id=<?=h($t['public_id'])?>"><strong><?=h($t['canonical_name'])?></strong><small><?=h($t['entity_type'])?> · <?=h((string)$t['mention_count'])?> mentions</small></a><?php endforeach?><?php if(!$intel['topics']):?><div class="exploreEmptyCard">Public topics appear as published Research reports contribute confirmed entities.</div><?php endif?></div>
    </section>
  </div>

  <?php if($viewer):?><section class="exploreDashboardSection">
    <div class="sectionHeadWeb compact"><div><span class="eyebrow">BECAUSE YOU FOLLOW</span><h2>Recommended annotations</h2></div></div>
    <div class="exploreAnnotationStrip"><?php foreach(array_slice($intel['recommendations'],0,3) as $a):?><?=annotation_ui_card($a,$viewer)?><?php endforeach?><?php if(!$intel['recommendations']):?><div class="exploreEmptyCard">Follow people or sources to personalize discovery.</div><?php endif?></div>
  </section><?php endif?>

  <section class="exploreDashboardSection">
    <div class="sectionHeadWeb compact"><div><span class="eyebrow">RECENTLY ACTIVE</span><h2>Sources</h2></div></div>
    <div class="exploreSourceCards"><?php foreach(array_slice($d['sources'],0,8) as $s):?><a class="exploreSourceCard" href="/source.php?id=<?=h($s['public_id'])?>"><small><?=h($s['domain'])?><?php if($s['status']!=='current'):?> · <?=h(ucfirst($s['status']))?><?php endif?></small><strong><?=h($s['title']?:$s['canonical_url'])?></strong><footer><span><?=h((string)$s['annotation_count'])?> annotations</span><span><?=h((string)$s['contributor_count'])?> contributors</span><span><?=h((string)$s['comment_count'])?> comments</span></footer></a><?php endforeach?><?php if(!$d['sources']):?><div class="exploreEmptyCard">Recently active sources will appear here.</div><?php endif?></div>
  </section>

  <section class="exploreFeedGrid">
    <div>
      <div class="sectionHeadWeb compact"><div><span class="eyebrow">LATEST</span><h2>Annotations</h2></div></div>
      <div class="exploreAnnotationFeed"><?php if(!$d['annotations']):?><div class="exploreEmptyCard">No public annotations yet.</div><?php endif?><?php foreach($d['annotations'] as $a):?><?=annotation_ui_card($a,$viewer)?><?php endforeach?></div>
    </div>
    <aside>
      <div class="sectionHeadWeb compact"><div><span class="eyebrow">MOST DISCUSSED</span><h2>Conversation</h2></div></div>
      <div class="exploreConversationList"><?php if(!$d['discussed']):?><div class="exploreEmptyCard">Discussion will appear here as public comments accumulate.</div><?php endif?><?php foreach($d['discussed'] as $s):?><a href="/source.php?id=<?=h($s['public_id'])?>"><small><?=h($s['domain'])?></small><strong><?=h($s['title']?:'Source')?></strong><span><?=h((string)$s['comment_count'])?> comments · <?=h((string)$s['annotation_count'])?> annotations</span></a><?php endforeach?></div>
    </aside>
  </section>

  <?php if($d['media']):?><section class="exploreDashboardSection">
    <div class="sectionHeadWeb compact"><div><span class="eyebrow">RICH CAPTURE</span><h2>Media & visual annotations</h2></div></div>
    <div class="exploreAnnotationStrip"><?php foreach(array_slice($d['media'],0,3) as $a):?><?=annotation_ui_card($a,$viewer)?><?php endforeach?></div>
  </section><?php endif?>

  <section class="exploreDashboardSection">
    <div class="sectionHeadWeb compact"><div><span class="eyebrow">PUBLISHED RESEARCH</span><h2>Reports</h2></div><a href="/search.php?q=research">Browse Research</a></div>
    <div class="exploreReportGrid"><?php if(!$d['reports']):?><div class="exploreEmptyCard">No public Research reports yet.</div><?php endif?><?php foreach(array_slice($d['reports'],0,6) as $r):?><a href="/research-report.php?id=<?=h($r['public_id'])?>"><small>Version <?=h((string)$r['version_number'])?> · <?=h($r['display_name'])?></small><strong><?=h($r['title'])?></strong><?php if($r['summary']):?><p><?=h(public_discovery_meta_description($r['summary'],180))?></p><?php endif?></a><?php endforeach?></div>
  </section>
</main><?=annotation_ui_scripts($viewer)?></body></html>