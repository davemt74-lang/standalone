<?php
declare(strict_types=1);

$GLOBALS['annotated_shell_disabled']=true;
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/public-discovery.php';
require_once __DIR__.'/app/annotation-ui.php';
require_once __DIR__.'/app/profile-showcase.php';

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
if(!$p){http_response_code(404);exit('Profile not found.');}

$owner=$viewer&&(int)$viewer['id']===(int)$p['id'];
$viewAsPublic=$owner&&($_GET['view']??'')==='public';
$ownerControls=$owner&&!$viewAsPublic;
$prefs=profile_showcase_preferences($pdo,(int)$p['id']);

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!$owner){http_response_code(403);exit('Profile owner access required.');}
    require_csrf();
    try{
        $action=(string)($_POST['action']??'');
        if($action==='profile_pin'){
            profile_showcase_pin_set($pdo,$viewer,(string)($_POST['object_type']??''),(string)($_POST['object_id']??''),true);
        }elseif($action==='profile_unpin'){
            profile_showcase_pin_set($pdo,$viewer,(string)($_POST['object_type']??''),(string)($_POST['object_id']??''),false);
        }else throw new RuntimeException('Unknown profile action.');
        $returnTab=preg_replace('/[^a-z_]/','',(string)($_POST['return_tab']??'activity'))?:'activity';
        header('Location: '.profile_path((string)$p['username']).'?tab='.rawurlencode($returnTab).'&profile_updated=1');exit;
    }catch(Throwable $e){$profileError=$e->getMessage();}
}

$collections=!empty($prefs['profile_show_collections'])||$ownerControls?profile_showcase_public_collections($pdo,(int)$p['id'],30):[];
$showResearch=!empty($prefs['profile_show_research'])||$ownerControls;
$showCollections=!empty($prefs['profile_show_collections'])||$ownerControls;
$showAbout=!empty($prefs['profile_show_about'])||$ownerControls;
$activity=profile_showcase_activity($p,$showCollections?$collections:[]);
$pins=profile_showcase_pins($pdo,$p,$viewer);
$pinMap=[];foreach($pins as $pin)$pinMap[$pin['type'].'|'.$pin['public_id']]=true;

$tabs=['activity'=>'Activity','annotations'=>'Annotations'];
if($showResearch)$tabs['research']='Research';
if($showCollections)$tabs['collections']='Collections';
if($showAbout)$tabs['about']='About';
$tab=(string)($_GET['tab']??'activity');if(!isset($tabs[$tab]))$tab='activity';

$isPublic=$p['profile_visibility']==='public';
$desc=public_discovery_meta_description((string)($p['bio']?:$p['display_name'].' on Annotated'));
$canonical=public_discovery_absolute_url($config,profile_path((string)$p['username']));
$initial=mb_strtoupper(mb_substr((string)$p['display_name'],0,1));
$annotationCount=(int)$p['annotation_count'];
$sourceCount=(int)$p['source_count'];
$followerCount=(int)$p['followers'];
$followingCount=(int)$p['following_count'];
$reportCount=$showResearch?count((array)$p['reports']):0;
$collectionCount=$showCollections?count($collections):0;

