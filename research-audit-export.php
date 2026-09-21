<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';$u=require_user($pdo);$id=(string)($_GET['id']??'');$receipt=provenance_receipt_access($pdo,$u,$id);if(!$receipt){http_response_code(404);exit('Audit receipt not found.');}
$payload=['schema'=>'annotated-audit-receipt-v1','receipt'=>['public_id'=>$receipt['public_id'],'project_public_id'=>$receipt['project_public_id'],'project_title'=>$receipt['project_title'],'created_at'=>$receipt['created_at'],'manifest_hash'=>$receipt['manifest_hash'],'stored_hash_valid'=>$receipt['stored_hash_valid']],'manifest'=>$receipt['manifest']];
$json=json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($json===false){http_response_code(500);exit('Unable to encode audit receipt.');}
header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="annotated-audit-'.$receipt['public_id'].'.json"');header('Cache-Control: private, no-store');echo $json;