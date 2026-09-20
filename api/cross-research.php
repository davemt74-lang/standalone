<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();$action=(string)($_GET['action']??'list');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $viewer=in_array($action,['list','links','entities','handoff'],true)?require_api_user($pdo):require_api_mutation_auth($pdo);
    if(!cross_research_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Cross-Research Intelligence.']],503);
    $project=isset($input['project'])&&trim((string)$input['project'])!==''?(string)$input['project']:null;
    if($action==='list')json_response(['ok'=>true,'data'=>['suggestions'=>cross_research_suggestions($pdo,$viewer,$project,150,false),'links'=>cross_research_links($pdo,$viewer,$project,100)]]);
    if($action==='links')json_response(['ok'=>true,'data'=>['links'=>cross_research_links($pdo,$viewer,$project,150)]]);
    if($action==='entities')json_response(['ok'=>true,'data'=>['threads'=>cross_research_entity_threads($pdo,$viewer,$project,60)]]);
    if($action==='handoff')json_response(['ok'=>true,'data'=>['handoff'=>cross_research_agent_handoff($pdo,$viewer,(string)($input['key']??''))]]);
    if($action==='accept'){rate_limit_api_or_429($pdo,'cross-research-accept','user:'.$viewer['id'],120,3600);$link=cross_research_accept($pdo,$viewer,(string)($input['key']??''),isset($input['relation'])?(string)$input['relation']:null);json_response(['ok'=>true,'data'=>['link'=>$link]]);}
    if($action==='reject'){rate_limit_api_or_429($pdo,'cross-research-reject','user:'.$viewer['id'],180,3600);json_response(['ok'=>true,'data'=>['rejected'=>cross_research_reject($pdo,$viewer,(string)($input['key']??''))]]);}
    if($action==='restore'){rate_limit_api_or_429($pdo,'cross-research-restore','user:'.$viewer['id'],180,3600);json_response(['ok'=>true,'data'=>['restored'=>cross_research_restore_decision($pdo,$viewer,(string)($input['key']??''))]]);}
    if($action==='project_link'){rate_limit_api_or_429($pdo,'cross-research-link','user:'.$viewer['id'],60,3600);$link=cross_research_create_project_link($pdo,$viewer,(string)($input['source_project']??''),(string)($input['target_project']??''),(string)($input['relation']??'related'),(string)($input['rationale']??''));json_response(['ok'=>true,'data'=>['link'=>$link]],201);}
    if($action==='delete_link'){rate_limit_api_or_429($pdo,'cross-research-unlink','user:'.$viewer['id'],120,3600);json_response(['ok'=>true,'data'=>['deleted'=>cross_research_delete_link($pdo,$viewer,(string)($input['link_id']??''))]]);}
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'CROSS_RESEARCH_ERROR','message'=>$e->getMessage()]],400);}
