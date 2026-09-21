<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();$action=(string)($_GET['action']??'list');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $viewer=$action==='create'?require_api_mutation_auth($pdo):require_api_user($pdo);
    if(!research_evidence_packs_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Phase 28 database upgrade is required.']],503);
    if($action==='list')json_response(['ok'=>true,'data'=>['packs'=>research_evidence_pack_list($pdo,$viewer,(string)($input['project_id']??''),100,false)]]);
    if($action==='get'){$pack=research_evidence_pack_access($pdo,$viewer,(string)($input['pack_id']??''));if(!$pack)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);json_response(['ok'=>true,'data'=>['pack'=>$pack,'comparison'=>research_evidence_pack_compare($pack)]]);}
    if($action==='create'){rate_limit_api_or_429($pdo,'research-evidence-pack','user:'.$viewer['id'],40,3600);$pack=research_evidence_pack_create($pdo,$viewer,(string)($input['project_id']??''),(string)($input['scope_type']??'project'),(string)($input['scope_id']??''));json_response(['ok'=>true,'data'=>['pack'=>['public_id'=>$pack['public_id'],'manifest_hash'=>$pack['manifest_hash'],'created_at'=>$pack['created_at']]]],201);}
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'EVIDENCE_PACK_ERROR','message'=>$e->getMessage()]],400);}
