<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&!str_contains($c,$needle))$fail[]=$message;};

require_once $root.'/app/research-surface-map.php';
require_once $root.'/app/research-agent-research-ui.php';

$views=research_agent_research_views();
if(array_keys($views)!==['missions','tasks','decisions','follow_through','recurring'])$fail[]='Research must expose exactly Missions, Tasks, Decisions, Follow-through, Recurring in that order.';
if(research_agent_research_view('garbage')!=='missions')$fail[]='Unknown Research views must safely default to Missions.';
if(research_agent_research_view('follow-through')!=='follow_through')$fail[]='Follow-through compatibility alias is missing.';
if(research_agent_research_view('programs')!=='recurring')$fail[]='Programs compatibility alias is missing.';
if(research_agent_research_href('agent-1','decisions')!=='/research-agent-research.php?agent=agent-1&view=decisions')$fail[]='Research view URLs must preserve Agent identity.';

$fixture=['public_id'=>'agent-1','conversation_public_id'=>'conversation-1','project_public_id'=>'project-1','name'=>'Example Agent'];
$links=research_agent_research_engine_links($fixture);
$expected=[
 'missions'=>['/research-missions.php?agent=agent-1'],
 'tasks'=>['/research-tasks.php?agent=agent-1'],
 'decisions'=>['/research-decisions.php'],
 'follow_through'=>['/research-action-plans.php'],
 'recurring'=>['/research-programs.php?agent=agent-1','/research-automations.php'],
];
foreach($expected as $view=>$hrefs){$actual=array_values(array_map(fn($x)=>(string)$x['href'],$links[$view]??[]));foreach($hrefs as $href)if(!in_array($href,$actual,true))$fail[]='Research '.$view.' lost engine link '.$href;}
$item=['public_id'=>'item-1'];
$itemExpected=[
 'missions'=>'/research-missions.php?agent=agent-1&mission=item-1',
 'tasks'=>'/research-tasks.php?agent=agent-1&plan=item-1',
 'decisions'=>'/research-decisions.php?decision=item-1',
 'follow_through'=>'/research-action-plans.php?action_plan=item-1',
 'recurring'=>'/research-programs.php?agent=agent-1&program=item-1',
];
foreach($itemExpected as $view=>$href)if(research_agent_research_item_href($view,'agent-1',$item)!==$href)$fail[]='Research item deep link mismatch for '.$view.'.';

$need('app/bootstrap.php',"research-agent-research-ui.php",'Bootstrap must load the unified Research helper.');
$need('research-agent-research.php',"research_agent_research_view",'Research page must use canonical Research view routing.');
$need('research-agent-research.php',"research_agent_research_render_nav",'Research page must render Missions / Tasks / Decisions / Follow-through / Recurring navigation.');
foreach(['research_mission_list','research_task_plan_list','research_decision_list','research_action_plan_list','research_program_list'] as $engine)$need('research-agent-research.php',$engine,'Unified Research page must read existing '.$engine.' engine.');
foreach(['Decision Ledger','Action Plans','Programs are the primary recurring Research model'] as $label)$need('research-agent-research.php',$label,'Unified Research page must preserve '.$label.' context.');
$need('app/research-agent-research-ui.php','Legacy Automations','Recurring must preserve the Phase 18 compatibility route.');
$need('assets/css/app.css','/* Phase 74 Section 4 — Unified Research UI */','Unified Research styles are missing.');
$need('assets/css/app.css','@media(max-width:760px)','Unified Research mobile layout is missing.');

$map=research_surface_map();
foreach([
 'research-missions.php'=>'agent.research.missions',
 'research-tasks.php'=>'agent.research.tasks',
 'research-decisions.php'=>'agent.research.decisions',
 'research-action-plans.php'=>'agent.research.follow_through',
 'research-programs.php'=>'agent.research.recurring',
 'research-automations.php'=>'agent.research.recurring',
] as $route=>$target)if(($map['routes'][$route]['target']??'')!==$target)$fail[]=$route.' must retain canonical target '.$target.'.';
if(($map['engines']['app/research-agent-research-ui.php']['classification']??'')!=='KEEP_ENGINE')$fail[]='Unified Research helper must be classified KEEP_ENGINE.';
$helper=$read('app/research-agent-research-ui.php');
foreach(['INSERT INTO','UPDATE ','DELETE FROM','CREATE TABLE','ALTER TABLE'] as $write)if(str_contains($helper,$write))$fail[]='Unified Research helper must remain presentation-only; found '.$write.'.';
if(glob($root.'/database/migrations/*_104_*.sql'))$fail[]='Phase 74 Section 4 must not add migration 104.';

$docs=$read('docs/phase-74-research-agent-simplification.md');
if(!str_contains($docs,"
## Section 4 — Unified Research UI
"))$fail[]='Phase 74 Section 4 documentation is missing.';
$need('tests/ci/run-static-contracts.sh','phase74-section4-unified-research-ui-contract.php','Static runner must execute Section 4.');
$need('tests/ci/run-full-regression.sh','phase74-section4-unified-research-ui-db.php','Full regression must execute Section 4 DB journey.');
$need('.github/workflows/full-regression.yml','phase74-section4-unified-research-ui-db.php','MySQL 8 workflow must execute Section 4 DB journey.');
$need('.github/workflows/package-two-zips.yml','phase74-section4-unified-research-ui-contract.php','Production package must include Section 4 contract.');
$need('.github/workflows/package-two-zips.yml','app/research-agent-research-ui.php','Production package must include unified Research helper.');
$need('tests/ci/package-smoke.sh','Phase 74 Section 4 Unified Research UI package extensions passed.','Package smoke must validate Section 4.');

if($fail){fwrite(STDERR,implode("
",array_values(array_unique($fail)))."
");exit(1);}
echo "Phase 74 Section 4 Unified Research UI contracts passed.
";
