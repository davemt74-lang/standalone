<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&!str_contains($c,$needle))$fail[]=$message;};

foreach(range(1,7) as $section){
    $matches=glob($root.'/tests/phase74-section'.$section.'-*.php')?:[];
    if(count($matches)<1)$fail[]='Phase 74 Section '.$section.' coverage is missing.';
}
foreach([
 'app/research-agent-shell-ui.php','app/research-agent-knowledge-ui.php','app/research-agent-research-ui.php','app/research-agent-reports-ui.php',
 'app/research-portfolios-ui.php','app/research-legacy-compat.php','app/research-surface-map.php',
 'research.php','research-agent-knowledge.php','research-agent-research.php','research-reports.php','research-intelligence-portfolios.php'
] as $path)if(!is_file($root.'/'.$path))$fail[]='Phase 74 final release file missing '.$path;

require_once $root.'/app/research-surface-map.php';
$map=research_surface_map();
$canonical=[];
foreach((array)$map['canonical_ui'] as $group)foreach($group as $key=>$row)$canonical[$key]=true;
foreach(['agent.chat','agent.knowledge','agent.research','agent.reports','research.home','portfolios','attention'] as $key)
    if(empty($canonical[$key]))$fail[]='Final canonical UI is missing '.$key.'.';
foreach((array)$map['engines'] as $path=>$row)if(($row['classification']??'')!=='KEEP_ENGINE')$fail[]='Final release may not retire engine '.$path.'.';
foreach((array)$map['database_concepts'] as $key=>$row)if(($row['classification']??'')!=='KEEP_ENGINE')$fail[]='Final release may not retire database concept '.$key.'.';

$need('docs/phase-74-research-agent-simplification.md','## Section 8 — End-to-End Simplification Release & Hardening','Phase 74 final release documentation is missing.');
$need('docs/phase-74-research-agent-simplification.md','Chat → Knowledge → Research → Reports → Portfolios','Final simplified journey must be explicit.');
$need('docs/phase-74-research-agent-simplification.md','migration 103 remains the schema boundary','Phase 74 final schema boundary must be explicit.');
$need('tests/ci/run-static-contracts.sh','phase74-section8-end-to-end-release-contract.php','Static runner must execute Phase 74 final release contract.');
$need('tests/ci/run-full-regression.sh','phase74-section8-end-to-end-release-db.php','Full regression must execute Phase 74 final integrated journey.');
$need('.github/workflows/full-regression.yml','phase74-section8-end-to-end-release-db.php','MySQL workflow must execute Phase 74 final integrated journey.');
$need('.github/workflows/package-two-zips.yml','phase74-section8-end-to-end-release-contract.php','Production package must include Phase 74 final release contract.');
$need('.github/workflows/package-two-zips.yml','phase74-section8-end-to-end-release-db.php','Production package must include Phase 74 final DB journey.');
$need('tests/ci/package-smoke.sh','Phase 74 End-to-End Simplification Release & Hardening package extensions passed.','Package smoke must explicitly validate final Phase 74 contents.');
$need('docs/RELEASE-V1.1.md','Phase 74 simplification release','V1.1 runbook must document Phase 74 release validation.');

if(glob($root.'/database/migrations/*_104_*.sql'))$fail[]='Phase 74 must remain schema-free; migration 104 is not allowed.';
foreach(['research-simplification-worker.php','research-agent-ui-worker.php','research-compatibility-worker.php'] as $worker)
    if(is_file($root.'/worker/'.$worker))$fail[]='Phase 74 must not ship a parallel UI/authority worker: '.$worker;

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 74 Section 8 End-to-End Simplification Release & Hardening contracts passed.\n";
