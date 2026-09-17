<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$cfg=$config['transcription']??[];$command=trim((string)($cfg['command']??''));$provider=(string)($cfg['provider']??'local');$model=(string)($cfg['model']??'');
$job=job_claim($pdo,'transcription_jobs',"SELECT j.id job_id,j.*,t.annotation_id FROM transcription_jobs j JOIN annotation_transcripts t ON t.id=j.transcript_id WHERE j.status='queued' AND j.available_at<=NOW() ORDER BY j.available_at,j.created_at LIMIT 1",[],7200);
if(!$job){echo "No queued transcription jobs.\n";exit(0);} $id=(int)$job['job_id'];$token=(string)$job['claim_token'];
$pdo->prepare("UPDATE annotation_transcripts SET status='processing',provider=?,model=?,last_error=NULL,updated_at=NOW() WHERE id=?")->execute([$provider,$model?:null,$job['transcript_id']]);
$input=storage_path_to_absolute($config,(string)$job['input_path']);
try{
    if(!$input||!is_file($input))throw new RuntimeException('Audio commentary file not found.');
    if($command===''){
        $pdo->beginTransaction();job_claim_assert($pdo,'transcription_jobs',$id,$token);$pdo->prepare("UPDATE annotation_transcripts SET status='blocked',last_error=?,updated_at=NOW() WHERE id=?")->execute(['No transcription command configured.',$job['transcript_id']]);job_claim_complete($pdo,'transcription_jobs',$id,$token,'blocked');$pdo->commit();echo "Transcription blocked: configure transcription.command.\n";exit(0);
    }
    $out=tempnam(sys_get_temp_dir(),'annotated-transcript-');if($out===false)throw new RuntimeException('Unable to create transcript temp file.');
    $cmd=str_replace(['{input}','{output}'],[escapeshellarg($input),escapeshellarg($out)],$command).' 2>&1';$lines=[];$code=0;exec($cmd,$lines,$code);
    if($code!==0)throw new RuntimeException('Transcription command failed: '.implode("\n",array_slice($lines,-8)));
    $text=trim((string)@file_get_contents($out));@unlink($out);if($text==='')throw new RuntimeException('Transcription returned no text.');
    job_claim_renew($pdo,'transcription_jobs',$id,$token,7200);
    $pdo->beginTransaction();job_claim_assert($pdo,'transcription_jobs',$id,$token);$pdo->prepare("UPDATE annotation_transcripts SET status='ready',raw_text=?,last_error=NULL,updated_at=NOW() WHERE id=?")->execute([$text,$job['transcript_id']]);job_claim_complete($pdo,'transcription_jobs',$id,$token);$pdo->commit();
    $q=$pdo->prepare('SELECT user_id,public_id FROM annotations WHERE id=?');$q->execute([$job['annotation_id']]);$a=$q->fetch();if($a)notify_user($pdo,(int)$a['user_id'],null,'transcript_ready','annotation',$a['public_id'],'Your audio commentary transcript is ready.');
    echo "Transcription ready.\n";
}catch(LostJobClaim $e){if($pdo->inTransaction())$pdo->rollBack();if(isset($out))@unlink($out);fwrite(STDERR,"Transcription lease lost; result discarded.\n");exit(2);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(isset($out))@unlink($out);$msg=mb_substr($e->getMessage(),0,1000);try{$state=job_claim_retry_or_fail($pdo,'transcription_jobs',$id,$token,$msg,(int)$job['attempts'],3,120);$pdo->prepare("UPDATE annotation_transcripts SET status=?,last_error=?,updated_at=NOW() WHERE id=?")->execute([$state==='failed'?'failed':'queued',$msg,$job['transcript_id']]);}catch(LostJobClaim $lost){}fwrite(STDERR,$msg."\n");exit(1);}
