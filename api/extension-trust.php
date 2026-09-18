<?php
if($action==='notifications'){
    $u=require_api_user($pdo);$rows=notification_rows($pdo,$u,(int)($input['limit']??100),!empty($input['unread_only']));
    json_response(['ok'=>true,'data'=>['notifications'=>$rows,'unread_count'=>notification_unread_count($pdo,$u)]]);
}
if($action==='notification_read'){
    $u=require_api_mutation_auth($pdo);$public=trim((string)($input['notification_id']??''));
    if($public==='')json_response(['ok'=>false,'error'=>['code'=>'INVALID_NOTIFICATION']],422);
    notification_mark_read($pdo,$u,$public);json_response(['ok'=>true,'data'=>['read'=>true,'unread_count'=>notification_unread_count($pdo,$u)]]);
}
if($action==='notification_read_all'){
    $u=require_api_mutation_auth($pdo);$count=notification_mark_read($pdo,$u,null);json_response(['ok'=>true,'data'=>['marked'=>$count,'unread_count'=>0]]);
}
if($action==='notification_archive'){
    $u=require_api_mutation_auth($pdo);$public=trim((string)($input['notification_id']??''));
    if($public===''||!notification_archive($pdo,$u,$public))json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
    json_response(['ok'=>true,'data'=>['archived'=>true,'unread_count'=>notification_unread_count($pdo,$u)]]);
}
if($action==='notification_mute'){
    $u=require_api_mutation_auth($pdo);
    try{$muted=notification_mute_set($pdo,$u,(string)($input['scope_type']??''),(string)($input['scope_id']??''),(string)($input['category']??'all'),!array_key_exists('muted',$input)||!empty($input['muted']));json_response(['ok'=>true,'data'=>['muted'=>$muted]]);}
    catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_MUTE','message'=>$e->getMessage()]],422);}
}
if($action==='report'){
    $u=require_api_mutation_auth($pdo);
    try{$created=moderation_report_create($pdo,$u,(string)($input['object_type']??''),(string)($input['object_id']??''),(string)($input['reason']??''),(string)($input['description']??''));json_response(['ok'=>true,'data'=>$created],$created['created']?201:200);}
    catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>['code'=>'INVALID_REPORT','message'=>$e->getMessage()]],422);}
    catch(RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'REPORT_FORBIDDEN','message'=>$e->getMessage()]],403);}
}
if($action==='annotation_integrity'){
    $u=require_api_user($pdo);$a=annotation_access($pdo,(string)($input['annotation_id']??''),$u);
    if(!$a)json_response(['ok'=>false,'error'=>['code'=>'NOT_FOUND']],404);
    $q=$pdo->prepare('SELECT current_version_id FROM sources WHERE id=?');$q->execute([$a['source_id']]);$a['current_source_version_id']=(int)($q->fetchColumn()?:0);
    json_response(['ok'=>true,'data'=>source_integrity_annotation_state($pdo,$a)]);
}
