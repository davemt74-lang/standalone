<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&!str_contains($c,$needle))$fail[]=$message;};
$avoid=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&str_contains($c,$needle))$fail[]=$message;};

require_once $root.'/app/research-surface-map.php';
require_once $root.'/app/research-agent-knowledge-ui.php';

$views=research_agent_knowledge_views();
if(array_keys($views)!==['library','insights','changes'])$fail[]='Knowledge must expose exactly Library, Insights, Changes in that order.';
if(research_agent_knowledge_view('garbage')!=='library')$fail[]='Unknown Knowledge views must safely default to Library.';
if(research_agent_knowledge_href('agent-1','insights')!=='/research-agent-knowledge.php?agent=agent-1&view=insights')$fail[]='Knowledge view URLs must preserve Agent identity.';
$fixture=['public_id'=>'agent-1','conversation_public_id'=>'conversation-1','project_public_id'=>'project-1','name'=>'Example Agent'];
$links=research_agent_knowledge_engine_links($fixture);
$expected=[
 'library'=>['/home.php?agent=conversation-1&workspace=library','/home.php?agent=conversation-1&workspace=desktop','/vp3-library.php'],
 'insights'=>['/research-verification.php?id=project-1','/research-provenance.php?id=project-1','/research-evidence-packs.php?id=project-1','/research-citations.php?id=project-1','/research-entities.php?id=project-1','/research-graph.php?id=project-1'],
 'changes'=>['/research-monitoring.php?agent=agent-1','/research-evolution.php?agent=agent-1'],
];
foreach($expected as $view=>$hrefs){$actual=array_values(array_map(fn($x)=>(string)$x['href'],$links[$view]??[]));foreach($hrefs as $href)if(!in_array($href,$actual,true))$fail[]='Knowledge '.$view.' lost engine link '.$href;}

$need('app/bootstrap.php',"research-agent-knowledge-ui.php",'Bootstrap must load the unified Knowledge helper.');
$need('research-agent-knowledge.php',"research_agent_knowledge_view",'Knowledge page must use canonical Knowledge view routing.');
$need('research-agent-knowledge.php',"research_agent_knowledge_render_nav",'Knowledge page must render Library / Insights / Changes navigation.');
foreach(['research_verification_project_summary','research_monitor_summary','research_longitudinal_summary'] as $engine)$need('research-agent-knowledge.php',$engine,'Unified Knowledge page must read existing '.$engine.' engine.');
foreach(['FINDINGS','CLAIMS','ENTITIES','VERIFICATION','PROVENANCE','SOURCE INTELLIGENCE'] as $label)$need('research-agent-knowledge.php',$label,'Insights must expose '.$label.'.');
foreach(['WHAT CHANGED','EVOLUTION','CONFIDENCE MOVEMENT','SOURCE CHANGES'] as $label)$need('research-agent-knowledge.php',$label,'Changes must expose '.$label.'.');
foreach(['Open Library','Open Desktop','VP3 Library'] as $label)$need('research-agent-knowledge.php',$label,'Library must expose '.$label.'.');
$need('assets/css/app.css','/* Phase 74 Section 3 — Unified Knowledge UI */','Unified Knowledge styles are missing.');
$need('assets/css/app.css','@media(max-width:760px)','Unified Knowledge mobile layout is missing.');

$map=research_surface_map();
foreach([
 'research-entities.php'=>'agent.knowledge.insights',
 'research-evidence-packs.php'=>'agent.knowledge.insights',
 'research-monitoring.php'=>'agent.knowledge.changes',
 'research-evolution.php'=>'agent.knowledge.changes',
 'research-provenance.php'=>'agent.knowledge.insights',
 'research-verification.php'=>'agent.knowledge.insights',
] as $route=>$target)if(($map['routes'][$route]['target']??'')!==$target)$fail[]=$route.' must retain canonical target '.$target.'.';
if(($map['engines']['app/research-agent-knowledge-ui.php']['classification']??'')!=='KEEP_ENGINE')$fail[]='Unified Knowledge helper must be classified KEEP_ENGINE.';
$helper=$read('app/research-agent-knowledge-ui.php');
foreach(['INSERT INTO','UPDATE ','DELETE FROM','CREATE TABLE','ALTER TABLE'] as $write)if(str_contains($helper,$write))$fail[]='Unified Knowledge helper must remain presentation-only; found '.$write.'.';
if(glob($root.'/database/migrations/*_104_*.sql'))$fail[]='Phase 74 Section 3 must not add migration 104.';

$docs=$read('docs/phase-74-research-agent-simplification.md');
if(!str_contains($docs,"
## Section 3 — Unified Knowledge UI
"))$fail[]='Phase 74 Section 3 documentation is missing.';
$need('tests/ci/run-static-contracts.sh','phase74-section3-unified-knowledge-ui-contract.php','Static runner must execute Section 3.');
$need('tests/ci/run-full-regression.sh','phase74-section3-unified-knowledge-ui-db.php','Full regression must execute Section 3 DB journey.');
$need('.github/workflows/full-regression.yml','phase74-section3-unified-knowledge-ui-db.php','MySQL 8 workflow must execute Section 3 DB journey.');
$need('.github/workflows/package-two-zips.yml','phase74-section3-unified-knowledge-ui-contract.php','Production package must include Section 3 contract.');
$need('.github/workflows/package-two-zips.yml','app/research-agent-knowledge-ui.php','Production package must include unified Knowledge helper.');
$need('tests/ci/package-smoke.sh','Phase 74 Section 3 Unified Knowledge UI package extensions passed.','Package smoke must validate Section 3.');

if($fail){fwrite(STDERR,implode("
",array_values(array_unique($fail)))."
");exit(1);}
echo "Phase 74 Section 3 Unified Knowledge UI contracts passed.
";
