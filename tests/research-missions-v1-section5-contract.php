<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{
    $path=$root.'/'.$file;if(!is_file($path)){$fail[]=$label.' missing '.$file;return;}
    $content=(string)file_get_contents($path);foreach($needles as $needle)if(!str_contains($content,$needle))$fail[]=$label.' missing '.$needle.' in '.$file;
};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{
    $path=$root.'/'.$file;if(!is_file($path))return;$content=(string)file_get_contents($path);foreach($needles as $needle)if(str_contains($content,$needle))$fail[]=$label.' must not contain '.$needle.' in '.$file;
};
$must('research-missions.php',[
    'Research Missions','data-mission-create','researchMissionCommandCenter','MISSION PROGRESS','MISSION PLAN','EVIDENCE',
    'CHANGE RESPONSE','MISSION HISTORY','CONFIGURATION HISTORY','data-mission-action="create_plan"','data-program-bind'
],'Mission Command Center page');
$must('app/research-missions.php',[
    'research_task_plan_detail','evidence_refs','research_program_detail','research_mission_versions'
],'Mission Command Center detail payload');
$must('assets/css/app.css',[
    '.researchMissionsCanvas','.researchMissionsTopGrid','.researchMissionProgressGrid','.researchMissionTaskGraph','.researchMissionProgramPanel',
    '@media(max-width:900px)'
],'Mission Command Center responsive styles');
foreach(['research.php','research-agent-knowledge.php','research-reports.php','research-monitoring.php','research-tasks.php','research-programs.php','research-evolution.php'] as $file)$must($file,['research-missions.php'],'Mission primary navigation');
$must('docs/research-missions-v1.md',['## Section 5 — Mission Command Center','no new migration','full Mission progress is loaded only for the selected Mission'],'Section 5 architecture');
$avoid('research-missions.php',['research_mission_jobs','mission-worker.php','research_program_enqueue('],'Mission UI execution isolation');
$must('tests/ci/run-full-regression.sh',['tests/research-missions-v1-section5-db.php'],'Section 5 regression gate');
$must('.github/workflows/full-regression.yml',['research-missions-v1-section5-db.php'],'Section 5 MySQL gate');
$must('.github/workflows/package-two-zips.yml',['research-missions.php','research-missions-v1-section5-contract.php','research-missions-v1-section5-db.php'],'Section 5 package gate');
if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Research Missions V1 Section 5 static contracts passed.\n";
