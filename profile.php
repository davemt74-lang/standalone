<?php
declare(strict_types=1);

$GLOBALS['annotated_shell_disabled']=true;
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/public-discovery.php';
require_once __DIR__.'/app/annotation-ui.php';

$viewer=current_user($pdo);
$username=trim((string)($_GET['u']??''));
$requestPath=(string)(parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)?:'');

if($requestPath==='/profile.php'&&$username!==''){
    header('Location: '.profile_path($username),true,301);
    exit;
}
if($viewer){
    header('Cache-Control: private, no-store');
    header('Vary: Cookie');
}

$p=$username!==''?public_discovery_profile($pdo,$username,$viewer):null;
if(!$p){
    http_response_code(404);
    exit('Profile not found.');
}

$owner=$viewer&&(int)$viewer['id']===(int)$p['id'];
$isPublic=$p['profile_visibility']==='public';
$desc=public_discovery_meta_description((string)($p['bio']?:$p['display_name'].' on Annotated'));
$canonical=public_discovery_absolute_url($config,profile_path((string)$p['username']));
$initial=mb_strtoupper(mb_substr((string)$p['display_name'],0,1));
$annotationCount=(int)$p['annotation_count'];
$sourceCount=(int)$p['source_count'];
$followerCount=(int)$p['followers'];
$followingCount=(int)$p['following_count'];
?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($p['display_name'])?> · Annotated</title>
<meta name="description" content="<?=h($desc)?>">
<?php if(!$isPublic):?><meta name="robots" content="noindex,nofollow"><?php endif?>
<link rel="canonical" href="<?=h($canonical)?>">
<?php if($isPublic):?>
<meta property="og:type" content="profile">
<meta property="og:title" content="<?=h($p['display_name'])?> · Annotated">
<meta property="og:description" content="<?=h($desc)?>">
<meta property="og:url" content="<?=h($canonical)?>">
<?php endif?>
<link rel="stylesheet" href="/assets/css/app.css?v=profile-100">
</head>
<body class="profileStandaloneBody">
<main class="profileStandalonePage">
    <section class="profileHero" aria-labelledby="profileName">
        <div class="profileHeroBackdrop" aria-hidden="true"></div>
        <div class="profileHeroInner">
            <div class="profileIdentity">
                <div class="profileAvatarWrap">
                    <?php if($p['profile_image_url']):?>
                        <img class="profileAvatar" src="<?=h($p['profile_image_url'])?>" alt="<?=h($p['display_name'])?>">
                    <?php else:?>
                        <div class="profileAvatar profileAvatarFallback" aria-hidden="true"><?=h($initial)?></div>
                    <?php endif?>
                </div>
                <div class="profileIdentityCopy">
                    <div class="profileHandle">@<?=h($p['username'])?></div>
                    <h1 id="profileName"><?=h($p['display_name'])?></h1>
                    <?php if($p['bio']):?><p class="profileBio"><?=nl2br(h($p['bio']))?></p><?php endif?>
                    <?php if($p['website_url']):?>
                        <a class="profileWebsite" href="<?=h($p['website_url'])?>" rel="nofollow noopener" target="_blank">
                            <span aria-hidden="true">↗</span><?=h($p['website_url'])?>
                        </a>
                    <?php endif?>
                </div>
            </div>

            <div class="profileActions">
                <?php if($viewer&&!$owner):?>
                    <button id="follow" class="profilePrimaryAction" type="button" aria-pressed="<?=$p['following']?'true':'false'?>"><?=$p['following']?'Following':'Follow'?></button>
                <?php elseif($owner):?>
                    <a class="profilePrimaryAction" href="/settings.php">Edit profile</a>
                <?php elseif(!$viewer):?>
                    <a class="profilePrimaryAction" href="/login.php">Log in to follow</a>
                <?php endif?>
                <button class="profileIconAction" type="button" id="copyProfile" aria-label="Copy profile link" title="Copy profile link">
                    <span aria-hidden="true">↗</span>
                </button>
                <?php if(!$owner&&$isPublic):?>
                    <details class="profileMoreMenu">
                        <summary class="profileIconAction" aria-label="More profile actions" title="More">•••</summary>
                        <div class="profileMoreMenuPanel">
                            <a href="/report.php?type=user&id=<?=h($p['public_id'])?>">Report profile</a>
                        </div>
                    </details>
                <?php endif?>
            </div>
        </div>

        <div class="profileStatsBar" aria-label="Profile statistics">
            <div><strong><?=h((string)$annotationCount)?></strong><span>Annotations</span></div>
            <div><strong><?=h((string)$sourceCount)?></strong><span>Sources</span></div>
            <div><strong><?=h((string)$followerCount)?></strong><span>Followers</span></div>
            <div><strong><?=h((string)$followingCount)?></strong><span>Following</span></div>
        </div>
    </section>

    <section class="profileContent">
        <header class="profileContentHeader">
            <div>
                <span class="profileSectionEyebrow">ACTIVITY</span>
                <h2>Annotations</h2>
            </div>
            <span class="profileActivityCount"><?=h((string)count($p['annotations']))?> shown</span>
        </header>

        <div class="profileFeed">
            <?php if(!$p['annotations']):?>
                <div class="profileEmptyState">
                    <div class="profileEmptyIcon" aria-hidden="true">✎</div>
                    <h3>No public annotations yet</h3>
                    <p><?= $owner ? 'Annotations you make public will appear here.' : 'This profile has not shared any public annotations yet.' ?></p>
                </div>
            <?php endif?>
            <?php foreach($p['annotations'] as $a):?>
                <?=annotation_ui_card($a,$viewer,['show_author'=>false])?>
            <?php endforeach?>
        </div>
    </section>

    <footer class="profileStandaloneFooter">
        <a href="<?=$viewer?'/home.php':'/'?>">Annotated</a>
        <?php if($viewer):?><a href="/home.php">Home</a><?php else:?><a href="/explore.php">Explore</a><a href="/login.php">Log in</a><?php endif?>
    </footer>
</main>

<script>
document.querySelector('#copyProfile')?.addEventListener('click',async e=>{
    try{
        await navigator.clipboard.writeText(location.href);
        const original=e.currentTarget.innerHTML;
        e.currentTarget.textContent='✓';
        e.currentTarget.setAttribute('aria-label','Profile link copied');
        setTimeout(()=>{e.currentTarget.innerHTML=original;e.currentTarget.setAttribute('aria-label','Copy profile link');},1400);
    }catch(_){}
});
</script>
<?php if($viewer&&!$owner):?>
<script>
const csrf=<?=json_encode(csrf_token())?>;
document.querySelector('#follow')?.addEventListener('click',async e=>{
    e.currentTarget.disabled=true;
    try{
        const r=await fetch('/api/extension.php?action=follow',{
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
            body:JSON.stringify({user_id:<?=json_encode($p['public_id'])?>})
        });
        const j=await r.json();
        if(j.ok){
            const following=!!j.data.following;
            e.currentTarget.textContent=following?'Following':'Follow';
            e.currentTarget.setAttribute('aria-pressed',following?'true':'false');
        }
    }finally{
        e.currentTarget.disabled=false;
    }
});
</script>
<?php endif?>
<?=annotation_ui_scripts($viewer)?>
</body>
</html>
