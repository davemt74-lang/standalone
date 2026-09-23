<?php
declare(strict_types=1);

$GLOBALS['annotated_shell_disabled']=true;
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/public-discovery.php';
require_once __DIR__.'/app/profile-showcase.php';

$viewer=current_user($pdo);
$username=trim((string)($_GET['u']??''));
$type=($_GET['type']??'followers')==='following'?'following':'followers';
$p=$username!==''?public_discovery_profile($pdo,$username,$viewer):null;
if(!$p){http_response_code(404);exit('Profile not found.');}
if($viewer){header('Cache-Control: private, no-store');header('Vary: Cookie');}
$people=profile_showcase_people($pdo,(int)$p['id'],$type,$viewer,150);
$title=$type==='following'?'Following':'Followers';
$count=$type==='following'?(int)$p['following_count']:(int)$p['followers'];
?><!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?> · <?=h($p['display_name'])?> · Annotated</title>
<meta name="robots" content="noindex,follow">
<link rel="stylesheet" href="/assets/css/app.css?v=profile-200">
</head>
<body class="profileStandaloneBody">
<main class="profileStandalonePage profileConnectionsPage">
    <header class="profileConnectionsHeader">
        <a class="profileBackLink" href="<?=h(profile_path((string)$p['username']))?>">← <?=h($p['display_name'])?></a>
        <div><span class="profileSectionEyebrow">PEOPLE</span><h1><?=h($title)?></h1><p><?=h((string)$count)?> total · showing public profiles you can currently access.</p></div>
        <nav><a class="<?=$type==='followers'?'active':''?>" href="/profile-connections.php?u=<?=rawurlencode((string)$p['username'])?>&type=followers">Followers</a><a class="<?=$type==='following'?'active':''?>" href="/profile-connections.php?u=<?=rawurlencode((string)$p['username'])?>&type=following">Following</a></nav>
    </header>
    <section class="profilePeopleList">
        <?php if(!$people):?><div class="profileEmptyState"><div class="profileEmptyIcon" aria-hidden="true">○</div><h3>No visible profiles here</h3><p>Private, blocked, or unavailable accounts are not exposed in public connection lists.</p></div><?php endif?>
        <?php foreach($people as $person):?>
        <a class="profilePersonCard" href="<?=h(profile_path((string)$person['username']))?>">
            <?php if(!empty($person['profile_image_url'])):?><img src="<?=h((string)$person['profile_image_url'])?>" alt="">
            <?php else:?><span class="profilePersonFallback"><?=h(mb_strtoupper(mb_substr((string)$person['display_name'],0,1)))?></span><?php endif?>
            <span class="profilePersonCopy"><strong><?=h((string)$person['display_name'])?></strong><small>@<?=h((string)$person['username'])?></small><?php if(!empty($person['bio'])):?><em><?=h(public_discovery_meta_description((string)$person['bio'],140))?></em><?php endif?></span>
            <span class="profilePersonArrow" aria-hidden="true">→</span>
        </a>
        <?php endforeach?>
    </section>
    <footer class="profileStandaloneFooter"><a href="<?=$viewer?'/home.php':'/'?>">Annotated</a><a href="<?=h(profile_path((string)$p['username']))?>">Profile</a></footer>
</main>
</body>
</html>
