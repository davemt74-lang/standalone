<?php
declare(strict_types=1);
/** Release metadata, archive hardening, permission and route contracts. */
$root=dirname(__DIR__);$failed=[];
$check=static function(bool $ok,string $msg)use(&$failed):void{if(!$ok)$failed[]=$msg;};
require $root.'/app/extension-releases.php';
$valid=['manifest_version'=>3,'name'=>'Annotated','version'=>'0.36.1','minimum_chrome_version'=>'116',
    'permissions'=>['sidePanel','activeTab'],'host_permissions'=>['https://example.test/*'],
    'background'=>['service_worker'=>'service-worker.js'],'side_panel'=>['default_path'=>'sidepanel.html']];
$v=extension_release_manifest_validate($valid);
$check($v['version']==='0.36.1'&&$v['manifest_version']===3&&count($v['permissions'])===2,'Normalizes valid MV3 version/permissions.');
foreach([
    array_merge($valid,['manifest_version'=>2]),
    array_merge($valid,['version'=>'0.36;rm -rf /']),
    array_merge($valid,['version'=>'01.2.3']),
    array_merge($valid,['permissions'=>'not an array']),
    array_merge($valid,['minimum_chrome_version'=>'Chrome latest'])
] as $bad){
    try{extension_release_manifest_validate($bad);$check(false,'Invalid manifest accepted.');}
    catch(InvalidArgumentException $expected){}
}
$meta=extension_release_meta_validate(['developer_name'=>'Annotated','release_notes'=>'Fix browser capture','channel'=>'beta','build_date'=>'2026-10-01','developer_url'=>'https://example.com']);
$check($meta['channel']==='beta'&&$meta['developer_name']==='Annotated','Release metadata validates publisher, notes, date and channel.');
foreach([
  ['developer_name'=>'','release_notes'=>'hi'],
  ['developer_name'=>'Annotated','release_notes'=>''],
  ['developer_name'=>'Annotated','release_notes'=>'notes','channel'=>'unknown'],
  ['developer_name'=>'Annotated','release_notes'=>'notes','build_date'=>'2026-02-31'],
  ['developer_name'=>'Annotated','release_notes'=>'notes','developer_url'=>'http://example.com']
] as $bad){
    try{extension_release_meta_validate($bad);$check(false,'Invalid release metadata accepted.');}
    catch(InvalidArgumentException $expected){}
}
if(!class_exists(ZipArchive::class)){$failed[]='PHP ZipArchive extension is required for managed release validation.';}
else{
    $path=tempnam(sys_get_temp_dir(),'extension-contract-');$zip=new ZipArchive();
    $write=static function(array $names)use($path,$zip):void{
        if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Unable to create test ZIP.');
        foreach($names as $name=>$body)$zip->addFromString($name,$body);
        $zip->close();
    };
    $canonical=['manifest.json'=>json_encode($valid,JSON_THROW_ON_ERROR),'service-worker.js'=>'','sidepanel.html'=>'<html></html>','content.js'=>'','sidepanel-state.js'=>'','sidepanel.js'=>'','options.html'=>'<html></html>'];
    try{
        $write($canonical);
        $m=extension_release_zip_inspect($path);
        $check($m['version']==='0.36.1','Accepts valid Annotated ZIP with root manifest and runtime files.');
        $write(array_diff_key($canonical,['manifest.json'=>true])+['sub/manifest.json'=>$canonical['manifest.json']]);
        try{extension_release_zip_inspect($path);$check(false,'Accepted a nested manifest.');}catch(InvalidArgumentException $e){}
        $write($canonical+['../outside.js'=>'']);
        try{extension_release_zip_inspect($path);$check(false,'Accepted ZIP path traversal.');}catch(InvalidArgumentException $e){}
        $write(array_diff_key($canonical,['service-worker.js'=>true]));
        try{extension_release_zip_inspect($path);$check(false,'Accepted ZIP missing background worker.');}catch(InvalidArgumentException $e){}
    }finally{@unlink($path);}
}
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
foreach(['extension_releases','extension_release_channels','extension_release_events'] as $table)
    $check(str_contains($read('database/migrations/20261001_127_extension_release_manager.sql'),$table),'Migration 127 defines '.$table.'.');
$check(str_contains($read('admin/extension-releases.php'),"require_csrf()")&&
       str_contains($read('admin/extension-releases.php'),"admin.platform.release"),'Admin mutation requires CSRF and explicit publish capability.');
$check(str_contains($read('app/admin-ui.php'),"'extension_releases'=>['label'=>'Chrome Extension Releases'"),'Admin navigation includes extension release management.');
$check(str_contains($read('app/admin-access.php'),"'/admin/extension-releases.php'=>['admin.platform.view','admin.platform.manage']"),'Admin route guards upload against platform-manage capability.');
$check(str_contains($read('chrome-extension.php'),"extension_release_active(")&&str_contains($read('chrome-extension.php'),'Earlier stable versions'),'User download surface consumes managed publication pointer and history.');
$check(str_contains($read('extension-download.php'),"hash_equals(")&&str_contains($read('extension-download.php'),'require_user('),'Downloads require authenticated users and verify artifact integrity.');
$check(str_contains($read('app/extension-releases.php'),"move_uploaded_file(")&&str_contains($read('app/extension-releases.php'),'private_storage_root('),'Admin uploads use verified HTTP upload and private storage.');
if($failed){foreach($failed as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Chrome Extension Release Manager static and archive security contracts passed.\n";
