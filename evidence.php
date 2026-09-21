<?php
declare(strict_types=1);require __DIR__.'/app/bootstrap.php';require_once __DIR__.'/app/evidence-access.php';require_once __DIR__.'/app/extension-auth.php';
if(!empty($_SERVER['HTTP_ORIGIN'])&&str_starts_with((string)$_SERVER['HTTP_ORIGIN'],'chrome-extension://'))extension_api_headers($config);
$viewer=current_user($pdo);$asset=(string)($_GET['asset']??'');$stored=null;
if(!empty($_GET['annotation'])){
    $stored=evidence_annotation_asset($pdo,(string)$_GET['annotation'],$viewer,$asset);
}elseif(!empty($_GET['source'])&&!empty($_GET['version'])&&$asset==='source_snapshot'){
    $stored=evidence_source_snapshot($pdo,(string)$_GET['source'],(int)$_GET['version'],$viewer);
}
if(!$stored){http_response_code(404);exit('Evidence not found.');}$abs=storage_path_to_absolute($config,$stored);if(!$abs){http_response_code(404);exit('Evidence not found.');}stream_evidence_file($abs);
