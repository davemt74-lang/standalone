<?php
declare(strict_types=1);

function research_agent_workspace_bookmark_card(array $row): string {
    $title=trim((string)($row['title']??''));if($title==='')$title=(string)($row['domain']??'Saved website');
    $url=(string)($row['canonical_url']??'');$description=trim((string)($row['description']??''));
    $agentName=trim((string)($row['research_agent_name']??''));$projectTitle=trim((string)($row['project_title']??'Research'));
    $teamName=trim((string)($row['team_name']??''));$folder=trim((string)($row['parent_title']??''));
    $creator=[
      'username'=>(string)($row['creator_username']??''),
      'display_name'=>(string)($row['creator_name']??$row['creator_username']??'Researcher'),
      'profile_image_url'=>$row['profile_image_url']??null,
    ];
    $agentHref=!empty($row['research_agent_public_id'])&&function_exists('research_agent_access')
      ?'/home.php?agent_context_type=bookmark&agent_context_id='.rawurlencode((string)$row['public_id'])
      :'';
    ob_start();?>
<article class="researchBookmarkCard socialPost" data-bookmark-id="<?=h((string)$row['public_id'])?>">
  <div class="postHead">
    <div class="author">
      <?=function_exists('app_shell_avatar')?app_shell_avatar($creator,'avatarSm'):''?>
      <span class="feedAuthorText"><strong><?=h($creator['display_name'])?></strong><span>@<?=h($creator['username'])?> · saved a bookmark</span></span>
    </div>
    <div class="postMeta">
      <span class="annotationType">Bookmark</span>
      <?php if($teamName!==''):?><span class="researchBookmarkBadge">Team · <?=h($teamName)?></span><?php endif?>
    </div>
  </div>
  <div class="researchBookmarkResearch"><span><?=h($agentName!==''?$agentName:$projectTitle)?></span><?php if($folder!==''):?><small>/ <?=h($folder)?></small><?php endif?></div>
  <a class="researchBookmarkPreview" href="<?=h($url)?>" target="_blank" rel="noopener noreferrer">
    <?php if(!empty($row['preview_image_url'])):?><img src="<?=h((string)$row['preview_image_url'])?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif?>
    <span><small><?=h((string)($row['domain']??''))?></small><strong><?=h($title)?></strong><?php if($description!==''):?><p><?=h($description)?></p><?php endif?></span>
  </a>
  <footer class="researchBookmarkActions">
    <a href="<?=h($url)?>" target="_blank" rel="noopener noreferrer">Open website</a>
    <?php if(!empty($row['research_agent_public_id'])):?><a href="/home.php?agent=<?=h(rawurlencode((string)($row['conversation_public_id']??'')))?>">Open Research Agent</a><?php endif?>
    <?php if($agentHref!==''):?><a href="<?=h($agentHref)?>">Ask Agent</a><?php endif?>
    <?php if(!empty($row['project_public_id'])):?><a href="/research-project.php?id=<?=h(rawurlencode((string)$row['project_public_id']))?>">Research</a><?php endif?>
  </footer>
</article>
<?php return (string)ob_get_clean();
}
