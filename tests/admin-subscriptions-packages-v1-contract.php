<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

$migration='database/migrations/20260924_062_subscriptions_packages_accounts.sql';
foreach(['subscription_packages','subscription_package_admin_events','accounts','account_members','subscription_package_events'] as $needle)$need($migration,$needle,'Subscriptions migration missing: '.$needle);
foreach(['Free Trial','Basic User','Team Builder'] as $needle)$need($migration,$needle,'Seeded subscription package missing: '.$needle);
$need($migration,"research_teams","Seed metadata must explicitly preserve manual Research Team separation.");
$need('app/subscriptions.php','function subscription_ensure_user_account','Subscriptions runtime must provision personal accounts.');
$need('app/subscriptions.php','function subscription_assign_user_package','Admin must be able to assign a user package.');
$need('app/subscriptions.php','function subscription_package_create','Admin package catalog must support create.');
$need('app/subscriptions.php','function subscription_package_update','Admin package catalog must support edit/archive.');
$need('app/subscriptions.php','subscription_package_events','User package changes must be audited.');
$need('app/subscriptions.php','subscription_package_admin_events','Package definition changes must be audited.');
$avoid('app/subscriptions.php','INSERT INTO teams','Subscriptions must never create Research Teams.');
$avoid('app/subscriptions.php','team_members','Subscriptions must remain independent of Research Team membership.');

foreach(['Subscription Packages','Create package','Monthly AI tokens','Member limit','Package definition changes'] as $needle)$need('admin/packages.php',$needle,'Admin Packages UI missing: '.$needle);
foreach(['User accounts & packages','assign_package','package_id','reason'] as $needle)$need('admin/users.php',$needle,'Admin Users package assignment UI missing: '.$needle);
$need('admin/index.php','/admin/packages.php','Admin dashboard must link to Packages.');
$need('register.php','subscriptions_ready($pdo)','Password registration must tolerate the migration deployment window.');
$need('app/oauth.php',"subscriptions_ready","OAuth registration must tolerate the migration deployment window.");
$need('first-admin.php','subscription_ensure_user_account','Fresh install must create the first admin personal account when package schema is ready.');

$latest=glob($root.'/database/migrations/*.sql')?:[];sort($latest,SORT_STRING);$latest=$latest?basename((string)end($latest)):'';
if($latest!=='20260924_062_subscriptions_packages_accounts.sql')$fail[]='Migration 062 must be the current latest migration for the package module.';

$css=(string)file_get_contents($root.'/assets/css/app.css');$ext=(string)file_get_contents($root.'/extension/landing-app.css');
if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';
foreach(['.adminPackageGrid','.adminPackageCard','.adminPackageAssignForm'] as $needle)if(!str_contains($css,$needle))$fail[]='Subscription Admin CSS missing: '.$needle;

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Admin Subscriptions & Packages V1 static contract passed.\n";
