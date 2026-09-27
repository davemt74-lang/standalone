<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p)){$fail[]=$label.' missing '.$file;return;}$c=(string)file_get_contents($p);foreach($needles as $n)if(!str_contains($c,$n))$fail[]=$label.' missing '.$n.' in '.$file;};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p))return;$c=(string)file_get_contents($p);foreach($needles as $n)if(str_contains($c,$n))$fail[]=$label.' must not contain '.$n.' in '.$file;};

$must('database/migrations/20260926_085_research_missions_v1.sql',[
  'CREATE TABLE IF NOT EXISTS research_missions','CREATE TABLE IF NOT EXISTS research_mission_versions','CREATE TABLE IF NOT EXISTS research_mission_criteria',
  'CREATE TABLE IF NOT EXISTS research_mission_subquestions','CREATE TABLE IF NOT EXISTS research_mission_events','fk_research_mission_plan','fk_research_mission_program'
],'Research Missions V1 schema');
$avoid('database/migrations/20260926_085_research_missions_v1.sql',['next_run_at','cadence ENUM','research_mission_jobs'],'Mission schema scheduler isolation');

$must('app/research-missions.php',[
  'research_missions_ready','research_mission_create','research_mission_update','research_mission_set_status','research_mission_list','research_mission_detail',
  'research_mission_add_criterion','research_mission_update_criterion','research_mission_add_subquestion','research_mission_update_subquestion',
  'research_mission_snapshot','research_mission_event'
],'Research Missions V1 runtime');
$avoid('app/research-missions.php',['mail(','PHPMailer','smtp','research_program_enqueue(','research_task_queue('],'Mission foundation execution isolation');

$must('api/research-missions.php',[
  'require_api_mutation_auth','require_api_user','research-missions-write',"\$action==='create'","\$action==='set_status'","\$action==='add_criterion'","\$action==='add_subquestion'",'METHOD_NOT_ALLOWED'
],'Research Missions V1 API');
$must('app/bootstrap.php',['research-missions.php'],'Research Missions bootstrap');
$must('docs/research-missions-v1.md',['Question → Mission → Mission Plan → Research Tasks','Programs remain the recurring/scheduled execution mechanism','Section 1 — Mission Foundation','Migration 085'],'Research Missions architecture');

foreach(['research-mission-worker.php','research-missions-worker.php','mission-worker.php'] as $worker)if(is_file($root.'/worker/'.$worker))$fail[]='Research Missions V1 Section 1 must not add a worker: '.$worker;
$must('tests/ci/run-full-regression.sh',['tests/research-missions-v1-db.php'],'Research Missions regression gate');
$must('.github/workflows/full-regression.yml',['research-missions-v1-db.php','research-missions-v1-upgrade-from-084.php'],'Research Missions MySQL gate');
$must('.github/workflows/package-two-zips.yml',[
  '20260926_085_research_missions_v1.sql','docs/research-missions-v1.md','app/research-missions.php','api/research-missions.php',
  'tests/research-missions-v1-contract.php','tests/research-missions-v1-db.php','tests/ci/research-missions-v1-upgrade-from-084.php'
],'Research Missions production package');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Research Missions V1 Section 1 static contracts passed.\n";
