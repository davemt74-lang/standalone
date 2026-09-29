<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&!str_contains($c,$needle))$fail[]=$message;};

require_once $root.'/app/research-surface-map.php';
require_once $root.'/app/research-portfolios-ui.php';

$views=research_portfolios_views();
if(array_keys($views)!==['overview','portfolios'])$fail[]='Portfolios must expose exactly Overview and Portfolios in that order.';
foreach(['command-center'=>'overview','command_center'=>'overview','attention'=>'overview','review'=>'overview','reviews'=>'overview','portfolio'=>'portfolios','manage'=>'portfolios','detail'=>'portfolios'] as $alias=>$expected){
    if(research_portfolios_view($alias)!==$expected)$fail[]='Portfolio alias '.$alias.' must resolve to '.$expected.'.';
}
if(research_portfolios_view('garbage')!=='overview')$fail[]='Unknown Portfolio views must safely default to Overview.';
if(research_portfolios_href('portfolios','portfolio-1',['focus'=>'review'])!=='/research-intelligence-portfolios.php?view=portfolios&portfolio=portfolio-1&focus=review')$fail[]='Portfolio URLs must preserve selected Portfolio and contextual query state.';

$fixture=[
 'organizational_cognition_signals'=>[['portfolio_id'=>'p1','portfolio_title'=>'Portfolio One','severity'=>'high','title'=>'Review signal','kind'=>'decision_reconsideration','summary'=>'Changed evidence.']],
 'strategic_review_attention'=>[['portfolio_id'=>'p1','portfolio_title'=>'Portfolio One','review_id'=>'r1','consensus'=>'awaiting_reviewers','reasons'=>['overdue']]],
 'decision_execution_attention'=>[['portfolio_id'=>'p2','portfolio_title'=>'Portfolio Two','action_plan_public_id'=>'a2','action_plan_title'=>'Plan Two','decision_title'=>'Decision Two','action_plan_status'=>'active','reasons'=>['overdue']]],
 'cross_portfolio_themes'=>[['key'=>'Shared signal','portfolio_count'=>2,'portfolios'=>['p1'=>'Portfolio One','p2'=>'Portfolio Two']]],
];
$sections=research_portfolios_attention_sections($fixture);
if(count($sections)!==13)$fail[]='Portfolio Overview must preserve all thirteen existing Command Center attention groups.';
if(research_portfolios_attention_count($sections)!==4)$fail[]='Global attention count must include every normalized Command Center item.';
$scoped=research_portfolios_attention_sections($fixture,'p1');
foreach($scoped as $section)foreach((array)$section['items'] as $item)if(($item['portfolio_id']??'')!=='p1')$fail[]='Contextual Portfolio attention must not leak another Portfolio.';
if(research_portfolios_attention_count($scoped)!==2)$fail[]='Contextual attention must include only selected-Portfolio signals; cross-Portfolio themes stay global.';

$need('app/bootstrap.php','research-portfolios-ui.php','Bootstrap must load the unified Portfolio presentation helper.');
$need('research-intelligence-portfolios.php','research_portfolios_view','Canonical Portfolio page must route Overview and Portfolios views.');
$need('research-intelligence-portfolios.php','GLOBAL ATTENTION','Portfolio Overview must contain Global Attention.');
$need('research-intelligence-portfolios.php','CONTEXTUAL ATTENTION','Selected Portfolio detail must contain contextual attention.');
$need('research-intelligence-portfolios.php','What needs review now','Command Center intelligence must be presented inside Portfolio Overview.');
$need('research-intelligence-command-center.php',"research_portfolios_href('overview'","Legacy Command Center must redirect into canonical Portfolio Overview.");
$command=$read('research-intelligence-command-center.php');
foreach(['Executive Intelligence','ORGANIZATION COMMAND CENTER','intelligenceCommandGrid'] as $legacy)if(str_contains($command,$legacy))$fail[]='Legacy Command Center must no longer render a competing product surface: '.$legacy.'.';

$page=$read('research-intelligence-portfolios.php');
foreach(['>Programs</a>','Project Portfolio','>Command Center</a>','>Publishing</a>','Review Center'] as $legacy)if(str_contains($page,$legacy))$fail[]='Primary Portfolio navigation must not expose duplicate research products: '.$legacy.'.';
foreach(['Research Agents','>Portfolios</a>','>Overview</a>'] as $label)if(!str_contains($page,$label))$fail[]='Canonical Portfolio navigation missing '.$label.'.';

$map=research_surface_map();
if(($map['routes']['research-intelligence-command-center.php']['target']??'')!=='portfolios.overview')$fail[]='Legacy Command Center must remain mapped to portfolios.overview.';
if(($map['routes']['research-intelligence-portfolios.php']['target']??'')!=='portfolios')$fail[]='Intelligence Portfolios must remain the canonical Portfolio route.';
if(($map['engines']['app/research-portfolios-ui.php']['classification']??'')!=='KEEP_ENGINE')$fail[]='Unified Portfolio helper must be classified KEEP_ENGINE.';
if(($map['engines']['app/research-portfolio.php']['domain']??'')!=='compatibility')$fail[]='Legacy Project Portfolio engine must remain compatibility-only.';

$helper=$read('app/research-portfolios-ui.php');
foreach(['INSERT INTO','UPDATE ','DELETE FROM','CREATE TABLE','ALTER TABLE'] as $write)if(str_contains($helper,$write))$fail[]='Unified Portfolio helper must remain presentation-only; found '.$write.'.';
if(glob($root.'/database/migrations/*_104_*.sql'))$fail[]='Phase 74 Section 6 must not add migration 104.';

$need('assets/css/app.css','/* Phase 74 Section 6 — Portfolios & Global Attention */','Unified Portfolio styles are missing.');
$css=$read('assets/css/app.css');$ext=$read('extension/landing-app.css');if($css!==''&&$ext!==''&&!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';
$docs=$read('docs/phase-74-research-agent-simplification.md');if(!str_contains($docs,"## Section 6 — Portfolios & Global Attention"))$fail[]='Phase 74 Section 6 implementation documentation is missing.';
$need('tests/ci/run-static-contracts.sh','phase74-section6-portfolios-global-attention-contract.php','Static runner must execute Section 6.');
$need('tests/ci/run-full-regression.sh','phase74-section6-portfolios-global-attention-db.php','Full regression must execute Section 6 DB journey.');
$need('.github/workflows/full-regression.yml','phase74-section6-portfolios-global-attention-db.php','MySQL 8 workflow must execute Section 6 DB journey.');
$need('.github/workflows/package-two-zips.yml','phase74-section6-portfolios-global-attention-contract.php','Production package must include Section 6 contract.');
$need('.github/workflows/package-two-zips.yml','app/research-portfolios-ui.php','Production package must include unified Portfolio helper.');
$need('tests/ci/package-smoke.sh','Phase 74 Section 6 Portfolios & Global Attention package extensions passed.','Package smoke must validate Section 6.');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 74 Section 6 Portfolios & Global Attention contracts passed.\n";
