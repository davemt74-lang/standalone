<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
release_worker_heartbeat($pdo,'research_files','starting','Worker invocation started.');

function research_file_extract_docx(string $path): string {
    if(!class_exists('ZipArchive'))throw new RuntimeException('DOCX extraction requires ZipArchive.');
    $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('DOCX file could not be opened.');
    $xml=(string)$zip->getFromName('word/document.xml');$zip->close();if($xml==='')throw new RuntimeException('DOCX document content is missing.');
    $xml=preg_replace('#</w:p>#i',"
",$xml);$xml=preg_replace('#</w:tr>#i',"
",$xml);
    return trim(preg_replace('/[ 	]+/u',' ',html_entity_decode(strip_tags((string)$xml),ENT_QUOTES|ENT_XML1,'UTF-8'))??'');
}
function research_file_extract_pdf(string $path,array $config): string {
    $configured=trim((string)($config['research_files']['pdf_text_command']??''));$out=tempnam(sys_get_temp_dir(),'annotated-pdf-');if($out===false)throw new RuntimeException('Unable to create PDF extraction temp file.');
    try{
        if($configured!=='')$cmd=str_replace(['{input}','{output}'],[escapeshellarg($path),escapeshellarg($out)],$configured).' 2>&1';
        else{
            $bin=trim((string)shell_exec('command -v pdftotext 2>/dev/null'));if($bin==='')throw new RuntimeException('PDF text extraction is not configured.');
            $cmd=escapeshellarg($bin).' -layout '.escapeshellarg($path).' '.escapeshellarg($out).' 2>&1';
        }
        $lines=[];$code=0;exec($cmd,$lines,$code);if($code!==0)throw new RuntimeException('PDF text extraction failed: '.implode("
",array_slice($lines,-5)));
        return trim((string)@file_get_contents($out));
    }finally{@unlink($out);}
}
function research_file_extract(string $path,string $mime,array $config): string {
    if(in_array($mime,['text/plain','text/markdown','text/csv'],true))return trim((string)file_get_contents($path));
    if($mime==='application/vnd.openxmlformats-officedocument.wordprocessingml.document')return research_file_extract_docx($path);
    if($mime==='application/pdf')return research_file_extract_pdf($path,$config);
    if(in_array($mime,['image/jpeg','image/png','image/webp'],true))return '';
    throw new RuntimeException('Unsupported Research file extraction type.');
}

$job=job_claim($pdo,'research_file_jobs',"SELECT j.id job_id,j.*,u.object_id,u.project_id,u.mime_type,u.original_name,rwo.created_by_user_id,rwo.public_id
  FROM research_file_jobs j JOIN research_workspace_uploads u ON u.object_id=j.object_id JOIN research_workspace_objects rwo ON rwo.id=j.object_id
  WHERE j.status='queued' AND j.available_at<=NOW() ORDER BY j.available_at,j.created_at LIMIT 1",[],3600);
if(!$job){release_worker_heartbeat($pdo,'research_files','success','No queued Research file jobs.');echo "No queued Research file jobs.\n";exit(0);}
$id=(int)$job['job_id'];$token=(string)$job['claim_token'];$input=storage_path_to_absolute($config,(string)$job['input_path']);
try{
    if(!$input||!is_file($input))throw new RuntimeException('Uploaded Research file not found.');
    $pdo->prepare("UPDATE research_workspace_uploads SET processing_status='extracting',last_error=NULL,updated_at=NOW() WHERE object_id=?")->execute([(int)$job['object_id']]);
    $text=research_file_extract($input,(string)$job['mime_type'],$config);
    $text=mb_substr($text,0,2_000_000);$pageCount=(string)$job['mime_type']==='application/pdf'&&$text!==''?max(1,substr_count($text,"\f")+1):null;
    job_claim_renew($pdo,'research_file_jobs',$id,$token,3600);
    $pdo->beginTransaction();job_claim_assert($pdo,'research_file_jobs',$id,$token);
    $pdo->prepare("UPDATE research_workspace_uploads SET processing_status='ready',extracted_text=?,page_count=COALESCE(?,page_count),last_error=NULL,updated_at=NOW() WHERE object_id=?")->execute([$text!==''?$text:null,$pageCount,(int)$job['object_id']]);
    job_claim_complete($pdo,'research_file_jobs',$id,$token);$pdo->commit();
    if(function_exists('research_retrieval_queue_project'))research_retrieval_queue_project($pdo,(int)$job['project_id']);
    notify_user($pdo,(int)$job['created_by_user_id'],null,'research_upload_ready','upload',(string)$job['public_id'],'Your Research file is ready: '.mb_substr((string)$job['original_name'],0,180));
    release_worker_heartbeat($pdo,'research_files','success','Research file processed.',1);echo "Research file ready.\n";
}catch(LostJobClaim $e){
    if($pdo->inTransaction())$pdo->rollBack();release_worker_heartbeat($pdo,'research_files','failure','Lease lost; stale result discarded.');fwrite(STDERR,"Research file lease lost.\n");exit(2);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();$msg=mb_substr($e->getMessage(),0,1000);
    try{
      $blocked=str_contains(strtolower($msg),'not configured')||str_contains(strtolower($msg),'requires ziparchive');
      if($blocked){job_claim_complete($pdo,'research_file_jobs',$id,$token,'blocked');$pdo->prepare("UPDATE research_workspace_uploads SET processing_status='blocked',last_error=?,updated_at=NOW() WHERE object_id=?")->execute([$msg,(int)$job['object_id']]);}
      else{$state=job_claim_retry_or_fail($pdo,'research_file_jobs',$id,$token,$msg,(int)$job['attempts'],3,90);$pdo->prepare("UPDATE research_workspace_uploads SET processing_status=?,last_error=?,updated_at=NOW() WHERE object_id=?")->execute([$state==='failed'?'failed':'queued',$msg,(int)$job['object_id']]);}
    }catch(LostJobClaim $lost){}
    release_worker_heartbeat($pdo,'research_files','failure',$msg);fwrite(STDERR,$msg."\n");exit(1);
}
