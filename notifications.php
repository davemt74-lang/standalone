<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');
$error='';$success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='read'){$id=(string)($_POST['notification_id']??'');notification_mark_read($pdo,$u,$id);$success='Notification marked read.';}
        elseif($action==='read_all'){notification_mark_read($pdo,$u,null);$success='All notifications marked read.';}
        elseif($action==='archive'){$id=(string)($_POST['notification_id']??'');if(!notification_archive($pdo,$u,$id))throw new RuntimeException('Notification not found.');$success='Notification archived.';}
        elseif($action==='mute'){notification_mute_set($pdo,$u,(string)($_POST['scope_type']??''),(string)($_POST['scope_id']??''),(string)($_POST['category']??'all'),true);$success='Similar notifications muted.';}
    }catch(Throwable $e){$error=$e->getMessage();}
}
$category=trim((string)($_GET['category']??''));$items=notification_rows($pdo,$u,250,false);
if($category!=='')$items=array_values(array_filter($items,fn($n)=>(string)$n['category']===$category));
$unread=notification_unread_count($pdo,$u);
$categories=['social'=>'Social','comments'=>'Comments','follows'=>'Follows','research'=>'Research','team'=>'Team','live'=>'Live','sources'=>'Source changes','claims'=>'Claims','moderation'=>'Moderation'];
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Notifications · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<header class="topbar"><a class="brand" href="/home.php">Annotated</a><nav><a href="/home.php">Home</a><a href="/explore.php">Explore</a><a href="/research.php">Research</a><a href="/settings.php#notifications">Settings</a></nav></header>
<main class="panel notificationCenter"><div class="pageTitle"><span class="eyebrow">NOTIFICATIONS</span><h1>What changed</h1><p><?=h((string)$unread)?> unread · grouped by conversation, source, claim, or moderation case.</p></div>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<div class="notificationToolbar"><div class="filterPills"><a class="<?=$category===''?'active':''?>" href="/notifications.php">All</a><?php foreach($categories as $key=>$label):?><a class="<?=$category===$key?'active':''?>" href="/notifications.php?category=<?=h($key)?>"><?=h($label)?></a><?php endforeach?></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="read_all"><button class="button secondary">Mark all read</button></form></div>
<?php if(!$items):?><div class="card empty">No notifications in this view.</div><?php endif?>
<?php foreach($items as $n):$ctx=$n['context']??[];$scopeType=!empty($ctx['source_public_id'])?'source':(!empty($ctx['conversation_public_id'])?'conversation':null);$scopeId=$ctx['source_public_id']??$ctx['conversation_public_id']??null;?><article class="card notificationCard <?=$n['read_at']?'':'unreadCard'?>">
<div class="notificationTitle"><div><span class="badge"><?=h(ucfirst((string)$n['category']))?></span><strong><?=h(ucwords(str_replace('_',' ',(string)$n['notification_type'])))?></strong><?php if((int)$n['group_count']>1):?><span class="badge"><?=h((string)$n['group_count'])?> updates</span><?php endif?></div><span class="meta"><?=h((string)$n['created_at'])?></span></div>
<p><?=h((string)$n['body'])?></p><div class="inlineActions"><?php if($n['url']):?><a class="button" href="<?=h($n['url'])?>">Open</a><?php endif?>
<?php if(!$n['read_at']):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="read"><input type="hidden" name="notification_id" value="<?=h((string)$n['public_id'])?>"><button class="button secondary">Mark read</button></form><?php endif?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="notification_id" value="<?=h((string)$n['public_id'])?>"><button class="button secondary">Archive</button></form>
<?php if($scopeType&&$scopeId):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="mute"><input type="hidden" name="scope_type" value="<?=h($scopeType)?>"><input type="hidden" name="scope_id" value="<?=h((string)$scopeId)?>"><input type="hidden" name="category" value="<?=h((string)$n['category'])?>"><button class="button secondary">Mute similar</button></form><?php endif?></div></article><?php endforeach?>
</main></body></html>