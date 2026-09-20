<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();$action=(string)($_GET['action']??'list');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $viewer=in_array($action,['list','get','eligible'],true)?require_api_user($pdo):require_api_mutation_auth($pdo);
    if(!research_reviews_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Collaborative Research Review.']],503);
    if($action==='list'){json_response(['ok'=>true,'data'=>['reviews'=>research_review_list($pdo,$viewer,(string)($input['scope']??'all'),150)]]);}
    if($action==='get'){$r=research_review_access($pdo,$viewer,(string)($input['review_id']??''));if(!$r)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);$r['aggregate']=research_review_aggregate($pdo,$r);$r['comments']=research_review_comments($pdo,$r,200);json_response(['ok'=>true,'data'=>['review'=>$r]]);}
    if($action==='eligible'){$project=(string)($input['project_id']??'');json_response(['ok'=>true,'data'=>['reviewers'=>research_review_eligible_reviewers($pdo,$viewer,$project)]]);}
    if($action==='create'){rate_limit_api_or_429($pdo,'research-review-create','user:'.$viewer['id'],60,3600);$r=research_review_create($pdo,$viewer,(string)($input['subject_type']??''),(string)($input['subject_id']??''),is_array($input['reviewer_ids']??null)?$input['reviewer_ids']:[],isset($input['due_at'])?(string)$input['due_at']:null,(string)($input['instructions']??''));json_response(['ok'=>true,'data'=>['review'=>$r]],201);}
    if($action==='respond'){rate_limit_api_or_429($pdo,'research-review-respond','user:'.$viewer['id'],120,3600);$r=research_review_respond($pdo,$viewer,(string)($input['review_id']??''),(string)($input['decision']??''),(string)($input['comment']??''));json_response(['ok'=>true,'data'=>['review'=>$r]]);}
    if($action==='comment'){rate_limit_api_or_429($pdo,'research-review-comment','user:'.$viewer['id'],180,3600);$row=research_review_comment($pdo,$viewer,(string)($input['review_id']??''),(string)($input['body']??''));json_response(['ok'=>true,'data'=>['comment'=>$row]],201);}
    if($action==='complete'){rate_limit_api_or_429($pdo,'research-review-complete','user:'.$viewer['id'],60,3600);$r=research_review_complete($pdo,$viewer,(string)($input['review_id']??''));json_response(['ok'=>true,'data'=>['review'=>$r]]);}
    if($action==='cancel'){rate_limit_api_or_429($pdo,'research-review-cancel','user:'.$viewer['id'],60,3600);json_response(['ok'=>true,'data'=>['cancelled'=>research_review_cancel($pdo,$viewer,(string)($input['review_id']??''))]]);}
    if($action==='restart'){rate_limit_api_or_429($pdo,'research-review-restart','user:'.$viewer['id'],60,3600);$r=research_review_restart($pdo,$viewer,(string)($input['review_id']??''),isset($input['due_at'])?(string)$input['due_at']:null);json_response(['ok'=>true,'data'=>['review'=>$r]],201);}
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'REVIEW_ERROR','message'=>$e->getMessage()]],400);}
