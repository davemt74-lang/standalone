<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p)){$fail[]=$label.' missing '.$file;return;}$c=(string)file_get_contents($p);foreach($needles as $n)if(!str_contains($c,$n))$fail[]=$label.' missing '.$n.' in '.$file;};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p))return;$c=(string)file_get_contents($p);foreach($needles as $n)if(str_contains($c,$n))$fail[]=$label.' must not contain '.$n.' in '.$file;};
$must('app/research-missions.php',[
 'research_mission_progress_for_row','research_mission_progress','research_mission_sync_execution',
 "'percent_complete'","'completion_readiness'","'open_contradictions'","'confidence_average'",
 "'mission_ready_for_review'","'mission_execution_reopened'"
],'Research Missions V1 Section 3 runtime');
$must('app/research-tasks.php',[
 "function_exists('research_mission_sync_execution')","research_mission_sync_execution(\$pdo,\$planId)"
],'Research Missions V1 Section 3 Task lifecycle hook');
$must('api/research-missions.php',["'progress'","\$action==='progress'"],'Research Missions V1 Section 3 API');
$must('docs/research-missions-v1.md',['## Section 3 — Mission Execution & Progress Engine','completion readiness','does not auto-complete','never invents confidence'],'Research Missions V1 Section 3 architecture');
$avoid('app/research-missions.php',['research_mission_jobs','research_mission_worker','confidence = 1','confidence=1'],'Mission progress integrity');
foreach(['research-mission-worker.php','research-missions-worker.php','mission-worker.php'] as $worker)if(is_file($root.'/worker/'.$worker))$fail[]='Research Missions V1 Section 3 must not add a worker: '.$worker;
$must('tests/ci/run-full-regression.sh',['tests/research-missions-v1-section3-db.php'],'Research Missions Section 3 regression gate');
$must('.github/workflows/full-regression.yml',['research-missions-v1-section3-db.php'],'Research Missions Section 3 MySQL gate');
$must('.github/workflows/package-two-zips.yml',['research-missions-v1-section3-contract.php','research-missions-v1-section3-db.php'],'Research Missions Section 3 package gate');
if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Research Missions V1 Section 3 static contracts passed.\n";
