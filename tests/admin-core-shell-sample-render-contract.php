<?php
declare(strict_types=1);
/** Render the real Sponsored Projects template, not a mock of its switch. */
$root=dirname(__DIR__);
require_once $root.'/app/functions.php';
function admin_ui_sidebar(string $active=''):string{return '<aside class="adminSidebar">Admin</aside>';}
function admin_access_has_capability(PDO $pdo,array $admin,string $cap):bool{return $cap==='admin.research_data.manage';}
function assertAdminUi(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException('FAIL: '.$reason);echo "PASS: $reason\n";}
function renderSponsoredAdmin(bool $enabled):string{
    global $root;
    $_SESSION['csrf']='test-admin-csrf-token';
    $source=(string)file_get_contents($root.'/admin/sponsored-projects.php');
    $point=strpos($source,'?><!doctype html>');
    if($point===false)throw new RuntimeException('Sponsored Admin template boundary missing.');
    $pdo=(new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
    $admin=['id'=>5,'role'=>'admin'];$error='';$success='';
    $projects=[];$campaign=null;$assignments=[];$submissions=[];
    $sampleSettings=['sample_data_enabled'=>$enabled?1:0];
    $sampleProjects=$enabled?[['title'=>'Sample Alpha','organization_name'=>'Sample Lab','status'=>'open',
      'access_mode'=>'public','brief'=>'Example project for layout verification','budget_currency'=>'USD',
      'researcher_compensation_cents'=>2500,'assigned_agents'=>0,'submissions'=>0,
      'submission_deadline'=>'2026-12-31','requirements'=>['Verified researcher']]]:[];
    $before=ob_get_level();ob_start();
    try{eval('?>'.substr($source,$point+2));return (string)ob_get_clean();}
    catch(Throwable $e){while(ob_get_level()>$before)ob_end_clean();throw $e;}
}
$off=renderSponsoredAdmin(false);
assertAdminUi(str_contains($off,'Sample project data')&&str_contains($off,'</html>'),
    'Sponsored Admin page renders through the footer, not just the sample description.');
assertAdminUi(str_contains($off,'name="op" value="sample_toggle"')&&str_contains($off,'name="csrf"'),
    'Sample control renders a CSRF-protected POST form.');
assertAdminUi(str_contains($off,'role="switch"')&&str_contains($off,'Sample data OFF')&&
    !str_contains($off,'name="sample_data_enabled" value="1" checked'),
    'Accessible switch is visible and disabled when sample data is OFF.');
assertAdminUi(!str_contains($off,'Sample Alpha'),'Sample content stays hidden when OFF.');
$on=renderSponsoredAdmin(true);
assertAdminUi(str_contains($on,'Sample data ON')&&str_contains($on,'name="sample_data_enabled" value="1" checked'),
    'Enabled control reflects persistent ON state.');
assertAdminUi(str_contains($on,'Sample Alpha')&&str_contains($on,'SAMPLE DATA'),
    'Enabled sample state displays sample cards with an explicit sample label.');
$shell=(string)file_get_contents($root.'/app/shell.php');
$css=(string)file_get_contents($root.'/assets/css/app.css');
$ext=(string)file_get_contents($root.'/extension/landing-app.css');
$adminSearch=(string)file_get_contents($root.'/admin/search.php');
assertAdminUi(str_contains($shell,'appShellAdminLegacy')&&str_contains($shell,'$adminOwnsSidebar')&&
    str_contains($shell,'app_shell_admin_search('),
    'One Admin sidebar and the real Admin search are provided by the shared shell.');
assertAdminUi(str_contains($shell,'action="/admin/search.php"')&&
    str_contains($adminSearch,'admin_ops_global_search(')&&
    !str_contains($adminSearch,'data-command-palette-open'),
    'Header search submits to a real Admin search route, not an unavailable palette.');
assertAdminUi(str_contains($css,'/* Canonical Admin geometry:')&&
    str_contains($css,'--admin-nav-width:268px')&&
    str_contains($css,'.appShellAdminLegacy .adminSidebar~main.panel>*')&&
    str_contains($css,'.appShellAdminLegacy .appShellHeaderAdmin .appHeaderSearch'),
    'Every Admin page inherits centered content and header search geometry from core CSS.');
assertAdminUi(!str_contains($css,'.adminDashboardPage{width:auto!important}')&&
    !str_contains($css,'max-width:none!important;}'),
    'Legacy dashboard width overrides must not defeat the canonical centered canvas.');
assertAdminUi(hash_equals(hash('sha256',$css),hash('sha256',$ext)),
    'Website and extension stylesheets are identical.');
echo "Admin core shell, search and sample-data rendering regression passed.\n";
