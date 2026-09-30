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

$profile=$username!==''?public_discovery_profile($pdo,$username,$viewer):null;
if(!$profile){http_response_code(404);exit('Profile not found.');}

$owner=$viewer&&(int)$viewer['id']===(int)$profile['id'];
$viewAsPublic=$owner&&($_GET['view']??'')==='public';
$ownerControls=$owner&&!$viewAsPublic;
$profileError='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!$viewer){http_response_code(403);exit('Sign in required.');}
    require_csrf();
    try{
        $action=(string)($_POST['action']??'');
        $returnTab=preg_replace('/[^a-z_]/','',(string)($_POST['return_tab']??'activity'))?:'activity';

        if($action==='profile_pin'||$action==='profile_unpin'){
            if(!$ownerControls)throw new RuntimeException('Profile owner access required.');
            profile_showcase_pin_set(
                $pdo,
                $viewer,
                (string)($_POST['object_type']??''),
                (string)($_POST['object_id']??''),
                $action==='profile_pin'
            );
            header('Location: '.profile_path((string)$profile['username']).'?tab='.rawurlencode($returnTab).'&profile_updated=1');
            exit;
        }

        if($action==='profile_update'){
            if(!$ownerControls)throw new RuntimeException('Profile owner access required.');
            $display=trim((string)($_POST['display_name']??''));
            $bio=trim((string)($_POST['bio']??''));
            $website=trim((string)($_POST['website_url']??''));

            if($display===''||mb_strlen($display)>100)throw new RuntimeException('Display name is required and must be 100 characters or fewer.');
            if(mb_strlen($bio)>2000)throw new RuntimeException('Bio must be 2,000 characters or fewer.');
            if($website!==''&&!filter_var($website,FILTER_VALIDATE_URL))throw new RuntimeException('Website must be a valid URL.');

            $image=trim((string)($profile['profile_image_url']??''));
            $cover=trim((string)($profile['profile_cover_image_url']??''));
            $oldImage=$image;$oldCover=$cover;$uploadedImage='';$uploadedCover='';

            if(isset($_POST['remove_profile_photo']))$image='';
            if(isset($_POST['remove_profile_cover']))$cover='';

            if(isset($_FILES['profile_photo'])&&(int)($_FILES['profile_photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                $uploadedImage=profile_image_upload($_FILES['profile_photo'],(int)$viewer['id']);
                $image=$uploadedImage;
            }
            if(isset($_FILES['profile_cover'])&&(int)($_FILES['profile_cover']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                $uploadedCover=profile_cover_upload($_FILES['profile_cover'],(int)$viewer['id']);
                $cover=$uploadedCover;
            }

            try{
                $pdo->prepare('UPDATE users SET display_name=?,bio=?,website_url=?,profile_image_url=?,profile_cover_image_url=? WHERE id=?')
                    ->execute([$display,$bio?:null,$website?:null,$image?:null,$cover?:null,(int)$viewer['id']]);
            }catch(Throwable $e){
                if($uploadedImage!=='')profile_image_delete_local($uploadedImage);
                if($uploadedCover!=='')profile_image_delete_local($uploadedCover);
                throw $e;
            }

            if($oldImage!==''&&$oldImage!==$image)profile_image_delete_local($oldImage);
            if($oldCover!==''&&$oldCover!==$cover)profile_image_delete_local($oldCover);

            header('Location: '.profile_path((string)$profile['username']).'?profile_updated=1');
            exit;
        }

        if(in_array($action,['network_add_research','network_research_this'],true)){
            $agentPublic=trim((string)($_POST['agent_id']??''));
            profile_network_add_to_agent(
                $pdo,
                $config,
                $viewer,
                $agentPublic,
                (string)($_POST['object_type']??''),
                (string)($_POST['object_id']??'')
            );
            if($action==='network_research_this'){
                $agent=research_agent_access($pdo,$viewer,$agentPublic);
                if($agent&&!empty($agent['conversation_public_id'])){
                    header('Location: /home.php?agent='.rawurlencode((string)$agent['conversation_public_id']));
                    exit;
                }
            }
            header('Location: '.profile_path((string)$profile['username']).'?tab='.rawurlencode($returnTab).'&research_added=1');
            exit;
        }

        throw new RuntimeException('Unknown profile action.');
    }catch(Throwable $e){
        $profileError=$e->getMessage();
    }
}

$viewerAgents=[];$publicAgents=[];$stories=[];$collections=[];$activity=[];$pins=[];
try{$viewerAgents=$viewer&&!$viewAsPublic?research_agent_list($pdo,$viewer,50):[];}catch(Throwable $e){}
try{$publicAgents=function_exists('research_agent_profile_list')?research_agent_profile_list($pdo,(int)$profile['id'],null,40):[];}catch(Throwable $e){}
try{$stories=function_exists('profile_showcase_public_agent_stories')?profile_showcase_public_agent_stories($pdo,(int)$profile['id'],60):[];}catch(Throwable $e){}
try{$collections=profile_showcase_public_collections($pdo,(int)$profile['id'],40);}catch(Throwable $e){}
try{$activity=profile_showcase_activity($profile,$collections,$stories);}catch(Throwable $e){}
try{$pins=profile_showcase_pins($pdo,$profile,$viewer);}catch(Throwable $e){}

$pinMap=[];
foreach($pins as $pin)$pinMap[(string)$pin['type'].'|'.(string)$pin['public_id']]=true;

$tabs=[
    'activity'=>'Activity',
    'stories'=>'Stories',
    'agents'=>'Research Agents',
    'research'=>'Research',
    'annotations'=>'Annotations',
    'collections'=>'Collections',
    'about'=>'About',
];
$tab=(string)($_GET['tab']??'activity');
if(!isset($tabs[$tab]))$tab='activity';

$isPublic=(string)$profile['profile_visibility']==='public';
$canonical=public_discovery_absolute_url($config,profile_path((string)$profile['username']));
$desc=public_discovery_meta_description((string)($profile['bio']?:$profile['display_name'].' on Annotated'));
$cover=trim((string)($profile['profile_cover_image_url']??''));
$avatar=trim((string)($profile['profile_image_url']??''));
$initial=mb_strtoupper(mb_substr((string)$profile['display_name'],0,1));

$annotationCount=(int)$profile['annotation_count'];
$researchCount=count((array)$profile['reports']);
$agentCount=count($publicAgents);
$storyCount=count($stories);
$collectionCount=count($collections);
$followerCount=(int)$profile['followers'];
$followingCount=(int)$profile['following_count'];

$storyByAgent=[];
foreach($stories as $story){
    $aid=(string)($story['agent_public_id']??'');
    if($aid==='')continue;
    if(!isset($storyByAgent[$aid])){
        $storyByAgent[$aid]=[
            'id'=>$aid,
            'name'=>(string)($story['agent_name']??'Research Agent'),
            'image'=>(string)($story['agent_profile_image_url']??''),
            'stories'=>[],
        ];
    }
    $storyByAgent[$aid]['stories'][]=$story;
}
foreach($publicAgents as &$agent){
    $group=$storyByAgent[(string)$agent['public_id']]??null;
    $agent['active_story_count']=$group?count($group['stories']):0;
    $agent['latest_story_at']=$group?(string)($group['stories'][0]['published_at']??''):'';
}
unset($agent);

$pinControl=function(string $type,string $id,string $returnTab)use($ownerControls,$pinMap): string{
    if(!$ownerControls)return '';
    $key=$type.'|'.$id;
    $pinned=!empty($pinMap[$key]);
    return '<form method="post" class="annotatedProfilePin">'
        .'<input type="hidden" name="csrf" value="'.h(csrf_token()).'">'
        .'<input type="hidden" name="action" value="'.($pinned?'profile_unpin':'profile_pin').'">'
        .'<input type="hidden" name="object_type" value="'.h($type).'">'
        .'<input type="hidden" name="object_id" value="'.h($id).'">'
        .'<input type="hidden" name="return_tab" value="'.h($returnTab).'">'
        .'<button type="submit">'.($pinned?'Unfeature':'Feature').'</button>'
        .'</form>';
};

$researchHandoff=function(string $type,string $id,string $returnTab)use($viewer,$viewerAgents): string{
    if(!$viewer||!$viewerAgents)return '';
    $options='';
    foreach($viewerAgents as $agent){
        $options.='<option value="'.h((string)$agent['public_id']).'">'.h((string)$agent['name']).'</option>';
    }
    return '<details class="annotatedProfileResearchAction"><summary>Research this</summary><form method="post">'
        .'<input type="hidden" name="csrf" value="'.h(csrf_token()).'">'
        .'<input type="hidden" name="object_type" value="'.h($type).'">'
        .'<input type="hidden" name="object_id" value="'.h($id).'">'
        .'<input type="hidden" name="return_tab" value="'.h($returnTab).'">'
        .'<select name="agent_id">'.$options.'</select>'
        .'<button name="action" value="network_add_research">Add</button>'
        .'<button name="action" value="network_research_this">Add & open</button>'
        .'</form></details>';
};

$renderResearch=function(array $row,string $returnTab='research')use($pinControl,$researchHandoff): string{
    $id=(string)$row['public_id'];
    return '<article class="annotatedProfileCard">'
        .'<div class="annotatedProfileCardTop"><span>Published research</span>'.$pinControl('research_report',$id,$returnTab).'</div>'
        .'<h3><a href="/research-report.php?id='.rawurlencode($id).'">'.h((string)$row['title']).'</a></h3>'
        .(!empty($row['summary'])?'<p>'.nl2br(h((string)$row['summary'])).'</p>':'')
        .$researchHandoff('research_report',$id,$returnTab)
        .'<footer><span>Version '.h((string)$row['version_number']).'</span><time>'.h(date('M j, Y',strtotime((string)$row['published_at'])?:time())).'</time></footer>'
        .'</article>';
};

$renderCollection=function(array $row,string $returnTab='collections')use($pinControl): string{
    $id=(string)$row['public_id'];
    return '<article class="annotatedProfileCard">'
        .'<div class="annotatedProfileCardTop"><span>Public collection</span>'.$pinControl('collection',$id,$returnTab).'</div>'
        .'<h3><a href="/collection.php?id='.rawurlencode($id).'">'.h((string)$row['title']).'</a></h3>'
        .(!empty($row['description'])?'<p>'.nl2br(h((string)$row['description'])).'</p>':'')
        .'<footer><span>'.(int)$row['item_count'].' public item'.((int)$row['item_count']===1?'':'s').'</span><time>'.h(date('M j, Y',strtotime((string)($row['recent_item_at']?:$row['updated_at']))?:time())).'</time></footer>'
        .'</article>';
};

$renderAgent=function(array $row,string $returnTab='agents')use($pinControl): string{
    $id=(string)$row['public_id'];
    $name=(string)($row['name']??'Research Agent');
    $image=trim((string)($row['profile_image_url']??''));
    $agentInitial=mb_strtoupper(mb_substr($name,0,1));
    $visual=$image!==''?'<img src="'.h($image).'" alt="">':'<span>'.h($agentInitial?:'R').'</span>';
    return '<article class="annotatedProfileCard annotatedProfileAgentCard">'
        .'<div class="annotatedProfileCardTop"><span>Public Research Agent</span>'.$pinControl('research_agent',$id,$returnTab).'</div>'
        .'<a class="annotatedProfileAgentLink" href="/research-agent-public.php?agent='.rawurlencode($id).'">'
        .'<div class="annotatedProfileAgentIdentity">'.$visual.'<div><strong>'.h($name).'</strong><small>'.(int)($row['active_story_count']??0).' active '.((int)($row['active_story_count']??0)===1?'Story':'Stories').'</small></div></div>'
        .(!empty($row['description'])?'<p>'.h(mb_substr((string)$row['description'],0,280)).'</p>':'')
        .'<strong class="annotatedProfileOpenLink">View Agent →</strong>'
        .'</a></article>';
};

$renderStory=function(array $story): string{
    $agentName=(string)($story['agent_name']??'Research Agent');
    $agentImage=trim((string)($story['agent_profile_image_url']??''));
    $storyInitial=mb_strtoupper(mb_substr($agentName,0,1));
    $visual=$agentImage!==''?'<img src="'.h($agentImage).'" alt="">':'<span>'.h($storyInitial?:'R').'</span>';
    $title=trim((string)($story['title']??''))?:'Research update';
    return '<article class="annotatedProfileStory" id="story-'.h((string)$story['public_id']).'">'
        .'<header><div class="annotatedProfileStoryAvatar">'.$visual.'</div><div><strong>'.h($agentName).'</strong><small>'.h(date('M j · g:i A',strtotime((string)$story['published_at'])?:time())).'</small></div></header>'
        .'<span class="annotatedProfileType">'.h(strtoupper(str_replace('_',' ',(string)($story['story_type']??'story')))).'</span>'
        .'<h3>'.h($title).'</h3>'
        .'<p>'.nl2br(h((string)$story['body'])).'</p>'
        .(!empty($story['why_it_matters'])?'<aside><strong>Why this matters</strong><p>'.h((string)$story['why_it_matters']).'</p></aside>':'')
        .'<footer><a href="/research-agent-public.php?agent='.rawurlencode((string)$story['agent_public_id']).'">View Research Agent →</a></footer>'
        .'</article>';
};
?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h((string)$profile['display_name'])?> · Annotated</title>
<meta name="description" content="<?=h($desc)?>">
<?php if(!$isPublic):?><meta name="robots" content="noindex,nofollow"><?php endif?>
<link rel="canonical" href="<?=h($canonical)?>">
<?php if($isPublic):?>
<meta property="og:type" content="profile">
<meta property="og:title" content="<?=h((string)$profile['display_name'])?> · Annotated">
<meta property="og:description" content="<?=h($desc)?>">
<meta property="og:url" content="<?=h($canonical)?>">
<?php endif?>
<link rel="stylesheet" href="/assets/css/app.css?v=profile-social-1">
<link rel="stylesheet" href="/assets/css/profile-social.css?v=1.0">
</head>
<body class="annotatedProfileBody">
<main class="annotatedProfilePage">
    <?php if(!$viewer):?>
    <header class="annotatedProfileGuestHeader">
        <a class="annotatedProfileGuestBrand" href="/"><span>A</span><strong>Annotated</strong></a>
        <nav><a href="/explore.php">Explore</a><a href="/login.php">Sign in</a><a class="annotatedProfileGuestCta" href="/register.php">Get Annotated</a></nav>
    </header>
    <?php endif?>

    <?php if($viewAsPublic):?>
    <div class="annotatedProfileNotice"><span>You are viewing your profile exactly as the public sees it.</span><a href="<?=h(profile_path((string)$profile['username']))?>">Exit public view</a></div>
    <?php endif?>
    <?php if($profileError!==''):?><div class="annotatedProfileAlert error"><?=h($profileError)?></div><?php endif?>
    <?php if(isset($_GET['profile_updated'])):?><div class="annotatedProfileAlert success">Profile updated.</div><?php endif?>
    <?php if(isset($_GET['research_added'])):?><div class="annotatedProfileAlert success">Added to your Research Agent.</div><?php endif?>

    <section class="annotatedProfileHero" aria-labelledby="annotatedProfileName">
        <div class="annotatedProfileCover<?=$cover!==''?' hasImage':''?>"<?php if($cover!==''):?> style="background-image:url('<?=h($cover)?>')"<?php endif?>></div>
        <div class="annotatedProfileHeroContent">
            <div class="annotatedProfileIdentity">
                <div class="annotatedProfileAvatarFrame">
                    <?php if($avatar!==''):?><img class="annotatedProfileAvatar" src="<?=h($avatar)?>" alt="<?=h((string)$profile['display_name'])?>">
                    <?php else:?><div class="annotatedProfileAvatar annotatedProfileAvatarFallback" aria-hidden="true"><?=h($initial)?></div><?php endif?>
                </div>
                <div class="annotatedProfileIdentityCopy">
                    <h1 id="annotatedProfileName"><?=h((string)$profile['display_name'])?></h1>
                    <div class="annotatedProfileHandle">@<?=h((string)$profile['username'])?></div>
                    <?php if(!empty($profile['bio'])):?><p class="annotatedProfileBio"><?=nl2br(h((string)$profile['bio']))?></p><?php endif?>
                    <?php if(!empty($profile['website_url'])):?><a class="annotatedProfileWebsite" href="<?=h((string)$profile['website_url'])?>" target="_blank" rel="nofollow noopener"><?=h((string)$profile['website_url'])?> ↗</a><?php endif?>
                </div>
            </div>

            <div class="annotatedProfileActions">
                <?php if($viewer&&!$owner&& !empty($profile['friends'])):?><span class="annotatedProfileRelationship">Friends</span><?php elseif($viewer&&!$owner&& !empty($profile['follows_you'])):?><span class="annotatedProfileRelationship">Follows you</span><?php endif?>

                <?php if($viewer&&!$owner):?>
                    <button class="annotatedProfilePrimary" id="follow" type="button" aria-pressed="<?=$profile['following']?'true':'false'?>"><?=$profile['following']?'Following':'Follow'?></button>
                <?php elseif($ownerControls):?>
                    <button class="annotatedProfilePrimary" type="button" data-profile-edit-open>Edit profile</button>
                    <a class="annotatedProfileSecondary" href="<?=h(profile_path((string)$profile['username']))?>?view=public">View as public</a>
                <?php elseif(!$viewer):?>
                    <a class="annotatedProfilePrimary" href="/login.php">Log in to follow</a>
                <?php endif?>

                <button class="annotatedProfileIconButton" id="copyProfile" type="button" aria-label="Copy profile link">↗</button>
            </div>
        </div>

        <div class="annotatedProfileStats">
            <a href="<?=h(profile_path((string)$profile['username']))?>?tab=research"><strong><?=$researchCount?></strong><span>Research</span></a>
            <a href="<?=h(profile_path((string)$profile['username']))?>?tab=annotations"><strong><?=$annotationCount?></strong><span>Annotations</span></a>
            <a href="<?=h(profile_path((string)$profile['username']))?>?tab=agents"><strong><?=$agentCount?></strong><span>Agents</span></a>
            <a href="/profile-connections.php?u=<?=rawurlencode((string)$profile['username'])?>&type=followers"><strong data-profile-follower-count><?=$followerCount?></strong><span>Followers</span></a>
            <a href="/profile-connections.php?u=<?=rawurlencode((string)$profile['username'])?>&type=following"><strong><?=$followingCount?></strong><span>Following</span></a>
        </div>
    </section>

    <nav class="annotatedProfileTabs" aria-label="Profile sections">
        <?php foreach($tabs as $key=>$label):
            $count=null;
            if($key==='stories')$count=$storyCount;
            elseif($key==='agents')$count=$agentCount;
            elseif($key==='research')$count=$researchCount;
            elseif($key==='annotations')$count=$annotationCount;
            elseif($key==='collections')$count=$collectionCount;
        ?>
        <a href="<?=h(profile_path((string)$profile['username']))?>?tab=<?=h($key)?>" class="<?=$tab===$key?'active':''?>" <?=$tab===$key?'aria-current="page"':''?>><?=h($label)?><?php if($count!==null):?><span><?=$count?></span><?php endif?></a>
        <?php endforeach?>
    </nav>

    <div class="annotatedProfileCanvas">
    <?php if($tab==='activity'):?>
        <?php if($pins):?>
        <section class="annotatedProfileSection">
            <header class="annotatedProfileSectionHeader"><div><span>Featured</span><h2>Public showcase</h2></div><small><?=count($pins)?> of 6</small></header>
            <div class="annotatedProfileGrid">
                <?php foreach($pins as $pin):?>
                    <?php if($pin['type']==='annotation'):?>
                        <div class="annotatedProfileAnnotationWrap"><?=$pinControl('annotation',(string)$pin['public_id'],'activity')?><?=annotation_ui_card($pin['item'],$viewer,['show_author'=>false])?></div>
                    <?php elseif($pin['type']==='research_report'):?><?=$renderResearch($pin['item'],'activity')?>
                    <?php elseif($pin['type']==='collection'):?><?=$renderCollection($pin['item'],'activity')?>
                    <?php elseif($pin['type']==='research_agent'):?><?=$renderAgent($pin['item'],'activity')?>
                    <?php endif?>
                <?php endforeach?>
            </div>
        </section>
        <?php endif?>

        <section class="annotatedProfileSection">
            <header class="annotatedProfileSectionHeader"><div><span>Recent</span><h2>Public activity</h2></div><small><?=count($activity)?> shown</small></header>
            <div class="annotatedProfileFeed">
                <?php if(!$activity):?><div class="annotatedProfileEmpty"><h3>No public activity yet</h3><p>Public research, annotations, collections, and Stories will appear here.</p></div><?php endif?>
                <?php foreach($activity as $row):?>
                    <?php if($row['type']==='annotation'):?>
                        <div class="annotatedProfileAnnotationWrap"><?=$pinControl('annotation',(string)$row['item']['public_id'],'activity')?><?=$researchHandoff('annotation',(string)$row['item']['public_id'],'activity')?><?=annotation_ui_card($row['item'],$viewer,['show_author'=>false])?></div>
                    <?php elseif($row['type']==='research_report'):?><?=$renderResearch($row['item'],'activity')?>
                    <?php elseif($row['type']==='collection'):?><?=$renderCollection($row['item'],'activity')?>
                    <?php elseif($row['type']==='research_agent_story'):?><?=$renderStory($row['item'])?>
                    <?php endif?>
                <?php endforeach?>
            </div>
        </section>

    <?php elseif($tab==='stories'):?>
        <section class="annotatedProfileSection">
            <header class="annotatedProfileSectionHeader"><div><span>Stories</span><h2>Research Agent Stories</h2></div><small><?=$storyCount?> public</small></header>
            <?php if($storyByAgent):?>
            <div class="annotatedProfileStoryRail">
                <?php foreach($storyByAgent as $group):?>
                <a href="#story-<?=h((string)$group['stories'][0]['public_id'])?>">
                    <span class="annotatedProfileStoryRing">
                        <?php if($group['image']!==''):?><img src="<?=h((string)$group['image'])?>" alt="">
                        <?php else:?><?=h(mb_strtoupper(mb_substr((string)$group['name'],0,1)))?><?php endif?>
                    </span>
                    <strong><?=h((string)$group['name'])?></strong>
                    <small><?=count($group['stories'])?> active</small>
                </a>
                <?php endforeach?>
            </div>
            <?php endif?>
            <div class="annotatedProfileStoryGrid">
                <?php if(!$stories):?><div class="annotatedProfileEmpty"><h3>No public Stories</h3><p>Only published Stories from public Research Agents appear here.</p></div><?php endif?>
                <?php foreach($stories as $story):?><?=$renderStory($story)?><?php endforeach?>
            </div>
        </section>

    <?php elseif($tab==='agents'):?>
        <section class="annotatedProfileSection">
            <header class="annotatedProfileSectionHeader"><div><span>Research Agents</span><h2>Public Research Agents</h2></div><small><?=$agentCount?> public</small></header>
            <div class="annotatedProfileGrid">
                <?php if(!$publicAgents):?><div class="annotatedProfileEmpty"><h3>No public Research Agents</h3><p>Private and friends-only agents are never shown on this profile.</p></div><?php endif?>
                <?php foreach($publicAgents as $agent):?><?=$renderAgent($agent,'agents')?><?php endforeach?>
            </div>
        </section>

    <?php elseif($tab==='research'):?>
        <section class="annotatedProfileSection">
            <header class="annotatedProfileSectionHeader"><div><span>Research</span><h2>Published Research</h2></div><small><?=$researchCount?> public</small></header>
            <div class="annotatedProfileGrid">
                <?php if(empty($profile['reports'])):?><div class="annotatedProfileEmpty"><h3>No public Research yet</h3><p>Published public Research reports will appear here.</p></div><?php endif?>
                <?php foreach((array)$profile['reports'] as $report):?><?=$renderResearch($report,'research')?><?php endforeach?>
            </div>
        </section>

    <?php elseif($tab==='annotations'):?>
        <section class="annotatedProfileSection">
            <header class="annotatedProfileSectionHeader"><div><span>Annotations</span><h2>Public annotations</h2></div><small><?=$annotationCount?> public</small></header>
            <div class="annotatedProfileFeed">
                <?php if(empty($profile['annotations'])):?><div class="annotatedProfileEmpty"><h3>No public annotations yet</h3><p>Only published public annotations appear here.</p></div><?php endif?>
                <?php foreach((array)$profile['annotations'] as $annotation):?>
                    <div class="annotatedProfileAnnotationWrap"><?=$pinControl('annotation',(string)$annotation['public_id'],'annotations')?><?=$researchHandoff('annotation',(string)$annotation['public_id'],'annotations')?><?=annotation_ui_card($annotation,$viewer,['show_author'=>false])?></div>
                <?php endforeach?>
            </div>
        </section>

    <?php elseif($tab==='collections'):?>
        <section class="annotatedProfileSection">
            <header class="annotatedProfileSectionHeader"><div><span>Collections</span><h2>Public collections</h2></div><small><?=$collectionCount?> public</small></header>
            <div class="annotatedProfileGrid">
                <?php if(!$collections):?><div class="annotatedProfileEmpty"><h3>No public collections yet</h3><p>Only collections marked Public appear here.</p></div><?php endif?>
                <?php foreach($collections as $collection):?><?=$renderCollection($collection,'collections')?><?php endforeach?>
            </div>
        </section>

    <?php elseif($tab==='about'):?>
        <section class="annotatedProfileSection">
            <header class="annotatedProfileSectionHeader"><div><span>About</span><h2><?=h((string)$profile['display_name'])?></h2></div></header>
            <div class="annotatedProfileAbout">
                <?php if(!empty($profile['bio'])):?><div><span>Bio</span><p><?=nl2br(h((string)$profile['bio']))?></p></div><?php endif?>
                <?php if(!empty($profile['website_url'])):?><div><span>Website</span><p><a href="<?=h((string)$profile['website_url'])?>" target="_blank" rel="nofollow noopener"><?=h((string)$profile['website_url'])?></a></p></div><?php endif?>
                <div><span>Member since</span><p><?=h(date('F Y',strtotime((string)$profile['created_at'])?:time()))?></p></div>
                <div><span>Public work</span><p><?=$annotationCount?> annotations · <?=$researchCount?> Research reports · <?=$agentCount?> Research Agents · <?=$storyCount?> active Stories · <?=$collectionCount?> collections</p></div>
                <div><span>Network</span><p><?=$followerCount?> followers · <?=$followingCount?> following</p></div>
            </div>
        </section>
    <?php endif?>
    </div>

    <?php if($ownerControls):?>
    <dialog class="annotatedProfileEditDialog" data-profile-edit-dialog>
        <form method="post" enctype="multipart/form-data" class="annotatedProfileEditForm">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="profile_update">
            <header><div><span>Profile</span><h2>Edit profile</h2></div><button type="button" data-profile-edit-close aria-label="Close">×</button></header>
            <label>Display name<input name="display_name" maxlength="100" required value="<?=h((string)$profile['display_name'])?>"></label>
            <label>Bio<textarea name="bio" maxlength="2000" rows="5"><?=h((string)($profile['bio']??''))?></textarea></label>
            <label>Website<input name="website_url" type="url" maxlength="500" value="<?=h((string)($profile['website_url']??''))?>"></label>
            <div class="annotatedProfileEditMedia">
                <label>Profile photo<input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp"><?php if($avatar!==''):?><small><input type="checkbox" name="remove_profile_photo" value="1"> Remove current photo</small><?php endif?></label>
                <label>Cover image<input type="file" name="profile_cover" accept="image/jpeg,image/png,image/webp"><?php if($cover!==''):?><small><input type="checkbox" name="remove_profile_cover" value="1"> Remove current cover</small><?php endif?></label>
            </div>
            <footer><a href="/settings.php">More settings</a><div><button type="button" class="annotatedProfileSecondary" data-profile-edit-close>Cancel</button><button type="submit" class="annotatedProfilePrimary">Save profile</button></div></footer>
        </form>
    </dialog>
    <?php endif?>
</main>

<script>
document.querySelector('[data-profile-edit-open]')?.addEventListener('click',()=>document.querySelector('[data-profile-edit-dialog]')?.showModal());
document.querySelectorAll('[data-profile-edit-close]').forEach(button=>button.addEventListener('click',()=>document.querySelector('[data-profile-edit-dialog]')?.close()));
document.querySelector('#copyProfile')?.addEventListener('click',async event=>{
    try{
        await navigator.clipboard.writeText(<?=json_encode($canonical)?>);
        const button=event.currentTarget;
        const original=button.textContent;
        button.textContent='✓';
        setTimeout(()=>button.textContent=original,1200);
    }catch(_){}
});
</script>

<?php if($viewer&&!$owner):?>
<script>
const profileCsrf=<?=json_encode(csrf_token())?>;
document.querySelector('#follow')?.addEventListener('click',async event=>{
    const button=event.currentTarget;
    if(button.disabled)return;
    const desired=button.getAttribute('aria-pressed')!=='true';
    button.disabled=true;
    try{
        const response=await fetch('/api/profile-follow.php',{
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-Token':profileCsrf},
            body:JSON.stringify({user_id:<?=json_encode($profile['public_id'])?>,following:desired})
        });
        const payload=await response.json().catch(()=>({ok:false,error:{message:'Follow request failed.'}}));
        if(!response.ok||payload.ok===false)throw new Error(payload.error?.message||'Follow request failed.');
        const following=!!payload.data.following;
        button.textContent=following?'Following':'Follow';
        button.setAttribute('aria-pressed',following?'true':'false');
        const count=document.querySelector('[data-profile-follower-count]');
        if(count&&Number.isFinite(Number(payload.data.follower_count)))count.textContent=String(payload.data.follower_count);
    }catch(error){
        alert(error?.message||'Unable to update follow state.');
    }finally{
        button.disabled=false;
    }
});
</script>
<?php endif?>

<?=annotation_ui_scripts($viewer)?>
</body>
</html>
