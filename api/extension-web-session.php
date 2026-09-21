<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/extension-auth.php';

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow',true);
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED']],405);

$base=(string)($config['app']['base_url']??'');
$bp=parse_url($base);
$baseOrigin='';
if($bp&&isset($bp['scheme'],$bp['host'])){
    $baseOrigin=strtolower((string)$bp['scheme']).'://'.strtolower((string)$bp['host']);
    if(isset($bp['port']))$baseOrigin.=':'.(int)$bp['port'];
}
$origin=rtrim((string)($_SERVER['HTTP_ORIGIN']??''),'/');
$referer=(string)($_SERVER['HTTP_REFERER']??'');
$refererOrigin='';
if($referer!==''){
    $rp=parse_url($referer);
    if($rp&&isset($rp['scheme'],$rp['host'])){
        $refererOrigin=strtolower((string)$rp['scheme']).'://'.strtolower((string)$rp['host']);
        if(isset($rp['port']))$refererOrigin.=':'.(int)$rp['port'];
    }
}
$fetchSite=strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE']??''));
if($baseOrigin===''||($origin!==''&&strtolower($origin)!==$baseOrigin)||($origin===''&&$refererOrigin!==''&&$refererOrigin!==$baseOrigin)||($fetchSite!==''&&$fetchSite!=='same-origin')){
    json_response(['ok'=>false,'error'=>['code'=>'SAME_SITE_REQUIRED','message'=>'Extension session handoff must start from the Annotated website.']],403);
}

$u=current_user($pdo);
if(!$u)json_response(['ok'=>true,'data'=>['signed_in'=>false]]);

$raw=json_decode((string)file_get_contents('php://input'),true)?:[];
$extensionId=strtolower(trim((string)($raw['extension_id']??'')));
if(!extension_id_allowed($extensionId,$config))json_response(['ok'=>false,'error'=>['code'=>'INVALID_EXTENSION_ID']],422);

rate_limit_api_or_429($pdo,'extension-web-session-user',(string)$u['id'],30,3600);
$session=extension_session_issue($pdo,(int)$u['id'],$config,'Chrome sidebar',(string)($raw['client_version']??''));
json_response(['ok'=>true,'data'=>[
    'signed_in'=>true,
    'token'=>$session['token'],
    'expires_at'=>$session['expires_at'],
    'user'=>[
        'public_id'=>$u['public_id'],
        'username'=>$u['username'],
        'display_name'=>$u['display_name'],
        'role'=>$u['role'],
    ],
]],201);
