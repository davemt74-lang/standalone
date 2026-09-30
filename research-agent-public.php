<?php
declare(strict_types=1);
$GLOBALS['annotated_shell_disabled']=true;
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/public-discovery.php';

$viewer=current_user($pdo);
$viewerContext=$viewer?:[];
$agentId=trim((string)($_GET['agent']??''));
$agent=$agentId!==''?research_agent_social_access($pdo,$viewerContext,$agentId):null;
if(!$agent){http_response_code(404);exit('Research Agent not found.');}
if($viewer){header('Cache-Control: private, no-store');header('Vary: Cookie');}

$ownerUrl=profile_path((string)$agent['owner_username']);
$stories=[];$latestStory=null;
if($viewer&&function_exists('research_agent_story_list')){
    foreach(research_agent_story_list($pdo,$viewer,60) as $story){
        if((string)($story['agent_public_id']??'')!==$agentId)continue;
        $stories[]=$story;if($latestStory===null)$latestStory=$story;
    }
}
$internal=$viewer?research_agent_access($pdo,$viewer,$agentId):null;
$relationship=$viewer&&function_exists('profile_network_relationship')?profile_network_relationship($pdo,$viewer,(int)$agent['owner_user_id']):['following'=>false,'follows_you'=>false,'friends'=>false,'blocked'=>false];
$initial=mb_strtoupper(mb_substr((string)$agent['name'],0,1));
?><!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h((string)$agent['name'])?> · Research Agent · Annotated</title>
<meta name="description" content="<?=h(public_discovery_meta_description((string)($agent['description']?:$agent['name'].' Research Agent on Annotated')))?>">
<link rel="stylesheet" href="/assets/css/app.css?v=77.4">
</head>
<body class="profileStandaloneBody">
<main class="profileStandalonePage publicAgentPage">
  <a class="profileBackLink" href="<?=h($ownerUrl)?>?tab=research">← Back to <?=h((string)$agent['owner_display_name'])?></a>
  <section class="publicAgentHero">
    <div class="publicAgentAvatar"><?php if(!empty($agent['profile_image_url'])):?><img src="<?=h((string)$agent['profile_image_url'])?>" alt=""><?php else:?><?=h($initial)?><?php endif?></div>
    <div class="publicAgentIdentity">
      <span class="eyebrow"><?=h(strtoupper((string)$agent['visibility']))?> RESEARCH AGENT</span>
      <h1><?=h((string)$agent['name'])?></h1>
      <a class="publicAgentOwner" href="<?=h($ownerUrl)?>">@<?=h((string)$agent['owner_username'])?></a>
      <?php if(!empty($agent['description'])):?><p><?=nl2br(h((string)$agent['description']))?></p><?php endif?>
      <div class="publicAgentRelationship">
        <?php if($viewer&&$relationship['friends']):?><span>Friends</span><?php elseif($viewer&&$relationship['following']):?><span>Following owner</span><?php elseif($viewer&&$relationship['follows_you']):?><span>Owner follows you</span><?php endif?>
      </div>
    </div>
    <div class="publicAgentActions">
      <?php if($internal&&!empty($agent['conversation_public_id'])):?><a class="profilePrimaryAction" href="/home.php?agent=<?=rawurlencode((string)$agent['conversation_public_id'])?>">Open Agent</a><?php endif?>
      <?php if($latestStory):?><a class="profilePrimaryAction" href="<?=h((string)$latestStory['story_url'])?>">View latest Story</a><?php elseif(!$viewer&&$agent['visibility']==='public'):?><a class="profilePrimaryAction" href="/login.php">Log in to follow Stories</a><?php endif?>
      <a class="profileSecondaryAction" href="<?=h($ownerUrl)?>">View owner profile</a>
    </div>
  </section>

  <section class="publicAgentDetails">
    <header class="profileContentHeader"><div><span class="profileSectionEyebrow">SOCIAL RESEARCH</span><h2>About this Agent</h2></div></header>
    <div class="publicAgentMetaGrid">
      <div><span>Visibility</span><strong><?=h(ucfirst((string)$agent['visibility']))?></strong></div>
      <div><span>Owner</span><strong><?=h((string)$agent['owner_display_name'])?></strong></div>
      <?php if($viewer):?><div><span>Active Stories</span><strong><?=h((string)count($stories))?></strong></div><?php endif?>
    </div>
    <?php if($viewer&&$stories):?>
      <div class="publicAgentStoryList">
        <?php foreach(array_slice($stories,0,6) as $story):?><a href="<?=h((string)$story['story_url'])?>"><div><strong><?=h((string)$story['title'])?></strong><small><?=h(date('M j',strtotime((string)$story['published_at'])))?></small></div><span>View Story →</span></a><?php endforeach?>
      </div>
    <?php elseif($viewer):?>
      <div class="profileEmptyState"><h3>No active Stories right now</h3><p>Stories will appear here when this Agent has a new research update you can access.</p></div>
    <?php endif?>
  </section>
</main>
</body>
</html>
