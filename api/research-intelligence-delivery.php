<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
$action=(string)($_GET['action']??'subscriptions');$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$input=$method==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $mutations=['create_subscription','update_subscription','set_status','deliver_now','mark_viewed'];
    $isMutation=in_array($action,$mutations,true);
    if($isMutation&&$method!=='POST')json_response(['ok'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED','message'=>'This intelligence-delivery action requires POST.']],405);
    $viewer=$isMutation?require_api_mutation_auth($pdo):require_api_user($pdo);
    if(!research_intelligence_delivery_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Research Intelligence Delivery requires the latest database upgrade.']],503);
    $agent=trim((string)($input['agent_id']??''));
    if(in_array($action,['subscriptions','deliveries','create_subscription'],true)&&$agent==='')throw new InvalidArgumentException('Research Agent is required.');

    if($action==='subscriptions'){
        rate_limit_api_or_429($pdo,'research-intelligence-delivery-read','user:'.$viewer['id'],600,3600);
        json_response(['ok'=>true,'data'=>['subscriptions'=>research_report_subscription_list($pdo,$viewer,$agent,(int)($input['limit']??100),!empty($input['include_archived']))]]);
    }
    if($action==='deliveries'){
        rate_limit_api_or_429($pdo,'research-intelligence-delivery-read','user:'.$viewer['id'],600,3600);
        json_response(['ok'=>true,'data'=>['deliveries'=>research_report_delivery_list($pdo,$viewer,$agent,(int)($input['limit']??100),array_key_exists('include_suppressed',$input)?!empty($input['include_suppressed']):true)]]);
    }
    if($action==='get_subscription'){
        rate_limit_api_or_429($pdo,'research-intelligence-delivery-read','user:'.$viewer['id'],600,3600);
        $sub=research_report_subscription_access($pdo,$viewer,(string)($input['subscription_id']??''));if(!$sub)throw new RuntimeException('Report subscription not found.');
        json_response(['ok'=>true,'data'=>['subscription'=>$sub]]);
    }
    if($action==='get_delivery'){
        rate_limit_api_or_429($pdo,'research-intelligence-delivery-read','user:'.$viewer['id'],600,3600);
        $delivery=research_report_delivery_access($pdo,$viewer,(string)($input['delivery_id']??''));if(!$delivery)throw new RuntimeException('Intelligence delivery not found.');
        json_response(['ok'=>true,'data'=>['delivery'=>$delivery]]);
    }
    if($action==='create_subscription'){
        rate_limit_api_or_429($pdo,'research-intelligence-delivery-write','user:'.$viewer['id'],120,3600);
        $sub=research_report_subscription_create($pdo,$viewer,$agent,$input,false);
        json_response(['ok'=>true,'data'=>['subscription'=>$sub]],201);
    }
    if($action==='update_subscription'){
        rate_limit_api_or_429($pdo,'research-intelligence-delivery-write','user:'.$viewer['id'],120,3600);
        $sub=research_report_subscription_update($pdo,$viewer,(string)($input['subscription_id']??''),$input,false);
        json_response(['ok'=>true,'data'=>['subscription'=>$sub]]);
    }
    if($action==='set_status'){
        rate_limit_api_or_429($pdo,'research-intelligence-delivery-write','user:'.$viewer['id'],120,3600);
        $sub=research_report_subscription_set_status($pdo,$viewer,(string)($input['subscription_id']??''),(string)($input['status']??''),false);
        json_response(['ok'=>true,'data'=>['subscription'=>$sub]]);
    }
    if($action==='deliver_now'){
        rate_limit_api_or_429($pdo,'research-intelligence-delivery-write','user:'.$viewer['id'],60,3600);
        $delivery=research_intelligence_delivery_run_manual($pdo,$config,$viewer,(string)($input['subscription_id']??''));
        json_response(['ok'=>true,'data'=>['delivery'=>$delivery]],201);
    }
    if($action==='mark_viewed'){
        rate_limit_api_or_429($pdo,'research-intelligence-delivery-write','user:'.$viewer['id'],300,3600);
        $delivery=research_report_delivery_mark_viewed($pdo,$viewer,(string)($input['delivery_id']??''));
        json_response(['ok'=>true,'data'=>['delivery'=>$delivery]]);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){
    json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);
}catch(RuntimeException $e){
    json_response(['ok'=>false,'error'=>['code'=>'INTELLIGENCE_DELIVERY_ERROR','message'=>$e->getMessage()]],403);
}catch(Throwable $e){
    $reference=substr(hash('sha256','research-intelligence-delivery|'.$e->getMessage().'|'.microtime(true)),0,12);
    error_log('[Annotated Research Intelligence Delivery '.$reference.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    json_response(['ok'=>false,'error'=>['code'=>'INTELLIGENCE_DELIVERY_INTERNAL','message'=>'Research Intelligence Delivery could not complete this request. Reference: '.$reference]],500);
}
