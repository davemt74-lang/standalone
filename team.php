<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';require_once __DIR__.'/app/team-research-resources.php';require_once __DIR__.'/app/public-discovery.php';require_once __DIR__.'/app/annotation-ui.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');
$id=trim((string)($_GET['id']??$_POST['team']??''));$error='';$success='';
$q=$pdo->prepare("SELECT t.id,t.public_id,t.name,t.owner_user_id,t.created_at,tm.role access_role FROM teams t JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=? WHERE t.public_id=? LIMIT 1");
$q->execute([$u['id'],$id]);$team=$q->fetch();
if(!$team){http_response_code(404);exit('Team not found or access denied.');}
$canManage=in_array($team['access_role'],['owner','admin'],true);$isOwner=$team['access_role']==='owner';

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$op=(string)($_POST['op']??'');
    try{
        if($op==='assign_agent'){
            if(!$isOwner)throw new RuntimeException('Only the Team owner may assign Research Agents.');
            if(empty($_POST['confirm_workspace_share']))throw new RuntimeException('Confirm that the Agent, its Desktop, and its Library will become Team-accessible.');
            team_research_assign($pdo,$team,$u,trim((string)($_POST['agent_id']??'')));
            $success='Research Agent and its existing Desktop and Library shared with the Team.';
        }elseif($op==='unassign_agent'){
            if(!$isOwner)throw new RuntimeException('Only the Team owner may remove assigned Research Agents.');
            team_research_unassign($pdo,$team,$u,trim((string)($_POST['agent_id']??'')));
            $success='Research Agent and its workspace removed from Team access.';
        }elseif($op==='invite'){
            if(!$canManage)throw new RuntimeException('Team admin permission is required.');
            $username=trim((string)($_POST['username']??''));
            $q=$pdo->prepare('SELECT id FROM users WHERE username=? AND status="active"');$q->execute([$username]);$uid=(int)($q->fetchColumn()?:0);
            if(!$uid)throw new RuntimeException('User not found.');
            $pdo->prepare("INSERT INTO team_members(team_id,user_id,role) VALUES(?,?,'researcher') ON DUPLICATE KEY UPDATE user_id=user_id")->execute([$team['id'],$uid]);
            team_research_sync_member($pdo,(int)$team['id'],$uid,true);$success='Member added.';
        }elseif($op==='role'){
            if(!$isOwner)throw new RuntimeException('Only the team owner can change member roles.');
            $member=(int)($_POST['member_id']??0);$role=(string)($_POST['role']??'researcher');
            if(!in_array($role,['admin','researcher','viewer'],true))throw new RuntimeException('Invalid team role.');
            if($member===(int)$team['owner_user_id'])throw new RuntimeException('The owner role cannot be changed here.');
            $pdo->prepare('UPDATE team_members SET role=? WHERE team_id=? AND user_id=?')->execute([$role,$team['id'],$member]);
            team_research_sync_member($pdo,(int)$team['id'],$member,true);$success='Member role updated.';
        }elseif($op==='remove'){
            if(!$isOwner)throw new RuntimeException('Only the team owner can remove members.');
            $member=(int)($_POST['member_id']??0);if($member===(int)$team['owner_user_id'])throw new RuntimeException('The team owner cannot be removed.');
            $pdo->prepare('DELETE FROM team_members WHERE team_id=? AND user_id=?')->execute([$team['id'],$member]);
            team_research_sync_member($pdo,(int)$team['id'],$member,false);$success='Member removed.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$q=$pdo->prepare('SELECT u.id,u.username,u.display_name,u.profile_image_url,tm.role,tm.created_at FROM team_members tm JOIN users u ON u.id=tm.user_id WHERE tm.team_id=? ORDER BY FIELD(tm.role,"owner","admin","researcher","viewer"),u.display_name');
$q->execute([$team['id']]);$members=$q->fetchAll();
$resources=team_research_resources($pdo,(int)$team['id']);$assignable=$isOwner?team_research_assignable($pdo,(int)$u['id']):[];
$q=$pdo->prepare('SELECT public_id,title,description,status,updated_at FROM research_projects WHERE team_id=? ORDER BY updated_at DESC LIMIT 20');$q->execute([$team['id']]);$projects=$q->fetchAll();
$q=$pdo->prepare("SELECT a.public_id FROM annotations a WHERE a.team_id=? AND a.visibility='team' AND a.status='published' ORDER BY a.published_at DESC LIMIT 25");
$q->execute([$team['id']]);$annotations=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $annotationPublic){$row=public_discovery_annotation($pdo,(string)$annotationPublic,$u);if($row)$annotations[]=$row;}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($team['name'])?> · Teams · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css?v=team-agent-2">
<style>
.teamWorkspaceFullWidth{width:min(100%,1500px);margin:0 auto;padding:22px clamp(16px,3vw,46px) 72px;box-sizing:border-box;min-width:0}
.teamWorkspaceFullWidth .sourceGrid{grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr))}
.teamWorkspaceFullWidth .card{max-width:none}
.teamAttachAgent{margin:22px 0 28px;padding:24px}
.teamAttachAgent .sectionHeadWeb{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.teamAttachAgentForm{display:grid;grid-template-columns:minmax(230px,1fr) auto;align-items:end;gap:14px;margin-top:18px}
.teamAttachAgentForm>label:first-of-type{min-width:0;display:grid;gap:8px}
.teamAttachAgentForm select{width:100%;max-width:100%;min-height:44px}
.teamAttachAgentConfirmation{grid-column:1/-1;display:flex;align-items:flex-start;gap:9px;font-size:.91rem;line-height:1.5}
.teamAttachAgentConfirmation input{flex:none;margin-top:5px}
.teamMemberAdd{margin:18px 0 0;padding:14px 18px}
.teamMemberAdd summary{cursor:pointer;font-weight:650}
.teamMemberAddForm{display:flex;gap:12px;align-items:end;flex-wrap:wrap;padding-top:12px}
.teamMemberAddForm label{flex:1;min-width:200px}
@media(max-width:700px){.teamAttachAgentForm{grid-template-columns:1fr}.teamWorkspaceFullWidth{padding:16px 14px 56px}}
</style></head><body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="team" data-workspace-team="<?=h((string)$team['public_id'])?>">
<main class="teamWorkspaceFullWidth">
<div class="pageTitle"><span class="eyebrow">TEAM · <?=h(strtoupper((string)$team['access_role']))?></span><h1><?=h($team['name'])?></h1><p>Shared people, Research Agents, Desktops, Libraries and private Team collaboration.</p><a class="button secondary" href="/home.php?team=<?=rawurlencode((string)$team['public_id'])?>#team-chat">Open Team Chat</a></div>
<?php if($error):?><div class="error"><?=h($error)?></div><?php endif?><?php if($success):?><div class="success"><?=h($success)?></div><?php endif?>
<div class="card"><div class="sectionHeadWeb"><div><span class="eyebrow">MEMBERS</span><h2><?=count($members)?> people</h2></div></div>
<?php foreach($members as $m):?><div class="sessionRow"><a class="profileMini" href="<?=h(profile_path((string)$m['username']))?>"><?=app_shell_avatar($m,'teamMiniAvatar')?><span><strong><?=h($m['display_name'])?></strong><small>@<?=h($m['username'])?> · <?=h(ucfirst($m['role']))?></small></span></a>
<?php if($isOwner&&(int)$m['id']!==(int)$team['owner_user_id']):?><div class="inlineActions"><form method="post" class="inlineForm"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="team" value="<?=h($team['public_id'])?>"><input type="hidden" name="op" value="role"><input type="hidden" name="member_id" value="<?=h((string)$m['id'])?>"><select name="role"><option value="admin" <?=$m['role']==='admin'?'selected':''?>>Admin</option><option value="researcher" <?=$m['role']==='researcher'?'selected':''?>>Researcher</option><option value="viewer" <?=$m['role']==='viewer'?'selected':''?>>Viewer</option></select><button class="button secondary">Save</button></form><form method="post" onsubmit="return confirm('Remove this member from the team?')"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="team" value="<?=h($team['public_id'])?>"><input type="hidden" name="op" value="remove"><input type="hidden" name="member_id" value="<?=h((string)$m['id'])?>"><button class="button secondary">Remove</button></form></div><?php endif?></div><?php endforeach?>
<?php if($canManage):?><details class="card teamMemberAdd"><summary>Add a member</summary><form method="post" class="teamMemberAddForm"><?=csrf_field()?><input type="hidden" name="team" value="<?=h((string)$team['public_id'])?>"><input type="hidden" name="op" value="invite"><label>Username<input name="username" required placeholder="Existing Annotated username"></label><button class="button">Add member</button></form></details><?php endif?>
</div>

<section class="card teamAttachAgent" id="team-agent-attachment" aria-labelledby="attachResearchAgentHeading">
  <div class="sectionHeadWeb"><div><span class="eyebrow">TEAM RESOURCES</span><h2 id="attachResearchAgentHeading">Attach Research Agent</h2>
  <p>Assign a Research Agent and its existing research, Desktop and Library to this Team. Only the Team owner may assign Agents they own.</p></div>
  <a class="button secondary" href="/research.php">My Research Agents</a></div>
  <?php if($isOwner):?>
    <?php if($assignable):?>
    <form method="post" class="teamAttachAgentForm">
      <?=csrf_field()?>
      <input type="hidden" name="team" value="<?=h((string)$team['public_id'])?>">
      <input type="hidden" name="op" value="assign_agent">
      <label>Choose one of your available Research Agents
        <select name="agent_id" required>
          <option value="">Select a Research Agent</option>
          <?php foreach($assignable as $candidate):?>
          <option value="<?=h((string)$candidate['public_id'])?>"><?=h((string)$candidate['name'])?> · <?=h((string)$candidate['project_title'])?></option>
          <?php endforeach?>
        </select>
      </label>
      <label class="teamAttachAgentConfirmation"><input type="checkbox" name="confirm_workspace_share" value="1" required>
        Share this Agent's existing Project, Desktop, Library, research and conversation with Team members.
      </label>
      <button class="button" type="submit">Attach Research Agent</button>
    </form>
    <?php else:?>
    <p class="meta">No available private Research Agents that you own. An Agent must belong to your personal Project and cannot already be assigned to another Team or active sponsored project.</p>
    <a class="button secondary" href="/research.php">Manage my Research Agents</a>
    <?php endif?>
  <?php else:?>
    <p class="meta">Only the Team owner can attach Research Agents. Shared Agents appear below for Team members.</p>
  <?php endif?>
</section>

<div class="sectionHeadWeb"><div><span class="eyebrow">TEAM RESOURCES</span><h2>Assigned Research Agents, Desktops &amp; Libraries</h2><p class="meta">Team research uses the existing Agent's Project, Desktop and Library. Access follows current Team membership.</p></div></div>
<div class="sourceGrid"><?php foreach($resources as $resource):?><article class="card sourceCard"><span class="eyebrow">SHARED RESEARCH AGENT</span><h3><?=h((string)$resource['name'])?></h3><p class="meta">Owned by <?=h((string)$resource['owner_name'])?> · Project <?=h((string)$resource['project_title'])?></p>
<div class="inlineActions">
<a class="button secondary" href="<?=h(research_agent_shell_href($resource,'chat'))?>">Agent Chat</a>
<a class="button secondary" href="<?=h(research_agent_shell_href($resource,'desktop'))?>">Desktop</a>
<a class="button secondary" href="<?=h(research_agent_shell_href($resource,'library'))?>">Library</a>
<a class="button secondary" href="<?=h(research_agent_shell_href($resource,'reports'))?>">Reports</a>
</div>
<?php if($isOwner&&(int)$resource['owner_user_id']===(int)$u['id']):?><form method="post" onsubmit="return confirm('Remove Team access to this Agent, Desktop, Library and its conversation?')"><?=csrf_field()?><input type="hidden" name="team" value="<?=h((string)$team['public_id'])?>"><input type="hidden" name="op" value="unassign_agent"><input type="hidden" name="agent_id" value="<?=h((string)$resource['public_id'])?>"><button class="button secondary">Remove from Team</button></form><?php endif?></article><?php endforeach?>
<?php if(!$resources):?><div class="card empty">No shared Research Agents yet. The Team owner can assign Agents they own.</div><?php endif?></div>
<div class="sectionHeadWeb"><div><span class="eyebrow">TEAM RESEARCH</span><h2>Projects</h2></div><a href="/research.php">All Research</a></div>
<div class="sourceGrid"><?php foreach($projects as $p):?><a class="card sourceCard" href="/research-project.php?id=<?=h($p['public_id'])?>"><span class="meta"><?=h(ucfirst((string)$p['status']))?> · updated <?=h((string)$p['updated_at'])?></span><h3><?=h($p['title'])?></h3><?php if($p['description']):?><p><?=h(mb_substr((string)$p['description'],0,220))?></p><?php endif?></a><?php endforeach?><?php if(!$projects):?><div class="card empty">No team Research projects yet.</div><?php endif?></div>

<div class="sectionHeadWeb"><div><span class="eyebrow">TEAM FEED</span><h2>Recent annotations</h2></div></div>
<?php foreach($annotations as $a):?><?=annotation_ui_card($a,$u)?><?php endforeach?><?php if(!$annotations):?><div class="card empty">No team-only annotations yet. Team captures from the Chrome sidebar will appear here.</div><?php endif?>
</main><?=annotation_ui_scripts($u)?><script src="/assets/js/workspace-state.js?v=34.0"></script></body></html>