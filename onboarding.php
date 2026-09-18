<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');$success='';$error='';
onboarding_mark_seen($pdo,(int)$u['id']);
if($_SERVER['REQUEST_METHOD']==='POST'){require_csrf();$action=(string)($_POST['action']??'');try{if($action==='dismiss'){onboarding_dismiss($pdo,(int)$u['id']);header('Location:/home.php');exit;}if($action==='refresh')$success='Checklist refreshed.';}catch(Throwable $e){$error=$e->getMessage();}}
$status=onboarding_status($pdo,$u,true);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Get started · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<header class="topbar"><a class="brand" href="/home.php">Annotated</a><nav><a href="/home.php">Home</a><a href="/explore.php">Explore</a><a href="/research.php">Research</a><a href="/settings.php">Settings</a></nav></header>
<main class="panel onboardingPage"><div class="pageTitle"><span class="eyebrow">V1.1 FIRST RUN</span><h1>Set up Annotated</h1><p>Your checklist is based on real account activity. Finish it in any order; completed steps update automatically.</p></div>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<div class="onboardingProgress"><strong><?=h((string)$status['completed_count'])?> / <?=h((string)$status['total_count'])?> complete</strong><div class="progressTrack"><span style="width:<?=h((string)round(($status['completed_count']/$status['total_count'])*100))?>%"></span></div></div>
<div class="onboardingSteps">
<?php $n=0;foreach($status['steps'] as $key=>$step):$n++;?><article class="card onboardingStep <?=$step['complete']?'done':''?>" id="<?=h($key)?>"><div class="stepNumber"><?=$step['complete']?'✓':$n?></div><div><h2><?=h($step['label'])?></h2><p><?=h($step['detail'])?></p>
<?php if(!$step['complete']):?><?php if($key==='extension'):?><div class="stack"><p class="meta">Install/load the Annotated extension, set this server URL in Extension Settings, then click <strong>Connect</strong> in the sidebar. The extension receives a revocable token—not your password or OAuth credentials.</p><a class="button" href="/connected-accounts.php">View extension sessions</a></div><?php else:?><a class="button" href="<?=h($step['url'])?>">Continue</a><?php endif?><?php else:?><span class="badge">Complete</span><?php endif?></div></article><?php endforeach?>
</div>
<?php if($status['complete']):?><div class="success"><strong>You’re ready.</strong> The full capture → publish → follow → Research loop is connected.</div><a class="button" href="/home.php">Open Annotated</a><?php else:?><div class="inlineActions"><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="refresh"><button>Refresh checklist</button></form><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="dismiss"><button class="button secondary">Skip for now</button></form></div><?php endif?>
</main></body></html>