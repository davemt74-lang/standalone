<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
api_headers();
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['ok'=>false,'error'=>['code'=>'METHOD_NOT_ALLOWED']],405);

$storedPath=null;
try{
    $viewer=require_api_mutation_auth($pdo);
    if(!research_agent_workspace_ready($pdo))throw new RuntimeException('Research Agent workspace requires the latest database upgrade.');
    $agentPublic=trim((string)($_POST['agent_id']??''));$projectPublic=trim((string)($_POST['project_id']??''));
    $project=research_agent_workspace_project($pdo,$viewer,$agentPublic,$projectPublic);
    if(!$project)json_response(['ok'=>false,'error'=>['code'=>'WORKSPACE_NOT_FOUND','message'=>'Research Agent workspace not found.']],404);
    research_agent_workspace_require_write($project);
    rate_limit_api_or_429($pdo,'research-workspace-upload','user:'.$viewer['id'],120,3600);

    $file=$_FILES['file']??null;if(!is_array($file))throw new InvalidArgumentException('Choose a file to upload.');
    $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
    if($error!==UPLOAD_ERR_OK){
        $message=match($error){
          UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE=>'The file exceeds the server upload limit.',
          UPLOAD_ERR_PARTIAL=>'The file upload was interrupted.',
          UPLOAD_ERR_NO_FILE=>'Choose a file to upload.',
          default=>'The file upload failed.'
        };
        throw new RuntimeException($message);
    }
    $tmp=(string)($file['tmp_name']??'');$size=(int)($file['size']??0);
    if($tmp===''||!is_uploaded_file($tmp)||$size<1)throw new RuntimeException('The uploaded file could not be validated.');
    $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=strtolower(trim((string)$finfo->file($tmp)));
    $specs=research_agent_workspace_upload_specs();$spec=$specs[$mime]??null;
    if(!$spec)throw new InvalidArgumentException('Unsupported file type. Upload PDF, DOCX, TXT, Markdown, CSV, JPG, PNG, WebP, MP3, M4A, WAV, OGG, or WebM audio.');
    if($size>(int)$spec['max'])throw new RuntimeException(($spec['kind']==='recording'?'Audio':'File').' is too large.');
    $original=mb_substr(trim((string)($file['name']??'upload.'.$spec['ext'])),0,255);if($original==='')$original='upload.'.$spec['ext'];
    $slot=private_storage_allocate($config,$spec['kind']==='recording'?'research-recording':'research-upload',(string)$spec['ext']);
    if(!move_uploaded_file($tmp,$slot['path']))throw new RuntimeException('Unable to save the uploaded file.');
    @chmod($slot['path'],0660);$storedPath=$slot['path'];$checksum=hash_file('sha256',$slot['path']);if(!$checksum)throw new RuntimeException('Unable to verify the uploaded file.');

    $payload=[
      'storage_uri'=>$slot['uri'],'original_name'=>$original,'mime_type'=>$mime,'file_size'=>$size,'checksum'=>$checksum,
      'title'=>(string)($_POST['title']??''),'parent_id'=>(string)($_POST['parent_id']??''),
      'duration_seconds'=>(float)($_POST['duration_seconds']??0),
      'recording_source'=>(string)($_POST['recording_source']??($spec['kind']==='recording'?'upload':''))
    ];
    $item=$spec['kind']==='recording'
      ?research_agent_workspace_register_recording($pdo,$viewer,$project,$payload)
      :research_agent_workspace_register_upload($pdo,$viewer,$project,$payload);

    $x=isset($_POST['x'])?(int)$_POST['x']:null;$y=isset($_POST['y'])?(int)$_POST['y']:null;
    if($x!==null&&$y!==null)research_agent_workspace_desktop_position_save($pdo,$viewer,$project,(string)$item['object_type'],(string)$item['public_id'],$x,$y,20);

    $storedPath=null;
    json_response(['ok'=>true,'data'=>['item'=>$item]],201);
}catch(InvalidArgumentException $e){
    if($storedPath&&is_file($storedPath))@unlink($storedPath);
    json_response(['ok'=>false,'error'=>['code'=>'INVALID_UPLOAD','message'=>$e->getMessage()]],422);
}catch(RuntimeException $e){
    if($storedPath&&is_file($storedPath))@unlink($storedPath);
    json_response(['ok'=>false,'error'=>['code'=>'UPLOAD_ERROR','message'=>$e->getMessage()]],400);
}catch(Throwable $e){
    if($storedPath&&is_file($storedPath))@unlink($storedPath);
    $reference=substr(hash('sha256','research-upload|'.$e->getMessage().'|'.microtime(true)),0,12);
    error_log('[Annotated research upload '.$reference.'] '.$e->getMessage());
    json_response(['ok'=>false,'error'=>['code'=>'UPLOAD_INTERNAL','message'=>'Upload could not be completed. Reference: '.$reference]],500);
}
