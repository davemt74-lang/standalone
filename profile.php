<?php
declare(strict_types=1);

$GLOBALS['annotated_shell_mode']='header_only';
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
    if(!$viewer){http_response_code(403);exit('Sign in required.');}
    require_csrf();
    try{
        $action=(string)($_POST['action']??'');$returnTab=preg_replace('/[^a-z_]/','',(string)($_POST['return_tab']??'activity'))?:'activity';
        if($action==='profile_pin'||$action==='profile_unpin'){
            if(!$owner)throw new RuntimeException('Profile owner access required.');
            profile_showcase_pin_set($pdo,$viewer,(string)($_POST['object_type']??''),(string)($_POST['object_id']??''),$action==='profile_pin');
            header('Location: '.profile_path((string)$p['username']).'?tab='.rawurlencode($returnTab).'&profile_updated=1');exit;
        }
        if($action==='profile_update'){
            if(!$ownerControls)throw new RuntimeException('Profile owner access required.');
            $display=trim((string)($_POST['display_name']??''));$bio=trim((string)($_POST['bio']??''));$website=trim((string)($_POST['website_url']??''));
            if($display===''||mb_strlen($display)>100)throw new RuntimeException('Display name is required and must be 100 characters or fewer.');
            if(mb_strlen($bio)>2000)throw new RuntimeException('Bio must be 2,000 characters or fewer.');
            if($website!==''&&!filter_var($website,FILTER_VALIDATE_URL))throw new RuntimeException('Website must be a valid URL.');
            $image=trim((string)($p['profile_image_url']??''));$cover=trim((string)($p['profile_cover_image_url']??''));$oldImage=$image;$oldCover=$cover;$uploadedImage='';$uploadedCover='';
            if(isset($_POST['remove_profile_photo']))$image='';
            if(isset($_POST['remove_profile_cover']))$cover='';
            if(isset($_FILES['profile_photo'])&&(int)($_FILES['profile_photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$uploadedImage=profile_image_upload($_FILES['profile_photo'],(int)$viewer['id']);$image=$uploadedImage;}
            if(isset($_FILES['profile_cover'])&&(int)($_FILES['profile_cover']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$uploadedCover=profile_cover_upload($_FILES['profile_cover'],(int)$viewer['id']);$cover=$uploadedCover;}
            try{$pdo->prepare('UPDATE users SET display_name=?,bio=?,website_url=?,profile_image_url=?,profile_cover_image_url=? WHERE id=?')->execute([$display,$bio?:null,$website?:null,$image?:null,$cover?:null,(int)$viewer['id']]);}
            catch(Throwable $e){if($uploadedImage!=='')profile_image_delete_local($uploadedImage);if($uploadedCover!=='')profile_image_delete_local($uploadedCover);throw $e;}
            if($oldImage!==''&&$oldImage!==$image)profile_image_delete_local($oldImage);if($oldCover!==''&&$oldCover!==$cover)profile_image_delete_local($oldCover);
            header('Location: '.profile_path((string)$p['username']).'?profile_updated=1');exit;
        }
        if(in_array($action,['network_add_research','network_research_this'],true)){
            $agentPublic=trim((string)($_POST['agent_id']??''));$result=profile_network_add_to_agent($pdo,$config,$viewer,$agentPublic,(string)($_POST['object_type']??''),(string)($_POST['object_id']??''));
            if($action==='network_research_this'){$agent=research_agent_access($pdo,$viewer,$agentPublic);if($agent&&!empty($agent['conversation_public_id'])){header('Location: /home.php?agent='.rawurlencode((string)$agent['conversation_public_id']));exit;}}
            header('Location: '.profile_path((string)$p['username']).'?tab='.rawurlencode($returnTab).'&research_added=1');exit;
        }
        throw new RuntimeException('Unknown profile action.');
    }catch(Throwable $e){$profileError=$e->getMessage();}
}

$researchAgents=[];$profileResearchAgents=[];$profileStories=[];$collections=[];$activity=[];$pins=[];
try{$researchAgents=$viewer&&!$viewAsPublic?research_agent_list($pdo,$viewer,50):[];}catch(Throwable $e){}
try{$profileResearchAgents=function_exists('research_agent_profile_list')?research_agent_profile_list($pdo,(int)$p['id'],null,30):[];}catch(Throwable $e){}
try{$profileStories=function_exists('profile_showcase_public_agent_stories')?profile_showcase_public_agent_stories($pdo,(int)$p['id'],50):[];}catch(Throwable $e){}
try{$collections=profile_showcase_public_collections($pdo,(int)$p['id'],30);}catch(Throwable $e){}
$showResearch=true;$showCollections=true;$showAbout=true;
try{$activity=profile_showcase_activity($p,$collections,$profileStories);}catch(Throwable $e){$activity=[];}
try{$pins=profile_showcase_pins($pdo,$p,$viewer);}catch(Throwable $e){$pins=[];}
$pinMap=[];foreach($pins as $pin)$pinMap[$pin['type'].'|'.$pin['public_id']]=true;

$tabs=['activity'=>'Activity','stories'=>'Stories','agents'=>'Research Agents','research'=>'Research','annotations'=>'Annotations','collections'=>'Collections','about'=>'About'];
$tab=(string)($_GET['tab']??'activity');if(!isset($tabs[$tab]))$tab='activity';

$isPublic=$p['profile_visibility']==='public';
$desc=public_discovery_meta_description((string)($p['bio']?:$p['display_name'].' on Annotated'));
$canonical=public_discovery_absolute_url($config,profile_path((string)$p['username']));
$initial=mb_strtoupper(mb_substr((string)$p['display_name'],0,1));$coverImage=trim((string)($p['profile_cover_image_url']??''));
$annotationCount=(int)$p['annotation_count'];
$sourceCount=(int)$p['source_count'];
$followerCount=(int)$p['followers'];
$followingCount=(int)$p['following_count'];
$reportCount=count((array)$p['reports']);$agentCount=count($profileResearchAgents);$storyCount=count($profileStories);
$collectionCount=count($collections);
$storyGroups=[];$agentStoryMeta=[];
foreach($profileStories as $story){
    $aid=(string)($story['agent_public_id']??'');if($aid==='')continue;
    if(!isset($storyGroups[$aid]))$storyGroups[$aid]=['agent_public_id'=>$aid,'agent_name'=>(string)($story['agent_name']??'Research Agent'),'profile_image_url'=>(string)($story['agent_profile_image_url']??''),'stories'=>[],'latest_at'=>(string)($story['published_at']??'')];
    $storyGroups[$aid]['stories'][]=$story;
    if(!isset($agentStoryMeta[$aid]))$agentStoryMeta[$aid]=['story_count'=>0,'latest_at'=>(string)($story['published_at']??''),'latest_story_public_id'=>(string)($story['public_id']??'')];
    $agentStoryMeta[$aid]['story_count']++;
}
$storyGroups=array_values($storyGroups);
foreach($profileResearchAgents as &$agent){$meta=$agentStoryMeta[(string)$agent['public_id']]??['story_count'=>0,'latest_at'=>(string)($agent['updated_at']??''),'latest_story_public_id'=>null];$agent=array_merge($agent,$meta);}unset($agent);

$renderPinControl=function(string $type,string $publicId,string $returnTab)use($ownerControls,$pinMap): string{
    if(!$ownerControls)return '';
    $key=$type.'|'.$publicId;$isPinned=!empty($pinMap[$key]);
    return '<form method="post" class="profilePinForm"><input type="hidden" name="csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="'.($isPinned?'profile_unpin':'profile_pin').'"><input type="hidden" name="object_type" value="'.h($type).'"><input type="hidden" name="object_id" value="'.h($publicId).'"><input type="hidden" name="return_tab" value="'.h($returnTab).'"><button class="profilePinButton" type="submit">'.($isPinned?'Unpin':'Pin to profile').'</button></form>';
};
$renderNetworkAction=function(string $type,string $publicId,string $returnTab)use($viewer,$researchAgents): string{
    if(!$viewer||!$researchAgents)return '';
    $options='';foreach($researchAgents as $agent)$options.='<option value="'.h((string)$agent['public_id']).'">'.h((string)$agent['name']).'</option>';
    return '<details class="profileResearchHandoff"><summary>Research this</summary><form method="post"><input type="hidden" name="csrf" value="'.h(csrf_token()).'"><input type="hidden" name="object_type" value="'.h($type).'"><input type="hidden" name="object_id" value="'.h($publicId).'"><input type="hidden" name="return_tab" value="'.h($returnTab).'"><select name="agent_id" aria-label="Research Agent">'.$options.'</select><button name="action" value="network_add_research">Add</button><button name="action" value="network_research_this">Add & open Agent</button></form></details>';
};
$renderResearchCard=function(array $r,string $returnTab='research')use($renderPinControl,$renderNetworkAction): string{
    $url='/research-report.php?id='.rawurlencode((string)$r['public_id']);
    return '<article class="profileShowcaseCard profileResearchCard"><div class="profileShowcaseCardTop"><span class="profileObjectType">PUBLISHED RESEARCH</span>'. $renderPinControl('research_report',(string)$r['public_id'],$returnTab).'</div><h3><a href="'.h($url).'">'.h((string)$r['title']).'</a></h3>'.(!empty($r['summary'])?'<p>'.nl2br(h((string)$r['summary'])).'</p>':'').$renderNetworkAction('research_report',(string)$r['public_id'],$returnTab).'<footer><span>Version '.h((string)$r['version_number']).'</span><time>'.h((string)$r['published_at']).'</time></footer></article>';
};
$renderCollectionCard=function(array $c,string $returnTab='collections')use($renderPinControl): string{
    $url='/collection.php?id='.rawurlencode((string)$c['public_id']);
    return '<article class="profileShowcaseCard profileCollectionCard"><div class="profileShowcaseCardTop"><span class="profileObjectType">PUBLIC COLLECTION</span>'.$renderPinControl('collection',(string)$c['public_id'],$returnTab).'</div><div class="profileCollectionGlyph" aria-hidden="true"></div><h3><a href="'.h($url).'">'.h((string)$c['title']).'</a></h3>'.(!empty($c['description'])?'<p>'.nl2br(h((string)$c['description'])).'</p>':'').'<footer><span>'.h((string)$c['item_count']).' public item'.((int)$c['item_count']===1?'':'s').'</span><time>'.h((string)($c['recent_item_at']?:$c['updated_at'])).'</time></footer></article>';
};
$renderAgentCard=function(array $agent,string $returnTab='agents')use($renderPinControl): string{
    $id=(string)$agent['public_id'];$name=(string)($agent['name']??'Research Agent');$avatar=trim((string)($agent['profile_image_url']??''));$initial=mb_strtoupper(mb_substr($name,0,1));
    $storyCount=(int)($agent['story_count']??0);$latest=trim((string)($agent['latest_at']??$agent['updated_at']??''));
    $identity=$avatar!==''?'<img src="'.h($avatar).'" alt="">':'<span>'.h($initial?:'R').'</span>';
    return '<article class="profileShowcaseCard profileAgentCard"><div class="profileShowcaseCardTop"><span class="profileObjectType">PUBLIC RESEARCH AGENT</span>'.$renderPinControl('research_agent',$id,$returnTab).'</div><a class="profileAgentCardLink" href="/research-agent-public.php?agent='.rawurlencode($id).'"><div class="profileDiscoveryIdentity">'.$identity.'<div><strong>'.h($name).'</strong><small>'.$storyCount.' active '.($storyCount===1?'Story':'Stories').'</small></div></div>'.(!empty($agent['description'])?'<p>'.h(mb_substr((string)$agent['description'],0,260)).'</p>':'').'<div class="profileAgentMeta"><span>Public</span>'.($latest!==''?'<span>Updated '.h(date('M j',strtotime($latest)?:time())).'</span>':'').'</div><span class="profileAgentCardAction">View Agent <span aria-hidden="true">→</span></span></a></article>';
};
$renderStoryCard=function(array $story,string $returnTab='stories')use($p): string{
    $public=(string)($story['public_id']??'');$agent=(string)($story['agent_public_id']??'');$name=(string)($story['agent_name']??'Research Agent');$avatar=trim((string)($story['agent_profile_image_url']??''));$initial=mb_strtoupper(mb_substr($name,0,1));
    $title=trim((string)($story['title']??''))?:'Research update';$body=(string)($story['body']??'');$why=trim((string)($story['why_it_matters']??''));
    $identity=$avatar!==''?'<img src="'.h($avatar).'" alt="">':h($initial?:'R');
    return '<article class="profileStoryCard" id="story-'.h($public).'"><header><span class="profileStoryAgentAvatar">'.$identity.'</span><div><strong>'.h($name).'</strong><small>'.h(date('M j · g:i A',strtotime((string)($story['published_at']??''))?:time())).'</small></div></header><span class="profileObjectType">'.h(strtoupper(str_replace('_',' ',(string)($story['story_type']??'story')))).'</span><h3>'.h($title).'</h3><p>'.nl2br(h($body)).'</p>'.($why!==''?'<aside><strong>Why this matters</strong><p>'.h($why).'</p></aside>':'').'<footer><a href="/research-agent-public.php?agent='.rawurlencode($agent).'">View Research Agent →</a><a href="'.h(profile_path((string)($p['username']??''))).'?tab=stories#story-'.rawurlencode($public).'">Link to Story</a></footer></article>';
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
<link rel="stylesheet" href="/assets/css/app.css?v=profile-300">
<link rel="stylesheet" href="/assets/css/profile-v2.css?v=79.3">
</head>
<body class="profileStandaloneBody profileV2Body">
<main class="profileStandalonePage profileV2Page">
    <?php if(!$viewer):?><header class="profileGuestHeader"><a class="profileGuestBrand" href="/"><span>A</span><strong>Annotated</strong></a><nav><a href="/explore.php">Explore</a><a href="/login.php">Sign in</a><a class="button" href="/register.php">Get Annotated</a></nav></header><?php endif?>
    <?php if($viewAsPublic):?><div class="profileViewAsBanner"><span>You are viewing your profile as the public sees it.</span><a href="<?=h(profile_path((string)$p['username']))?>">Exit public view</a></div><?php endif?>
    <?php if(!empty($profileError)):?><div class="error profilePageNotice"><?=h($profileError)?></div><?php endif?>
    <?php if(isset($_GET['profile_updated'])):?><div class="success profilePageNotice">Profile showcase updated.</div><?php endif?><?php if(isset($_GET['research_added'])):?><div class="success profilePageNotice">Added to your Research Agent with the original public object preserved as provenance.</div><?php endif?>

    <section class="profileHero" aria-labelledby="profileName">
        <div class="profileHeroBackdrop<?=$coverImage!==''?' hasCover':''?>" aria-hidden="true"<?php if($coverImage!==''):?> style="background-image:url('<?=h($coverImage)?>')"<?php endif?>></div>
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
                <?php if($viewer&&!$owner&& !empty($p['friends'])):?><span class="profileRelationshipBadge">Friends</span><?php elseif($viewer&&!$owner&& !empty($p['follows_you'])):?><span class="profileRelationshipBadge">Follows you</span><?php endif?>
                <?php if($viewer&&!$owner):?><button id="follow" class="profilePrimaryAction" type="button" aria-pressed="<?=$p['following']?'true':'false'?>"><?=$p['following']?'Following':'Follow'?></button>
                <?php elseif($ownerControls):?><button class="profilePrimaryAction" type="button" data-profile-edit-open>Edit profile</button><a class="profileSecondaryAction" href="<?=h(profile_path((string)$p['username']))?>?view=public">View as public</a>
                <?php elseif(!$viewer):?><a class="profilePrimaryAction" href="/login.php">Log in to follow</a><?php endif?>
                <button class="profileIconAction" type="button" id="copyProfile" aria-label="Copy profile link" title="Copy profile link"><span aria-hidden="true">↗</span></button>
                <?php if(!$owner&&$isPublic):?><details class="profileMoreMenu"><summary class="profileIconAction" aria-label="More profile actions" title="More">•••</summary><div class="profileMoreMenuPanel"><a href="/report.php?type=user&id=<?=h($p['public_id'])?>">Report profile</a></div></details><?php endif?>
            </div>
        </div>

        <div class="profileStatsBar" aria-label="Profile statistics">
            <a href="<?=h(profile_path((string)$p['username']))?>?tab=research"><strong><?=h((string)$reportCount)?></strong><span>Research</span></a>
            <a href="<?=h(profile_path((string)$p['username']))?>?tab=annotations"><strong><?=h((string)$annotationCount)?></strong><span>Annotations</span></a>
            <a href="<?=h(profile_path((string)$p['username']))?>?tab=agents"><strong><?=h((string)$agentCount)?></strong><span>Agents</span></a>
            <a href="/profile-connections.php?u=<?=rawurlencode((string)$p['username'])?>&type=followers"><strong data-profile-follower-count><?=h((string)$followerCount)?></strong><span>Followers</span></a>
            <a href="/profile-connections.php?u=<?=rawurlencode((string)$p['username'])?>&type=following"><strong><?=h((string)$followingCount)?></strong><span>Following</span></a>
        </div>
    </section>

    <nav class="profileTabs" aria-label="Profile sections">
        <?php foreach($tabs as $key=>$label):?><a class="<?=$tab===$key?'active':''?>" href="<?=h(profile_path((string)$p['username']))?>?tab=<?=h($key)?>" <?=$tab===$key?'aria-current="page"':''?>><?=h($label)?><?php if($key==='stories'):?><span><?=h((string)$storyCount)?></span><?php elseif($key==='agents'):?><span><?=h((string)$agentCount)?></span><?php elseif($key==='annotations'):?><span><?=h((string)$annotationCount)?></span><?php elseif($key==='research'):?><span><?=h((string)$reportCount)?></span><?php elseif($key==='collections'):?><span><?=h((string)$collectionCount)?></span><?php endif?></a><?php endforeach?>
    </nav>

    <?php if($tab==='activity'):?>
    <section class="profileContent">
        <?php if($pins):?><section class="profilePinnedSection"><header class="profileContentHeader"><div><span class="profileSectionEyebrow">FEATURED</span><h2>Pinned</h2></div><span class="profileActivityCount"><?=count($pins)?> of 6</span></header><div class="profilePinnedGrid">
            <?php foreach($pins as $pin):?>
                <?php if($pin['type']==='annotation'):?><div class="profilePinnedAnnotation"><?=$renderPinControl('annotation',(string)$pin['public_id'],'activity')?><?=annotation_ui_card($pin['item'],$viewer,['show_author'=>false])?></div>
                <?php elseif($pin['type']==='research_report'):?><?=$renderResearchCard($pin['item'],'activity')?>
                <?php elseif($pin['type']==='collection'):?><?=$renderCollectionCard($pin['item'],'activity')?><?php elseif($pin['type']==='research_agent'):?><?=$renderAgentCard($pin['item'],'activity')?><?php endif?>
            <?php endforeach?>
        </div></section><?php endif?>
        <header class="profileContentHeader"><div><span class="profileSectionEyebrow">RECENT</span><h2>Activity</h2></div><span class="profileActivityCount"><?=h((string)count($activity))?> shown</span></header>
        <div class="profileActivityStream">
            <?php if(!$activity):?><div class="profileEmptyState"><div class="profileEmptyIcon" aria-hidden="true">✦</div><h3>No public activity yet</h3><p><?= $ownerControls?'Publish an annotation, Research report, or public collection and it will appear here.':'This profile has not shared public activity yet.' ?></p></div><?php endif?>
            <?php foreach($activity as $row):?>
                <?php if($row['type']==='annotation'):?><div class="profileActivityObject"><?=$renderPinControl('annotation',(string)$row['item']['public_id'],'activity')?><?=$renderNetworkAction('annotation',(string)$row['item']['public_id'],'activity')?><?=annotation_ui_card($row['item'],$viewer,['show_author'=>false])?></div>
                <?php elseif($row['type']==='research_report'):?><?=$renderResearchCard($row['item'],'activity')?>
                <?php elseif($row['type']==='collection'):?><?=$renderCollectionCard($row['item'],'activity')?><?php elseif($row['type']==='research_agent_story'):?><?=$renderStoryCard($row['item'],'activity')?><?php endif?>
            <?php endforeach?>
        </div>
    </section>
    <?php elseif($tab==='stories'):?>
    <section class="profileContent profileWideContent"><header class="profileContentHeader"><div><span class="profileSectionEyebrow">STORIES</span><h2>Research Agent Stories</h2></div><span class="profileActivityCount"><?=h((string)$storyCount)?> public</span></header>
      <?php if($storyGroups):?><div class="profileStoryRail" aria-label="Research Agents with active public Stories"><?php foreach($storyGroups as $group):?><a href="#story-<?=h((string)$group['stories'][0]['public_id'])?>" class="profileStoryRailItem"><span class="profileStoryRing"><?php if(!empty($group['profile_image_url'])):?><img src="<?=h((string)$group['profile_image_url'])?>" alt=""><?php else:?><?=h(mb_strtoupper(mb_substr((string)$group['agent_name'],0,1)))?><?php endif?></span><strong><?=h((string)$group['agent_name'])?></strong><small><?=h((string)count($group['stories']))?> active</small></a><?php endforeach?></div><?php endif?>
      <div class="profileStoryGrid"><?php if(!$profileStories):?><div class="profileEmptyState"><div class="profileEmptyIcon" aria-hidden="true">◌</div><h3>No public Stories yet</h3><p>Public Research Agent Stories will appear here.</p></div><?php endif?>
      <?php foreach($profileStories as $story):?><?=$renderStoryCard($story,'stories')?><?php endforeach?></div>
    </section>
    <?php elseif($tab==='agents'):?>
    <section class="profileContent profileWideContent"><header class="profileContentHeader"><div><span class="profileSectionEyebrow">RESEARCH AGENTS</span><h2>Public Research Agents</h2></div><span class="profileActivityCount"><?=h((string)$agentCount)?> public</span></header>
      <div class="profileShowcaseGrid profileAgentShowcaseGrid"><?php if(!$profileResearchAgents):?><div class="profileEmptyState"><div class="profileEmptyIcon" aria-hidden="true">✦</div><h3>No public Research Agents</h3><p>Only Research Agents explicitly set to Public appear on this profile.</p></div><?php endif?>
      <?php foreach($profileResearchAgents as $agent):?><?=$renderAgentCard($agent,'agents')?><?php endforeach?></div>
    </section>
    <?php elseif($tab==='annotations'):?>
    <section class="profileContent"><header class="profileContentHeader"><div><span class="profileSectionEyebrow">ANNOTATIONS</span><h2>Public annotations</h2></div><span class="profileActivityCount"><?=h((string)count($p['annotations']))?> shown</span></header><div class="profileFeed">
        <?php if(!$p['annotations']):?><div class="profileEmptyState"><div class="profileEmptyIcon" aria-hidden="true">✎</div><h3>No public annotations yet</h3><p><?= $ownerControls?'Annotations you make public will appear here.':'This profile has not shared any public annotations yet.' ?></p></div><?php endif?>
        <?php foreach($p['annotations'] as $a):?><div class="profileActivityObject"><?=$renderPinControl('annotation',(string)$a['public_id'],'annotations')?><?=$renderNetworkAction('annotation',(string)$a['public_id'],'annotations')?><?=annotation_ui_card($a,$viewer,['show_author'=>false])?></div><?php endforeach?>
    </div></section>
    <?php elseif($tab==='research'&&$showResearch):?>
    <section class="profileContent profileWideContent">

      <header class="profileContentHeader"><div><span class="profileSectionEyebrow">RESEARCH</span><h2>Published Research</h2></div><span class="profileActivityCount"><?=h((string)$reportCount)?> public</span></header><div class="profileShowcaseGrid">
        <?php if(!$p['reports']):?><div class="profileEmptyState"><div class="profileEmptyIcon" aria-hidden="true">⌁</div><h3>No published Research yet</h3><p><?= $ownerControls?'Public immutable Research Reports you publish will appear here.':'This profile has not published public Research yet.' ?></p></div><?php endif?>
        <?php foreach($p['reports'] as $r):?><?=$renderResearchCard($r,'research')?><?php endforeach?>
      </div>
    </section>
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
        <div><span>Public work</span><p><?=h((string)$annotationCount)?> annotations · <?=h((string)$reportCount)?> Research reports · <?=h((string)$agentCount)?> Research Agents · <?=h((string)$storyCount)?> active Stories · <?=h((string)$collectionCount)?> collections</p></div>
        <div><span>Network</span><p><?=h((string)$followerCount)?> followers · <?=h((string)$followingCount)?> following</p></div>
    </div></section>
    <?php endif?>

    <?php if($ownerControls):?><dialog class="profileEditDialog" data-profile-edit-dialog><form method="post" enctype="multipart/form-data" class="profileEditForm"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="profile_update"><header><div><span class="profileSectionEyebrow">PROFILE</span><h2>Edit profile</h2></div><button type="button" data-profile-edit-close aria-label="Close">×</button></header><label>Display name<input name="display_name" maxlength="100" required value="<?=h((string)$p['display_name'])?>"></label><label>Bio<textarea name="bio" maxlength="2000" rows="5"><?=h((string)($p['bio']??''))?></textarea></label><label>Website<input name="website_url" type="url" maxlength="500" value="<?=h((string)($p['website_url']??''))?>"></label><div class="profileEditMediaGrid"><label>Profile photo<input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp"><?php if(!empty($p['profile_image_url'])):?><span><input type="checkbox" name="remove_profile_photo" value="1"> Remove current photo</span><?php endif?></label><label>Cover image<input type="file" name="profile_cover" accept="image/jpeg,image/png,image/webp"><?php if(!empty($p['profile_cover_image_url'])):?><span><input type="checkbox" name="remove_profile_cover" value="1"> Remove current cover</span><?php endif?></label></div><footer><a href="/settings.php">More settings</a><div><button type="button" class="profileSecondaryAction" data-profile-edit-close>Cancel</button><button type="submit" class="profilePrimaryAction">Save profile</button></div></footer></form></dialog><?php endif?>
    <footer class="profileStandaloneFooter"><a href="<?=$viewer?'/home.php':'/'?>">Annotated</a><?php if($viewer):?><a href="/home.php">Home</a><?php else:?><a href="/explore.php">Explore</a><a href="/login.php">Log in</a><?php endif?></footer>
</main>

<script>
document.querySelector('[data-profile-edit-open]')?.addEventListener('click',()=>document.querySelector('[data-profile-edit-dialog]')?.showModal());
document.querySelectorAll('[data-profile-edit-close]').forEach(b=>b.addEventListener('click',()=>document.querySelector('[data-profile-edit-dialog]')?.close()));
document.querySelector('#copyProfile')?.addEventListener('click',async e=>{try{await navigator.clipboard.writeText(<?=json_encode($canonical)?>);const original=e.currentTarget.innerHTML;e.currentTarget.textContent='✓';e.currentTarget.setAttribute('aria-label','Profile link copied');setTimeout(()=>{e.currentTarget.innerHTML=original;e.currentTarget.setAttribute('aria-label','Copy profile link');},1400);}catch(_){}});
</script>
<?php if($viewer&&!$owner):?>
<script>
const csrf=<?=json_encode(csrf_token())?>;
document.querySelector('#follow')?.addEventListener('click',async e=>{const button=e.currentTarget;if(button.disabled)return;const desired=button.getAttribute('aria-pressed')!=='true';button.disabled=true;button.setAttribute('aria-busy','true');try{const r=await fetch('/api/profile-follow.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({user_id:<?=json_encode($p['public_id'])?>,following:desired})});const j=await r.json().catch(()=>({ok:false,error:{message:'Follow request failed.'}}));if(!r.ok||j.ok===false)throw new Error(j.error?.message||'Follow request failed.');const following=!!j.data.following;button.textContent=following?'Following':'Follow';button.setAttribute('aria-pressed',following?'true':'false');const count=document.querySelector('[data-profile-follower-count]');if(count&&Number.isFinite(Number(j.data.follower_count)))count.textContent=String(j.data.follower_count);}catch(err){alert(err?.message||'Unable to update follow state.');}finally{button.disabled=false;button.removeAttribute('aria-busy');}});
</script>
<?php endif?>
<?=annotation_ui_scripts($viewer)?>
</body>
</html>
