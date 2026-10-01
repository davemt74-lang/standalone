<?php
declare(strict_types=1);
/** Public demo samples must follow the Admin setting without entering real campaign flows. */
$root=dirname(__DIR__);$errors=[];
$verify=static function(bool $ok,string $message)use(&$errors):void{if(!$ok)$errors[]=$message;};
require_once $root.'/app/sponsored-research-project-compensation.php';
$fixtures=[
 ['public_id'=>'sample-public','title'=>'Retail Test','brief'=>'Research the market','organization_name'=>'Sample Labs','requirements'=>['Verified'],'status'=>'open','access_mode'=>'public','sample_data'=>1],
 ['public_id'=>'sample-private','title'=>'Private Sample','status'=>'open','access_mode'=>'private','sample_data'=>1],
 ['public_id'=>'sample-draft','title'=>'Draft Sample','status'=>'draft','access_mode'=>'public','sample_data'=>1],
 ['public_id'=>'live','title'=>'Not a Sample','status'=>'open','access_mode'=>'public','sample_data'=>0]
];
$visible=sponsored_project_public_sample_rows($fixtures);
$verify(count($visible)===1&&$visible[0]['public_id']==='sample-public','Public sample preview must exclude private, draft, and non-sample records.');
$verify(count(sponsored_project_public_sample_rows($fixtures,'retail'))===1,'Search must find eligible example by title.');
$verify(sponsored_project_public_sample_rows($fixtures,'missing')===[],'Public sample search must filter unmatched examples.');
$verify(sponsored_project_public_sample_rows($fixtures,'sample labs')[0]['public_id']==='sample-public','Search includes sample organization.');
$admin=file_get_contents($root.'/admin/sponsored-projects.php');
$backend=file_get_contents($root.'/app/sponsored-research-project-compensation.php');
$public=file_get_contents($root.'/research-projects.php');
$sponsor=file_get_contents($root.'/sponsored-research.php');
$researcher=file_get_contents($root.'/research-sponsored-projects.php');
$verify(str_contains($backend,'sponsored_project_sample_projects($pdo)')&&str_contains($backend,"sample_data_enabled"),'Public preview must reuse the existing Admin-controlled samples.');
$verify(str_contains($admin,'sponsored_project_sample_toggle('),'Existing Admin setting remains the only switch.');
$verify(str_contains($public,'sponsored_project_public_samples($pdo,$query)')&&str_contains($public,'SAMPLE — NOT AVAILABLE'),'Public discovery visibly labels read-only samples.');
$verify(str_contains($sponsor,'sponsored_project_sample_projects($pdo)')&&str_contains($sponsor,'SAMPLE — NOT ACTIVE'),'Sponsor dashboard includes disabled sample project cards.');
$verify(str_contains($researcher,'sponsored_project_public_samples($pdo)')&&str_contains($researcher,'SAMPLE — NOT AVAILABLE'),'Researcher page includes disabled public samples.');
$verify(!str_contains($public,'sponsored_project_sample_projects('),'Public operational marketplace must not directly merge Admin fixtures into real campaign results.');
$verify(str_contains($public,'count($projects)'),'Real public listing counts must remain unchanged.');
$verify(!str_contains($backend,'UNION SELECT'),'Samples must not enter a real campaign SQL query.');
if($errors){foreach($errors as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Sponsored sample demo visibility and operational separation passed.\n";
