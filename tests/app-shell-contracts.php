<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use($root,&$fail){$s=(string)file_get_contents($root.'/'.$file);if(!str_contains($s,$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use($root,&$fail){$s=(string)file_get_contents($root.'/'.$file);if(str_contains($s,$needle))$fail[]=$message;};

foreach(['app/shell.php','chrome-extension.php','team.php','teams.php'] as $file)if(!is_file($root.'/'.$file))$fail[]="Missing product shell file: $file";
$need('app/bootstrap.php',"/shell.php",'Bootstrap must load the universal application shell.');
$need('app/functions.php','app_shell_activate($pdo,$user)','Authenticated identity must activate the shared shell.');
$need('app/functions.php','profile_image_url','Current user identity must expose the profile image.');
$need('app/shell.php','/teams.php','Teams must be first-class universal navigation.');
$need('app/shell.php','/chrome-extension.php','Universal shell must expose Chrome extension download/install.');
$need('app/shell.php','appUserMenu','Universal header must include the profile/avatar dropdown.');
$need('app/shell.php','appShellFooter','Every authenticated product page must inherit the shared footer.');
$need('app/shell.php','$isAdmin','Admin navigation must be role-gated.');
$need('app/shell.php','app_shell_admin_nav','Admin pages must use the dedicated permission-aware admin navigation.');
$need('app/shell.php','if($pro)','Ask Annotated navigation must be plan/permission gated.');
$need('teams.php','/team.php?id=','Teams list must open real team workspaces.');
$need('team.php','team_members','Team workspace must expose permission-scoped membership.');
$need('team.php','profile_image_url','Team members must display social identity/profile images.');
$need('home.php','Discover researchers','Home must expose social discovery.');
$need('home.php','Your teams','Home must expose team membership.');
$need('settings.php','profile_image_url','Settings must let users control their profile picture.');
$need('chrome-extension.php','Annotated-Chrome-V0.9.0.zip','Extension page must offer the packaged Chrome download.');
$need('.github/workflows/package-two-zips.yml','package-website/downloads/Annotated-Chrome-V0.9.0.zip','Website package must embed the Chrome extension ZIP.');
$need('live.php','if($sourcePublic','Live navigation must have a directory rather than require a source ID.');
$need('ai.php','catch(PDOException $e)','Ask Annotated must tolerate pre-upgrade project ordering during migration recovery.');

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Universal app shell and social product contracts passed.\n";
