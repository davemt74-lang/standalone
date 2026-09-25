<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$fail=[];
$read=function(string $file)use($root,&$fail): string {
    $path=$root.'/'.$file;
    if(!is_file($path)){$fail[]='Missing '.$file;return '';}
    return (string)file_get_contents($path);
};
$need=function(string $file,string $needle,string $message)use($read,&$fail): void {
    $content=$read($file);
    if($content!==''&&!str_contains($content,$needle))$fail[]=$message;
};

$ui=$read('app/admin-ui.php');
$access=$read('app/admin-access.php');
$platform=$read('admin/platform-governance.php');
$index=$read('admin/index.php');
$web=$read('assets/css/app.css');
$ext=$read('extension/landing-app.css');

foreach([
    'Admin V2.61 · Final Admin Hardening',
    'aria-current="page"',
] as $needle){
    if(!str_contains($ui,$needle))$fail[]='Shared Admin shell missing '.$needle;
}
if(!str_contains($index,'ADMIN V2.61 · ADMIN V2.60'))$fail[]='Command Center must identify V2.61 hardening.';
if(!str_contains($index,'<h1>Admin control center</h1>'))$fail[]='Command Center must preserve the established dashboard heading contract.';

$route="'/admin/platform-governance.php'=>['admin.platform.view','admin.platform.view']";
if(!str_contains($access,$route))$fail[]='Platform Governance route must admit authorized viewers and defer POST mutation authority to operation-specific checks.';
foreach([
    "if($op==='feature_preview'){if(!$canManage||!$canRequest)",
    "elseif($op==='module_update'){if(!$canManage)",
    "elseif($op==='integration_update'){if(!$canManage)",
    "elseif($op==='snapshot'){if(!$canRelease)",
    "elseif($op==='execute'){if(!$canExecute)",
] as $needle){
    if(!str_contains($platform,$needle))$fail[]='Platform Governance operation-specific authorization missing '.$needle;
}

if($web!==$ext)$fail[]='Extension landing base CSS must remain exactly synchronized with website base CSS.';
foreach([
    'Admin V2.61 — final Admin hardening',
    '.adminSidebar~main.panel .adminContentSplit>aside.card',
    'background:transparent',
    'outline:2px solid currentColor',
    'overscroll-behavior-inline:contain',
    'overflow-wrap:anywhere',
] as $needle){
    if(!str_contains($web,$needle))$fail[]='V2.61 Admin CSS hardening missing '.$needle;
}

if($ui!==''){
    preg_match_all("/'([a-z_]+)'=>\['label'=>'[^']+','url'=>'([^']+)'\]/",$ui,$matches,PREG_SET_ORDER);
    foreach($matches as $m){
        $url=(string)$m[2];
        $path=(string)(parse_url($url,PHP_URL_PATH)?:$url);
        if(!str_starts_with($path,'/admin/'))continue;
        $routeNeedle="'".$path."'=>";
        if(!str_contains($access,$routeNeedle))$fail[]='Admin nav route '.$path.' has no explicit delegated-access route boundary.';
    }
}

foreach(glob($root.'/admin/*.php')?:[] as $file){
    $content=(string)file_get_contents($file);
    if(!str_contains($content,'admin_ui_sidebar('))continue;
    $name='admin/'.basename($file);
    if(!str_contains($content,'<link rel="stylesheet" href="/assets/css/app.css">'))$fail[]=$name.' must use the shared Admin stylesheet.';
    if(!str_contains($content,'<main class="panel'))$fail[]=$name.' must render inside the shared full-width Admin panel shell.';
}

$need('tests/ci/run-static-contracts.sh','php tests/admin-v2-61-final-hardening-contract.php','Static CI must execute V2.61 hardening contract.');
$need('.github/workflows/package-two-zips.yml','tests/admin-v2-61-final-hardening-contract.php','Production package must include V2.61 hardening contract.');
$need('.github/workflows/package-two-zips.yml','docs/admin-v2-61-final-admin-hardening.md','Production package must include V2.61 hardening documentation.');
$need('tests/ci/package-smoke.sh','admin-v2-61-final-admin-hardening.md','Package smoke must require V2.61 documentation.');
$need('tests/ci/package-smoke.sh','admin-v2-61-final-hardening-contract.php','Package smoke must require V2.61 contract.');

if($fail){
    foreach(array_values(array_unique($fail)) as $f)fwrite(STDERR,"FAIL: $f\n");
    exit(1);
}
echo "Admin V2.61 Final Admin Hardening static contract passed.\n";
