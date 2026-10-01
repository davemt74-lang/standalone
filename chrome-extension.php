<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
header('Cache-Control: private, no-store');
require_once __DIR__.'/app/extension-releases.php';
$active=extension_release_active($pdo,'stable');
$history=array_values(array_filter(extension_release_list($pdo,30),static fn($r)=>$r['channel']==='stable'&&!empty($r['published_at'])&&(!$active||(int)$r['id']!==(int)$active['id'])));
$manifest=$active?json_decode((string)$active['manifest_json'],true):json_decode((string)file_get_contents(__DIR__.'/extension/manifest.json'),true);
$manifest=is_array($manifest)?$manifest:[];
$version=$active?(string)$active['version']:ANNOTATED_EXTENSION_VERSION;
$download=$active?'/extension-download.php':'/downloads/Annotated-Chrome-Extension.zip';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Chrome Extension · Annotated</title>
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<main class="panel article">
  <div class="extensionHero">
    <section>
      <span class="eyebrow">ANNOTATED FOR CHROME</span>
      <h1>Annotate the live web from the page itself.</h1>
      <p class="commentary">Highlight text, capture screenshots, clip audio or video, join Live rooms, and send what you find directly into your Annotated research and teams.</p>
      <div class="inlineActions">
        <a class="button" href="<?=h($download)?>" download>Download Chrome Extension <?=h($version)?></a>
        <a class="button secondary" href="/connected-accounts.php">Manage extension sessions</a>
      </div>
      <p class="meta">Version <?=h($version)?> · Manifest V3 · Chrome <?=h((string)($manifest['minimum_chrome_version']??'116'))?>+ · <?= $active?'Admin-published release':'Bundled deployment version' ?></p>
    </section>
    <aside class="card">
      <span class="eyebrow">CONNECTED PRODUCT</span>
      <h2>Website + sidebar</h2>
      <p>Your website account, Teams, Research projects, follows, notifications, saved items, Live rooms, and extension captures all use the same Annotated account and permission model.</p>
    </aside>
  </div>

  <section class="card"><span class="eyebrow">VERSION &amp; TECHNICAL SPECIFICATIONS</span>
  <h2><?=h((string)($manifest['name']??'Annotated'))?> <?=h($version)?></h2>
  <p><?=h((string)($manifest['description']??'Annotated Chrome sidebar'))?></p>
  <?php if($active):?><p><strong>Developer:</strong> <?=h((string)$active['developer_name'])?><?php if($active['developer_url']):?> · <a href="<?=h((string)$active['developer_url'])?>" target="_blank" rel="noopener noreferrer">Developer website</a><?php endif?></p>
  <p><strong>Build date:</strong> <?=h((string)($active['build_date']??'Not supplied'))?> · <strong>First published:</strong> <?=h((string)$active['published_at'])?></p>
  <p><strong>Size:</strong> <?=number_format(((int)$active['size_bytes'])/1024,1)?> KB · <strong>SHA-256:</strong> <code style="overflow-wrap:anywhere"><?=h((string)$active['sha256'])?></code></p>
  <h3>What's new</h3><p><?=nl2br(h((string)$active['release_notes']))?></p>
  <?php else:?><p class="meta">This is the extension version bundled with your Annotated website deployment. An administrator has not published a separate managed download.</p><?php endif?>
  <p><strong>Browser:</strong> Chrome <?=h((string)($manifest['minimum_chrome_version']??'116'))?>+ · <strong>Manifest:</strong> V<?=h((string)($manifest['manifest_version']??'3'))?></p>
  <details><summary>Permission and compatibility details</summary><p><strong>Permissions:</strong> <?=h(implode(', ',(array)($manifest['permissions']??[]))?:'None declared')?></p>
  <p><strong>Host permissions:</strong> <?=h(implode(', ',(array)($manifest['host_permissions']??[]))?:'None declared')?></p>
  <p class="meta">Chrome's Load unpacked installation method does not automatically install subsequent ZIP releases. Download the updated release and load its extracted directory to upgrade.</p></details></section>
  <?php if($history):?><section class="card"><h2>Earlier stable versions</h2><p class="meta">Previously published ZIPs remain available to support manual rollback.</p>
  <?php foreach(array_slice($history,0,10) as $prior):?><div class="inlineActions" style="padding:12px 0;border-bottom:1px solid #e5e9eb"><div><strong>Version <?=h((string)$prior['version'])?></strong><div class="meta">Published <?=h((string)$prior['published_at'])?> · <?=h((string)$prior['developer_name'])?></div></div>
  <a class="button secondary" href="/extension-download.php?release=<?=rawurlencode((string)$prior['public_id'])?>">Download version</a></div><?php endforeach?></section><?php endif?>
  <section class="card">
    <h2>Install from ZIP</h2>
    <div class="extensionSteps">
      <div><p><strong>Download and unzip the extension.</strong><br><span class="meta">Keep the extracted folder somewhere Chrome can continue to access it.</span></p></div>
      <div><p><strong>Open <code>chrome://extensions</code>.</strong><br><span class="meta">Turn on Developer mode, then choose <em>Load unpacked</em>.</span></p></div>
      <div><p><strong>Select the extracted Annotated extension folder.</strong><br><span class="meta">The folder should contain <code>manifest.json</code> at its root.</span></p></div>
      <div><p><strong>Open this Annotated website, then open the sidebar.</strong><br><span class="meta">The extension detects the open Annotated installation automatically. Click Log in, enter your Annotated credentials, and you go straight to This Page. Create account is available from the login screen. Manual server setup in Extension Options is only a fallback.</span></p></div>
    </div>
  </section>
</main>
</body>
</html>
