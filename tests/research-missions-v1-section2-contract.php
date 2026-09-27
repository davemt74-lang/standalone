<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p)){$fail[]=$label.' missing '.$file;return;}$c=(string)file_get_contents($p);foreach($needles as $n)if(!str_contains($c,$n))$fail[]=$label.' missing '.$n.' in '.$file;};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p))return;$c=(string)file_get_contents($p);foreach($needles as $n)if(str_contains($c,$n))$fail[]=$label.' must not contain '.$n.' in '.$file;};

$must('app/research-missions.php',[
  'research_mission_plan_blueprint','research_mission_create_plan','research_mission_plan_detail','research_mission_start','research_mission_pause',
  "'initial_status'=>'paused'","'create_deliverable'=>false",'mission_plan_created','mission_execution_started','mission_execution_paused','FOR UPDATE'
],'Research Missions V1 Section 2 orchestration');
$must('app/research-tasks.php',[
  "\$initialStatus=(string)(\$input['initial_status']??'active')","\$createDeliverable=array_key_exists('create_deliverable'", "if(\$initialStatus==='active')research_task_queue_ready"
],'Research Task paused-plan compatibility');
$must('api/research-missions.php',["\$action==='create_plan'","\$action==='start'","\$action==='pause'"],'Research Missions V1 Section 2 API');
$must('docs/research-missions-v1.md',['## Section 2 — Mission Planning & Task Orchestration','Plans are created paused','explicit start','existing Research Task queue'],'Research Missions V1 Section 2 architecture');
$avoid('app/research-missions.php',['research_program_enqueue(','research_mission_worker','mission_jobs'],'Mission Section 2 scheduler isolation');
foreach(['research-mission-worker.php','research-missions-worker.php','mission-worker.php'] as $worker)if(is_file($root.'/worker/'.$worker))$fail[]='Research Missions V1 Section 2 must not add a worker: '.$worker;
$must('tests/ci/run-full-regression.sh',['tests/research-missions-v1-section2-db.php'],'Research Missions Section 2 regression gate');
$must('.github/workflows/full-regression.yml',['research-missions-v1-section2-db.php'],'Research Missions Section 2 MySQL gate');
$must('.github/workflows/package-two-zips.yml',['research-missions-v1-section2-contract.php','research-missions-v1-section2-db.php'],'Research Missions Section 2 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Research Missions V1 Section 2 static contracts passed.\n";
