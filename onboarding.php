<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');$success='';$error='';
onboarding_mark_seen($pdo,(int)$u['id']);
try{if(function_exists('research_agent_ensure_default'))research_agent_ensure_default($pdo,$u);}catch(Throwable $e){}
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='dismiss'){onboarding_dismiss($pdo,(int)$u['id']);header('Location:/home.php');exit;}
        if($action==='refresh')$success='Checklist refreshed from your current Annotated activity.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
$status=onboarding_status($pdo,$u,true);
$agents=function_exists('research_agent_list')?research_agent_list($pdo,$u,5):[];
$primaryAgent=$agents[0]??null;
$agentUrl=$primaryAgent?'/home.php?agent='.rawurlencode((string)$primaryAgent['conversation_public_id']):'/research.php';
$desktopUrl=$primaryAgent?$agentUrl.'&workspace=desktop':'/research.php';
$nextKey='';foreach($status['steps'] as $key=>$step)if(!$step['complete']){$nextKey=(string)$key;break;}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Get started · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="onboardingPage phase65Onboarding">
  <section class="onboardingHero">
    <span class="eyebrow">GET STARTED</span>
    <h1>Turn something you find into Research.</h1>
    <p>Annotated works as one loop: capture evidence, work with a Research Agent, save the result, and come back later without losing context.</p>
    <div class="phase65Flow" aria-label="Annotated workflow">
      <span><b>1</b> Capture</span><i>→</i><span><b>2</b> Research Agent</span><i>→</i><span><b>3</b> Save work</span><i>→</i><span><b>4</b> Continue later</span>
    </div>
    <div class="inlineActions">
      <a class="button" href="<?=h($agentUrl)?>"><?= $primaryAgent?'Open your Research Agent':'Open Research' ?></a>
      <a class="button secondary" href="<?=h($desktopUrl)?>">Open Research Desktop</a>
      <a class="button secondary" href="/chrome-extension.php">Browser capture</a>
    </div>
  </section>
  <?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
  <section class="onboardingProgressCard">
    <div class="onboardingProgressHead"><div><span class="eyebrow">YOUR SETUP</span><strong><?=h((string)$status['completed_count'])?> / <?=h((string)$status['total_count'])?> complete</strong></div><?php if($nextKey!==''):?><span>Next: <?=h((string)$status['steps'][$nextKey]['label'])?></span><?php endif?></div>
    <div class="progressTrack"><span style="width:<?=h((string)round(($status['completed_count']/max(1,$status['total_count']))*100))?>%"></span></div>
  </section>
  <div class="onboardingSteps">
  <?php $n=0;foreach($status['steps'] as $key=>$step):$n++;?><article class="card onboardingStep <?=$step['complete']?'done':''?>" id="<?=h($key)?>">
    <div class="stepNumber"><?=$step['complete']?'✓':$n?></div>
    <div><h2><?=h($step['label'])?></h2><p><?=h($step['detail'])?></p>
      <?php if(!$step['complete']):?>
        <?php if($key==='extension'):?><div class="inlineActions"><a class="button" href="/chrome-extension.php">Get the extension</a><a class="button secondary" href="/connected-accounts.php">Connection status</a></div>
        <?php elseif($key==='capture'&&$primaryAgent):?><div class="inlineActions"><a class="button" href="<?=h($desktopUrl)?>">Upload or create evidence</a><a class="button secondary" href="/chrome-extension.php">Annotate a webpage</a></div>
        <?php elseif(in_array($key,['agent','continue'],true)&&$primaryAgent):?><a class="button" href="<?=h($agentUrl)?>">Continue with <?=h((string)$primaryAgent['name'])?></a>
        <?php else:?><a class="button" href="<?=h((string)$step['url'])?>">Continue</a><?php endif?>
      <?php else:?><span class="badge">Complete</span><?php endif?>
    </div>
  </article><?php endforeach?>
  </div>
  <?php if($status['complete']):?><div class="success onboardingComplete"><strong>You’re ready.</strong> Capture → Research Agent → saved work → return later is connected.</div><a class="button" href="/home.php">Open Annotated Home</a>
  <?php else:?><div class="inlineActions onboardingFooterActions"><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="refresh"><button>Refresh checklist</button></form><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="dismiss"><button class="button secondary">Skip for now</button></form></div><?php endif?>
</main></body></html>