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

$must('database/migrations/20260927_086_research_missions_collaboration_cognition_reporting.sql',["'mission'"],'Migration 086 Mission review compatibility');
$must('app/research-missions.php',[
  'research_mission_review_state_hash','research_mission_agent_context','research_mission_cognitive_observations',
  'research_mission_report_data','research_mission_render_report'
],'Mission cognition and reporting runtime');
$must('app/agent-chat.php',['$type===\'mission\'','research_mission_agent_context'],'Mission Agent Chat integration');
$must('app/cognitive-feed.php',['research_mission_cognitive_observations'],'Mission Now integration');
$must('app/research-reviews.php',['$type===\'mission\'','research_mission_review_state_hash'],'Mission Review Center subject');
$must('app/research-system-reports.php',["'mission_brief'","'mission_review'",'research_mission_report_data','research_mission_render_report'],'Mission Report Studio types');
$must('app/research-report-studio.php',['$out[\'missions\']','$snapshot[\'missions\'][\'missions\']'],'Mission Report Studio manifests');
$must('app/agent-actions.php',["'research.create_mission'",'research_mission_create'],'Governed Agent Mission creation');
$must('research-missions.php',['Request team review','Run Mission Brief','Run Review Brief'],'Mission Command Center integrations');
$must('research-reviews.php',['Claims, Findings, Missions'],'Review Center Mission language');
$must('docs/research-missions-v1.md',['## Section 6 — Agent Cognition, Collaboration & Reporting','Migration 086','cannot cast review votes'],'Section 6 architecture');
$avoid('app/research-missions.php',['research_mission_review_assignments','research_mission_report_jobs','research_mission_chat_jobs'],'Mission integration isolation');
foreach(['research-mission-worker.php','research-missions-worker.php','mission-worker.php'] as $worker)if(is_file($root.'/worker/'.$worker))$fail[]='Section 6 must not add a Mission worker: '.$worker;
$must('tests/ci/run-full-regression.sh',['tests/research-missions-v1-section6-db.php'],'Section 6 regression gate');
$must('.github/workflows/full-regression.yml',['research-missions-v1-section6-db.php','research-missions-v1-upgrade-from-085.php'],'Section 6 MySQL and upgrade gates');
$must('.github/workflows/package-two-zips.yml',[
  '20260927_086_research_missions_collaboration_cognition_reporting.sql',
  'research-missions-v1-section6-contract.php','research-missions-v1-section6-db.php','research-missions-v1-upgrade-from-085.php'
],'Section 6 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Research Missions V1 Section 6 static contracts passed.\n";
