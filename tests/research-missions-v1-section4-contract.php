<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p)){$fail[]=$label.' missing '.$file;return;}$c=(string)file_get_contents($p);foreach($needles as $n)if(!str_contains($c,$n))$fail[]=$label.' missing '.$n.' in '.$file;};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p))return;$c=(string)file_get_contents($p);foreach($needles as $n)if(str_contains($c,$n))$fail[]=$label.' must not contain '.$n.' in '.$file;};
$must('app/research-missions.php',['research_mission_bind_program','research_mission_unbind_program','research_mission_set_program_status','research_mission_program_observe_run','mission_material_change_reactivated',"'paused'"],'Mission Program integration');
$must('app/research-programs.php',['research_mission_program_observe_run','program_quiet','program_completed'],'Existing Program lifecycle hooks');
$must('api/research-missions.php',['bind_program','unbind_program','set_program_status'],'Mission Program API');
$must('docs/research-missions-v1.md',['## Section 4 — Programs & Change Response','start paused','material-change count','no Mission cadence'],'Section 4 architecture');
$avoid('app/research-missions.php',['next_run_at','research_mission_runs','research_mission_jobs'],'Mission scheduler isolation');
foreach(['research-mission-worker.php','research-missions-worker.php','mission-worker.php'] as $worker)if(is_file($root.'/worker/'.$worker))$fail[]='Section 4 must not add a Mission worker: '.$worker;
$must('tests/ci/run-full-regression.sh',['tests/research-missions-v1-section4-db.php'],'Section 4 regression gate');
$must('.github/workflows/full-regression.yml',['research-missions-v1-section4-db.php'],'Section 4 MySQL gate');
$must('.github/workflows/package-two-zips.yml',['research-missions-v1-section4-contract.php','research-missions-v1-section4-db.php'],'Section 4 package gate');
if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Research Missions V1 Section 4 static contracts passed.\n";
