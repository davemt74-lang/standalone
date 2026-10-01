<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';require_once __DIR__.'/app/team-research-resources.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');

$agentId=trim((string)($_GET['agent']??$_POST['agent_id']??''));
if($agentId===''){header('Location: /research.php');exit;}
$error='';$success='';
try{$ctx=research_agent_edit_context($pdo,$u,$agentId);}catch(Throwable $e){http_response_code(404);exit('Research Agent not found.');}

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$op=(string)($_POST['op']??'');
    try{
        if($op==='assign_team'){
            if(empty($_POST['confirm_workspace_share']))throw new RuntimeException('Confirm that the Agent, Desktop, Library and research will become Team-accessible.');
            $team=team_research_owned_team($pdo,(int)$u['id'],trim((string)($_POST['team_id']??'')));
            if(!$team)throw new RuntimeException('Choose a Team that you own.');
            team_research_assign($pdo,$team,$u,$agentId);
            $success='Research Agent, Desktop and Library attached to '.$team['name'].'.';
        }elseif($op==='unassign_team'){
            $team=team_research_agent_team($pdo,(int)$u['id'],$agentId);
            if(!$team||empty($team['owner_member'])||(int)$team['owner_user_id']!==(int)$u['id'])
                throw new RuntimeException('Only the owner of this Team and Research Agent can remove this assignment.');
            $team['access_role']='owner';
            team_research_unassign($pdo,$team,$u,$agentId);
            $success='Research Agent removed from the Team and returned to your personal workspace.';
        }elseif($op==='save_agent'){
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
        }elseif($op==='save_story_policy'){
            research_agent_story_policy_update($pdo,$u,$agentId,[
              'stories_enabled'=>isset($_POST['stories_enabled']),
              'publish_mode'=>(string)($_POST['publish_mode']??'approval'),
              'min_priority'=>(string)($_POST['min_priority']??'medium'),
              'daily_story_cap'=>(int)($_POST['daily_story_cap']??3),
              'quiet_hours_enabled'=>isset($_POST['quiet_hours_enabled']),
              'timezone_name'=>(string)($_POST['story_timezone_name']??($u['timezone_name']??'UTC')),
              'quiet_start'=>(string)($_POST['quiet_start']??''),
              'quiet_end'=>(string)($_POST['quiet_end']??''),
              'trigger_evidence'=>isset($_POST['trigger_evidence']),
              'trigger_risk'=>isset($_POST['trigger_risk']),
              'trigger_question'=>isset($_POST['trigger_question']),
              'trigger_decision'=>isset($_POST['trigger_decision']),
              'trigger_task'=>isset($_POST['trigger_task']),
              'trigger_update'=>isset($_POST['trigger_update'])
            ]);$success='Story publishing policy updated.';
        }elseif($op==='create_story'){
            $story=research_agent_story_create_manual($pdo,$u,$agentId,[
              'title'=>(string)($_POST['story_title']??''),
              'body'=>(string)($_POST['story_body']??''),
              'story_type'=>(string)($_POST['story_type']??'update'),
              'priority'=>(string)($_POST['story_priority']??'medium'),
              'primary_url'=>(string)($_POST['story_url']??''),
              'status'=>(string)($_POST['story_status']??'draft')
            ]);$success=((string)$story['status']==='published'?'Story published.':'Story saved as draft.');
        }elseif($op==='update_story'){
            research_agent_story_update_manual($pdo,$u,(string)($_POST['story_id']??''),[
              'title'=>(string)($_POST['story_title']??''),'body'=>(string)($_POST['story_body']??''),'story_type'=>(string)($_POST['story_type']??'update'),
              'priority'=>(string)($_POST['story_priority']??'medium'),'primary_url'=>(string)($_POST['story_url']??''),'scheduled_at'=>(string)($_POST['scheduled_at']??'')
            ]);$success='Story draft updated.';
        }elseif($op==='review_story'){
            $reviewed=research_agent_story_review_manual($pdo,$u,(string)($_POST['story_id']??''),(string)($_POST['decision']??''),(string)($_POST['review_note']??''));
            $success=((string)($reviewed['status']??'draft')==='published'?'Story approved and published.':'Story rejected for revision.');
        }elseif($op==='publish_story'){
            research_agent_story_publish_manual($pdo,$u,(string)($_POST['story_id']??''));$success='Story published.';
        }elseif($op==='archive_story'){
            research_agent_story_archive_manual($pdo,$u,(string)($_POST['story_id']??''));$success='Story archived.';
        }
        $ctx=research_agent_edit_context($pdo,$u,$agentId);
    }catch(Throwable $e){$error=$e->getMessage();}
}
$agent=$ctx['agent'];$project=$ctx['project'];$automation=$ctx['automation'];$missions=$ctx['missions'];$plans=$ctx['plans'];$programs=$ctx['programs'];$watches=$ctx['watches'];$portfolios=$ctx['portfolios'];
$drafts=function_exists('research_agent_story_drafts')?research_agent_story_drafts($pdo,$u,$agentId,30):[];
$storyPolicy=function_exists('research_agent_story_policy')?research_agent_story_policy($pdo,$agent):research_agent_story_policy_defaults();
$ownedTeams=team_research_owned_teams($pdo,(int)$u['id']);
$assignedTeam=team_research_agent_team($pdo,(int)$u['id'],$agentId);
$canAttachTeam=in_array($agentId,array_column(team_research_assignable($pdo,(int)$u['id']),'public_id'),true);
$timezone=(string)($automation['timezone_name']??($u['timezone_name']??'UTC'));$runTime=substr((string)($automation['run_time_local']??'09:00'),0,5);
?><!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Edit <?=h((string)$agent['name'])?> · Annotated</title>
<link rel="stylesheet" href="/assets/css/app.css?v=team-agent-2">
<style>
.researchAgentEditTeamPanel{margin:0 0 22px;padding:24px}
.researchAgentEditTeamHeading p{max-width:830px;line-height:1.5}
.researchAgentEditTeamForm{display:grid;gap:15px;max-width:680px}
.researchAgentEditTeamForm>label:first-of-type{display:grid;gap:8px}
.researchAgentEditTeamForm select{width:100%;min-height:44px}
.researchAgentEditTeamConfirm{display:flex;align-items:flex-start;gap:10px}
.researchAgentEditTeamConfirm input{flex:none;margin-top:5px}
.researchAgentEditTeamAssigned{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin:16px 0}
</style>
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

  <section class="card researchAgentEditTeamPanel" id="team-assignment" aria-labelledby="teamAssignmentHeading">
    <header class="researchAgentEditTeamHeading">
      <div><span class="eyebrow">TEAM ACCESS</span><h2 id="teamAssignmentHeading">Assign to a Team</h2>
      <p>Team assignment is optional. Your Research Agent works independently unless you choose to attach it to a Team you own. Attaching shares its existing Project, Desktop, Library, research and conversation with that Team.</p></div>
    </header>
    <?php if($assignedTeam):?>
      <div class="researchAgentEditTeamAssigned"><strong>Currently attached to <?=h((string)$assignedTeam['name'])?></strong>
        <a class="button secondary" href="/team.php?id=<?=rawurlencode((string)$assignedTeam['public_id'])?>">Open Team</a></div>
      <?php if(!empty($assignedTeam['owner_member'])&&(int)$assignedTeam['owner_user_id']===(int)$u['id']):?>
        <form method="post" onsubmit="return confirm('Remove this Agent, its Desktop, Library, research and conversation from the Team?')">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent_id" value="<?=h($agentId)?>">
          <input type="hidden" name="op" value="unassign_team">
          <button class="button secondary" type="submit">Remove from Team</button>
        </form>
        <p class="meta">To move this Agent to another Team, remove it here and then attach it to the new Team.</p>
      <?php else:?><p class="meta">The current Team assignment needs its owner to resolve its access permissions.</p><?php endif?>
    <?php elseif(!$ownedTeams):?>
      <p>This Agent is not assigned to a Team. It will continue working independently.</p><p>You don't own any Teams yet.</p><a class="button secondary" href="/teams.php">Create a Team</a>
    <?php elseif(!$canAttachTeam):?>
      <p>This Agent is not assigned to a Team and can continue working independently.</p><p>This Agent is not currently eligible for Team sharing. Make it Private and ensure you own its Project. It also cannot share a Project with another active Agent or have an active sponsored assignment.</p>
      <a class="button secondary" href="/teams.php">View my Teams</a>
    <?php else:?>
      <p class="meta">Currently unassigned — this Agent remains yours unless you attach it below.</p>
      <form method="post" class="researchAgentEditTeamForm">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent_id" value="<?=h($agentId)?>">
        <input type="hidden" name="op" value="assign_team">
        <label>Choose one of your Teams
          <select name="team_id" required><option value="">Select a Team</option>
            <?php foreach($ownedTeams as $ownedTeam):?>
            <option value="<?=h((string)$ownedTeam['public_id'])?>"><?=h((string)$ownedTeam['name'])?></option>
            <?php endforeach?>
          </select>
        </label>
        <label class="researchAgentEditTeamConfirm"><input type="checkbox" name="confirm_workspace_share" value="1" required>
          I understand that this Agent's research, Desktop, Library and conversation will become accessible to this Team's members.
        </label>
        <button class="button" type="submit">Attach to Team</button>
      </form>
    <?php endif?>
  </section>

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
      <header><span class="eyebrow">STORY POLICY</span><h2>Proactive publishing</h2><p>Control when this Agent turns research activity into Stories.</p></header>
      <form method="post" class="researchAgentStoryPolicyForm">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent_id" value="<?=h($agentId)?>"><input type="hidden" name="op" value="save_story_policy">
        <label class="researchAgentEditCheck"><input type="checkbox" name="stories_enabled" value="1" <?=!empty($storyPolicy['stories_enabled'])?'checked':''?>> <strong>Stories on</strong> — allow this Research Agent to publish and display Stories</label>
        <div class="researchAgentEditSplit">
          <label>Publishing mode<select name="publish_mode"><option value="draft_only" <?=$storyPolicy['publish_mode']==='draft_only'?'selected':''?>>Draft only</option><option value="approval" <?=$storyPolicy['publish_mode']==='approval'?'selected':''?>>Require approval</option><option value="auto_publish" <?=$storyPolicy['publish_mode']==='auto_publish'?'selected':''?>>Auto-publish</option></select></label>
          <label>Minimum importance<select name="min_priority"><option value="low" <?=$storyPolicy['min_priority']==='low'?'selected':''?>>Low</option><option value="medium" <?=$storyPolicy['min_priority']==='medium'?'selected':''?>>Medium</option><option value="high" <?=$storyPolicy['min_priority']==='high'?'selected':''?>>High</option></select></label>
          <label>Daily Story cap<input type="number" min="1" max="20" name="daily_story_cap" value="<?=h((string)$storyPolicy['daily_story_cap'])?>"></label><label>Story timezone<input name="story_timezone_name" value="<?=h((string)($storyPolicy['timezone_name']??$timezone))?>"></label>
        </div>
        <div class="researchAgentStoryTriggers">
          <strong>Trigger on</strong>
          <?php foreach(['evidence'=>'Evidence','risk'=>'Risk / contradiction','question'=>'Open question','decision'=>'Decision','task'=>'Task / milestone','update'=>'General update'] as $key=>$label):?><label class="researchAgentEditCheck"><input type="checkbox" name="trigger_<?=h($key)?>" value="1" <?=!empty($storyPolicy['trigger_'.$key])?'checked':''?>> <?=h($label)?></label><?php endforeach?>
        </div>
        <div class="researchAgentStoryQuiet">
          <label class="researchAgentEditCheck"><input type="checkbox" name="quiet_hours_enabled" value="1" <?=!empty($storyPolicy['quiet_hours_enabled'])?'checked':''?>> Use quiet hours</label>
          <label>Start<input type="time" name="quiet_start" value="<?=h(substr((string)($storyPolicy['quiet_start']??''),0,5))?>"></label>
          <label>End<input type="time" name="quiet_end" value="<?=h(substr((string)($storyPolicy['quiet_end']??''),0,5))?>"></label>
        </div>
        <button class="button secondary" type="submit">Save Story policy</button>
      </form>

      <header class="researchAgentEditSubhead"><span class="eyebrow">STORIES</span><h2>Create a Story</h2><p>Publish a direct update from this Agent or save it as a draft.</p></header>
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
      <?php if($drafts):?><div class="researchAgentEditDrafts"><h3>Draft & scheduled Stories</h3><?php foreach($drafts as $story):?><details class="researchAgentStoryDraftEditor"><summary><span><strong><?=h((string)$story['title'])?></strong><small><?=h(ucfirst((string)$story['story_type']))?> · <?=h(ucfirst((string)$story['priority']))?><?php if(!empty($story['approval_state'])&&$story['approval_state']!=='not_required'):?> · <?=h(ucfirst((string)$story['approval_state']))?><?php endif?><?php if(!empty($story['scheduled_at'])):?> · Scheduled <?=h((string)$story['scheduled_at'])?><?php endif?></small></span><b>Edit</b></summary><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent_id" value="<?=h($agentId)?>"><input type="hidden" name="story_id" value="<?=h((string)$story['public_id'])?>"><input type="hidden" name="op" value="update_story"><label>Title<input name="story_title" maxlength="240" required value="<?=h((string)$story['title'])?>"></label><label>Story<textarea name="story_body" rows="5" maxlength="1800" required><?=h((string)$story['body'])?></textarea></label><div class="researchAgentEditSplit"><label>Type<select name="story_type"><?php foreach(['update','evidence','risk','question','decision','task','briefing'] as $v):?><option value="<?=h($v)?>" <?=$story['story_type']===$v?'selected':''?>><?=h(ucfirst($v))?></option><?php endforeach?></select></label><label>Priority<select name="story_priority"><?php foreach(['low','medium','high'] as $v):?><option value="<?=h($v)?>" <?=$story['priority']===$v?'selected':''?>><?=h(ucfirst($v))?></option><?php endforeach?></select></label></div><label>Source URL<input type="url" name="story_url" value="<?=h((string)($story['primary_url']??''))?>"></label><label>Schedule publish<input type="datetime-local" name="scheduled_at" value="<?=!empty($story['scheduled_at'])?h(date('Y-m-d\TH:i',strtotime((string)$story['scheduled_at']))):''?>"></label><div class="researchAgentEditStoryActions"><button class="button secondary" type="submit">Save changes</button></div></form><?php $approval=(string)($story['approval_state']??'not_required');$proactive=((string)($story['origin']??'manual')==='proactive');?>
<?php if($proactive&&$approval==='pending'):?>
  <form method="post" class="researchAgentStoryReviewForm">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent_id" value="<?=h($agentId)?>"><input type="hidden" name="op" value="review_story"><input type="hidden" name="story_id" value="<?=h((string)$story['public_id'])?>">
    <label>Review note<textarea name="review_note" rows="2" maxlength="1000" placeholder="Optional approval/rejection note"></textarea></label>
    <div class="researchAgentEditStoryActions"><button class="button secondary" name="decision" value="reject">Reject</button><button class="button" name="decision" value="approve">Approve & publish</button></div>
  </form>
<?php else:?>
  <div class="researchAgentEditStoryActions"><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent_id" value="<?=h($agentId)?>"><input type="hidden" name="op" value="archive_story"><input type="hidden" name="story_id" value="<?=h((string)$story['public_id'])?>"><button class="button secondary">Archive</button></form><?php if($approval!=='rejected'):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="agent_id" value="<?=h($agentId)?>"><input type="hidden" name="op" value="publish_story"><input type="hidden" name="story_id" value="<?=h((string)$story['public_id'])?>"><button class="button">Publish now</button></form><?php endif?></div>
<?php endif?></details><?php endforeach?></div><?php endif?>
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
