<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$viewer=require_user($pdo);
$publicId=trim((string)($_GET['id']??''));$download=!empty($_GET['download']);
$obj=$publicId!==''?research_agent_workspace_object($pdo,$viewer,$publicId,false):null;
if(!$obj||!in_array((string)($obj['object_type']??''),['upload','recording'],true)){http_response_code(404);exit('File not found.');}
if($obj['object_type']==='upload'){
    $stored=(string)($obj['upload_storage_uri']??'');$mime=(string)($obj['upload_mime_type']??'application/octet-stream');$name=(string)($obj['upload_original_name']??$obj['title']??'upload');
}else{
    $stored=(string)($obj['recording_storage_uri']??'');$mime=(string)($obj['recording_mime_type']??'application/octet-stream');$name=(string)($obj['recording_original_name']??$obj['title']??'recording');
}
$path=storage_path_to_absolute($config,$stored);if(!$path){http_response_code(404);exit('File not found.');}
stream_private_file($path,$mime,$name,$download);
