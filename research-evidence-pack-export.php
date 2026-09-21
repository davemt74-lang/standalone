<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';$u=require_user($pdo);$id=trim((string)($_GET['pack']??''));$pack=research_evidence_pack_access($pdo,$u,$id);if(!$pack){http_response_code(404);exit('Evidence Pack not found.');}
$payload=['schema'=>'annotated-evidence-pack-export-v1','pack'=>['public_id'=>$pack['public_id'],'project_public_id'=>$pack['project_public_id'],'scope_type'=>$pack['scope_type'],'scope_public_id'=>$pack['scope_public_id'],'manifest_hash'=>$pack['manifest_hash'],'created_at'=>$pack['created_at']],'manifest'=>$pack['manifest']];
header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="annotated-evidence-pack-'.preg_replace('/[^A-Za-z0-9_-]/','',(string)$pack['public_id']).'.json"');echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
