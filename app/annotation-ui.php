<?php
declare(strict_types=1);

function annotation_type_label(array $a): string {
    $type=(string)($a['capture_type']??'');
    if($type==='video_clip')return 'Video';
    if($type==='audio_clip'){
        $sourceType=(string)($a['source_type']??'');
        return $sourceType==='podcast'?'Podcast':'Audio / Music';
    }
    if(in_array($type,['image_region','page_region'],true))return trim((string)($a['selected_text']??''))!==''?'Image + Quote':'Image';
    if($type==='text')return 'Quote';
    if(!empty($a['screenshot_url'])||!empty($a['screenshot_target_path']))return trim((string)($a['selected_text']??''))!==''?'Image + Quote':'Image';
    return 'Annotation';
}

function annotation_source_label(array $a): string {
    $domain=trim((string)($a['source_domain']??$a['domain']??''));
    if($domain!=='')return preg_replace('/^www\./i','',$domain);
    $url=(string)($a['canonical_url']??'');$host=(string)(parse_url($url,PHP_URL_HOST)?:'');
    return $host!==''?preg_replace('/^www\./i','',$host):'Source';
}

function annotation_snapshot_url(array $a): ?string {
    if(!empty($a['screenshot_url']))return (string)$a['screenshot_url'];
    if(!empty($a['screenshot_target_path'])&&!empty($a['public_id']))return evidence_url((string)$a['public_id'],'target');
    return null;
}

function annotation_media_url(array $a): ?string {
    if(!empty($a['media_url']))return (string)$a['media_url'];
    if(!empty($a['media_path'])&&!empty($a['public_id']))return evidence_url((string)$a['public_id'],'media');
    return null;
}

function annotation_ui_card(array $a,?array $viewer=null,array $options=[]): string {
    $showAuthor=$options['show_author']??true;$showSource=$options['show_source']??true;
    $id=(string)($a['public_id']??'');$author=(string)($a['display_name']??'');$username=(string)($a['username']??'');
    $type=annotation_type_label($a);$sourceLabel=annotation_source_label($a);$snapshot=annotation_snapshot_url($a);$media=annotation_media_url($a);
    $comments=(int)($a['comment_count']??0);$likes=(int)($a['like_count']??0);$liked=!empty($a['viewer_liked']);$saved=!empty($a['is_saved']);
    $published=(string)($a['published_at']??'');$visibility=(string)($a['visibility']??'public');
    $commentary=trim((string)($a['text_commentary']??''));$selected=trim((string)($a['selected_text']??''));
    $canonical=(string)($a['canonical_url']??'');$sourceId=(string)($a['source_public_id']??'');
    $captureVersion=(int)($a['capture_version_number']??0);$currentVersion=(int)($a['current_version_number']??0);
    $integrity=is_array($a['integrity']??null)?$a['integrity']:null;$integrityLabel=(string)($integrity['label']??($a['source_status']??''));
    ob_start();?>
<article class="card annotationPost" data-annotation-id="<?=h($id)?>">
  <div class="annotationPostHead">
    <div class="annotationPostIdentity">
      <?php if($showAuthor&&$author!==''):?><?php if($username!==''):?><a href="/profile.php?u=<?=h($username)?>" class="annotationAuthor"><?=app_shell_avatar(['display_name'=>$author,'profile_image_url'=>$a['profile_image_url']??null],'avatarSm')?><span><strong><?=h($author)?></strong><small>@<?=h($username)?></small></span></a><?php else:?><strong><?=h($author)?></strong><?php endif?><?php endif?>
    </div>
    <div class="annotationPostMeta"><span class="annotationTypeBadge"><?=h($type)?></span><?php if($visibility!=='public'):?><span class="badge"><?=h(ucfirst($visibility))?></span><?php endif?><?php if($published!==''):?><time><?=h($published)?></time><?php endif?></div>
  </div>
  <?php if($commentary!==''):?><p class="annotationCaption"><?=nl2br(h($commentary))?></p><?php endif?>
  <?php if($selected!==''):?><blockquote class="annotationQuote"><?=h(mb_substr($selected,0,1200))?></blockquote><?php endif?>
  <?php if($snapshot):?><img class="snapshot annotationPostImage" src="<?=h($snapshot)?>" alt="Preserved annotation capture"><?php endif?>
  <?php if($media):?><?php if(($a['capture_type']??'')==='video_clip'):?><video class="webMedia" controls src="<?=h($media)?>"></video><?php else:?><audio class="wideAudio" controls src="<?=h($media)?>"></audio><?php endif?><?php endif?>
  <?php if($showSource):?><details class="annotationSourceDetails">
    <summary><span class="annotationSourceIcon" aria-hidden="true">↗</span><span><small>Source content</small><strong><?=h($sourceLabel)?></strong></span><span class="annotationSourceChevron" aria-hidden="true">⌄</span></summary>
    <div class="annotationSourceBody">
      <?php if($canonical!==''):?><div class="sourceUrl"><?=h($canonical)?></div><?php endif?>
      <?php if($captureVersion||$currentVersion||$integrityLabel!==''):?><div class="sourceStats"><?php if($captureVersion):?><span>Captured <strong>v<?=h((string)$captureVersion)?></strong></span><?php endif?><?php if($currentVersion):?><span>Current <strong>v<?=h((string)$currentVersion)?></strong></span><?php endif?><?php if($integrityLabel!==''):?><span>Integrity <strong><?=h($integrityLabel)?></strong></span><?php endif?></div><?php endif?>
      <div class="annotationSourceActions"><?php if($sourceId!==''):?><a href="/source.php?id=<?=h($sourceId)?>">Source page</a><?php endif?><?php if(!empty($a['source_changed'])&&$sourceId!==''&&!empty($a['source_version_id'])&&!empty($a['current_source_version_id'])):?><a href="/source-compare.php?id=<?=h($sourceId)?>&from=<?=h((string)$a['source_version_id'])?>&to=<?=h((string)$a['current_source_version_id'])?>">Compare versions</a><?php endif?></div>
    </div>
  </details><?php endif?>
  <div class="annotationSocialBar">
    <?php if($viewer):?><button type="button" class="annotationSocialAction <?=$liked?'active':''?>" data-web-annotation-action="like" data-id="<?=h($id)?>"><span aria-hidden="true">♥</span> <span>Like</span> <strong data-like-count><?=h((string)$likes)?></strong></button><?php else:?><a class="annotationSocialAction" href="/login.php"><span aria-hidden="true">♡</span> Like <strong><?=h((string)$likes)?></strong></a><?php endif?>
    <a class="annotationSocialAction" href="/annotation.php?id=<?=h($id)?>&comments=1#discussion"><span aria-hidden="true">💬</span> Comments <strong><?=h((string)$comments)?></strong></a>
    <?php if($viewer):?><button type="button" class="annotationSocialAction <?=$saved?'active':''?>" data-web-annotation-action="save" data-id="<?=h($id)?>"><span aria-hidden="true">🔖</span> <span data-save-label><?=$saved?'Saved':'Save'?></span></button><?php endif?>
    <a class="annotationSocialAction annotationOpenAction" href="/annotation.php?id=<?=h($id)?>">Open</a>
  </div>
</article>
<?php return (string)ob_get_clean();
}

function annotation_ui_scripts(?array $viewer): string {
    if(!$viewer)return '';
    return '<script>window.ANNOTATED_CSRF='.json_encode(csrf_token()).';</script><script src="/assets/js/annotation-cards.js"></script>';
}
