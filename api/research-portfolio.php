<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();$action=(string)($_GET['action']??'list');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $viewer=$action==='pin'?require_api_mutation_auth($pdo):require_api_user($pdo);
    if(!research_portfolio_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Research Portfolio Intelligence.']],503);
    if($action==='list'){$portfolio=research_portfolio_compose($pdo,$viewer,50);$portfolio=research_portfolio_filter($portfolio,['attention'=>$input['attention']??'','access'=>$input['access']??'','q'=>$input['q']??'','pinned'=>!empty($input['pinned'])]);json_response(['ok'=>true,'data'=>$portfolio]);}
    if($action==='pin'){rate_limit_api_or_429($pdo,'research-portfolio-pin','user:'.$viewer['id'],240,3600);research_portfolio_set_pin($pdo,$viewer,(string)($input['project_id']??''),!empty($input['pinned']));json_response(['ok'=>true,'data'=>['pinned'=>(bool)!empty($input['pinned'])]]);}
    if($action==='context'){$ctx=research_portfolio_context($pdo,$viewer,12);json_response(['ok'=>true,'data'=>$ctx]);}
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'PORTFOLIO_ERROR','message'=>$e->getMessage()]],400);}