$renderPinControl=function(string $type,string $publicId,string $returnTab)use($ownerControls,$pinMap): string{
    if(!$ownerControls)return '';
    $key=$type.'|'.$publicId;$isPinned=!empty($pinMap[$key]);
    return '<form method="post" class="profilePinForm"><input type="hidden" name="csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="'.($isPinned?'profile_unpin':'profile_pin').'"><input type="hidden" name="object_type" value="'.h($type).'"><input type="hidden" name="object_id" value="'.h($publicId).'"><input type="hidden" name="return_tab" value="'.h($returnTab).'"><button class="profilePinButton" type="submit">'.($isPinned?'Unpin':'Pin to profile').'</button></form>';
};
$renderResearchCard=function(array $r,string $returnTab='research')use($renderPinControl): string{
    $url='/research-report.php?id='.rawurlencode((string)$r['public_id']);
    return '<article class="profileShowcaseCard profileResearchCard"><div class="profileShowcaseCardTop"><span class="profileObjectType">PUBLISHED RESEARCH</span>'. $renderPinControl('research_report',(string)$r['public_id'],$returnTab).'</div><h3><a href="'.h($url).'">'.h((string)$r['title']).'</a></h3>'.(!empty($r['summary'])?'<p>'.nl2br(h((string)$r['summary'])).'</p>':'').'<footer><span>Version '.h((string)$r['version_number']).'</span><time>'.h((string)$r['published_at']).'</time></footer></article>';
};
$renderCollectionCard=function(array $c,string $returnTab='collections')use($renderPinControl): string{
    $url='/collection.php?id='.rawurlencode((string)$c['public_id']);
    return '<article class="profileShowcaseCard profileCollectionCard"><div class="profileShowcaseCardTop"><span class="profileObjectType">PUBLIC COLLECTION</span>'.$renderPinControl('collection',(string)$c['public_id'],$returnTab).'</div><div class="profileCollectionGlyph" aria-hidden="true"></div><h3><a href="'.h($url).'">'.h((string)$c['title']).'</a></h3>'.(!empty($c['description'])?'<p>'.nl2br(h((string)$c['description'])).'</p>':'').'<footer><span>'.h((string)$c['item_count']).' public item'.((int)$c['item_count']===1?'':'s').'</span><time>'.h((string)($c['recent_item_at']?:$c['updated_at'])).'</time></footer></article>';
};
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
<link rel="stylesheet" href="/assets/css/app.css?v=profile-200">
</head>
<body class="profileStandaloneBody">
<main class="profileStandalonePage">
    <?php if($viewAsPublic):?><div class="profileViewAsBanner"><span>You are viewing your profile as the public sees it.</span><a href="<?=h(profile_path((string)$p['username']))?>">Exit public view</a></div><?php endif?>
    <?php if(!empty($profileError)):?><div class="error profilePageNotice"><?=h($profileError)?></div><?php endif?>
    <?php if(isset($_GET['profile_updated'])):?><div class="success profilePageNotice">Profile showcase updated.</div><?php endif?>

    <section class="profileHero" aria-labelledby="profileName">
        <div class="profileHeroBackdrop" aria-hidden="true"></div>
        <div class="profileHeroInner">
            <div class="profileIdentity">
                <div class="profileAvatarWrap">
                    <?php if($p['profile_image_url']):?><img class="profileAvatar" src="<?=h($p['profile_image_url'])?>" alt="<?=h($p['display_name'])?>">
                    <?php else:?><div class="profileAvatar profileAvatarFallback" aria-hidden="true"><?=h($initial)?></div><?php endif?>
                </div>
                <div class="profileIdentityCopy">
                    <div class="profileHandle">@<?=h($p['username'])?></div>
                    <h1 id="profileName"><?=h($p['display_name'])?></h1>
                    <?php if($p['bio']):?><p class="profileBio"><?=nl2br(h($p['bio']))?></p><?php endif?>
                    <?php if($p['website_url']):?><a class="profileWebsite" href="<?=h($p['website_url'])?>" rel="nofollow noopener" target="_blank"><span aria-hidden="true">↗</span><?=h($p['website_url'])?></a><?php endif?>
                </div>
            </div>

            <div class="profileActions">
                <?php if($viewer&&!$owner):?><button id="follow" class="profilePrimaryAction" type="button" aria-pressed="<?=$p['following']?'true':'false'?>"><?=$p['following']?'Following':'Follow'?></button>
                <?php elseif($ownerControls):?><a class="profilePrimaryAction" href="/settings.php#profile-showcase">Edit profile</a><a class="profileSecondaryAction" href="<?=h(profile_path((string)$p['username']))?>?view=public">View as public</a>
                <?php elseif(!$viewer):?><a class="profilePrimaryAction" href="/login.php">Log in to follow</a><?php endif?>
                <button class="profileIconAction" type="button" id="copyProfile" aria-label="Copy profile link" title="Copy profile link"><span aria-hidden="true">↗</span></button>
                <?php if(!$owner&&$isPublic):?><details class="profileMoreMenu"><summary class="profileIconAction" aria-label="More profile actions" title="More">•••</summary><div class="profileMoreMenuPanel"><a href="/report.php?type=user&id=<?=h($p['public_id'])?>">Report profile</a></div></details><?php endif?>
            </div>
        </div>

        <div class="profileStatsBar" aria-label="Profile statistics">
            <a href="<?=h(profile_path((string)$p['username']))?>?tab=annotations"><strong><?=h((string)$annotationCount)?></strong><span>Annotations</span></a>
            <div><strong><?=h((string)$sourceCount)?></strong><span>Sources</span></div>
            <a href="/profile-connections.php?u=<?=rawurlencode((string)$p['username'])?>&type=followers"><strong><?=h((string)$followerCount)?></strong><span>Followers</span></a>
            <a href="/profile-connections.php?u=<?=rawurlencode((string)$p['username'])?>&type=following"><strong><?=h((string)$followingCount)?></strong><span>Following</span></a>
        </div>
    </section>

    <nav class="profileTabs" aria-label="Profile sections">
        <?php foreach($tabs as $key=>$label):?><a class="<?=$tab===$key?'active':''?>" href="<?=h(profile_path((string)$p['username']))?>?tab=<?=h($key)?>" <?=$tab===$key?'aria-current="page"':''?>><?=h($label)?><?php if($key==='annotations'):?><span><?=h((string)$annotationCount)?></span><?php elseif($key==='research'):?><span><?=h((string)$reportCount)?></span><?php elseif($key==='collections'):?><span><?=h((string)$collectionCount)?></span><?php endif?></a><?php endforeach?>
    </nav>

    <?php if($tab==='activity'):?>
    <section class="profileContent">
        <?php if($pins):?><section class="profilePinnedSection"><header class="profileContentHeader"><div><span class="profileSectionEyebrow">FEATURED</span><h2>Pinned</h2></div><span class="profileActivityCount"><?=count($pins)?> of 3</span></header><div class="profilePinnedGrid">
            <?php foreach($pins as $pin):?>
                <?php if($pin['type']==='annotation'):?><div class="profilePinnedAnnotation"><?=$renderPinControl('annotation',(string)$pin['public_id'],'activity')?><?=annotation_ui_card($pin['item'],$viewer,['show_author'=>false])?></div>
                <?php elseif($pin['type']==='research_report'):?><?=$renderResearchCard($pin['item'],'activity')?>
                <?php elseif($pin['type']==='collection'):?><?=$renderCollectionCard($pin['item'],'activity')?><?php endif?>
            <?php endforeach?>
        </div></section><?php endif?>
        <header class="profileContentHeader"><div><span class="profileSectionEyebrow">RECENT</span><h2>Activity</h2></div><span class="profileActivityCount"><?=h((string)count($activity))?> shown</span></header>
        <div class="profileActivityStream">
            <?php if(!$activity):?><div class="profileEmptyState"><div class="profileEmptyIcon" aria-hidden="true">✦</div><h3>No public activity yet</h3><p><?= $ownerControls?'Publish an annotation, Research report, or public collection and it will appear here.':'This profile has not shared public activity yet.' ?></p></div><?php endif?>
            <?php foreach($activity as $row):?>
                <?php if($row['type']==='annotation'):?><div class="profileActivityObject"><?=$renderPinControl('annotation',(string)$row['item']['public_id'],'activity')?><?=annotation_ui_card($row['item'],$viewer,['show_author'=>false])?></div>
                <?php elseif($row['type']==='research_report'):?><?=$renderResearchCard($row['item'],'activity')?>
                <?php elseif($row['type']==='collection'):?><?=$renderCollectionCard($row['item'],'activity')?><?php endif?>
            <?php endforeach?>
        </div>
    </section>
    <?php elseif($tab==='annotations'):?>
    <section class="profileContent"><header class="profileContentHeader"><div><span class="profileSectionEyebrow">ANNOTATIONS</span><h2>Public annotations</h2></div><span class="profileActivityCount"><?=h((string)count($p['annotations']))?> shown</span></header><div class="profileFeed">
        <?php if(!$p['annotations']):?><div class="profileEmptyState"><div class="profileEmptyIcon" aria-hidden="true">✎</div><h3>No public annotations yet</h3><p><?= $ownerControls?'Annotations you make public will appear here.':'This profile has not shared any public annotations yet.' ?></p></div><?php endif?>
        <?php foreach($p['annotations'] as $a):?><div class="profileActivityObject"><?=$renderPinControl('annotation',(string)$a['public_id'],'annotations')?><?=annotation_ui_card($a,$viewer,['show_author'=>false])?></div><?php endforeach?>
    </div></section>
    <?php elseif($tab==='research'&&$showResearch):?>
    <section class="profileContent profileWideContent"><header class="profileContentHeader"><div><span class="profileSectionEyebrow">RESEARCH</span><h2>Published Research</h2></div><span class="profileActivityCount"><?=h((string)$reportCount)?> public</span></header><div class="profileShowcaseGrid">
        <?php if(!$p['reports']):?><div class="profileEmptyState"><div class="profileEmptyIcon" aria-hidden="true">⌁</div><h3>No published Research yet</h3><p><?= $ownerControls?'Public immutable Research Reports you publish will appear here.':'This profile has not published public Research yet.' ?></p></div><?php endif?>
        <?php foreach($p['reports'] as $r):?><?=$renderResearchCard($r,'research')?><?php endforeach?>
    </div></section>
    <?php elseif($tab==='collections'&&$showCollections):?>
    <section class="profileContent profileWideContent"><header class="profileContentHeader"><div><span class="profileSectionEyebrow">COLLECTIONS</span><h2>Public collections</h2></div><span class="profileActivityCount"><?=h((string)$collectionCount)?> public</span></header><div class="profileShowcaseGrid">
        <?php if(!$collections):?><div class="profileEmptyState"><div class="profileEmptyIcon" aria-hidden="true">▱</div><h3>No public collections yet</h3><p><?= $ownerControls?'Collections marked Public will appear here.':'This profile has not shared public collections yet.' ?></p></div><?php endif?>
        <?php foreach($collections as $c):?><?=$renderCollectionCard($c,'collections')?><?php endforeach?>
    </div></section>
    <?php elseif($tab==='about'&&$showAbout):?>
    <section class="profileContent"><header class="profileContentHeader"><div><span class="profileSectionEyebrow">ABOUT</span><h2><?=h($p['display_name'])?></h2></div></header><div class="profileAboutCard">
        <?php if($p['bio']):?><div><span>Bio</span><p><?=nl2br(h($p['bio']))?></p></div><?php endif?>
        <?php if($p['website_url']):?><div><span>Website</span><p><a href="<?=h($p['website_url'])?>" rel="nofollow noopener" target="_blank"><?=h($p['website_url'])?></a></p></div><?php endif?>
        <div><span>Member since</span><p><?=h(date('F Y',strtotime((string)$p['created_at'])))?></p></div>
        <div><span>Public work</span><p><?=h((string)$annotationCount)?> annotations<?= $showResearch?' · '.h((string)$reportCount).' Research reports':'' ?><?= $showCollections?' · '.h((string)$collectionCount).' collections':'' ?></p></div>
    </div></section>
    <?php endif?>

    <footer class="profileStandaloneFooter"><a href="<?=$viewer?'/home.php':'/'?>">Annotated</a><?php if($viewer):?><a href="/home.php">Home</a><?php else:?><a href="/explore.php">Explore</a><a href="/login.php">Log in</a><?php endif?></footer>
</main>

<script>
document.querySelector('#copyProfile')?.addEventListener('click',async e=>{try{await navigator.clipboard.writeText(<?=json_encode($canonical)?>);const original=e.currentTarget.innerHTML;e.currentTarget.textContent='✓';e.currentTarget.setAttribute('aria-label','Profile link copied');setTimeout(()=>{e.currentTarget.innerHTML=original;e.currentTarget.setAttribute('aria-label','Copy profile link');},1400);}catch(_){}});
</script>
<?php if($viewer&&!$owner):?>
<script>
const csrf=<?=json_encode(csrf_token())?>;
document.querySelector('#follow')?.addEventListener('click',async e=>{e.currentTarget.disabled=true;try{const r=await fetch('/api/extension.php?action=follow',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({user_id:<?=json_encode($p['public_id'])?>})});const j=await r.json();if(j.ok){const following=!!j.data.following;e.currentTarget.textContent=following?'Following':'Follow';e.currentTarget.setAttribute('aria-pressed',following?'true':'false');}}finally{e.currentTarget.disabled=false;}});
</script>
<?php endif?>
<?=annotation_ui_scripts($viewer)?>
</body>
</html>
