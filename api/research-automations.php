<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();$action=(string)($_GET['action']??'list');$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;
try{
    $viewer=in_array($action,['list','runs','options'],true)?require_api_user($pdo):require_api_mutation_auth($pdo);
    if(!research_automation_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Annotated database upgrade is required for Research Automation.']],503);
    if($action==='list')json_response(['ok'=>true,'data'=>['automations'=>research_automation_list($pdo,$viewer,100)]]);
    if($action==='runs')json_response(['ok'=>true,'data'=>['runs'=>research_automation_run_list($pdo,$viewer,isset($input['automation_id'])?(string)$input['automation_id']:null,100)]]);
    if($action==='options'){
        $projects=[];$q=$pdo->prepare("SELECT DISTINCT rp.public_id,rp.title,CASE WHEN rp.owner_user_id=? THEN 'owner' ELSE COALESCE(tm.role,'viewer') END access_role FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE rp.owner_user_id=? OR tm.user_id=? ORDER BY rp.updated_at DESC");$q->execute([$viewer['id'],$viewer['id'],$viewer['id'],$viewer['id']]);$projects=$q->fetchAll();
        $watches=proactive_intelligence_ready($pdo)?proactive_watch_list($pdo,$viewer):[];
        json_response(['ok'=>true,'data'=>['projects'=>$projects,'watches'=>$watches,'workflows'=>research_automation_workflows(),'cadences'=>research_automation_cadences()]]);
    }
    if($action==='create'){rate_limit_api_or_429($pdo,'research-automation-create','user:'.$viewer['id'],30,3600);$row=research_automation_create($pdo,$viewer,$input);json_response(['ok'=>true,'data'=>['automation'=>$row]],201);}
    if($action==='update'){rate_limit_api_or_429($pdo,'research-automation-update','user:'.$viewer['id'],60,3600);$row=research_automation_update($pdo,$viewer,(string)($input['automation_id']??''),$input);json_response(['ok'=>true,'data'=>['automation'=>$row]]);}
    if($action==='status'){rate_limit_api_or_429($pdo,'research-automation-status','user:'.$viewer['id'],120,3600);$status=(string)($input['status']??'paused');if(!research_automation_set_status($pdo,$viewer,(string)($input['automation_id']??''),$status))json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);json_response(['ok'=>true,'data'=>['updated'=>true,'status'=>$status]]);}
    if($action==='run'){rate_limit_api_or_429($pdo,'research-automation-run','user:'.$viewer['id'],30,3600);$run=research_automation_enqueue_manual($pdo,$viewer,(string)($input['automation_id']??''));json_response(['ok'=>true,'data'=>['run_id'=>$run]],202);}
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'AUTOMATION_ERROR','message'=>$e->getMessage()]],400);}
