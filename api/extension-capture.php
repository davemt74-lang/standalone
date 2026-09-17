<?php
if($action==='capture_options'){
    $u=require_api_user($pdo);
    $q=$pdo->prepare('SELECT t.public_id,t.name,tm.role FROM teams t JOIN team_members tm ON tm.team_id=t.id WHERE tm.user_id=? ORDER BY t.name');$q->execute([$u['id']]);$teams=$q->fetchAll();
    $q=$pdo->prepare("SELECT DISTINCT rp.public_id,rp.title,t.public_id team_public_id,CASE WHEN rp.owner_user_id=? THEN 'owner' ELSE tm.role END access_role FROM research_projects rp LEFT JOIN teams t ON t.id=rp.team_id LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE rp.owner_user_id=? OR tm.role IN ('owner','admin','researcher') ORDER BY rp.title");$q->execute([$u['id'],$u['id'],$u['id']]);$projects=$q->fetchAll();
    json_response(['ok'=>true,'data'=>['teams'=>$teams,'projects'=>$projects]]);
}

if($action==='media_upload_start'){
    $u=require_api_mutation_auth($pdo);try{$result=rich_capture_upload_start($pdo,$config,(int)$u['id'],(string)($input['media_type']??''),(string)($input['mime_type']??''),isset($input['expected_bytes'])?(int)$input['expected_bytes']:null);json_response(['ok'=>true,'data'=>$result],201);}catch(InvalidArgumentException|RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'MEDIA_UPLOAD_INVALID','message'=>$e->getMessage()]],422);}
}
if($action==='media_upload_chunk'){
    $u=require_api_mutation_auth($pdo);try{$result=rich_capture_upload_append($pdo,$config,(int)$u['id'],(string)($input['upload_id']??''),(int)($input['offset']??-1),(string)($input['chunk_base64']??''));json_response(['ok'=>true,'data'=>$result]);}catch(InvalidArgumentException|RuntimeException $e){json_response(['ok'=>false,'error'=>['code'=>'MEDIA_UPLOAD_INVALID','message'=>$e->getMessage()]],422);}
}
