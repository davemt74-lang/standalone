<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{
    $path=$root.'/'.$file;if(!is_file($path)){$fail[]=$label.' missing '.$file;return;}
    $c=(string)file_get_contents($path);foreach($needles as $needle)if(!str_contains($c,$needle))$fail[]=$label.' missing '.$needle;
};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{
    $path=$root.'/'.$file;if(!is_file($path))return;$c=(string)file_get_contents($path);foreach($needles as $needle)if(str_contains($c,$needle))$fail[]=$label.' must not contain '.$needle;
};

$must('research-missions.php',['/assets/css/app.css?v=71.1','researchMissionsCanvas','researchMissionsTopGrid','researchMissionsStats'],'Research Missions UI cache bust');
$must('research-publications.php',['/assets/css/app.css?v=71.1','publicationCenter','Research Publishing'],'Research Publishing UI cache bust');
$must('assets/css/app.css',['.researchMissionsCanvas{','.researchMissionsTopGrid{','.researchMissionsStats{'],'Research Missions layout CSS');
$must('app/living-research.php',[
  'SELECT DISTINCT rr.public_id,rr.updated_at,rr.id FROM research_reports',
  'ORDER BY rr.updated_at DESC,rr.id DESC'
],'Living Research managed-report MySQL 8 query');
$avoid('app/living-research.php',[
  'SELECT DISTINCT rr.public_id FROM research_reports rr JOIN research_projects'
],'Living Research managed-report MySQL 8 regression');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Post-Phase-71 Research Publications/UI hotfix contracts passed.\n";
