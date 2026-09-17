<?php
declare(strict_types=1);require dirname(__DIR__).'/app/bootstrap.php';
// Processes only media inputs supplied by an authorized acquisition/upload adapter.
// It intentionally does not download or bypass controls on third-party media providers.
$job=job_claim($pdo,'media_jobs',"SELECT mj.*,md.media_type,md.capture_id FROM media_jobs mj JOIN media_derivatives md ON md.id=mj.derivative_id WHERE mj.status='queued' AND mj.available_at<=NOW() ORDER BY mj.available_at,mj.id LIMIT 1",[],7200);
if(!$job){echo "No queued media jobs.\n";exit(0);} $id=(int)$job['id'];$token=(string)$job['claim_token'];
try{
 $duration=(float)$job['end_seconds']-(float)$job['start_seconds'];if($duration<=0||$duration>90)throw new RuntimeException('Invalid clip duration.');
 $input=storage_path_to_absolute($config,(string)($job['input_path']??''));if(!$input||!is_file($input)){
   $pdo->beginTransaction();job_claim_assert($pdo,'media_jobs',$id,$token);$pdo->prepare("UPDATE media_derivatives SET processing_status='blocked',processing_error='Authorized media input required' WHERE id=?")->execute([$job['derivative_id']]);job_claim_complete($pdo,'media_jobs',$id,$token,'blocked');$pdo->commit();
   echo "Job $id blocked: authorized input required.\n";exit(0);
 }
 $pdo->prepare("UPDATE media_derivatives SET processing_status='processing',processing_error=NULL WHERE id=?")->execute([$job['derivative_id']]);
 $ext=$job['media_type']==='video'?'mp4':'m4a';$slot=private_storage_allocate($config,'clip',$ext);$out=$slot['path'];
 $start=(float)$job['start_seconds'];$cmd=$job['media_type']==='video' ? sprintf('ffmpeg -y -ss %s -i %s -t %s -vf %s -c:v libx264 -preset veryfast -crf 28 -c:a aac -movflags +faststart %s 2>&1',escapeshellarg((string)$start),escapeshellarg($input),escapeshellarg((string)$duration),escapeshellarg('scale=-2:240'),escapeshellarg($out)) : sprintf('ffmpeg -y -ss %s -i %s -t %s -vn -c:a aac -b:a 96k %s 2>&1',escapeshellarg((string)$start),escapeshellarg($input),escapeshellarg((string)$duration),escapeshellarg($out));
 exec($cmd,$lines,$code);if($code!==0||!is_file($out))throw new RuntimeException('FFmpeg failed: '.implode("\n",array_slice($lines,-3)));
 job_claim_renew($pdo,'media_jobs',$id,$token,7200);
 $rel=$slot['uri'];$checksum=hash_file('sha256',$out);$pdo->beginTransaction();job_claim_assert($pdo,'media_jobs',$id,$token);$pdo->prepare("UPDATE media_derivatives SET storage_path=?,checksum=?,processing_status='ready',processing_error=NULL,height=IF(media_type='video',240,NULL),resolution_label=IF(media_type='video','240p',NULL) WHERE id=?")->execute([$rel,$checksum,$job['derivative_id']]);$pdo->prepare("UPDATE annotations a JOIN media_derivatives md ON md.capture_id=a.capture_id SET a.status='published' WHERE md.id=?")->execute([$job['derivative_id']]);job_claim_complete($pdo,'media_jobs',$id,$token);$pdo->commit();echo "Processed job $id.\n";
}catch(LostJobClaim $e){if($pdo->inTransaction())$pdo->rollBack();if(isset($out)&&is_file($out))@unlink($out);fwrite(STDERR,"Job $id lease lost; result discarded.\n");exit(2);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$msg=mb_substr($e->getMessage(),0,1000);try{$state=job_claim_retry_or_fail($pdo,'media_jobs',$id,$token,$msg,(int)$job['attempts'],3,60);if($state==='failed')$pdo->prepare("UPDATE media_derivatives SET processing_status='failed',processing_error=? WHERE id=?")->execute([$msg,$job['derivative_id']]);else $pdo->prepare("UPDATE media_derivatives SET processing_status='queued',processing_error=? WHERE id=?")->execute([$msg,$job['derivative_id']]);}catch(LostJobClaim $lost){}fwrite(STDERR,$msg."\n");exit(1);}
