<?php
declare(strict_types=1);
/** V1 security release contract: public HTTP boundary, no database needed. */
$root=dirname(__DIR__);
$ht=(string)file_get_contents($root.'/.htaccess');
$runbook=(string)file_get_contents($root.'/docs/v1-webserver-and-recovery.md');
$failed=[];
$check=static function(bool $ok,string $msg)use(&$failed):void{if(!$ok)$failed[]=$msg;};
$check(!is_file($root.'/reset-admin.php'),'Legacy unauthenticated admin reset page must never ship.');
$check(str_contains($runbook,'Nginx')&&str_contains($runbook,'stale reset-admin.php')&&str_contains($runbook,'admin-password-reset.enable'),
 'Recovery and two-server webroot runbook is present.');
$rules=[];
foreach(preg_split('/\r?\n/',$ht) as $line)
 if(preg_match('/^RewriteRule\s+(\S+)\s+-\s+\[F,L,NC\]$/',$line,$matches))$rules[]=$matches[1];
$blocked=static function(string $path)use($rules):bool{
 foreach($rules as $pattern)if(preg_match('~'.$pattern.'~i',$path)===1)return true;
 return false;
};
foreach(['reset-admin.php','app/bootstrap.php','bin/release-backup.php',
 'database/schema.sql','docs/sponsored-agent-e2e-4e.md',
 'tests/phase81-v1-release-webroot-contract.php','worker/ai-worker.php',
 'storage/.htaccess','README.md','AUDIT.md','admin-password-reset.enable',
 '.github/workflows/ci.yml','.git/config'] as $path)
 $check($blocked($path),'Missing HTTP deny for '.$path);
foreach(['index.php','login.php','api/extension.php','admin/extension-releases.php',
 'assets/css/app.css','uploads/profiles/profile-sample.jpg',
 'extension/manifest.json','.well-known/acme-challenge/example'] as $path)
 $check(!$blocked($path),'Legitimate route blocked: '.$path);
$first=strpos($ht,'RewriteRule ^reset-admin\\.php$');
$bypass=strpos($ht,'RewriteCond %{REQUEST_FILENAME} -f');
$check($first!==false&&$bypass!==false&&$first<$bypass,'Deny rules must precede the existing-file bypass.');
if($failed){foreach($failed as $error)fwrite(STDERR,'FAIL: '.$error.PHP_EOL);exit(1);}
echo "V1 webroot access and legacy recovery contract passed.\n";
