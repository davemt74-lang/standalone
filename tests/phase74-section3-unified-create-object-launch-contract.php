<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $p)use($root,&$fail): string{$f=$root.'/'.$p;if(!is_file($f)){$fail[]='Missing '.$p;return '';}return (string)file_get_contents($f);};
$need=function(string $p,string $n,string $m)use($read,&$fail): void{$c=$read($p);if($c!==''&&!str_contains($c,$n))$fail[]=$m;};
foreach(['app/research-object-navigation.php','api/object-navigation.php','database/migrations/20260929_105_unified_create_object_launch.sql'] as $p)if(!is_file($root.'/'.$p))$fail[]='Section 3 file missing '.$p;
$need('app/bootstrap.php',"require_once __DIR__ . '/research-object-navigation.php';",'Bootstrap must load object navigation.');
$need('app/research-object-navigation.php','function research_object_link_create','Section 3 must provide one canonical cross-object relationship helper.');
$need('app/research-object-navigation.php','function research_object_recent_touch','Section 3 must provide recent-object tracking.');
$need('app/research-object-navigation.php','function research_object_pin_set','Section 3 must provide pinning.');
$need('app/research-object-navigation.php','function research_object_context_agent_public','Context creation must inherit the current Research Agent.');
$need('api/create.php','research_object_context_from_input','Global Create must accept current-object context.');
$need('api/create.php','research_object_link_create','Global Create must persist created-from lineage.');
$need('app/shell.php','data-object-context-bar','Universal shell must render the shared object header.');
$need('app/shell.php','data-create-shortcuts','Create launcher must expose recent/pinned objects.');
$need('assets/js/create-launcher.js',"/api/object-navigation.php?action=pin",'Object header pinning must use the authenticated navigation API.');
$need('assets/css/create-launcher.css','/* Phase 74 Section 3 — Unified object launch */','Section 3 object UI styles must ship in isolated launcher CSS.');
if($fail){fwrite(STDERR,implode("\n",array_unique($fail))."\n");exit(1);}echo "Phase 74 Section 3 unified create/object launch contract passed.\n";
