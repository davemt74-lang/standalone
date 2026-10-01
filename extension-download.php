<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/extension-releases.php';
$viewer=require_user($pdo);
header('Cache-Control: private, no-store');
$requested=trim((string)($_GET['release']??''));
$release=$requested!==''?extension_release_download_row($pdo,$requested):extension_release_active($pdo,'stable');
if($requested!==''&&!$release){http_response_code(404);exit('Extension release not found.');}
if(!$release){
    // Preserve the existing bundled download until an Admin publishes a stable release.
    $path=__DIR__.'/downloads/Annotated-Chrome-Extension.zip';
    $filename='Annotated-Chrome-Extension.zip';
}else{
    $path=extension_release_storage_path($config,(string)$release['artifact_uri']);
    $filename='Annotated-Chrome-Extension-'.(string)$release['version'].'.zip';
}
if(!is_file($path)||!is_readable($path)){http_response_code(503);exit('Extension package is not currently available.');}
if($release&&(!is_string($actual=hash_file('sha256',$path))||!hash_equals((string)$release['sha256'],$actual))){
    error_log('Annotated extension artifact integrity mismatch for '.(string)$release['public_id']);
    http_response_code(503);exit('Extension package integrity check failed. Contact support.');
}
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Content-Length: '.(string)filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
