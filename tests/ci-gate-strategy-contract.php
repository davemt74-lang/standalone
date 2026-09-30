<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=fn(string $p)=>(string)@file_get_contents($root.'/'.$p);
$section=$read('.github/workflows/section-gate.yml');
$full=$read('.github/workflows/full-regression.yml');
$runner=$read('tests/ci/run-section-gate.sh');
$doc=$read('docs/ci-gate-strategy.md');

foreach([
 ['section', $section, "contains(github.event.pull_request.title, '[section-gate]')", 'Section workflow must require [section-gate].'],
 ['section', $section, 'targeted-mariadb:', 'Section workflow must test MariaDB.'],
 ['section', $section, 'targeted-mysql8:', 'Section workflow must test MySQL 8.'],
 ['section', $section, 'fetch-depth: 0', 'Section workflow must fetch PR base history for changed-test discovery.'],
 ['full', $full, "contains(github.event.pull_request.title, '[release-gate]')", 'Full regression must require [release-gate].'],
 ['runner', $runner, 'git diff --name-only "$BASE_SHA"...HEAD', 'Section runner must discover changed files from PR base.'],
 ['runner', $runner, "^tests/.*-db", 'Section runner must discover changed DB journeys.'],
 ['runner', $runner, 'upgrade', 'Section runner must discover changed migration rehearsals.'],
 ['doc', $doc, '[section-gate]', 'CI strategy must document section gates.'],
 ['doc', $doc, '[release-gate]', 'CI strategy must document release gates.'],
] as [$name,$haystack,$needle,$message])if($haystack===''||!str_contains($haystack,$needle))$fail[]=$message;

if(str_contains($full,"[phase-gate]"))$fail[]='Legacy [phase-gate] trigger must not remain in full regression.';
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}
echo "CI section/release gate strategy contract passed.\n";
