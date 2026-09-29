<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $path)use($root,&$fail): string{$p=$root.'/'.$path;if(!is_file($p)){$fail[]='Missing '.$path;return '';}return (string)file_get_contents($p);};
$need=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&!str_contains($c,$needle))$fail[]=$message;};
$avoid=function(string $path,string $needle,string $message)use($read,&$fail): void{$c=$read($path);if($c!==''&&str_contains($c,$needle))$fail[]=$message;};

foreach(['api/create.php','assets/js/create-launcher.js','app/teams-core.php'] as $path)if(!is_file($root.'/'.$path))$fail[]='Global Create file missing '.$path;
$need('app/shell.php','data-create-launcher-open','Universal shell must expose the header + create button.');
$need('app/shell.php','data-create-launcher','Universal shell must render the create modal.');
$need('app/shell.php','What do you want to create?','Create launcher must start with an action chooser.');
foreach(['research_agent','portfolio','mission','task','program','decision','action_plan','document','report','sticky','team','source'] as $action)
    $need('app/shell.php','data-create-action="'.$action.'"','Create launcher missing action '.$action.'.');
foreach([
 'research_agent_create','research_intelligence_portfolio_create','research_mission_create','research_task_create_for_project',
 'research_program_create','research_decision_create','research_action_plan_from_decision','research_agent_workspace_create_document',
 'research_system_report_generate','research_agent_workspace_create_sticky','team_create','ensure_source'
] as $fn)$need('api/create.php',$fn,'Global Create must delegate to canonical function '.$fn.'.');
$need('assets/js/create-launcher.js',"fetch('/api/create.php?action='",'Create launcher forms must submit through the authenticated create router.');
$need('assets/js/create-launcher.js','out.success_criteria','Mission form must normalize success criteria before creation.');
$need('assets/js/create-launcher.js','json.data?.redirect','Successful creation must route directly to the created object.');
$need('app/teams-core.php','function team_create','Team creation must have one reusable canonical helper.');
$need('teams.php','team_create($pdo,$u,$name)','Teams page must reuse the canonical Team helper.');
$avoid('teams.php',"INSERT INTO teams(public_id,owner_user_id,name)",'Teams page must not retain duplicate Team creation SQL.');
$need('assets/css/app.css','/* Global header create launcher */','Create launcher styling must ship in the application CSS.');
$need('assets/css/app.css','.appCreateGrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))','Desktop launcher must present a compact action grid.');
$site=$read('assets/css/app.css');$ext=$read('extension/landing-app.css');
if($site!==''&&$ext!==''&&!hash_equals(hash('sha256',$site),hash('sha256',$ext)))$fail[]='Extension landing CSS must remain byte-identical to website CSS.';
if(glob($root.'/database/migrations/*_104_*create*launcher*.sql'))$fail[]='Global Create launcher must remain schema-free.';
if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Global header Create launcher contract passed.\n";
