<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');

$agentId=trim((string)($_GET['agent']??$_POST['agent_id']??''));
if($agentId===''){header('Location: /research.php');exit;}
$error='';$success='';
try{$ctx=research_agent_edit_context($pdo,$u,$agentId);}catch(Throwable $e){http_response_code(404);exit('Research Agent not found.');}

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$op=(string)($_POST['op']??'');
    try{
        if($op==='save_agent'){
            $image=isset($_POST['remove_profile_photo'])?'':(string)($_POST['profile_image_url']??($ctx['agent']['profile_image_url']??''));
            if(isset($_FILES['agent_profile_photo'])&&(int)($_FILES['agent_profile_photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)$image=profile_image_upload($_FILES['agent_profile_photo'],(int)$u['id']);
            $ctx=research_agent_update_settings($pdo,$u,$agentId,[
              'name'=>(string)($_POST['name']??''),
              'description'=>(string)($_POST['description']??''),
              'profile_image_url'=>$image,
              'visibility'=>(string)($_POST['visibility']??'private'),
              'status'=>(string)($_POST['status']??'active'),
              'cadence'=>(string)($_POST['cadence']??'daily'),
              'timezone_name'=>(string)($_POST['timezone_name']??'UTC'),
              'run_time_local'=>(string)($_POST['run_time_local']??'09:00'),
              'weekday'=>(int)($_POST['weekday']??1),
              'automation_prompt'=>(string)($_POST['automation_prompt']??'')
            ]);$success='Research Agent updated.';
        }elseif($op==='create_story'){
            $story=research_agent_story_create_manual($pdo,$u,$agentId,[
              'title'=>(string)($_POST['story_title']??''),
              'body'=>(string)($_POST['story_body']??''),
              'story_type'=>(string)($_POST['story_type']??'update'),
              'priority'=>(string)($_POST['story_priority']??'medium'),
              'primary_url'=>(string)($_POST['story_url']??''),
              'status'=>(string)($_POST['story_status']??'draft')
            ]);$success=((string)$story['status']==='published'?'Story published.':'Story saved as draft.');
        }elseif($op==='publish_story'){
            research_agent_story_publish_manual($pdo,$u,(string)($_POST['story_id']??''));$success='Story published.';
        }
        $ctx=research_agent_edit_context($pdo,$u,$agentId);
    }catch(Throwable $e){$error=$e->getMessage();}
}
$agent=$ctx['agent'];$project=$ctx['project'];$automation=$ctx['automation'];$missions=$ctx['missions'];$plans=$ctx['plans'];$programs=$ctx['programs'];$watches=$ctx['watches'];$portfolios=$ctx['portfolios'];
$drafts=function_exists('research_agent_story_drafts')?research_agent_story_drafts($pdo,$u,$agentId,30):[];
$timezone=(string)($automation['timezone_name']??($u['timezone_name']??'UTC'));$runTime=substr((string)($automation['run_time_local']??'09:00'),0,5);
?><!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Edit <?=h((string)$agent['name'])?> · Annotated</title>
<link rel="stylesheet" href="/assets/css/app.css?v=78.1">
</head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-agent-edit">
<main class="researchAgentEditCanvas">
  <header class="researchAgentEditHero">
    <div>
      <a class="profileBackLink" href="/research.php">← Research</a>
      <span class="eyebrow">RESEARCH AGENT CONTROL CENTER</span>
      <h1>Edit <?=h((string)$agent['name'])?></h1>
      <p>Manage this Agent’s identity, visibility, monitoring, project, Missions, Tasks, Programs, Portfolios, and Stories from one place.</p>
    </div>
    <div class="researchAgentEditHeroActions">
      <a class="button secondary" href="<?=h(research_agent_shell_href($agent,'chat'))?>">Open Agent</a>
      <?php if((string)$agent['visibility']==='public'):?><a class="button secondary" href="/research-agent-public.php?agent=<?=rawurlencode($agentId)?>">Public profile</a><?php endif?>
    </div>
  </header>

  <?php if($error!==''):?><div class="researchAgentEditNotice error"><?=h($error)?></div><?php endif?>
  <?php if($success!==''):?><div class="researchAgentEditNotice success"><?=h($success)?></div><?php endif?>

  <section class="researchAgentEditGrid">
    <form method="post" enctype="multipart/form-data" class="card researchAgentEditIdentity">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent_id" value="<?=h($agentId)?>"><input type="hidden" name="op" value="save_agent">
      <header><span class="eyebrow">IDENTITY & ACCESS</span><h2>Agent settings</h2></header>
      <div class="researchAgentEditProfileRow">
        <div class="researchAgentEditAvatar"><?php if(!empty($agent['profile_image_url'])):?><img src="<?=h((string)$agent['profile_image_url'])?>" alt=""><?php else:?><?=h(mb_strtoupper(mb_substr((string)$agent['name'],0,1)))?><?php endif?></div>
        <div><label>Upload profile photo<input type="file" name="agent_profile_photo" accept="image/jpeg,image/png,image/webp"></label><label>Or image URL<input name="profile_image_url" value="<?=h((string)($agent['profile_image_url']??''))?>" placeholder="/uploads/... or https://..."></label><?php if(!empty($agent['profile_image_url'])):?><label class="researchAgentEditCheck"><input type="checkbox" name="remove_profile_photo" value="1"> Remove current photo</label><?php endif?></div>
      </div>
      <label>Name<input name="name" required maxlength="190" value="<?=h((string)$agent['name'])?>"></label>
      <label>Description<textarea name="description" rows="5" maxlength="4000"><?=h((string)($agent['description']??''))?></textarea></label>
      <div class="researchAgentEditSplit">
        <label>Visibility<select name="visibility"><option value="private" <?=$agent['visibility']==='private'?'selected':''?>>Private</option><option value="friends" <?=$agent['visibility']==='friends'?'selected':''?>>Friends only</option><option value="public" <?=$agent['visibility']==='public'?'selected':''?>>Public</option></select></label>
        <label>Status<select name="status"><option value="active" <?=$agent['status']==='active'?'selected':''?>>Active</option><option value="paused" <?=$agent['status']==='paused'?'selected':''?>>Paused</option></select></label>
      </div>

      <header class="researchAgentEditSubhead"><span class="eyebrow">MONITORING</span><h3>Automation</h3></header>
      <div class="researchAgentEditSplit">
        <label>Cadence<select name="cadence"><?php foreach(['hourly','daily','weekly','manual'] as $v):?><option value="<?=h($v)?>" <?=($agent['monitoring_cadence']??'daily')===$v?'selected':''?>><?=h(ucfirst($v))?></option><?php endforeach?></select></label>
        <label>Timezone<input name="timezone_name" value="<?=h($timezone)?>"></label>
        <label>Run time<input type="time" name="run_time_local" value="<?=h($runTime)?>"></label>
        <label>Weekday<select name="weekday"><?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $i=>$day):?><option value="<?=$i?>" <?=((int)($automation['weekday']??1)===$i)?'selected':''?>><?=h($day)?></option><?php endforeach?></select></label>
      </div>
      <label>Automation prompt<textarea name="automation_prompt" rows="4"><?=h((string)($automation['prompt']??''))?></textarea></label>
      <button class="button" type="submit">Save Agent</button>
    </form>

    <section class="card researchAgentEditStory">
      <header><span class="eyebrow">STORIES</span><h2>Create a Story</h2><p>Publish a direct update from this Agent or save it as a draft.</p></header>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent_id" value="<?=h($agentId)?>"><input type="hidden" name="op" value="create_story">
        <label>Title<input name="story_title" maxlength="240" required></label>
        <label>Story<textarea name="story_body" rows="6" maxlength="1800" required></textarea></label>
        <div class="researchAgentEditSplit">
          <label>Type<select name="story_type"><?php foreach(['update','evidence','risk','question','decision','task','briefing'] as $v):?><option value="<?=h($v)?>"><?=h(ucfirst($v))?></option><?php endforeach?></select></label>
          <label>Priority<select name="story_priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option></select></label>
        </div>
        <label>Source URL<input type="url" name="story_url" placeholder="https://..."></label>
        <div class="researchAgentEditStoryActions"><button class="button secondary" name="story_status" value="draft">Save draft</button><button class="button" name="story_status" value="published">Publish now</button></div>
      </form>
      <?php if($drafts):?><div class="researchAgentEditDrafts"><h3>Draft Stories</h3><?php foreach($drafts as $story):?><article><div><strong><?=h((string)$story['title'])?></strong><small><?=h(ucfirst((string)$story['story_type']))?> · <?=h(ucfirst((string)$story['priority']))?></small></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent_id" value="<?=h($agentId)?>"><input type="hidden" name="op" value="publish_story"><input type="hidden" name="story_id" value="<?=h((string)$story['public_id'])?>"><button class="button secondary">Publish</button></form></article><?php endforeach?></div><?php endif?>
    </section>
  </section>

  <section class="researchAgentEditObjects">
    <article class="card"><header><span class="eyebrow">PROJECT</span><h2><?=h((string)$project['title'])?></h2></header><p><?=h((string)($project['description']??'The canonical project backing this Research Agent.'))?></p><footer><a href="/research-agent-research.php?agent=<?=rawurlencode($agentId)?>">Open project research →</a></footer></article>
    <article class="card"><header><span class="eyebrow">MISSIONS</span><h2><?=h((string)count($missions))?> Missions</h2></header><?php foreach(array_slice($missions,0,5) as $m):?><a class="researchAgentEditObjectRow" href="/research-missions.php?agent=<?=rawurlencode($agentId)?>&mission=<?=rawurlencode((string)$m['public_id'])?>"><span><strong><?=h((string)$m['title'])?></strong><small><?=h(ucfirst((string)$m['status']))?></small></span><b>→</b></a><?php endforeach?><footer><a href="/research-missions.php?agent=<?=rawurlencode($agentId)?>">Manage Missions →</a></footer></article>
    <article class="card"><header><span class="eyebrow">TASKS & PLANS</span><h2><?=h((string)count($plans))?> Plans</h2></header><?php foreach(array_slice($plans,0,5) as $p):?><a class="researchAgentEditObjectRow" href="/research-tasks.php?agent=<?=rawurlencode($agentId)?>&plan=<?=rawurlencode((string)$p['public_id'])?>"><span><strong><?=h((string)$p['title'])?></strong><small><?=h(ucfirst((string)$p['status']))?> · <?=h((string)($p['total_tasks']??0))?> tasks</small></span><b>→</b></a><?php endforeach?><footer><a href="/research-tasks.php?agent=<?=rawurlencode($agentId)?>">Manage Tasks →</a></footer></article>
    <article class="card"><header><span class="eyebrow">PROGRAMS</span><h2><?=h((string)count($programs))?> Programs</h2></header><?php foreach(array_slice($programs,0,5) as $p):?><a class="researchAgentEditObjectRow" href="/research-programs.php?agent=<?=rawurlencode($agentId)?>&program=<?=rawurlencode((string)$p['public_id'])?>"><span><strong><?=h((string)$p['title'])?></strong><small><?=h(ucfirst((string)$p['status']))?> · <?=h(ucfirst((string)$p['cadence']))?></small></span><b>→</b></a><?php endforeach?><footer><a href="/research-programs.php?agent=<?=rawurlencode($agentId)?>">Manage Programs →</a></footer></article>
    <article class="card"><header><span class="eyebrow">MONITORING</span><h2><?=h((string)count($watches))?> Watches</h2></header><?php foreach(array_slice($watches,0,5) as $w):?><a class="researchAgentEditObjectRow" href="/research-monitoring.php?agent=<?=rawurlencode($agentId)?>"><span><strong><?=h((string)($w['target']??$w['query_text']??'Watch'))?></strong><small><?=h(ucfirst((string)$w['status']))?> · <?=h(ucfirst((string)$w['cadence']))?></small></span><b>→</b></a><?php endforeach?><footer><a href="/research-monitoring.php?agent=<?=rawurlencode($agentId)?>">Manage Monitoring →</a></footer></article>
    <article class="card"><header><span class="eyebrow">PORTFOLIOS</span><h2><?=h((string)count($portfolios))?> Portfolios</h2></header><?php foreach(array_slice($portfolios,0,5) as $p):?><a class="researchAgentEditObjectRow" href="/research-intelligence-portfolios.php?portfolio=<?=rawurlencode((string)$p['public_id'])?>"><span><strong><?=h((string)$p['title'])?></strong><small><?=h((string)($p['program_count']??0))?> Programs</small></span><b>→</b></a><?php endforeach?><footer><a href="/research-intelligence-portfolios.php">Manage Portfolios →</a></footer></article>
  </section>
</main>
</body>
</html>
