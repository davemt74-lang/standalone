<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/extension-releases.php';
$admin=require_admin($pdo);
header('Cache-Control: private, no-store');
if(!extension_releases_ready($pdo)){http_response_code(503);exit('Chrome Extension Releases requires migration 127. Run the site upgrade.');}
admin_access_assert_capability($pdo,$admin,'admin.platform.view');
$canUpload=admin_access_has_capability($pdo,$admin,'admin.platform.manage');
$canPublish=$canUpload&&admin_access_has_capability($pdo,$admin,'admin.platform.release');
$success='';$error='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    require_csrf();
    try{
        $op=(string)($_POST['op']??'');
        if($op==='upload'){
            if(!$canUpload)throw new RuntimeException('You cannot upload extension builds.');
            $created=extension_release_upload($pdo,$config,$admin,(array)($_FILES['artifact']??[]),$_POST);
            $success='Version '.$created['version'].' securely uploaded to '.$created['channel'].'. Publish explicitly when ready.';
        }elseif($op==='publish'){
            if(!$canPublish)throw new RuntimeException('You cannot publish or restore extension versions.');
            $created=extension_release_publish($pdo,$admin,(string)($_POST['release_id']??''),(string)($_POST['reason']??''));
            $success='Version '.$created['version'].' is now active in '.$created['channel'].'.';
        }else throw new InvalidArgumentException('Unknown extension release operation.');
    }catch(Throwable $e){$error=$e->getMessage();}
}
$releases=extension_release_list($pdo);
$stable=extension_release_active($pdo,'stable');
$beta=extension_release_active($pdo,'beta');
$events=extension_release_events($pdo,30);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Chrome Extension Releases · Annotated Admin</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><?=admin_ui_sidebar('extension_releases')?>
<main class="panel"><div class="pageTitle"><span class="eyebrow">PLATFORM · CLIENT DISTRIBUTION</span><h1>Chrome Extension Releases</h1><p>Validate and publish immutable extension packages, manage stable and beta channels, and inspect release evidence.</p></div>
<?php if($success):?><div class="success"><?=h($success)?></div><?php endif?>
<?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
<section class="adminAccountSummaryGrid">
<article class="card"><span class="meta">Stable download</span><h2><?=h((string)($stable['version']??'Bundled fallback'))?></h2><p><?= $stable?'Published '.h((string)$stable['published_at']):'No managed stable release published.' ?></p></article>
<article class="card"><span class="meta">Beta channel</span><h2><?=h((string)($beta['version']??'Not published'))?></h2><p><?= $beta?'Published '.h((string)$beta['published_at']):'Private until a beta build is published.' ?></p></article>
<article class="card"><span class="meta">Stored releases</span><h2><?=count($releases)?></h2><p>Uploads are immutable and stay privately stored. Publication changes only the active channel pointer.</p></article>
</section>
<div class="inlineActions"><a class="button secondary" href="/chrome-extension.php">View user download page</a><a class="button secondary" href="/downloads/Annotated-Chrome-Extension.zip">Bundled deployment ZIP</a></div>
<?php if($canUpload):?><section class="card"><span class="eyebrow">UPLOAD</span><h2>Upload a release candidate</h2>
<p>Upload an actual Annotated Manifest V3 extension ZIP with <code>manifest.json</code> at its root. The version, Chrome compatibility, permissions and SHA-256 checksum are extracted automatically. Uploading never publishes the package.</p>
<form method="post" enctype="multipart/form-data" class="stack">
<input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="op" value="upload">
<label>Extension ZIP (30 MB maximum)<input type="file" name="artifact" accept=".zip,application/zip" required></label>
<div class="researchAgentEditSplit"><label>Release channel<select name="channel"><option value="stable">Stable</option><option value="beta">Beta</option></select></label>
<label>Build date<input type="date" name="build_date" value="<?=h(gmdate('Y-m-d'))?>"></label></div>
<label>Developer / publisher<input name="developer_name" maxlength="190" value="Annotated" required></label>
<label>Developer website (HTTPS)<input name="developer_url" type="url" maxlength="512" placeholder="https://..."></label>
<label>Release notes<textarea name="release_notes" rows="6" maxlength="16000" required placeholder="What's new, changes, fixes, compatibility considerations"></textarea></label>
<button>Validate and upload ZIP</button></form><p class="meta">ZIP validation rejects traversal paths, symlinks, oversized expanded content, missing runtime files, and invalid version metadata. PHP's ZIP extension and adequate upload limits are required.</p>
</section><?php endif?>
<section class="card"><span class="eyebrow">REGISTRY</span><h2>Version history &amp; publication</h2>
<?php if(!$releases):?><p>No managed releases yet. Users continue receiving the bundled ZIP until a stable release is explicitly published.</p><?php endif?>
<?php foreach($releases as $r):
    $manifest=json_decode((string)$r['manifest_json'],true)?:[];
    $active=(string)($r['active_channel']??'')===(string)$r['channel'];
    $published=!empty($r['published_at']);?>
