<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&!str_contains($c,$needle))$fail[]=$message;};

require_once $root.'/app/research-surface-map.php';
require_once $root.'/app/research-legacy-compat.php';

if(research_legacy_query_url('/research-agent-research.php',['agent'=>'agent one','view'=>'recurring'])!=='/research-agent-research.php?agent=agent%20one&view=recurring')$fail[]='Compatibility URLs must RFC3986-encode canonical query state.';
$need('app/bootstrap.php',"/research-legacy-compat.php","Bootstrap must load the Phase 74 compatibility helper.");
foreach([
 'research-automations.php'=>'research-automations.php',
 'research-brief.php'=>'research-brief.php',
 'research-knowledge.php'=>'research-knowledge.php',
 'research-outcomes.php'=>'research-outcomes.php',
 'research-portfolio.php'=>'research-portfolio.php',
 'research-project.php'=>'research-project.php',
 'research-reviews.php'=>'research-reviews.php',
] as $path=>$route)$need($path,"research_legacy_redirect_if_needed(\$pdo,\$u,'".$route."'","Legacy route ".$path." must invoke canonical compatibility routing.");

$command=$read('research-intelligence-command-center.php');
if(!str_contains($command,"research_portfolios_href('overview'"))$fail[]='Legacy Command Center must continue redirecting to canonical Portfolio Overview.';

$map=research_surface_map();$routes=(array)$map['routes'];
if(isset($routes['report.php'])||isset($routes['report-status.php']))$fail[]='Trust & Safety report routes must never be classified as Research Report compatibility routes.';
foreach([
 'research-automations.php'=>'agent.research.recurring',
 'research-brief.php'=>'agent.reports',
 'research-knowledge.php'=>'agent.knowledge',
 'research-outcomes.php'=>'agent.research.decisions',
 'research-portfolio.php'=>'research.home',
 'research-project.php'=>'agent.chat',
 'research-reviews.php'=>'attention',
 'research-intelligence-command-center.php'=>'portfolios.overview',
] as $path=>$target)if(($routes[$path]['target']??'')!==$target)$fail[]=$path.' must retain canonical target '.$target.'.';
if(($map['engines']['app/research-legacy-compat.php']['classification']??'')!=='KEEP_ENGINE')$fail[]='Compatibility helper must be KEEP_ENGINE.';
if(($map['engines']['app/research-legacy-compat.php']['domain']??'')!=='compatibility')$fail[]='Compatibility helper must remain compatibility-only.';

$helper=$read('app/research-legacy-compat.php');
foreach(['INSERT INTO','UPDATE ','DELETE FROM','CREATE TABLE','ALTER TABLE'] as $write)if(str_contains($helper,$write))$fail[]='Compatibility routing must remain read-only; found '.$write.'.';
if(glob($root.'/database/migrations/*_104_*.sql'))$fail[]='Phase 74 Section 7 must not add migration 104.';

$need('docs/phase-74-research-agent-simplification.md','## Section 7 — Legacy Route & Navigation Compatibility','Section 7 implementation documentation is missing.');
$need('tests/ci/run-static-contracts.sh','phase74-section7-legacy-navigation-compatibility-contract.php','Static runner must execute Section 7.');
$need('tests/ci/run-full-regression.sh','phase74-section7-legacy-navigation-compatibility-db.php','Full regression must execute Section 7 DB journey.');
$need('.github/workflows/full-regression.yml','phase74-section7-legacy-navigation-compatibility-db.php','MySQL 8 workflow must execute Section 7 DB journey.');
$need('.github/workflows/package-two-zips.yml','app/research-legacy-compat.php','Production package must include the compatibility helper.');
$need('tests/ci/package-smoke.sh','Phase 74 Section 7 Legacy Route & Navigation Compatibility package extensions passed.','Package smoke must validate Section 7.');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 74 Section 7 Legacy Route & Navigation Compatibility contracts passed.\n";
