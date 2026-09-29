<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$body=$read($path);if($body!==''&&!str_contains($body,$needle))$fail[]=$message;};

foreach(range(1,7) as $section){
    $contracts=glob($root.'/tests/phase74-section'.$section.'-*-contract.php')?:[];
    if(!$contracts)$fail[]='Phase 74 Section '.$section.' contract coverage is missing.';
    if($section>=2){
        $db=glob($root.'/tests/phase74-section'.$section.'-*-db.php')?:[];
        if(!$db)$fail[]='Phase 74 Section '.$section.' database journey is missing.';
    }
}
foreach([
 'app/research-surface-map.php',
 'app/research-agent-shell-ui.php',
 'app/research-agent-knowledge-ui.php',
 'app/research-agent-research-ui.php',
 'app/research-agent-reports-ui.php',
 'app/research-portfolios-ui.php',
 'app/research-legacy-compat.php',
 'research-agent-knowledge.php',
 'research-agent-research.php',
 'research-reports.php',
 'research-intelligence-portfolios.php',
 'tests/phase74-section8-end-to-end-simplification-release-db.php',
] as $path)if(!is_file($root.'/'.$path))$fail[]='Phase 74 release file missing '.$path;

require_once $root.'/app/research-surface-map.php';
$map=research_surface_map();
$agentTop=[];foreach((array)($map['canonical_ui']['agent']??[]) as $key=>$row)if(substr_count($key,'.')===1)$agentTop[]=$key;
sort($agentTop);$expected=['agent.chat','agent.knowledge','agent.reports','agent.research'];sort($expected);
if($agentTop!==$expected)$fail[]='Final Agent UI must remain exactly Chat, Knowledge, Research, Reports.';
foreach([
 'research.php'=>'research.home',
 'research-agent-knowledge.php'=>'agent.knowledge',
 'research-agent-research.php'=>'agent.research',
 'research-reports.php'=>'agent.reports',
 'research-intelligence-portfolios.php'=>'portfolios',
 'research-intelligence-command-center.php'=>'portfolios.overview',
 'research-automations.php'=>'agent.research.recurring',
 'research-brief.php'=>'agent.reports',
 'research-project.php'=>'agent.chat',
] as $route=>$target)if(($map['routes'][$route]['target']??'')!==$target)$fail[]=$route.' lost final canonical target '.$target.'.';
if(isset($map['routes']['report.php'])||isset($map['routes']['report-status.php']))$fail[]='Trust & Safety report routes must remain outside the Research product map.';

$need('docs/phase-74-research-agent-simplification.md','## Section 8 — End-to-End Simplification Release','Phase 74 final release documentation is missing.');
$need('docs/phase-74-research-agent-simplification.md','Phase 74 is complete','Phase 74 completion statement is missing.');
$need('docs/phase-74-research-agent-simplification.md','report.php and report-status.php are Trust & Safety','Phase 74 documentation must preserve the Trust & Safety route correction.');
$need('tests/ci/run-static-contracts.sh','phase74-section8-end-to-end-simplification-release-contract.php','Static contracts must execute final Phase 74 release contract.');
$need('tests/ci/run-full-regression.sh','phase74-section8-end-to-end-simplification-release-db.php','Full regression must execute final Phase 74 integrated journey.');
$need('.github/workflows/full-regression.yml','phase74-section8-end-to-end-simplification-release-db.php','MySQL workflow must execute the Phase 74 final journey.');
$need('.github/workflows/full-regression.yml',"cancel-in-progress: \${{ github.event.action == 'synchronize' }}",'Full regression must cancel superseded feature heads while preserving metadata-edit evidence.');
$need('.github/workflows/package-two-zips.yml','phase74-section8-end-to-end-simplification-release-contract.php','Website package must include final Phase 74 contract.');
$need('.github/workflows/package-two-zips.yml','phase74-section8-end-to-end-simplification-release-db.php','Website package must include final Phase 74 DB journey.');
$need('tests/ci/package-smoke.sh','Phase 74 End-to-End Simplification Release package extensions passed.','Package smoke must explicitly validate final Phase 74 contents.');
$need('docs/RELEASE-V1.1.md','current Phase 74 release-hardened source tree','Production runbook must identify Phase 74 as the current release tree.');
$need('docs/RELEASE-V1.1.md','Phase 74 CI','Production runbook must document the final Phase 74 acceptance matrix.');
$need('docs/RELEASE-V1.1.md','Chat | Knowledge | Research | Reports','Post-deploy runbook must validate the simplified Agent shell.');

if(glob($root.'/database/migrations/*_104_*.sql'))$fail[]='Phase 74 must remain schema-free; migration 104 is not allowed.';
foreach(['research-simplification-worker.php','research-ui-worker.php','research-agent-simplification-worker.php'] as $worker)if(is_file($root.'/worker/'.$worker))$fail[]='Phase 74 must not ship a parallel simplification authority worker: '.$worker;

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 74 Section 8 End-to-End Simplification Release contracts passed.\n";