<article class="card" style="margin-bottom:18px">
<div class="inlineActions"><div><span class="eyebrow"><?=h(strtoupper((string)$r['channel']))?> <?= $active?'· ACTIVE':'· '.($published?'PREVIOUS RELEASE':'UNPUBLISHED') ?></span>
<h3>Annotated <?=h((string)$r['version'])?></h3><div class="meta">Uploaded <?=h((string)$r['created_at'])?><?php if($published):?> · First published <?=h((string)$r['published_at'])?><?php endif?></div></div>
<?php if($published):?><a class="button secondary" href="/extension-download.php?release=<?=rawurlencode((string)$r['public_id'])?>">Download this version</a><?php endif?></div>
<p><?=nl2br(h((string)$r['release_notes']))?></p>
<div class="researchAgentEditSplit">
<div><strong>Developer</strong><p><?=h((string)$r['developer_name'])?><?php if($r['developer_url']):?> · <a href="<?=h((string)$r['developer_url'])?>" target="_blank" rel="noopener noreferrer">Website</a><?php endif?></p></div>
<div><strong>Build date</strong><p><?=h((string)($r['build_date']??'Not supplied'))?></p></div>
<div><strong>Manifest</strong><p>V<?=h((string)($manifest['manifest_version']??'?'))?> · Chrome <?=h((string)($manifest['minimum_chrome_version']?:'Unspecified'))?>+</p></div>
<div><strong>Artifact</strong><p><?=number_format(((int)$r['size_bytes'])/1024,1)?> KB</p></div></div>
<details><summary>Technical manifest &amp; SHA-256</summary>
<p><strong>Title:</strong> <?=h((string)($manifest['name']??'Annotated'))?></p>
<p><strong>Description:</strong> <?=h((string)($manifest['description']??''))?></p>
<p><strong>Permissions:</strong> <?=h(implode(', ',(array)($manifest['permissions']??[]))?:'None declared')?></p>
<p><strong>Host permissions:</strong> <?=h(implode(', ',(array)($manifest['host_permissions']??[]))?:'None declared')?></p>
<p><strong>SHA-256:</strong> <code style="overflow-wrap:anywhere"><?=h((string)$r['sha256'])?></code></p>
<p><strong>Uploaded by:</strong> <?=h((string)$r['uploader_name'])?></p></details>
<?php if($canPublish&&!$active):?><form method="post" class="inlineForm"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="op" value="publish"><input type="hidden" name="release_id" value="<?=h((string)$r['public_id'])?>">
<label>Publication / rollback reason<input name="reason" required maxlength="1000" placeholder="Release validation complete / rollback reason"></label>
<button><?= $published?'Restore previous version':'Publish to '.h((string)$r['channel']) ?></button></form><?php endif?>
</article><?php endforeach?></section>
<section class="card"><span class="eyebrow">AUDIT TRAIL</span><h2>Recent extension release activity</h2>
<?php if(!$events):?><p>No recorded release activity.</p><?php endif?>
<?php foreach($events as $e):?><div style="padding:12px 0;border-bottom:1px solid #e1e4e8"><strong><?=h(ucfirst((string)$e['event_type']))?> · v<?=h((string)$e['version'])?></strong><div class="meta"><?=h((string)$e['channel'])?> · <?=h((string)$e['actor_name'])?> · <?=h((string)$e['created_at'])?><?php if($e['previous_version']):?> · previous <?=h((string)$e['previous_version'])?><?php endif?></div><p><?=h((string)$e['reason'])?></p></div><?php endforeach?>
</section></main></body></html>
