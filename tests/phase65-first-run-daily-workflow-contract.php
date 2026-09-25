<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$file=$root.'/'.$path;if(!is_file($file)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($file);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$body=$read($path);if($body!==''&&!str_contains($body,$needle))$fail[]=$message;};

$release=$read('app/release.php');$onboarding=$read('onboarding.php');$home=$read('home.php');
$workspace=$read('assets/js/research-agent-workspace-ui.js');$chat=$read('assets/js/agent-chat.js');$css=$read('assets/css/app.css');$landing=$read('extension/landing-app.css');

foreach(["'research_agents'","'workspace_items'","'agent_messages'","'capture'=>","'agent'=>","'continue'=>"] as $needle)if(!str_contains($release,$needle))$fail[]='Onboarding activity model missing '.$needle;
if(str_contains($release,"'follow'=>"))$fail[]='First-run completion must not require an unrelated social follow.';
foreach(['Turn something you find into Research.','phase65Flow','Open your Research Agent','Open Research Desktop','Capture → Research Agent → saved work → return later'] as $needle)if(!str_contains($onboarding,$needle))$fail[]='Canonical onboarding UX missing '.$needle;
foreach(['$homeOnboardingVisible','homeJourneyCard','homeContinueResearch','workspace=desktop','workspace=library','phase65HomeEmpty','data-research-initial-workspace'] as $needle)if(!str_contains($home,$needle))$fail[]='Home daily-workflow guidance missing '.$needle;
foreach(["const initialWorkspace=","initialWorkspace==='desktop'","initialWorkspace==='library'","researchDesktopEmptyGuide","phase65LibraryEmpty"] as $needle)if(!str_contains($workspace,$needle))$fail[]='Desktop/Library first-run behavior missing '.$needle;
foreach(['function renderResearchQuickActions','Add evidence','Open Library','New Research Doc','Review next steps','function renderResearchWelcome'] as $needle)if(!str_contains($chat,$needle))$fail[]='Research Agent next-step UX missing '.$needle;
foreach(['Phase 65 — First-Run Experience & Daily Workflow Polish','.homeJourneyCard','.homeContinueResearch','.researchDesktopEmptyGuide','.phase65AgentQuickActions'] as $needle)if(!str_contains($css,$needle))$fail[]='Phase 65 styling missing '.$needle;
if(!hash_equals(hash('sha256',$css),hash('sha256',$landing)))$fail[]='Website and extension landing CSS must remain byte-identical.';
$need('tests/ci/run-full-regression.sh','tests/phase65-first-run-daily-workflow-db.php','Full regression must execute Phase 65 database journey.');
$need('.github/workflows/full-regression.yml','php tests/phase65-first-run-daily-workflow-db.php','MySQL 8 gate must execute Phase 65 database journey.');
$need('.github/workflows/package-two-zips.yml','docs/phase-65-first-run-daily-workflow-polish.md','Release package must include Phase 65 documentation.');
$need('.github/workflows/package-two-zips.yml','tests/phase65-first-run-daily-workflow-contract.php','Release package must include Phase 65 static contract.');
$need('.github/workflows/package-two-zips.yml','tests/phase65-first-run-daily-workflow-db.php','Release package must include Phase 65 database journey.');
$need('tests/ci/package-smoke.sh','phase-65-first-run-daily-workflow-polish.md','Package smoke must require Phase 65 documentation.');

if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 65 First-Run Experience & Daily Workflow Polish static contract passed.\n";
