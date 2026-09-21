<?php
declare(strict_types=1);
require_once __DIR__.'/cognitive-feed.php';

function cognitive_feed_ui_icon(string $type): string {
    return match($type){
      'pending_agent_action'=>'✓',
      'research_conflict','related_conflict'=>'!',
      'source_change'=>'↻',
      'research_gap'=>'?',
      'new_evidence'=>'+',
      'research_opportunity'=>'✦',
      'research_task'=>'→',
      'team_activity'=>'◌',
      'related_research'=>'↔',
      'workspace_activity'=>'◎',
      'recent_change'=>'•',
      default=>'•'
    };
}

function cognitive_feed_ui_reason(string $type): string {
    return match($type){
      'pending_agent_action'=>'Pending confirmation',
      'research_conflict'=>'Conflicting Research evidence',
      'related_conflict'=>'Related conflicting evidence',
      'source_change'=>'Source monitoring',
      'research_gap'=>'Research gap',
      'new_evidence'=>'New project evidence',
      'research_opportunity'=>'Research opportunity',
      'research_task'=>'Open Research work',
      'team_activity'=>'Unread Team activity',
      'related_research'=>'Evidence relationship',
      'workspace_activity'=>'Workspace activity',
      'recent_change'=>'Recent workspace change',
      default=>'Cognitive Feed'
    };
}

function cognitive_feed_ui_card(array $item): string {
    $key=(string)($item['key']??'');$type=(string)($item['type']??'observation');$priority=(string)($item['priority']??'medium');
    $meta=is_array($item['meta']??null)?$item['meta']:[];$actions=is_array($item['actions']??null)?$item['actions']:[];$watchTarget=function_exists('proactive_observation_watch_target')?proactive_observation_watch_target($item):null;
    ob_start();?>
<article class="cognitiveCard cognitiveCard-<?=h($type)?>" data-cognitive-card data-key="<?=h($key)?>" data-type="<?=h($type)?>">
  <header class="cognitiveCardHeader">
    <span class="cognitiveCardIcon" aria-hidden="true"><?=h(cognitive_feed_ui_icon($type))?></span>
    <div><small><?=h(cognitive_feed_ui_reason($type))?></small><strong><?=h((string)($item['title']??'Annotated update'))?></strong></div>
    <button type="button" class="cognitiveDismiss" data-cognitive-dismiss aria-label="Hide this item" title="Hide this item">×</button>
  </header>
  <?php if(trim((string)($item['body']??''))!==''):?><p class="cognitiveCardBody"><?=nl2br(h((string)$item['body']))?></p><?php endif?>
  <?php if($meta):?><div class="cognitiveCardMeta">
    <?php foreach($meta as $label=>$value):if($value===null||$value===''||$value===false)continue;if(is_float($value)&&str_contains((string)$label,'confidence'))$value=number_format($value*100,0).'%';elseif(is_bool($value))$value=$value?'Yes':'No';?>
      <span><small><?=h(ucfirst(str_replace('_',' ',(string)$label)))?></small><?=h((string)$value)?></span>
    <?php endforeach?>
    <?php if($priority==='high'):?><span class="cognitivePriority high">High priority</span><?php endif?>
  </div><?php endif?>
  <?php if($actions||$watchTarget):?><footer class="cognitiveCardActions">
    <?php if($watchTarget):?><button type="button" data-proactive-watch data-watch-type="<?=h((string)$watchTarget['type'])?>" data-watch-id="<?=h((string)$watchTarget['public_id'])?>">Watch</button><?php endif?>
    <?php foreach($actions as $action):?>
      <?php if(($action['type']??'')==='link'&&!empty($action['href'])):?><a href="<?=h((string)$action['href'])?>"><?=h((string)($action['label']??'Open'))?></a>
      <?php elseif(($action['type']??'')==='agent'):?><button type="button" data-cognitive-agent data-prompt="<?=h((string)($action['prompt']??''))?>" data-context="<?=h(json_encode($action['context']??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))?>"><?=h((string)($action['label']??'Ask Agent'))?></button>
      <?php endif?>
    <?php endforeach?>
  </footer><?php endif?>
</article>
<?php return (string)ob_get_clean();
}

function cognitive_feed_ui_sections(array $feed): string {
    $sections=is_array($feed['sections']??null)?$feed['sections']:[];
    ob_start();
    foreach($sections as $section):?>
<section class="cognitiveSection cognitiveSection-<?=h((string)$section['key'])?>" data-cognitive-section>
  <div class="cognitiveSectionHead"><div><span class="eyebrow"><?=h(strtoupper((string)$section['label']))?></span><h2><?=h((string)$section['label'])?></h2></div><p><?=h((string)$section['description'])?></p></div>
  <div class="cognitiveCardStack"><?php foreach((array)$section['items'] as $item)echo cognitive_feed_ui_card($item);?></div>
</section>
<?php endforeach;
    return (string)ob_get_clean();
}
