<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';require_once __DIR__.'/app/ai.php';
$u=current_user($pdo);$type=(string)($_GET['type']??$_POST['type']??'annotation');$id=(string)($_GET['id']??$_POST['id']??'');$target=moderation_target($pdo,$type,$id,$u);
if(!$target){http_response_code(404);exit('Content not found.');}
$done=false;$error='';$reportPublic='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$reason=trim((string)($_POST['reason']??''));$description=trim((string)($_POST['description']??''));
    $limit=rate_limit_page_message($pdo,'report-ip',rate_limit_ip_subject(),5,3600);if(!$limit&&$u)$limit=rate_limit_page_message($pdo,'report-user','user:'.$u['id'],10,3600);
    if($limit)$error=$limit;else try{$r=moderation_report_create($pdo,$u,$type,$id,$reason,$description);$reportPublic=$r['public_id'];if($r['created']){try{$model=ai_setting_model_id($pdo,'moderation');if($model)ai_queue_job($pdo,$u['id']??null,'moderation_report_triage',$model,'moderation_report',$reportPublic,[],2);}catch(Throwable $e){}}$done=true;}catch(Throwable $e){$error=$e->getMessage();}
}
$returnUrl=match($type){'annotation'=>'/annotation.php?id='.rawurlencode($id),'source'=>'/source.php?id='.rawurlencode($id),'user'=>'/profile.php?u='.(function()use($pdo,$id){$q=$pdo->prepare('SELECT username FROM users WHERE public_id=?');$q->execute([$id]);return rawurlencode((string)($q->fetchColumn()?:''));})(),default=>'/home.php'};
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Report <?=h(str_replace('_',' ',$type))?> · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head><body><main class="panel narrow"><span class="eyebrow">TRUST & SAFETY</span><h1>Report <?=h(str_replace('_',' ',$type))?></h1><p>Reports are reviewed by Annotated moderators. Reporting does not automatically remove content.</p>
<?php if($done):?><div class="success">Your report has been submitted for review.</div><?php if($u&&$reportPublic):?><p><a href="/report-status.php?id=<?=h($reportPublic)?>">Track this report</a></p><?php endif?><p><a href="<?=h($returnUrl)?>">Return</a></p>
<?php else:?><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="type" value="<?=h($type)?>"><input type="hidden" name="id" value="<?=h($id)?>">
<label>Reason<select name="reason" required><option value="">Choose…</option><option value="spam">Spam</option><option value="harassment">Harassment</option><option value="impersonation">Impersonation</option><option value="misleading_context">Misleading or manipulated context</option><option value="privacy">Privacy concern</option><option value="illegal_content">Illegal content concern</option><option value="other">Other</option></select></label>
<label>Details<textarea name="description" rows="6" maxlength="5000"></textarea></label><button>Submit report</button></form>
<?php if($type==='annotation'):?><p class="meta">Copyright, attribution, ownership, and rights concerns should use <a href="/file-a-claim.php?id=<?=h($id)?>">File a claim</a>.</p><?php endif?><?php endif?></main></body></html>