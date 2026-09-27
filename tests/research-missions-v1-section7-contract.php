<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$file=$root.'/'.$path;if(!is_file($file)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($file);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$body=$read($path);if($body!==''&&!str_contains($body,$needle))$fail[]=$message;};

$page=$read('research-missions.php');$css=$read('assets/css/app.css');$extCss=$read('extension/landing-app.css');
foreach(['researchMissionsHero','researchMissionsTopGrid','researchMissionCommandCenter','researchMissionProgressGrid','researchMissionTaskGraph','researchMissionProgramPanel','Request team review','Run Mission Brief'] as $needle)
    if(!str_contains($page,$needle))$fail[]='Mission Command Center release surface missing '.$needle;
foreach(['@media(max-width:1180px)','@media(max-width:900px)','@media(max-width:620px)','.researchMissionsTopGrid','.researchMissionProgressGrid'] as $needle)
    if(!str_contains($css,$needle))$fail[]='Mission desktop/mobile responsive contract missing '.$needle;
if($css===''||$extCss===''||!hash_equals(hash('sha256',$css),hash('sha256',$extCss)))$fail[]='Website and extension landing base CSS must remain byte-identical for Mission release.';
$need('extension/manifest.json','"manifest_version": 3','Chrome extension must remain Manifest V3.');
foreach([
 'research.php','research-agent-knowledge.php','research-reports.php','research-monitoring.php','research-tasks.php','research-programs.php','research-evolution.php'
] as $nav)$need($nav,'research-missions.php','Primary Research navigation must expose Missions in '.$nav.'.');
foreach([
 'database/migrations/20260926_085_research_missions_v1.sql',
 'database/migrations/20260927_086_research_missions_collaboration_cognition_reporting.sql',
 'app/research-missions.php','api/research-missions.php','research-missions.php',
 'tests/research-missions-v1-section7-db.php','tests/ci/research-missions-v1-final-upgrade-from-084.php'
] as $path)if(!is_file($root.'/'.$path))$fail[]='Final Mission release file missing '.$path;
$need('tests/ci/package-smoke.sh','Research Missions V1 final release package extensions passed.','Package smoke must explicitly validate Research Missions V1.');
$need('tests/ci/package-smoke.sh','research-missions.php','Package smoke must lint the Mission Command Center.');
$need('tests/ci/package-smoke.sh','20260927_086_research_missions_collaboration_cognition_reporting.sql','Package smoke must require migration 086.');
$need('.github/workflows/full-regression.yml','research-missions-v1-final-upgrade-from-084.php','MySQL gate must exercise the full 084→086 Mission upgrade.');
$need('.github/workflows/package-two-zips.yml','research-missions-v1-section7-contract.php','Production package must include Section 7 acceptance contracts.');
$need('docs/research-missions-v1.md','## Section 7 — End-to-End Hardening & Release','Mission V1 final acceptance documentation is required.');
foreach(['research-mission-worker.php','research-missions-worker.php','mission-worker.php'] as $worker)if(is_file($root.'/worker/'.$worker))$fail[]='Research Missions V1 must not ship a Mission-specific worker: '.$worker;

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Research Missions V1 Section 7 release contracts passed.\n";
