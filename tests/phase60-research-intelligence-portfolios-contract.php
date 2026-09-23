<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail){
    if(!is_file($root.'/'.$file)){$fail[]="$label file missing: $file";return;}
    $body=(string)file_get_contents($root.'/'.$file);foreach($needles as $needle)if(!str_contains($body,$needle))$fail[]="$label contract missing in $file: $needle";
};
$must('database/migrations/20260923_058_research_intelligence_portfolios_executive_briefing.sql',[
 'research_intelligence_portfolios','research_intelligence_portfolio_programs','research_intelligence_portfolio_snapshots',
 'research_intelligence_insights','research_executive_briefings','research_intelligence_portfolio_events',
 "insight_kind ENUM('aggregation','inference')",'aggregate_json','provenance_json','snapshot_hash','publication_workflow_id'
],'Phase 60 migration');
$must('app/research-intelligence-portfolios.php',[
 'research_intelligence_portfolio_create','research_intelligence_portfolio_add_program','research_intelligence_portfolio_aggregate',
 'research_intelligence_portfolio_snapshot','research_intelligence_portfolio_add_inference','research_intelligence_portfolio_create_briefing',
 'research_intelligence_portfolio_prepare_publication','research_intelligence_portfolio_dashboard','research_intelligence_portfolio_cognitive_observations',
 'research_agent_workspace_create_document','research_publication_workflow_create'
],'Phase 60 runtime');
$must('research-intelligence-portfolios.php',[
 'Research Intelligence Portfolios','Cross-program trends & tensions','AGENT INTERPRETATION',
 'Create frozen snapshot','Create Executive Briefing','Send to Phase 59 review','Project Portfolio'
],'Phase 60 dashboard');
$must('api/research-intelligence-portfolios.php',['dashboard','add_program','snapshot','create_briefing','prepare_publication','add_inference'],'Phase 60 API');
$must('app/bootstrap.php',["research-intelligence-portfolios.php"],'Phase 60 bootstrap');
$must('app/cognitive-feed.php',['research_intelligence_portfolio_cognitive_observations'],'Phase 60 Now integration');
$must('app/agent-actions.php',["'research.record_portfolio_inference'","research_intelligence_portfolio_contains_project","research_intelligence_portfolio_add_inference"],'Phase 60 governed Agent inference');
$agent=(string)file_get_contents($root.'/app/agent-actions.php');
$start=strpos($agent,'function agent_action_clean_arguments');$end=strpos($agent,'function agent_action_event',$start);$clean=$start!==false&&$end!==false?substr($agent,$start,$end-$start):'';
if(str_contains($clean,'$seen[')||str_contains($clean,'research_publication_workflow_access($pdo'))$fail[]='Phase 60 stabilization: project/access validation must not remain inside argument cleaning.';
$validateStart=strpos($agent,'function agent_action_validate_project_arguments');$validateEnd=strpos($agent,'function agent_action_create_proposals',$validateStart);$validate=$validateStart!==false&&$validateEnd!==false?substr($agent,$validateStart,$validateEnd-$validateStart):'';
foreach(['research.prepare_publication_review','research.publish_approved_document','research.record_portfolio_inference'] as $needle)if(!str_contains($validate,$needle))$fail[]="Phase 60 stabilization: governed validation missing for $needle.";
$css=(string)file_get_contents($root.'/assets/css/app.css');$ext=(string)file_get_contents($root.'/extension/landing-app.css');
if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension landing CSS must remain byte-identical.';
foreach(['.intelligencePortfolioCanvas','.intelligencePortfolioStats','.intelligenceTensionGrid','@media(max-width:720px)'] as $needle)if(!str_contains($css,$needle))$fail[]="Phase 60 responsive CSS contract missing: $needle";
if(is_file($root.'/worker/research-intelligence-portfolio-worker.php'))$fail[]='Phase 60 must reuse existing execution infrastructure; no Portfolio worker is permitted.';
$must('docs/phase-60-research-intelligence-portfolios-executive-briefing.md',['60A — Stabilization gate','60B — Research Intelligence Portfolios','60C — Executive Briefing','legacy Phase 23 Research Portfolio'],'Phase 60 architecture');
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}echo "Phase 60 Research Intelligence Portfolios & Executive Briefing static contracts passed.\n";
