<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$fail=[];
$manifest=json_decode((string)file_get_contents($root.'/extension/manifest.json'),true);
if(($manifest['manifest_version']??null)!==3)$fail[]='Chrome extension must remain Manifest V3.';
$annotation=(string)file_get_contents($root.'/annotation.php');
if(!str_contains($annotation,'File a claim'))$fail[]='Public annotation page must expose File a claim.';
$api='';foreach(glob($root.'/api/*.php') as $f)$api.="\n".file_get_contents($f);
if(!str_contains($api,'90'))$fail[]='Server/API must contain the 90-second media limit contract.';
$migrations=array_values(array_filter(glob($root.'/database/migrations/*.sql')?:[],'is_file'));
sort($migrations,SORT_STRING);$seen=[];$last='';
foreach($migrations as $file){$base=basename($file);if(!preg_match('/^(\d{8}_\d{3})_/', $base,$m)){$fail[]="Invalid migration filename: $base";continue;}if(isset($seen[$m[1]]))$fail[]="Duplicate migration version: {$m[1]}";$seen[$m[1]]=1;if($last!==''&&strcmp($m[1],$last)<=0)$fail[]='Migrations are not strictly ordered.';$last=$m[1];}
if(!is_file($root.'/database/migrations/20260917_003_profiles_settings_search_collections.sql'))$fail[]='V1 beta migration 003 missing.';
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Release contracts passed (".count($migrations)." migrations).\n";
