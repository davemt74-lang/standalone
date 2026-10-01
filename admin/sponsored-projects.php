<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$admin=require_admin($pdo);header('Cache-Control: private, no-store');$error='';$success='';
if(function_exists('admin_access_assert_capability'))admin_access_assert_capability($pdo,$admin,$_SERVER['REQUEST_METHOD']==='POST'?'admin.research_data.manage':'admin.research_data.view');
if($_SERVER['REQUEST_METHOD']==='POST'){require_csrf();try{
    $op=(string)($_POST['op']??'review');
    if($op==='compensation'){
        sponsored_project_compensation_admin_transition($pdo,$admin,(string)($_POST['compensation_id']??''),(string)($_POST['compensation_status']??''),(string)($_POST['payment_reference']??''),(string)($_POST['admin_note']??''));
        $success='Compensation status updated.';
    }else{
        sponsored_project_admin_status($pdo,$admin,(string)($_POST['submission_id']??''),(string)($_POST['status']??''),(string)($_POST['review_note']??''));
        $success='Submission review updated.';
    }
}catch(Throwable $e){$error=$e->getMessage();}}
$projects=sponsored_project_admin_projects($pdo);
$campaignPublic=(string)($_GET['campaign']??$_POST['campaign_id']??'');
$campaign=$campaignPublic!==''?sponsored_research_campaign_by_public($pdo,$campaignPublic):null;
$assignments=$campaign?sponsored_project_admin_assignments($pdo,(int)$campaign['id']):[];
$submissions=$campaign?sponsored_project_admin_submissions($pdo,(int)$campaign['id']):[];
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sponsored Projects · Annotated Admin</title><link rel="stylesheet" href="/assets/css/app.css"></head><body><?=admin_ui_sidebar('sponsored_projects')?><main class="panel">
<div class="pageTitle"><span class="eyebrow">RESEARCH OPERATIONS</span><h1>Sponsored Projects</h1><p>Track projects, assigned Research Agents, immutable submissions, review decisions, and researcher compensation.</p></div>
<?php if($error):?><div class="error"><?=h($error)?></div><?php endif?><?php if($success):?><div class="success"><?=h($success)?></div><?php endif?>
<section class="card"><h2>Projects</h2><table><thead><tr><th>Project</th><th>Status</th><th>Agents</th><th>Submissions</th><th>Flat fee</th><th>Earned</th><th>Approved</th><th>Paid</th></tr></thead><tbody>
<?php foreach($projects as $p):?><tr>
<td><a href="/admin/sponsored-projects.php?campaign=<?=rawurlencode((string)$p['public_id'])?>"><strong><?=h((string)$p['title'])?></strong></a></td>
<td><?=h((string)$p['status'])?></td><td><?=h((string)$p['assigned_agents'])?></td><td><?=h((string)$p['submissions'])?></td>
<td><?=h((string)$p['budget_currency'])?> <?=number_format(((int)($p['researcher_compensation_cents']??0))/100,2)?></td>
<td><?=h((string)$p['budget_currency'])?> <?=number_format(((int)($p['compensation_earned_cents']??0))/100,2)?></td>
<td><?=h((string)$p['budget_currency'])?> <?=number_format(((int)($p['compensation_approved_cents']??0))/100,2)?></td>
<td><?=h((string)$p['budget_currency'])?> <?=number_format(((int)($p['compensation_paid_cents']??0))/100,2)?></td>
</tr><?php endforeach?></tbody></table></section>
<?php if($campaign):?>
<section class="card"><span class="eyebrow">PROJECT DETAIL</span><h2><?=h((string)$campaign['title'])?></h2><p><?=nl2br(h((string)$campaign['brief']))?></p>
<div class="meta"><?=h((string)$campaign['status'])?> · <?=h((string)$campaign['access_mode'])?> · researcher flat fee <?=h((string)$campaign['budget_currency'])?> <?=number_format(((int)($campaign['researcher_compensation_cents']??0))/100,2)?> · submission deadline <?=h((string)($campaign['submission_deadline']??'Not set'))?></div></section>
<section class="card"><h2>Assigned Research Agents & Compensation</h2><?php if(!$assignments):?><p>No Research Agents assigned yet.</p><?php else:?><table><thead><tr><th>Researcher</th><th>Agent</th><th>Assignment</th><th>Compensation</th><th>Payment state</th><th>Action</th></tr></thead><tbody>
<?php foreach($assignments as $a):$comp=$a['compensation']??null;?><tr>
<td><?=h((string)$a['researcher_name'])?><div class="meta">@<?=h((string)$a['researcher_username'])?></div></td>
<td><?=h((string)$a['agent_name'])?><div class="meta"><?=h((string)$a['agent_public_id'])?></div></td>
<td><?=h((string)$a['status'])?><div class="meta"><?=h((string)$a['assigned_at'])?></div></td>
<td><?php if($comp):?><?=h((string)$comp['currency'])?> <?=number_format(((int)$comp['amount_cents'])/100,2)?><div class="meta">Frozen <?=h((string)$comp['agreed_at'])?></div><?php else:?><span class="meta">Not configured</span><?php endif?></td>
<td><?=h($comp?ucwords(str_replace('_',' ',(string)$comp['status'])):'—')?></td>
<td><?php if($comp&&in_array((string)$comp['status'],['pending','earned','approved_for_payment'],true)):?><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="op" value="compensation"><input type="hidden" name="campaign_id" value="<?=h((string)$campaign['public_id'])?>"><input type="hidden" name="compensation_id" value="<?=h((string)$comp['public_id'])?>">
<select name="compensation_status"><?php if($comp['status']==='earned'):?><option value="approved_for_payment">Approve for payment</option><?php endif?><?php if($comp['status']==='approved_for_payment'):?><option value="paid">Mark paid</option><?php endif?><option value="voided">Void</option></select>
<label>Payment reference<input name="payment_reference" placeholder="Required when marking paid"></label><label>Admin note<input name="admin_note"></label><button>Update</button></form><?php elseif($comp):?><span class="meta"><?=h(ucwords(str_replace('_',' ',(string)$comp['status'])))?></span><?php endif?></td>
</tr><?php endforeach?></tbody></table><?php endif?></section>
<section class="card"><h2>Submissions</h2><?php if(!$submissions):?><p>No submissions yet.</p><?php endif?>
<?php foreach($submissions as $s):?><article id="submission-<?=h((string)$s['public_id'])?>" class="card" style="margin-bottom:18px"><div class="inlineActions"><div><strong><?=h((string)$s['title'])?></strong><div class="meta"><?=h((string)$s['researcher_name'])?> · Agent <?=h((string)$s['agent_name'])?> · revision <?=h((string)($s['revision_number']??1))?> · <?=h((string)$s['submitted_at'])?> · hash <?=h(substr((string)$s['submission_hash'],0,12))?>…</div><?php if(!empty($s['supersedes_public_id'])):?><div class="meta">Supersedes <?=h((string)$s['supersedes_public_id'])?></div><?php endif?></div><span class="badge"><?=h((string)$s['status'])?></span></div>
<?php if($s['summary']):?><p><?=h((string)$s['summary'])?></p><?php endif?><?php if(!empty($s['review_note'])):?><div class="card"><strong>Review note</strong><p><?=nl2br(h((string)$s['review_note']))?></p><div class="meta"><?=h((string)($s['reviewer_name']??'Admin'))?> · <?=h((string)($s['decision_at']??$s['reviewed_at']??''))?></div></div><?php endif?>
<h3>Documents & Reports</h3><?php foreach($s['assets'] as $asset):$snap=json_decode((string)$asset['snapshot_json'],true)?:[];?><div class="card"><strong><?=h(ucwords(str_replace('_',' ',(string)$asset['asset_type'])))?>: <?=h((string)$asset['title'])?></strong><div class="meta">ID <?=h((string)$asset['asset_public_id'])?><?php if($asset['asset_version']):?> · version <?=h((string)$asset['asset_version'])?><?php endif?> · snapshot <?=h(substr((string)$asset['snapshot_hash'],0,12))?>…</div><?php if(!empty($snap['summary'])):?><p><?=h((string)$snap['summary'])?></p><?php endif?></div><?php endforeach?>
<?php if(in_array((string)$s['status'],['submitted','under_review'],true)):?><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="op" value="review"><input type="hidden" name="campaign_id" value="<?=h((string)$campaign['public_id'])?>"><input type="hidden" name="submission_id" value="<?=h((string)$s['public_id'])?>"><label>Decision<select name="status"><option value="under_review">Under review</option><option value="accepted">Accepted</option><option value="revision_requested">Revision requested</option><option value="rejected">Rejected</option></select></label><label>Review note / revision instructions<textarea name="review_note" rows="3" placeholder="Required when requesting a revision"></textarea></label><button>Save review</button></form><?php else:?><div class="meta">Decision recorded. This submission is immutable.</div><?php endif?></article><?php endforeach?></section>
<?php endif?></main></body></html>