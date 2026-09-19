<?php
declare(strict_types=1);

function annotation_type_label(array $a): string {
    $labels=['quote'=>'Quote','note'=>'Note','image'=>'Image','image_quote'=>'Image + Quote','video'=>'Video','music'=>'Music','podcast'=>'Podcast','audio'=>'Audio','annotation'=>'Annotation'];
    $postType=(string)($a['post_type']??'');
    if(isset($labels[$postType]))return $labels[$postType];
    if(function_exists('feed_annotation_post_type'))return $labels[feed_annotation_post_type($a)]??'Annotation';
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
    $showAuthor=$options['show_author']??true;$showSource=$options['show_source']??true;$showOpen=$options['show_open']??true;$commentsHref=(string)($options['comments_href']??('/annotation.php?id='.rawurlencode((string)($a['public_id']??'')).'&comments=1#discussion'));$extraActions=(string)($options['extra_actions_html']??'');
    $id=(string)($a['public_id']??'');$author=(string)($a['display_name']??'');$username=(string)($a['username']??'');
    $postType=(string)($a['post_type']??'');if($postType===''&&function_exists('feed_annotation_post_type'))$postType=feed_annotation_post_type($a);if($postType==='')$postType='annotation';
    $type=annotation_type_label($a);$sourceLabel=annotation_source_label($a);$snapshot=annotation_snapshot_url($a);$media=annotation_media_url($a);
    $comments=(int)($a['comment_count']??0);$likes=(int)($a['like_count']??0);$liked=!empty($a['viewer_liked']);$saved=!empty($a['is_saved']);
    $published=(string)($a['published_at']??'');$visibility=(string)($a['visibility']??'public');$publicPost=$visibility==='public';
    $commentary=trim((string)($a['text_commentary']??''));$selected=trim((string)($a['selected_text']??''));
    // Evidence-first feed rendering: when a preserved screenshot exists, it is the visual post.
    // Selected text remains attached as a machine-readable transcript and only renders as a fallback
    // when no screenshot is available.
    $showSnapshot=$snapshot!==null&&in_array($postType,['quote','image','image_quote','annotation'],true);
    $showSelected=$selected!==''&&!$showSnapshot&&(in_array($postType,['quote','image_quote','annotation'],true));
    $showMedia=$media!==null&&(in_array($postType,['video','podcast','music','audio'],true)||$postType==='annotation');
    $mediaTranscript=trim((string)($a['transcript_edited']??''));if($mediaTranscript==='')$mediaTranscript=trim((string)($a['transcript_text']??''));if($mediaTranscript==='')$mediaTranscript=trim((string)($a['transcript_raw']??''));
    $transcriptText=$selected!==''&&$showSnapshot?$selected:$mediaTranscript;
    $transcriptLabel=$selected!==''&&$showSnapshot?'Captured text transcript':'Media transcript';
    $hasTranscript=$transcriptText!=='';
    $commentaryLong=mb_strlen($commentary)>420||substr_count($commentary,"\n")>5;
    $canonical=(string)($a['canonical_url']??'');$sourceId=(string)($a['source_public_id']??'');
    $captureVersion=(int)($a['capture_version_number']??0);$currentVersion=(int)($a['current_version_number']??0);
    $integrity=is_array($a['integrity']??null)?$a['integrity']:null;$integrityLabel=(string)($integrity['label']??($a['source_status']??''));
    $postUrl='/annotation.php?id='.rawurlencode($id);
    ob_start();?>
<article class="card annotationPost" data-annotation-id="<?=h($id)?>">
  <div class="annotationPostHead">
    <div class="annotationPostIdentity">
      <?php if($showAuthor&&$author!==''):?><?php if($username!==''):?><a href="<?=h(profile_path($username))?>" class="annotationAuthor"><?=app_shell_avatar(['display_name'=>$author,'profile_image_url'=>$a['profile_image_url']??null],'avatarSm')?><span><strong><?=h($author)?></strong><small>@<?=h($username)?></small></span></a><?php else:?><strong><?=h($author)?></strong><?php endif?><?php endif?>
    </div>
    <div class="annotationPostHeadRight">
      <div class="annotationPostMeta"><span class="annotationTypeBadge"><?=h($type)?></span><?php if($visibility!=='public'):?><span class="badge"><?=h(ucfirst($visibility))?></span><?php endif?><?php if($published!==''):?><time><?=h($published)?></time><?php endif?></div>
      <details class="annotationHeaderMenu">
        <summary aria-label="Post options">•••</summary>
        <div class="annotationHeaderMenuPanel">
          <?php if($showOpen):?><a href="<?=h($postUrl)?>">Open post</a><?php endif?>
          <button type="button" data-copy-annotation-link data-url="<?=h($postUrl)?>">Copy link</button>
          <?php if($hasTranscript):?><button type="button" data-annotation-transcript-toggle aria-controls="annotation-transcript-<?=h($id)?>" aria-expanded="false">Show transcript</button><?php endif?>
          <?php if($publicPost):?><a href="/file-a-claim.php?id=<?=h($id)?>">File a claim</a><a href="/report.php?type=annotation&id=<?=h($id)?>">Report post</a><?php endif?>
          <?=$extraActions?>
        </div>
      </details>
    </div>
  </div>
  <?php if($commentary!==''):?><div class="annotationCaptionWrap"><p class="annotationCaption <?=$commentaryLong?'is-collapsed':''?>" <?=$commentaryLong?'data-collapsible="1"':''?>><?=nl2br(h($commentary))?></p><?php if($commentaryLong):?><button type="button" class="annotationReadMore" data-annotation-expand aria-expanded="false">Read more</button><?php endif?></div><?php endif?>
  <?php if($showSelected):?><blockquote class="annotationQuote"><?=h(mb_substr($selected,0,1200))?></blockquote><?php endif?>
  <?php if($showSnapshot):?><img class="snapshot annotationPostImage" src="<?=h((string)$snapshot)?>" alt="Preserved annotation capture"><?php endif?>
  <?php if($hasTranscript):?><section class="annotationTranscriptPanel" id="annotation-transcript-<?=h($id)?>" hidden><div class="annotationTranscriptLabel"><?=h($transcriptLabel)?></div><p><?=nl2br(h($transcriptText))?></p></section><?php endif?>
  <?php if($showMedia):?><?php if(($a['capture_type']??'')==='video_clip'):?><video class="webMedia" controls src="<?=h((string)$media)?>"></video><?php else:?><audio class="wideAudio" controls src="<?=h((string)$media)?>"></audio><?php endif?><?php endif?>
  <?php if(!empty($a['audio_url'])):?><div class="annotationAudioCommentary"><span class="meta">Audio commentary</span><audio class="wideAudio" controls src="<?=h((string)$a['audio_url'])?>"></audio></div><?php endif?>
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
    <a class="annotationSocialAction" href="<?=h($commentsHref)?>"><span aria-hidden="true">💬</span> Comments <strong><?=h((string)$comments)?></strong></a>
    <?php if($viewer):?><button type="button" class="annotationSocialAction <?=$saved?'active':''?>" data-web-annotation-action="save" data-id="<?=h($id)?>"><span aria-hidden="true">🔖</span> <span data-save-label><?=$saved?'Saved':'Save'?></span></button><button type="button" class="annotationSocialAction" data-web-annotation-action="research" data-id="<?=h($id)?>"><span aria-hidden="true">▣</span> <span data-research-label>Research</span></button><?php endif?>
  </div>
</article>
<?php return (string)ob_get_clean();
}

function annotation_ui_scripts(?array $viewer): string {
    $csrf=$viewer?csrf_token():'';
    return '<script>window.ANNOTATED_CSRF='.json_encode($csrf).';</script><script src="/assets/js/annotation-cards.js?v=0.11.0"></script>';
}
