<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);
header('Cache-Control: private, no-store');
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
        <a class="button" href="/downloads/Annotated-Chrome-Extension.zip" download>Download Chrome Extension</a>
        <a class="button secondary" href="/connected-accounts.php">Manage extension sessions</a>
      </div>
      <p class="meta">Version 0.9.0 · Manifest V3 · Chrome 116+</p>
    </section>
    <aside class="card">
      <span class="eyebrow">CONNECTED PRODUCT</span>
      <h2>Website + sidebar</h2>
      <p>Your website account, Teams, Research projects, follows, notifications, saved items, Live rooms, and extension captures all use the same Annotated account and permission model.</p>
    </aside>
  </div>

  <section class="card">
    <h2>Install from ZIP</h2>
    <div class="extensionSteps">
      <div><p><strong>Download and unzip the extension.</strong><br><span class="meta">Keep the extracted folder somewhere Chrome can continue to access it.</span></p></div>
      <div><p><strong>Open <code>chrome://extensions</code>.</strong><br><span class="meta">Turn on Developer mode, then choose <em>Load unpacked</em>.</span></p></div>
      <div><p><strong>Select the extracted Annotated extension folder.</strong><br><span class="meta">The folder should contain <code>manifest.json</code> at its root.</span></p></div>
      <div><p><strong>Log in from the sidebar.</strong><br><span class="meta">Open Extension Options once to set this site's URL. Then open the sidebar and use Log in or Create account. It uses the same Annotated account system as the website.</span></p></div>
    </div>
  </section>
</main>
</body>
</html>
