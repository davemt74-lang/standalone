<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/research-library.php';
$u=require_user($pdo);
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    $title=trim((string)($_POST['title']??''));
    $description=trim((string)($_POST['description']??''));
    $teamPublic=trim((string)($_POST['team_id']??''));
    if($title==='')$error='Project title is required.';
    else{
        $teamId=null;
        if($teamPublic!==''){
            $q=$pdo->prepare("SELECT t.id FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE t.public_id=? AND tm.user_id=? AND tm.role IN ('owner','admin','researcher')");
            $q->execute([$teamPublic,$u['id']]);
            $teamId=$q->fetchColumn()?:null;
            if(!$teamId)$error='You do not have permission to create research in that team.';
        }
        if(!$error){
            $public=ulid_like();
            $q=$pdo->prepare('INSERT INTO research_projects(public_id,owner_user_id,team_id,title,description) VALUES(?,?,?,?,?)');
            $q->execute([$public,$u['id'],$teamId,$title,$description?:null]);
            header('Location:/research-project.php?id='.urlencode($public));
            exit;
        }
    }
}

$projects=research_library_projects($pdo,$u,100);
$q=$pdo->prepare("SELECT t.public_id,t.name FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE tm.user_id=? AND tm.role IN ('owner','admin','researcher') ORDER BY t.name");
$q->execute([$u['id']]);
$teams=$q->fetchAll();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Research · Annotated</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research">
<header class="topbar"><a class="brand" href="/home.php">Annotated</a></header>
<main class="researchLibraryCanvas">
  <section class="researchLibraryHero">
    <div class="researchLibraryIntro">
      <span class="eyebrow">RESEARCH</span>
      <h1>Evidence workspaces</h1>
      <p>Open a project like a folder: see what changed, how active it is, and where the evidence and discussion are growing.</p>
    </div>
    <div class="researchLibraryActions">
      <details class="researchAddResearch"<?= $error!==''?' open':'' ?>>
        <summary class="button researchAddResearchButton">ADD RESEARCH</summary>
        <div class="researchCreatePopover">
          <div class="researchCreateHead">
            <div><span class="eyebrow">NEW WORKSPACE</span><h2>Add Research</h2></div>
          </div>
          <?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
          <form method="post" class="stack researchCreateForm">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <label>Title<input name="title" required value="<?=h((string)($_POST['title']??''))?>" placeholder="Research project title"></label>
            <label>Description<textarea name="description" rows="4" placeholder="What are you researching?"><?=h((string)($_POST['description']??''))?></textarea></label>
            <label>Workspace<select name="team_id"><option value="">Personal</option><?php foreach($teams as $t):?><option value="<?=h($t['public_id'])?>"<?=((string)($_POST['team_id']??'')===(string)$t['public_id'])?' selected':''?>><?=h($t['name'])?></option><?php endforeach?></select></label>
            <button>Create Research</button>
          </form>
        </div>
      </details>
    </div>
  </section>

  <section class="researchLibraryToolbar" aria-label="Research workspace tools">
    <nav class="researchLibraryTabs">
      <a class="active" href="/research.php">Projects <span><?=h((string)count($projects))?></span></a>
      <a href="/research-portfolio.php">Portfolio</a>
      <a href="/research-publications.php">Living Research</a>
      <a href="/research-reviews.php">Review Center</a>
    </nav>
    <details class="researchAdvancedTools">
      <summary>Advanced tools</summary>
      <div class="researchAdvancedMenu">
        <a href="/research-network.php">Research Network</a>
        <a href="/research-audit.php">Audit Ledger</a>
        <a href="/research-verification.php">Verification</a>
        <a href="/research-evidence-packs.php">Evidence Packs</a>
        <a href="/research-outcomes.php">Decision Memory</a>
        <a href="/cross-research.php">Related Research</a>
        <a href="/research-automations.php">Automations</a>
      </div>
    </details>
  </section>

  <?php if(!$projects):?>
    <section class="researchLibraryEmpty">
      <div class="researchFolderGlyph" aria-hidden="true"></div>
      <h2>No Research projects yet</h2>
      <p>Create your first workspace to start collecting sources, annotations, Claims, Findings, discussion, and ongoing Research activity.</p>
    </section>
  <?php else:?>
    <section class="researchFolderGrid" id="projects" aria-label="Research projects">
      <?php foreach($projects as $p):$recent=research_library_recent_meta((string)($p['recent_at']??$p['updated_at']??''));?>
      <article class="researchFolderCard">
        <div class="researchFolderTab" aria-hidden="true"></div>
        <header class="researchFolderHeader">
          <div class="researchFolderGlyph" aria-hidden="true"></div>
          <div class="researchFolderIdentity">
            <span class="researchFolderScope"><?=h($p['team_name']?:'Personal')?></span>
            <h2><a href="/research-project.php?id=<?=h($p['public_id'])?>"><?=h($p['title'])?></a></h2>
          </div>
          <span class="researchFolderStatus"><?=h(ucfirst((string)$p['status']))?></span>
        </header>

        <?php if(trim((string)$p['description'])!==''):?><p class="researchFolderDescription"><?=h($p['description'])?></p><?php endif?>

        <div class="researchFolderSignals">
          <?php if((int)$p['notification_count']>0):?><span class="researchSignal researchSignalAlert"><span aria-hidden="true">●</span><?=h((string)$p['notification_count'])?> notifications</span><?php endif?>
          <?php if($recent['recent']):?><span class="researchSignal researchSignalRecent">Recent · <?=h($recent['label'])?></span><?php else:?><span class="researchSignal">Updated <?=h($recent['label'])?></span><?php endif?>
        </div>

        <div class="researchFolderSocial" aria-label="Project activity">
          <span title="Comments"><b>💬</b><strong><?=h((string)$p['comment_count'])?></strong><small>Comments</small></span>
          <span title="Likes"><b>♥</b><strong><?=h((string)$p['like_count'])?></strong><small>Likes</small></span>
          <span title="Annotations"><b>◫</b><strong><?=h((string)$p['annotation_count'])?></strong><small>Annotations</small></span>
          <span title="Sources"><b>↗</b><strong><?=h((string)$p['source_count'])?></strong><small>Sources</small></span>
        </div>

        <div class="researchFolderEvidence">
          <span><strong><?=h((string)$p['claim_count'])?></strong> Claims</span>
          <span><strong><?=h((string)$p['finding_count'])?></strong> Findings</span>
          <span><strong><?=h((string)$p['task_count'])?></strong> Open tasks</span>
        </div>

        <footer class="researchFolderFooter">
          <a class="researchFolderOpen" href="/research-project.php?id=<?=h($p['public_id'])?>">Open folder</a>
          <nav aria-label="<?=h($p['title'])?> shortcuts">
            <a href="/research-brief.php?id=<?=h($p['public_id'])?>">Brief</a>
            <a href="/research-timeline.php?id=<?=h($p['public_id'])?>">Timeline</a>
            <a href="/home.php?agent_context_type=research&amp;agent_context_id=<?=h($p['public_id'])?>#agent-chat">Agent</a>
          </nav>
        </footer>
      </article>
      <?php endforeach?>
    </section>
  <?php endif?>
</main>
<script src="/assets/js/workspace-state.js?v=34.0"></script>
</body>
</html>
