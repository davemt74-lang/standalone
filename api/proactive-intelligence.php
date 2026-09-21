<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();$action=(string)($_GET['action']??'briefing');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $viewer=in_array($action,['briefing','watches'],true)?require_api_user($pdo):require_api_mutation_auth($pdo);
    if(!proactive_intelligence_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Proactive Research Intelligence.']],503);
    if($action==='briefing'){proactive_intelligence_sync($pdo,$viewer);json_response(['ok'=>true,'data'=>proactive_briefing($pdo,$viewer,5)]);}
    if($action==='watches')json_response(['ok'=>true,'data'=>['watches'=>proactive_watch_list($pdo,$viewer)]]);
    if($action==='sync'){rate_limit_api_or_429($pdo,'proactive-sync','user:'.$viewer['id'],60,3600);json_response(['ok'=>true,'data'=>proactive_intelligence_sync($pdo,$viewer)]);}
    if($action==='watch'){rate_limit_api_or_429($pdo,'proactive-watch','user:'.$viewer['id'],120,3600);$watch=proactive_watch_upsert($pdo,$viewer,(string)($input['type']??''),$input['public_id']??null,$input['query']??null,(string)($input['alert_level']??'important'));json_response(['ok'=>true,'data'=>['watch'=>$watch]]);}
    if($action==='unwatch'){rate_limit_api_or_429($pdo,'proactive-unwatch','user:'.$viewer['id'],120,3600);json_response(['ok'=>true,'data'=>['removed'=>proactive_watch_remove($pdo,$viewer,(string)($input['watch_id']??''))]]);}
    if($action==='pause_watch'){rate_limit_api_or_429($pdo,'proactive-watch-pause','user:'.$viewer['id'],120,3600);json_response(['ok'=>true,'data'=>['updated'=>proactive_watch_pause($pdo,$viewer,(string)($input['watch_id']??''),(bool)($input['paused']??true))]]);}
    if($action==='snooze'){rate_limit_api_or_429($pdo,'proactive-snooze','user:'.$viewer['id'],120,3600);json_response(['ok'=>true,'data'=>['snoozed'=>proactive_alert_snooze($pdo,$viewer,(string)($input['key']??''),(int)($input['hours']??24))]]);}
    if($action==='resolve'){rate_limit_api_or_429($pdo,'proactive-resolve','user:'.$viewer['id'],120,3600);json_response(['ok'=>true,'data'=>['resolved'=>proactive_alert_resolve($pdo,$viewer,(string)($input['key']??''))]]);}
    if($action==='preferences'){rate_limit_api_or_429($pdo,'proactive-preferences','user:'.$viewer['id'],60,3600);json_response(['ok'=>true,'data'=>proactive_preferences_set($pdo,$viewer,(string)($input['mode']??'important'),(bool)($input['briefing']??true))]);}
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'PROACTIVE_INTELLIGENCE_ERROR','message'=>$e->getMessage()]],400);}
