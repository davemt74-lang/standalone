<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();

$action=(string)($_GET['action']??'list');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;

try{
    $viewer=in_array($action,['list','folders','document','document_revisions','stickies','desktop','recording','upload'],true)?require_api_user($pdo):require_api_mutation_auth($pdo);
    if(!research_agent_workspace_ready($pdo))throw new RuntimeException('Research Agent workspace requires the latest database upgrade.');

    $agentPublic=trim((string)($input['agent_id']??''));
    $projectPublic=trim((string)($input['project_id']??''));
    $project=research_agent_workspace_project($pdo,$viewer,$agentPublic,$projectPublic);
    if(!$project)json_response(['ok'=>false,'error'=>['code'=>'WORKSPACE_NOT_FOUND','message'=>'Research Agent workspace not found.']],404);
    $queueWorkspaceChange=function(string $reason='Research workspace changed.') use($pdo,$project,$viewer): void {
        if(function_exists('research_retrieval_queue_project'))research_retrieval_queue_project($pdo,(int)$project['id']);
        if(function_exists('research_autonomy_queue_project'))research_autonomy_queue_project($pdo,(int)$project['id'],(int)$viewer['id'],'workspace_change',$reason);
    };

    if($action==='list'){
        $trashed=!empty($input['trashed']);
        $items=research_agent_workspace_list($pdo,$viewer,$project,$trashed,300);
        json_response(['ok'=>true,'data'=>[
          'project'=>['public_id'=>$project['public_id'],'title'=>$project['title'],'access_role'=>$project['access_role']],
          'agent'=>isset($project['research_agent'])?[
            'public_id'=>$project['research_agent']['public_id'],
            'name'=>$project['research_agent']['name'],
            'conversation_public_id'=>$project['research_agent']['conversation_public_id']
          ]:null,
          'items'=>$items
        ]]);
    }
    if($action==='desktop'){
        $trashed=!empty($input['trashed']);
        $items=research_agent_workspace_desktop_items($pdo,$viewer,$project,$trashed);
        $stickies=$trashed?[]:research_agent_workspace_stickies($pdo,$viewer,$project);
        json_response(['ok'=>true,'data'=>[
          'project'=>['public_id'=>$project['public_id'],'title'=>$project['title'],'access_role'=>$project['access_role']],
          'agent'=>isset($project['research_agent'])?[
            'public_id'=>$project['research_agent']['public_id'],
            'name'=>$project['research_agent']['name'],
            'conversation_public_id'=>$project['research_agent']['conversation_public_id']
          ]:null,
          'items'=>$items,'stickies'=>$stickies
        ]]);
    }
    if($action==='upload'||$action==='recording'){
        $item=research_agent_workspace_object($pdo,$viewer,(string)($input['object_id']??''),false);
        if(!$item||($item['object_type']??'')!==$action)json_response(['ok'=>false,'error'=>['code'=>'ITEM_NOT_FOUND','message'=>ucfirst($action).' not found.']],404);
        if(!hash_equals((string)$project['public_id'],(string)$item['project_public_id']))json_response(['ok'=>false,'error'=>['code'=>'ITEM_NOT_FOUND','message'=>ucfirst($action).' not found.']],404);
        json_response(['ok'=>true,'data'=>['item'=>$item]]);
    }
    if($action==='document'){
        $item=research_agent_workspace_object($pdo,$viewer,(string)($input['object_id']??''),false);
        if(!$item||($item['object_type']??'')!=='document')json_response(['ok'=>false,'error'=>['code'=>'DOCUMENT_NOT_FOUND','message'=>'Document not found.']],404);
        json_response(['ok'=>true,'data'=>['item'=>$item]]);
    }
    if($action==='document_revisions'){
        $items=research_agent_workspace_document_revisions($pdo,$viewer,(string)($input['object_id']??''),50);
        json_response(['ok'=>true,'data'=>['revisions'=>$items]]);
    }
    if($action==='stickies'){
        $items=research_agent_workspace_stickies($pdo,$viewer,$project);
        json_response(['ok'=>true,'data'=>['items'=>$items]]);
    }
    if($action==='retry_transcription'){
        rate_limit_api_or_429($pdo,'research-workspace-write','user:'.$viewer['id'],180,3600);
        $item=research_agent_workspace_retry_transcription($pdo,$viewer,(string)($input['object_id']??''));
        $queueWorkspaceChange('Research transcription was requeued.');
        json_response(['ok'=>true,'data'=>['item'=>$item]]);
    }
    if($action==='transcript_to_document'){
        rate_limit_api_or_429($pdo,'research-workspace-write','user:'.$viewer['id'],180,3600);
        $item=research_agent_workspace_transcript_to_document($pdo,$viewer,(string)($input['object_id']??''));
        $queueWorkspaceChange('A transcript was converted to a Research Doc.');
        json_response(['ok'=>true,'data'=>['item'=>$item]],201);
    }
    if($action==='save_desktop_position'){
        rate_limit_api_or_429($pdo,'research-workspace-write','user:'.$viewer['id'],900,3600);
        $item=research_agent_workspace_desktop_position_save(
          $pdo,$viewer,$project,(string)($input['object_type']??''),(string)($input['object_id']??''),
          (int)($input['x']??0),(int)($input['y']??0),(int)($input['z']??1)
        );
        json_response(['ok'=>true,'data'=>['item'=>$item]]);
    }
    if($action==='create_document'){
        rate_limit_api_or_429($pdo,'research-workspace-write','user:'.$viewer['id'],180,3600);
        $item=research_agent_workspace_create_document($pdo,$viewer,$project,is_array($input)?$input:[]);
        $queueWorkspaceChange('A Research Doc was created.');
        json_response(['ok'=>true,'data'=>['item'=>$item]],201);
    }
    if($action==='save_document'){
        rate_limit_api_or_429($pdo,'research-workspace-write','user:'.$viewer['id'],240,3600);
        $item=research_agent_workspace_save_document($pdo,$viewer,(string)($input['object_id']??''),is_array($input)?$input:[]);
        $queueWorkspaceChange('A Research Doc changed.');
        json_response(['ok'=>true,'data'=>['item'=>$item]]);
    }
    if($action==='restore_document_revision'){
        rate_limit_api_or_429($pdo,'research-workspace-write','user:'.$viewer['id'],120,3600);
        $item=research_agent_workspace_restore_document_revision($pdo,$viewer,(string)($input['object_id']??''),(string)($input['revision_id']??''),(int)($input['base_revision']??0));
        $queueWorkspaceChange('A Research Doc revision was restored.');
        json_response(['ok'=>true,'data'=>['item'=>$item]]);
    }
    if($action==='create_sticky'){
        rate_limit_api_or_429($pdo,'research-workspace-write','user:'.$viewer['id'],240,3600);
        $item=research_agent_workspace_create_sticky($pdo,$viewer,$project,is_array($input)?$input:[]);
        $queueWorkspaceChange('A Research sticky was created.');
        json_response(['ok'=>true,'data'=>['item'=>$item]],201);
    }
    if($action==='update_sticky'){
        rate_limit_api_or_429($pdo,'research-workspace-write','user:'.$viewer['id'],600,3600);
        $item=research_agent_workspace_update_sticky($pdo,$viewer,(string)($input['object_id']??''),is_array($input)?$input:[]);
        $queueWorkspaceChange('A Research sticky changed.');
        json_response(['ok'=>true,'data'=>['item'=>$item]]);
    }
    if($action==='create_folder'){
        rate_limit_api_or_429($pdo,'research-workspace-write','user:'.$viewer['id'],180,3600);
        $item=research_agent_workspace_create_folder($pdo,$viewer,$project,(string)($input['title']??''),(string)($input['parent_id']??''));
        $queueWorkspaceChange('Research workspace organization changed.');
        json_response(['ok'=>true,'data'=>['item'=>$item]],201);
    }
    if($action==='create_bookmark'){
        rate_limit_api_or_429($pdo,'research-workspace-write','user:'.$viewer['id'],180,3600);
        $item=research_agent_workspace_create_bookmark($pdo,$viewer,$project,is_array($input)?$input:[]);
        $queueWorkspaceChange('A Research bookmark was added.');
        json_response(['ok'=>true,'data'=>['item'=>$item]],201);
    }
    if($action==='rename'){
        rate_limit_api_or_429($pdo,'research-workspace-write','user:'.$viewer['id'],180,3600);
        $item=research_agent_workspace_rename($pdo,$viewer,(string)($input['object_id']??''),(string)($input['title']??''));
        $queueWorkspaceChange('A Research workspace item was renamed.');
        json_response(['ok'=>true,'data'=>['item'=>$item]]);
    }
    if($action==='move'){
        rate_limit_api_or_429($pdo,'research-workspace-write','user:'.$viewer['id'],180,3600);
        $type=strtolower(trim((string)($input['object_type']??'')));
        $item=$type!==''
          ?research_agent_workspace_desktop_move($pdo,$viewer,$project,$type,(string)($input['object_id']??''),(string)($input['parent_id']??''))
          :research_agent_workspace_move($pdo,$viewer,(string)($input['object_id']??''),(string)($input['parent_id']??''));
        $queueWorkspaceChange('Research workspace organization changed.');
        json_response(['ok'=>true,'data'=>['item'=>$item]]);
    }
    if($action==='trash'){
        rate_limit_api_or_429($pdo,'research-workspace-write','user:'.$viewer['id'],180,3600);
        $item=research_agent_workspace_trash($pdo,$viewer,(string)($input['object_id']??''));
        $queueWorkspaceChange('Research workspace evidence was moved to Trash.');
        json_response(['ok'=>true,'data'=>['item'=>$item]]);
    }
    if($action==='restore'){
        rate_limit_api_or_429($pdo,'research-workspace-write','user:'.$viewer['id'],180,3600);
        $item=research_agent_workspace_restore($pdo,$viewer,(string)($input['object_id']??''));
        $queueWorkspaceChange('Research workspace evidence was restored.');
        json_response(['ok'=>true,'data'=>['item'=>$item]]);
    }
    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){
    json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);
}catch(RuntimeException $e){
    json_response(['ok'=>false,'error'=>['code'=>'WORKSPACE_ERROR','message'=>$e->getMessage()]],403);
}catch(Throwable $e){
    $reference=substr(hash('sha256','research-workspace|'.$e->getMessage().'|'.microtime(true).'|'.random_bytes(8)),0,12);
    error_log('[Annotated research workspace '.$reference.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    json_response(['ok'=>false,'error'=>['code'=>'WORKSPACE_INTERNAL','message'=>'Research workspace could not complete this request. Reference: '.$reference]],500);
}
