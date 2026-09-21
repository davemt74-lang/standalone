<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();$action=(string)($_GET['action']??'project');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
  $viewer=in_array($action,['save','delete'],true)?require_api_mutation_auth($pdo):require_api_user($pdo);
  if(!research_network_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Research Network.']],503);
  if($action==='project'){json_response(['ok'=>true,'data'=>['references'=>research_network_project_references($pdo,$viewer,(string)($input['project_id']??''))]]);}
  if($action==='save'){rate_limit_api_or_429($pdo,'research-network-write','user:'.$viewer['id'],120,3600);$r=research_network_reference_upsert($pdo,$viewer,(string)($input['project_id']??''),(string)($input['report']??''),isset($input['version'])?(int)$input['version']:null,(string)($input['relation']??'context'),(string)($input['note']??''));json_response(['ok'=>true,'data'=>['reference'=>$r]],201);}
  if($action==='delete'){rate_limit_api_or_429($pdo,'research-network-write','user:'.$viewer['id'],120,3600);$ok=research_network_reference_delete($pdo,$viewer,(string)($input['project_id']??''),(string)($input['reference_id']??''));json_response(['ok'=>true,'data'=>['deleted'=>$ok]]);}
  if($action==='report'){$report=research_report_access($pdo,(string)($input['report_id']??''),$viewer);if(!$report)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);$version=(int)($input['version']??$report['version_number']);json_response(['ok'=>true,'data'=>research_network_report_network($pdo,$viewer,$report,$version)]);}
  json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'NETWORK_ERROR','message'=>$e->getMessage()]],400);}
