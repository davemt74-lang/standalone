<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');$error='';$success='';$case=null;$assignment=null;$responses=[];
$casePublic=(string)($_GET['case']??$_POST['case_id']??'');
if($casePublic!==''){$case=sponsored_research_review_case_get($pdo,$casePublic);if($case)$assignment=sponsored_research_review_assignment_for($pdo,$u,$casePublic);}
if($_SERVER['REQUEST_METHOD']==='POST'){
 require_csrf();
 try{
   $op=(string)($_POST['op']??'');
   if($op==='respond'){
      $quality=[];foreach((array)($case['criteria']??[]) as $criterion){$key=(string)$criterion['key'];$quality[$key]=(int)($_POST['quality_'.$key]??0);}
      sponsored_research_review_respond($pdo,$u,$casePublic,(string)($_POST['recommendation']??''),$quality,(string)($_POST['comment']??''));
      $success='Review response submitted.';$assignment=sponsored_research_review_assignment_for($pdo,$u,$casePublic);
   }
 }catch(Throwable $e){$error=$e->getMessage();}
}
if($case)$responses=sponsored_research_review_responses($pdo,(int)$case['id']);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sponsored Research Review · Annotated</title><link rel="stylesheet" href="/assets/css/app.css"></head><body>
<main class="panel" style="max-width:1100px;margin:32px auto"><div class="pageTitle"><span class="eyebrow">SPONSORED RESEARCH REVIEW</span><h1><?=h((string)($case['submission_title']??'Review'))?></h1><?php if($case):?><p><?=h((string)$case['campaign_title'])?> · submission revision <?=h((string)$case['revision_number'])?></p><?php endif?></div>
<?php if($error):?><div class="error"><?=h($error)?></div><?php endif?><?php if($success):?><div class="success"><?=h($success)?></div><?php endif?>
<?php if(!$case):?><section class="card"><p>Review case not found.</p></section>
<?php elseif(!$assignment):?><section class="card"><p>You are not assigned to this review.</p></section>
<?php else:?><section class="card">
<div class="meta">Review mode: <?=!empty($assignment['blind_review'])?'Blind':'Named'?> · status <?=h((string)$case['status'])?></div>
<h2><?=h((string)$assignment['submission_title'])?></h2><p><?=nl2br(h((string)$assignment['submission_summary']))?></p>
<p><strong>Researcher:</strong> <?=h((string)$assignment['researcher_display_name'])?><?php if(!empty($assignment['researcher_username'])):?> (@<?=h((string)$assignment['researcher_username'])?>)<?php endif?></p>
<details><summary>Immutable submitted snapshot</summary><pre style="white-space:pre-wrap"><?=h((string)$assignment['snapshot_json'])?></pre></details>
<?php if($assignment['status']==='assigned'):?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="op" value="respond"><input type="hidden" name="case_id" value="<?=h((string)$case['public_id'])?>">
<h3>Quality criteria</h3><?php foreach($case['criteria'] as $criterion):?><label><?=h((string)$criterion['label'])?><select name="quality_<?=h((string)$criterion['key'])?>" required><option value="">Score 1–5</option><?php for($i=1;$i<=5;$i++):?><option value="<?=$i?>"><?=$i?></option><?php endfor?></select></label><?php endforeach?>
<label>Recommendation<select name="recommendation" required><option value="accept">Accept</option><option value="request_revision">Request revision</option><option value="reject">Reject</option><option value="abstain">Abstain</option></select></label>
<label>Review comment<textarea name="comment" rows="6"></textarea></label><button>Submit review</button></form>
<?php else:?><div class="success">Your review response has been submitted.</div><?php endif?>
</section><?php endif?>
</main></body></html>