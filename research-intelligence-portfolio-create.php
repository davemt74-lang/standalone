<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user($pdo);header('Cache-Control: private, no-store');header('Vary: Cookie');
if(!research_intelligence_portfolios_ready($pdo)){header('Location: /upgrade.php?from=research-intelligence-portfolio-create');exit;}
$error='';
$teams=[];try{$q=$pdo->prepare("SELECT t.public_id,t.name,tm.role FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE tm.user_id=? AND tm.role IN ('owner','admin','researcher') ORDER BY t.name");$q->execute([(int)$u['id']]);$teams=$q->fetchAll()?:[];}catch(Throwable $e){}
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    try{
        $p=research_intelligence_portfolio_create($pdo,$u,[
          'title'=>(string)($_POST['title']??''),'objective'=>(string)($_POST['objective']??''),'team_id'=>(string)($_POST['team_id']??''),
          'briefing_cadence'=>(string)($_POST['briefing_cadence']??'manual'),'timezone_name'=>(string)($_POST['timezone_name']??($u['timezone_name']??'UTC')),
          'briefing_time_local'=>(string)($_POST['briefing_time_local']??'09:00'),'briefing_weekday'=>(int)($_POST['briefing_weekday']??1),'briefing_day_of_month'=>(int)($_POST['briefing_day_of_month']??1),
          'briefing_policy'=>(string)($_POST['briefing_policy']??'material_only'),'materiality_threshold'=>(string)($_POST['materiality_threshold']??'important')
        ]);
        header('Location: /research-intelligence-portfolios.php?view=portfolios&portfolio='.rawurlencode((string)$p['public_id']).'&created=1');exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create Portfolio · Annotated</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/css/app.css?v=74.6"><link rel="stylesheet" href="/assets/css/portfolio-create.css?v=74.3"></head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="research-intelligence-portfolio-create">
<main class="intelligencePortfolioCanvas">
  <section class="researchLibraryToolbar"><nav class="researchLibraryTabs researchPrimaryActions"><a href="/research.php">Research Agents</a><a class="active" href="/research-intelligence-portfolios.php">Portfolios</a></nav></section>
  <header class="intelligencePortfolioHero">
    <div><span class="eyebrow">CREATE PORTFOLIO</span><h1>New Portfolio</h1><p>Group existing Research Programs into one organization-level intelligence view. Portfolio creation does not create a second worker, task engine, scheduler, or document system.</p></div>
    <a class="button secondary" href="/research-intelligence-portfolios.php?view=portfolios">Cancel</a>
  </header>
  <?php if($error):?><div class="error"><?=h($error)?></div><?php endif?>
  <section class="card intelligencePortfolioCreate portfolioCreateStandalone">
    <span class="eyebrow">NEW PORTFOLIO</span>
    <h2>Portfolio details</h2>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <label>Portfolio title<input name="title" required maxlength="255" placeholder="Market Intelligence" value="<?=h((string)($_POST['title']??''))?>"></label>
      <label>Objective<textarea name="objective" required rows="6" maxlength="16000" placeholder="What should leadership continuously understand across these Programs?"><?=h((string)($_POST['objective']??''))?></textarea></label>
      <label>Scope<select name="team_id"><option value="">Personal</option><?php foreach($teams as $team):?><option value="<?=h((string)$team['public_id'])?>" <?=((string)($_POST['team_id']??'')===(string)$team['public_id'])?'selected':''?>><?=h((string)$team['name'])?></option><?php endforeach?></select></label>
      <label>Briefing cadence<select name="briefing_cadence"><?php foreach(['manual'=>'Manual','weekly'=>'Weekly','monthly'=>'Monthly','quarterly'=>'Quarterly'] as $v=>$label):?><option value="<?=h($v)?>" <?=((string)($_POST['briefing_cadence']??'manual')===$v)?'selected':''?>><?=h($label)?></option><?php endforeach?></select></label>
      <label>Draft policy<select name="briefing_policy"><option value="material_only" <?=((string)($_POST['briefing_policy']??'material_only')==='material_only')?'selected':''?>>Only when materially changed</option><option value="always" <?=((string)($_POST['briefing_policy']??'')==='always')?'selected':''?>>Every scheduled cycle</option></select></label>
      <label>Materiality<select name="materiality_threshold"><option value="important" <?=((string)($_POST['materiality_threshold']??'important')==='important')?'selected':''?>>Important + high</option><option value="high" <?=((string)($_POST['materiality_threshold']??'')==='high')?'selected':''?>>High only</option><option value="any" <?=((string)($_POST['materiality_threshold']??'')==='any')?'selected':''?>>Any tracked change</option></select></label>
      <input type="hidden" name="timezone_name" value="<?=h((string)($u['timezone_name']??'UTC'))?>">
      <div class="inlineActions"><button type="submit">Create Portfolio</button><a class="button secondary" href="/research-intelligence-portfolios.php?view=portfolios">Cancel</a></div>
    </form>
  </section>
</main>
</body></html>
