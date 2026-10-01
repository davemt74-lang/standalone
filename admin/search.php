<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$admin=require_admin($pdo);header('Cache-Control: private, no-store');
$q=mb_substr(trim((string)($_GET['q']??'')),0,160);$error='';$rows=[];
$allowed=[
  'Account'=>'admin.accounts.view','User'=>'admin.accounts.view',
  'Invoice'=>'admin.billing.view','Subscription'=>'admin.billing.view','Promotion'=>'admin.billing.view',
  'Admin action'=>'admin.actions.view','Support case'=>'admin.support.view'
];
$canView=fn(string $cap):bool=>!function_exists('admin_access_ready')||!admin_access_ready($pdo)||admin_access_has_capability($pdo,$admin,$cap);
if($q!==''&&mb_strlen($q)>=2){
  try {
    $matches=admin_ops_global_search($pdo,$q,70,$admin);
    foreach($matches as $row){$cap=$allowed[(string)($row['type']??'')]??null;
      if($cap!==null&&$canView($cap))$rows[]=$row;
    }
  }catch(Throwable $e){$error='Search could not be completed. Please try again.';}
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Search · Annotated</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><?=admin_ui_sidebar('dashboard')?>
<main class="panel"><header class="pageTitle"><span class="eyebrow">ADMIN SEARCH</span><h1>Search operations</h1><p>Find accounts, users, billing items, actions and support cases you are permitted to view.</p></header>
<?php if($error):?><div class="error" role="alert"><?=h($error)?></div><?php endif?>
<section class="card"><form action="/admin/search.php" method="get" class="stack" role="search"><label for="adminSearchQuery">Search Admin</label><input id="adminSearchQuery" name="q" type="search" maxlength="160" minlength="2" required value="<?=h($q)?>" placeholder="Account, user, invoice, action or support case"><button class="button" type="submit">Search</button></form></section>
<?php if($q!==''):?><section class="card"><h2>Results for <?=h($q)?></h2><?php if(!$rows):?><p class="meta">No matching results visible to your Admin role.</p><?php else:?><div class="stack">
<?php foreach($rows as $row):?><a class="card" href="<?=h((string)$row['url'])?>"><span class="eyebrow"><?=h((string)$row['type'])?></span><h3><?=h((string)$row['title'])?></h3><p><?=h((string)$row['subtitle'])?></p></a><?php endforeach?>
</div><?php endif?></section><?php endif?></main></body></html>
