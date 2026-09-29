<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&!str_contains($c,$needle))$fail[]=$message;};
$avoid=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&str_contains($c,$needle))$fail[]=$message;};

require_once $root.'/app/research-surface-map.php';
require_once $root.'/app/research-agent-shell-ui.php';

$tabs=research_agent_shell_tabs();
if(array_keys($tabs)!==['chat','knowledge','research','reports'])$fail[]='Unified Research Agent shell must expose exactly Chat, Knowledge, Research, Reports in that order.';
$fixture=['public_id'=>'agent-1','conversation_public_id'=>'conversation-1','name'=>'Example Agent'];
$expected=[
 'chat'=>'/home.php?agent=conversation-1',
 'knowledge'=>'/research-agent-knowledge.php?agent=agent-1',
 'research'=>'/research-agent-research.php?agent=agent-1',
 'reports'=>'/research-reports.php?agent=agent-1',
 'library'=>'/home.php?agent=conversation-1&workspace=library',
 'desktop'=>'/home.php?agent=conversation-1&workspace=desktop',
];
foreach($expected as $tab=>$href)if(research_agent_shell_href($fixture,$tab)!==$href)$fail[]='Shell href mismatch for '.$tab.'.';

$need('app/bootstrap.php',"research-agent-shell-ui.php",'Bootstrap must load the unified Research Agent shell helper.');
$need('home.php',"research_agent_shell_render(\$requestedResearchAgent,\$homeResearchAgents,'chat'",'Agent Chat must use the unified shell.');
$need('research-agent-knowledge.php',"research_agent_shell_render(\$selected,\$agents,'knowledge'",'Knowledge must use the unified shell.');
$need('research-agent-research.php',"research_agent_shell_render(\$selected,\$agents,'research'",'Research must use the unified shell.');
$need('research-reports.php',"research_agent_shell_render(\$selected,\$agents,'reports'",'Reports must use the unified shell.');
$need('app/research-agent-research-ui.php','/research-missions.php?agent=','Unified Research must retain Missions engine access.');
$need('app/research-agent-research-ui.php','/research-tasks.php?agent=','Unified Research must retain Tasks engine access.');
$need('app/research-agent-research-ui.php','/research-decisions.php','Unified Research must retain Decisions engine access.');
$need('app/research-agent-research-ui.php','/research-action-plans.php','Unified Research must retain Action Plan engine access.');
$need('app/research-agent-research-ui.php','/research-programs.php?agent=','Unified Research must retain Program engine access.');
$need('assets/js/research-agent-unified-shell.js',"annotated.researchAgent.last",'Unified shell must persist the selected Agent context in browser state.');
$need('assets/css/app.css','.researchAgentUnifiedShell','Unified shell styles are missing.');
$need('assets/css/app.css','.researchUnifiedNav','Unified Research canonical navigation styles are missing.');

$need('research.php','/research-intelligence-portfolios.php','Global Research must expose Portfolios beside Research Agents.');
$researchPage=$read('research.php');$primaryStart=strpos($researchPage,'<nav class="researchLibraryTabs researchPrimaryActions">');$primaryEnd=$primaryStart===false?false:strpos($researchPage,'</nav>',$primaryStart);$primary=$primaryStart!==false&&$primaryEnd!==false?substr($researchPage,$primaryStart,$primaryEnd-$primaryStart):'';
if($primary==='')$fail[]='Global Research primary navigation block is missing.';
foreach(['/research-monitoring.php','/research-missions.php','/research-tasks.php','/research-programs.php','/research-publications.php','/research-decisions.php','/research-action-plans.php','/research-reviews.php'] as $legacy)
    if(str_contains($primary,$legacy))$fail[]='Global Research primary navigation must not expose '.$legacy.'.';
$need('research.php','Advanced Research tools','Legacy/advanced Research compatibility routes must remain discoverable under progressive disclosure.');
foreach(['/research-portfolio.php','/research-network.php','/research-citations.php','/research-audit.php','/research-provenance.php','/research-verification.php','/research-evidence-packs.php'] as $legacy)
    if(!str_contains($researchPage,$legacy))$fail[]='Historical Research compatibility route must remain discoverable: '.$legacy.'.';
foreach(['/research-project.php?id=','/research-tasks.php?agent=','/research-programs.php?agent='] as $legacy)
    $avoid('research.php','<a href="'.$legacy,'"Research Agent cards must use the four canonical shell destinations instead of '.$legacy.'.');

$map=research_surface_map();
if(($map['routes']['research-agent-research.php']['target']??'')!=='agent.research')$fail[]='New unified Research tab must map to agent.research.';
if(($map['engines']['app/research-agent-shell-ui.php']['classification']??'')!=='KEEP_ENGINE')$fail[]='Unified shell helper must be classified KEEP_ENGINE.';
$helper=$read('app/research-agent-shell-ui.php');
foreach(['INSERT INTO','UPDATE ','DELETE FROM','CREATE TABLE','ALTER TABLE'] as $write)if(str_contains($helper,$write))$fail[]='Unified shell helper must remain presentation/read-only; found '.$write.'.';
if(glob($root.'/database/migrations/*_104_*.sql'))$fail[]='Phase 74 Section 2 must not add migration 104.';

$need('docs/phase-74-research-agent-simplification.md','## Section 2 — Unified Research Agent Shell','Phase 74 Section 2 documentation is missing.');
$need('docs/phase-74-research-agent-simplification.md','Chat | Knowledge | Research | Reports','Section 2 docs must preserve the four-tab model.');
$need('tests/ci/run-static-contracts.sh','phase74-section2-unified-research-agent-shell-contract.php','Static runner must execute the Section 2 shell contract.');
$need('tests/ci/run-full-regression.sh','phase74-section2-unified-research-agent-shell-db.php','Full regression must execute the Section 2 DB journey.');
$need('.github/workflows/package-two-zips.yml','phase74-section2-unified-research-agent-shell-contract.php','Release package must include the Section 2 contract.');
$need('tests/ci/package-smoke.sh','Phase 74 Section 2 Unified Research Agent Shell package extensions passed.','Package smoke must validate Section 2.');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 74 Section 2 Unified Research Agent Shell contracts passed.\n";
