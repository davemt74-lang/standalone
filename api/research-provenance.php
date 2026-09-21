<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();$action=(string)($_GET['action']??'project');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $viewer=$action==='create_receipt'?require_api_mutation_auth($pdo):require_api_user($pdo);
    if(!provenance_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Research Provenance.']],503);
    if($action==='project'){$project=(string)($input['project_id']??'');$manifest=provenance_project_manifest($pdo,$viewer,$project);json_response(['ok'=>true,'data'=>['manifest'=>$manifest,'integrity'=>provenance_project_integrity($manifest),'edges'=>provenance_manifest_edges($manifest)]]);}
    if($action==='create_receipt'){rate_limit_api_or_429($pdo,'research-audit-receipt','user:'.$viewer['id'],60,3600);$receipt=provenance_receipt_create($pdo,$viewer,(string)($input['project_id']??''));json_response(['ok'=>true,'data'=>['receipt'=>['public_id'=>$receipt['public_id'],'manifest_hash'=>$receipt['manifest_hash'],'created_at'=>$receipt['created_at']]]],201);}
    if($action==='receipt'){$receipt=provenance_receipt_access($pdo,$viewer,(string)($input['receipt_id']??''));if(!$receipt)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);json_response(['ok'=>true,'data'=>['receipt'=>$receipt]]);}
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'PROVENANCE_ERROR','message'=>$e->getMessage()]],400);}
