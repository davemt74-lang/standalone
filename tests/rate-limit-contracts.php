<?php
declare(strict_types=1);$root=dirname(__DIR__);$fail=[];$need=function(string $file,string $needle,string $message)use($root,&$fail){$s=(string)file_get_contents($root.'/'.$file);if(!str_contains($s,$needle))$fail[]=$message;};
$need('app/bootstrap.php','rate-limit.php','Bootstrap must load rate limiting helpers.');
$need('login.php','login-ip','Login must be IP rate limited.');$need('login.php','login-account','Login must be account rate limited.');
$need('register.php','register-ip','Registration must be IP rate limited.');$need('register.php','register-email','Registration must be identity rate limited.');
$need('api/extension-token.php','extension-token-ip','Extension token exchange must be IP rate limited.');
$need('api/extension.php','extension-'.$action='','');
$api=(string)file_get_contents($root.'/api/extension.php');foreach(['publish','comment','live_message'] as $action)if(!str_contains($api,"'$action'=>"))$fail[]="Extension mutation rate limit missing: $action";
$research=(string)file_get_contents($root.'/research-project.php');if(!str_contains($research,"'ai-pro'"))$fail[]='Pro AI requests must have an account quota.';if(!str_contains($research,"'ai-admin'"))$fail[]='Admin interactive AI requests must have a higher account quota.';
if(!is_file($root.'/database/migrations/20260917_007_rate_limits.sql'))$fail[]='Rate limit migration 007 is missing.';
if($fail){foreach($fail as $f)if($f!=='')fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Rate limit contracts passed.\n";
