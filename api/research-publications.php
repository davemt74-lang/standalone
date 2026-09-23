<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();

$action=(string)($_GET['action']??'list');
$input=$_SERVER['REQUEST_METHOD']==='POST'?(json_decode(file_get_contents('php://input'),true)?:[]):$_GET;

try{
    $read=['list','summary','detail','threads'];
    $viewer=in_array($action,$read,true)?require_api_user($pdo):require_api_mutation_auth($pdo);
    if(!research_publications_ready($pdo))json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Collaborative Publishing requires the latest database upgrade.']],503);

    if($action==='list'){
        $status=(string)($input['status']??'all');
        json_response(['ok'=>true,'data'=>['workflows'=>research_publication_list($pdo,$viewer,$status,(int)($input['limit']??200))]]);
    }
    if($action==='summary')json_response(['ok'=>true,'data'=>research_publication_summary($pdo,$viewer)]);
    if($action==='detail'){
        $id=trim((string)($input['workflow_id']??''));if($id==='')throw new InvalidArgumentException('Publication workflow is required.');
        $w=research_publication_workflow_detail($pdo,$viewer,$id);if(!$w)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
        json_response(['ok'=>true,'data'=>['workflow'=>$w]]);
    }
    if($action==='threads'){
        $id=trim((string)($input['workflow_id']??''));if($id==='')throw new InvalidArgumentException('Publication workflow is required.');
        json_response(['ok'=>true,'data'=>['threads'=>research_publication_threads($pdo,$viewer,$id,(int)($input['limit']??250))]]);
    }

    rate_limit_api_or_429($pdo,'research-publications-write','user:'.$viewer['id'],180,3600);

    if($action==='create'){
        $doc=trim((string)($input['document_id']??''));if($doc==='')throw new InvalidArgumentException('Research document is required.');
        json_response(['ok'=>true,'data'=>['workflow'=>research_publication_workflow_create($pdo,$viewer,$doc,$input)]],201);
    }
    if($action==='update'){
        $id=trim((string)($input['workflow_id']??''));if($id==='')throw new InvalidArgumentException('Publication workflow is required.');
        json_response(['ok'=>true,'data'=>['workflow'=>research_publication_workflow_update($pdo,$viewer,$id,$input)]]);
    }
    if($action==='request_review'){
        $id=trim((string)($input['workflow_id']??''));if($id==='')throw new InvalidArgumentException('Publication workflow is required.');
        $reviewers=is_array($input['reviewers']??null)?$input['reviewers']:[];
        json_response(['ok'=>true,'data'=>['workflow'=>research_publication_request_review($pdo,$viewer,$id,$reviewers,(string)($input['due_at']??''),(string)($input['instructions']??''))]]);
    }
    if($action==='restart_review'){
        $id=trim((string)($input['workflow_id']??''));if($id==='')throw new InvalidArgumentException('Publication workflow is required.');
        json_response(['ok'=>true,'data'=>['workflow'=>research_publication_restart_review($pdo,$viewer,$id,(string)($input['due_at']??''))]]);
    }
    if($action==='owner_approve'){
        $id=trim((string)($input['workflow_id']??''));if($id==='')throw new InvalidArgumentException('Publication workflow is required.');
        json_response(['ok'=>true,'data'=>['workflow'=>research_publication_owner_approve($pdo,$viewer,$id)]]);
    }
    if($action==='evaluate'){
        $id=trim((string)($input['workflow_id']??''));if($id==='')throw new InvalidArgumentException('Publication workflow is required.');
        json_response(['ok'=>true,'data'=>research_publication_evaluate($pdo,$viewer,$id)]);
    }
    if($action==='publish'){
        $id=trim((string)($input['workflow_id']??''));if($id==='')throw new InvalidArgumentException('Publication workflow is required.');
        json_response(['ok'=>true,'data'=>research_publication_publish($pdo,$viewer,$id)]);
    }
    if($action==='archive'){
        $id=trim((string)($input['workflow_id']??''));if($id==='')throw new InvalidArgumentException('Publication workflow is required.');
        json_response(['ok'=>true,'data'=>['workflow'=>research_publication_archive($pdo,$viewer,$id)]]);
    }
    if($action==='thread_create'){
        $id=trim((string)($input['workflow_id']??''));if($id==='')throw new InvalidArgumentException('Publication workflow is required.');
        json_response(['ok'=>true,'data'=>['thread'=>research_publication_thread_create($pdo,$viewer,$id,$input)]],201);
    }
    if($action==='thread_message'){
        $id=trim((string)($input['thread_id']??''));if($id==='')throw new InvalidArgumentException('Review thread is required.');
        json_response(['ok'=>true,'data'=>['message'=>research_publication_thread_message($pdo,$viewer,$id,(string)($input['body']??''))]]);
    }
    if($action==='thread_resolve'||$action==='thread_reopen'){
        $id=trim((string)($input['thread_id']??''));if($id==='')throw new InvalidArgumentException('Review thread is required.');
        json_response(['ok'=>true,'data'=>['thread'=>research_publication_thread_resolve($pdo,$viewer,$id,$action==='thread_resolve')]]);
    }

    json_response(['ok'=>false,'error'=>['code'=>'UNKNOWN_ACTION']],404);
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_INPUT','message'=>$e->getMessage()]],422);}
catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_PUBLICATION_ERROR','message'=>$e->getMessage()]],403);}
catch(Throwable $e){$ref=substr(hash('sha256','research-publication|'.$e->getMessage().'|'.microtime(true)),0,12);error_log('[Annotated research publication '.$ref.'] '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());json_response(['ok'=>false,'error'=>['code'=>'RESEARCH_PUBLICATION_INTERNAL','message'=>'Collaborative Publishing could not complete this request. Reference: '.$ref]],500);}
