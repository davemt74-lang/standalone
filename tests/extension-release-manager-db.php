<?php
declare(strict_types=1);
/** Integration: immutable per-channel registry, authenticated actor, integrity and rollback. */
$root=dirname(__DIR__);
$dsn=(string)getenv('DB_DSN');
if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,(string)getenv('DB_USER'),(string)getenv('DB_PASS'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
require_once $root.'/app/installer.php';
require_once $root.'/app/storage.php';
require_once $root.'/app/functions.php';
$GLOBALS['allowed_extension_caps']=['admin.platform.manage','admin.platform.release'];
function admin_access_assert_capability(PDO $pdo,array $admin,string $capability):void {
    if(($admin['role']??'')!=='admin'||!in_array($capability,$GLOBALS['allowed_extension_caps'],true))
        throw new RuntimeException('Admin capability denied.');
}
require_once $root.'/app/extension-releases.php';
function extensionDbCheck(bool $valid,string $message):void {
    if(!$valid)throw new RuntimeException('FAIL: '.$message);
    echo "PASS: $message\n";
}
extensionDbCheck(extension_releases_ready($pdo),'Migration 127 created release registry, channel pointer and audit events.');
$run='ext'.substr(bin2hex(random_bytes(5)),0,10);
$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,email_verified_at,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?,NOW(),'active','admin','pro','cloaked')")
    ->execute([$run.'-user',$run,'Extension Release Admin',$run.'@example.test']);
$admin=['id'=>(int)$pdo->lastInsertId(),'role'=>'admin'];
$private=sys_get_temp_dir().'/annotated-extension-regression-'.bin2hex(random_bytes(9));
mkdir($private,0700,true);
$GLOBALS['config']=['storage'=>['private_root'=>$private]];
$config=$GLOBALS['config'];
$fixtures=[];
$manifest=['manifest_version'=>3,'name'=>'Annotated','minimum_chrome_version'=>'116','permissions'=>['sidePanel'],'host_permissions'=>[]];
try{
    foreach([['version'=>'0.36.1','channel'=>'stable'],['version'=>'0.36.2','channel'=>'stable'],['version'=>'0.37.0','channel'=>'beta']] as $candidate){
        $path=$private.'/artifact-'.str_replace('.','-',$candidate['version']).'.zip';
        $zip=new ZipArchive();
        if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)
            throw new RuntimeException('Unable to create fixture ZIP.');
        $m=$manifest;$m['version']=$candidate['version'];
        $zip->addFromString('manifest.json',json_encode($m,JSON_THROW_ON_ERROR));
        $zip->addFromString('service-worker.js','// valid extension');
        $zip->addFromString('sidepanel.html','<!doctype html><html></html>');
        $zip->addFromString('content.js','// content script');
        $zip->addFromString('sidepanel-state.js','// state');
        $zip->addFromString('sidepanel.js','// main');
        $zip->addFromString('options.html','<!doctype html><html></html>');
        $zip->close();
        $validated=extension_release_zip_inspect($path);
        extensionDbCheck($validated['version']===$candidate['version'],'Accepted '.$candidate['version'].' fixture archive.');
        $uri='private://'.gmdate('Y/m').'/extension-release-'.bin2hex(random_bytes(12)).'.zip';
        $target=extension_release_storage_path($config,$uri,true);
        if(!copy($path,$target))throw new RuntimeException('Unable to copy fixture artifact.');
        $id=ulid_like();
        $pdo->prepare('INSERT INTO extension_releases(public_id,version,channel,artifact_uri,sha256,size_bytes,manifest_json,developer_name,release_notes,uploaded_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$candidate['version'],$candidate['channel'],$uri,hash_file('sha256',$target),filesize($target),json_encode($validated,JSON_THROW_ON_ERROR),'Annotated','Release fixture',(int)$admin['id']]);
        $fixtures[$candidate['version']]=['public_id'=>$id,'path'=>$target];
        @unlink($path);
    }
    extensionDbCheck(extension_release_active($pdo,'stable')===null,'No channel is live until an explicit publish.');
    $first=extension_release_publish($pdo,$admin,$fixtures['0.36.1']['public_id'],'Validated stable build.');
    extensionDbCheck($first['version']==='0.36.1'&&extension_release_active($pdo,'stable')['version']==='0.36.1','Publish sets the stable download pointer.');
    $second=extension_release_publish($pdo,$admin,$fixtures['0.36.2']['public_id'],'Validated next stable build.');
    extensionDbCheck($second['version']==='0.36.2'&&extension_release_active($pdo,'stable')['version']==='0.36.2','New stable publish changes pointer but preserves prior release.');
    $beta=extension_release_publish($pdo,$admin,$fixtures['0.37.0']['public_id'],'Beta pilot.');
    extensionDbCheck($beta['version']==='0.37.0'&&extension_release_active($pdo,'stable')['version']==='0.36.2','Beta channel publishes without replacing stable.');
    $restored=extension_release_publish($pdo,$admin,$fixtures['0.36.1']['public_id'],'Return to validated stable.');
    extensionDbCheck($restored['version']==='0.36.1'&&extension_release_active($pdo,'beta')['version']==='0.37.0','Rollback restores earlier immutable stable artifact independently of beta.');
    $events=extension_release_events($pdo);
    extensionDbCheck(count($events)>=4&&$events[0]['event_type']==='restored','Publication and rollback create append-only events.');
    $GLOBALS['allowed_extension_caps']=['admin.platform.manage'];
    try{
        extension_release_publish($pdo,$admin,$fixtures['0.36.2']['public_id'],'Unauthorized publish.');
        throw new RuntimeException('FAIL: Missing publish capability was accepted.');
    }catch(RuntimeException $e){
        if(str_starts_with($e->getMessage(),'FAIL:'))throw $e;
        extensionDbCheck(true,'Manage-only operators cannot publish.');
    }
    $GLOBALS['allowed_extension_caps']=['admin.platform.manage','admin.platform.release'];
    file_put_contents($fixtures['0.36.2']['path'],'corrupted',FILE_APPEND);
    try{
        extension_release_publish($pdo,$admin,$fixtures['0.36.2']['public_id'],'Tamper check.');
        throw new RuntimeException('FAIL: Tampered private artifact was published.');
    }catch(RuntimeException $e){
        if(str_starts_with($e->getMessage(),'FAIL:'))throw $e;
        extensionDbCheck(true,'Corrupted private artifact cannot be published.');
    }
    extensionDbCheck(extension_release_active($pdo,'stable')['version']==='0.36.1','Rejected publication leaves active stable version intact.');
    $pdo->prepare('DELETE FROM extension_release_events WHERE actor_user_id=?')->execute([(int)$admin['id']]);
    $pdo->prepare('DELETE FROM extension_release_channels WHERE updated_by_user_id=?')->execute([(int)$admin['id']]);
    $pdo->prepare('DELETE FROM extension_releases WHERE uploaded_by_user_id=?')->execute([(int)$admin['id']]);
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([(int)$admin['id']]);
    echo "Chrome Extension Release Manager database, publication and rollback tests passed.\n";
}finally{
    foreach($fixtures as $f)if(is_file($f['path']))@unlink($f['path']);
    $dirs=glob($private.'/*/*')?:[];foreach($dirs as $d)@rmdir($d);
    foreach(glob($private.'/*')?:[] as $d)@rmdir($d);
    @rmdir($private);
}
