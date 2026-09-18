<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/extension-auth.php';
require_once dirname(__DIR__).'/app/rich-capture.php';
require_once dirname(__DIR__).'/app/feed.php';
require_once dirname(__DIR__).'/app/live.php';
extension_api_headers($config);
enforce_extension_bearer_session($pdo);
$action=(string)($_GET['action']??'');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $actor=current_user($pdo);$subject=$actor?'user:'.(string)$actor['id']:'ip:'.rate_limit_ip_subject();
    $limits=['publish'=>[60,3600],'media_upload_start'=>[30,3600],'media_upload_chunk'=>[2400,3600],'comment'=>[120,3600],'feed_read'=>[900,3600],'watch_source'=>[120,3600],'follow'=>[120,3600],'save'=>[240,3600],'presence'=>[300,3600],'live_leave'=>[120,3600],'live_message'=>[180,3600],'live_message_delete'=>[120,3600],'live_message_pin'=>[120,3600],'live_react'=>[600,3600],'research_add'=>[120,3600],'transcript_edit'=>[60,3600]];
    if(isset($limits[$action]))rate_limit_api_or_429($pdo,'extension-'.$action,$subject,$limits[$action][0],$limits[$action][1]);
}
require __DIR__.'/extension-feed.php';
require __DIR__.'/extension-base.php';
require __DIR__.'/extension-capture.php';
require __DIR__.'/extension-live.php';
require __DIR__.'/extension-publish.php';
json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
