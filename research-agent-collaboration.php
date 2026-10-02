<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/v2-collaboration.php';
$user=require_user($pdo);
header('Cache-Control: private, no-store');header('Vary: Cookie');
$agentPublic=trim((string)($_GET['agent']??$_POST['agent']??''));
$agent=research_agent_access($pdo,$user,$agentPublic);
if(!$agent){http_response_code(404);exit('Research Agent not found.');}
$isOwner=(int)$agent['owner_user_id']===(int)$user['id'];
$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    if(!$isOwner){http_response_code(403);exit('Only the Agent owner can manage collaboration.');}
    try{
        rate_limit_api_or_429($pdo,'v2-collaboration-manage','user:'.$user['id'],30,3600);
        $op=(string)($_POST['op']??'');
        $current=v2_collaboration_for_lead($pdo,$user,$agentPublic);
        if($op==='create'&&!$current){
            v2_collaboration_create($pdo,$user,$agentPublic);$notice='Collaboration roster created.';
        }elseif($current&&$op==='assign'){
            v2_collaboration_assign($pdo,$user,(string)$current['public_id'],(string)($_POST['member']??''),(string)($_POST['role']??''));$notice='Agent role saved.';
        }elseif($current&&$op==='member_state'){
            v2_collaboration_member_state($pdo,$user,(string)$current['public_id'],(string)($_POST['member']??''),(string)($_POST['state']??''));$notice='Membership updated.';
        }elseif($current&&$op==='plan_state'){
            v2_collaboration_plan_state($pdo,$user,(string)$current['public_id'],(string)($_POST['state']??''));$notice='Collaboration status updated.';
        }else throw new RuntimeException('Invalid collaboration action.');
    }catch(Throwable $e){$error=$e instanceof InvalidArgumentException||$e instanceof RuntimeException?$e->getMessage():'Unable to update collaboration.';}
}
$ready=v2_collaboration_ready($pdo);
$plan=$ready?v2_collaboration_for_lead($pdo,$user,$agentPublic):null;
$possible=$ready&&$isOwner?v2_collaboration_assignable_agents($pdo,$user,$agentPublic):[];
$assigned=array_column($plan['members']??[],'agent_public_id');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Agent collaboration · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><?=function_exists('app_shell_header')?app_shell_header($user):''?>
<main class="panel" style="max-width:1040px;margin:32px auto;padding:24px">
<p><a href="/research-agent-edit.php?agent=<?=rawurlencode($agentPublic)?>">← Back to Agent</a></p>
<h1>Agent collaboration</h1><p>Lead: <?=h((string)$agent['name'])?>. Assign existing Agents to distinct roles without merging their projects or sharing private research automatically.</p>
<?php if($error!==''):?><p role="alert"><?=h($error)?></p><?php endif?>
<?php if($notice!==''):?><p role="status"><?=h($notice)?></p><?php endif?>
<?php if(!$ready):?><p>V2 collaboration requires migration 130. V1 Agent workflows remain unchanged.</p>
<?php elseif(!$plan):?>
<?php if($isOwner):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($agentPublic)?>"><input type="hidden" name="op" value="create"><button class="button" type="submit">Create collaboration roster</button></form>
<?php else:?><p>The Agent owner has not enabled collaboration.</p><?php endif?>
<?php else:?>
<p><strong>Status:</strong> <?=h((string)$plan['status'])?> · <strong>Revision:</strong> <?=h((string)$plan['revision'])?></p>
<p>Roster only. Delegating tasks, accessing another Agent's memory and transferring evidence are disabled until the governed handoff section.</p>
<?php if($isOwner):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($agentPublic)?>"><input type="hidden" name="op" value="plan_state"><input type="hidden" name="state" value="<?=$plan['status']==='active'?'paused':'active'?>"><button class="button secondary" type="submit"><?=$plan['status']==='active'?'Pause':'Resume'?> collaboration</button></form><?php endif?>
<h2>Assigned Agents</h2>
<?php foreach($plan['members'] as $member):?><article class="card"><strong><?=h((string)$member['name'])?></strong><p><?=h((string)(v2_collaboration_roles()[$member['role']]??$member['role']))?> · <?=h((string)$member['status'])?> <?=!$member['eligible_now']?'· Eligibility changed':''?></p>
<?php if($isOwner&&$member['role']!=='lead'):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($agentPublic)?>"><input type="hidden" name="member" value="<?=h((string)$member['agent_public_id'])?>"><input type="hidden" name="op" value="member_state">
<button type="submit" name="state" value="<?=$member['status']==='active'?'paused':'active'?>"><?=$member['status']==='active'?'Pause member':'Resume member'?></button>
<button type="submit" name="state" value="removed">Remove</button></form><?php endif?></article><?php endforeach?>
<?php if($isOwner&&$plan['status']==='active'):?><h2>Assign an Agent</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent" value="<?=h($agentPublic)?>"><input type="hidden" name="op" value="assign">
<label>Agent <select name="member" required><option value="">Select</option><?php foreach($possible as $candidate):?><option value="<?=h((string)$candidate['public_id'])?>"><?=h((string)$candidate['name'])?></option><?php endforeach?></select></label>
<label>Work role <select name="role"><?php foreach(v2_collaboration_roles() as $key=>$label):?><?php if($key!=='lead'):?><option value="<?=h($key)?>"><?=h($label)?></option><?php endif?><?php endforeach?></select></label>
<button type="submit">Save Agent assignment</button></form><?php endif?>
<?php endif?>
</main></body></html>
