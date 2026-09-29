<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&!str_contains($c,$needle))$fail[]=$message;};

require_once $root.'/app/research-surface-map.php';
require_once $root.'/app/research-agent-reports-ui.php';

$views=research_agent_reports_views();
if(array_keys($views)!==['create','recent','scheduled','published'])$fail[]='Reports must expose exactly Create, Recent, Scheduled, Published in that order.';
foreach(['run'=>'create','studio'=>'create','presets'=>'create','inbox'=>'recent','delivery'=>'recent','history'=>'recent','subscriptions'=>'scheduled','schedule'=>'scheduled','publishing'=>'published','publications'=>'published'] as $alias=>$expected){
    if(research_agent_reports_view($alias)!==$expected)$fail[]='Reports alias '.$alias.' must resolve to '.$expected.'.';
}
if(research_agent_reports_view('garbage')!=='create')$fail[]='Unknown Reports views must safely default to Create.';
if(research_agent_reports_href('agent-1','published',['doc'=>'doc-1'])!=='/research-reports.php?agent=agent-1&view=published&doc=doc-1')$fail[]='Reports URLs must preserve Agent identity and contextual query state.';

$fixture=['public_id'=>'agent-1','project_public_id'=>'project-1','conversation_public_id'=>'conversation-1','name'=>'Example Agent'];
$links=research_agent_reports_engine_links($fixture);
foreach([
 'create'=>['/research-reports.php?agent=agent-1&view=create','/research-intelligence-portfolios.php'],
 'recent'=>['/research-reports.php?agent=agent-1&view=recent'],
 'scheduled'=>['/research-reports.php?agent=agent-1&view=scheduled','/research-programs.php?agent=agent-1'],
 'published'=>['/research-publications.php?agent=agent-1','/research-reviews.php'],
] as $view=>$hrefs){$actual=array_values(array_map(fn($x)=>(string)$x['href'],$links[$view]??[]));foreach($hrefs as $href)if(!in_array($href,$actual,true))$fail[]='Reports '.$view.' lost engine link '.$href;}

$filtered=research_agent_reports_filter_publications($fixture,[
 ['public_id'=>'wf-agent','agent_public_id'=>'agent-1','project_public_id'=>'project-x'],
 ['public_id'=>'wf-project','agent_public_id'=>'','project_public_id'=>'project-1'],
 ['public_id'=>'wf-other','agent_public_id'=>'agent-2','project_public_id'=>'project-2'],
]);
if(array_column($filtered,'public_id')!==['wf-agent','wf-project'])$fail[]='Published must filter workflows to the selected Agent or its internal Project.';
if(research_agent_reports_publication_href('agent-1',['public_id'=>'wf-1'])!=='/research-publications.php?workflow=wf-1')$fail[]='Published workflow deep links must preserve Phase 59 authority.';

$need('app/bootstrap.php','research-agent-reports-ui.php','Bootstrap must load the unified Reports helper.');
$need('research-reports.php','research_agent_reports_view','Reports page must use canonical Reports view routing.');
$need('research-reports.php','research_agent_reports_render_nav','Reports page must render Create / Recent / Scheduled / Published navigation.');
foreach(['research_system_report_list','research_report_studio_preset_list','research_report_subscription_list','research_report_delivery_list','research_publication_list'] as $engine)$need('research-reports.php',$engine,'Unified Reports page must read existing '.$engine.' engine.');
foreach(['Run Report','Saved Presets','Recent Reports','Intelligence Inbox','Subscriptions','Review, approval & publication','Portfolio & executive briefings'] as $label)$need('research-reports.php',$label,'Unified Reports page must preserve '.$label.' capability.');
$need('research-reports.php','Phase 59 remains the publication authority','Published view must explicitly preserve Phase 59 authority.');
$need('research-reports.php','Phase 69 does not create another scheduler','Scheduled view must explicitly preserve Program scheduler authority.');
$need('assets/css/app.css','/* Phase 74 Section 5 — Unified Reports UI */','Unified Reports styles are missing.');
$css=$read('assets/css/app.css');$ext=$read('extension/landing-app.css');if($css!==''&&$ext!==''&&!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';

$map=research_surface_map();
foreach([
 'research-reports.php'=>'agent.reports',
 'research-publications.php'=>'agent.reports.published',
 'research-report.php'=>'agent.reports.recent',
] as $route=>$target)if(($map['routes'][$route]['target']??'')!==$target)$fail[]=$route.' must retain canonical target '.$target.'.';
if(($map['engines']['app/research-agent-reports-ui.php']['classification']??'')!=='KEEP_ENGINE')$fail[]='Unified Reports helper must be classified KEEP_ENGINE.';
$helper=$read('app/research-agent-reports-ui.php');foreach(['INSERT INTO','UPDATE ','DELETE FROM','CREATE TABLE','ALTER TABLE'] as $write)if(str_contains($helper,$write))$fail[]='Unified Reports helper must remain presentation-only; found '.$write.'.';
if(glob($root.'/database/migrations/*_104_*.sql'))$fail[]='Phase 74 Section 5 must not add migration 104.';

$docs=$read('docs/phase-74-research-agent-simplification.md');if(!str_contains($docs,"## Section 5 — Unified Reports UI"))$fail[]='Phase 74 Section 5 implementation documentation is missing.';
$need('tests/ci/run-static-contracts.sh','phase74-section5-unified-reports-ui-contract.php','Static runner must execute Section 5.');
$need('tests/ci/run-full-regression.sh','phase74-section5-unified-reports-ui-db.php','Full regression must execute Section 5 DB journey.');
$need('.github/workflows/full-regression.yml','phase74-section5-unified-reports-ui-db.php','MySQL 8 workflow must execute Section 5 DB journey.');
$need('.github/workflows/package-two-zips.yml','phase74-section5-unified-reports-ui-contract.php','Production package must include Section 5 contract.');
$need('.github/workflows/package-two-zips.yml','app/research-agent-reports-ui.php','Production package must include unified Reports helper.');
$need('tests/ci/package-smoke.sh','Phase 74 Section 5 Unified Reports UI package extensions passed.','Package smoke must validate Section 5.');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 74 Section 5 Unified Reports UI contracts passed.\n";
