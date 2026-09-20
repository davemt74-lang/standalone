<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/cognitive-feed.php';
api_headers();
$action=(string)($_GET['action']??'get');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    if($action==='get'){
        $viewer=require_api_user($pdo);
        if(!cognitive_feed_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Cognitive Feed.']],503);
        $data=cognitive_feed_compose($pdo,$viewer,null,4,28);$data['mode']=cognitive_feed_mode_get($pdo,$viewer);json_response(['ok'=>true,'data'=>$data]);
    }
    $viewer=require_api_mutation_auth($pdo);
    if(!cognitive_feed_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Cognitive Feed.']],503);
    if($action==='mode'){rate_limit_api_or_429($pdo,'cognitive-feed-mode','user:'.$viewer['id'],120,3600);$mode=cognitive_feed_mode_set($pdo,$viewer,(string)($input['mode']??''));json_response(['ok'=>true,'data'=>['mode'=>$mode]]);}
    if($action==='dismiss'){rate_limit_api_or_429($pdo,'cognitive-feed-dismiss','user:'.$viewer['id'],240,3600);cognitive_feed_dismiss($pdo,$viewer,(string)($input['key']??''),(string)($input['type']??''));json_response(['ok'=>true,'data'=>['dismissed'=>true]]);}
    if($action==='restore'){rate_limit_api_or_429($pdo,'cognitive-feed-restore','user:'.$viewer['id'],240,3600);cognitive_feed_restore($pdo,$viewer,(string)($input['key']??''));json_response(['ok'=>true,'data'=>['restored'=>true]]);}
    if($action==='restore_all'){rate_limit_api_or_429($pdo,'cognitive-feed-restore-all','user:'.$viewer['id'],30,3600);$count=cognitive_feed_restore_all($pdo,$viewer);json_response(['ok'=>true,'data'=>['restored'=>$count]]);}
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'COGNITIVE_FEED_ERROR','message'=>$e->getMessage()]],400);}
