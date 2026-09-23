<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
release_worker_heartbeat($pdo,'research_transcription','starting','Worker invocation started.');
$cfg=$config['transcription']??[];$command=trim((string)($cfg['command']??''));$provider=(string)($cfg['provider']??'local');$model=(string)($cfg['model']??'');
$job=job_claim($pdo,'research_transcription_jobs',"SELECT j.id job_id,j.*,t.object_id,rwr.project_id,rwo.public_id,rwo.created_by_user_id,rwo.title
  FROM research_transcription_jobs j JOIN research_workspace_recording_transcripts t ON t.id=j.transcript_id JOIN research_workspace_recordings rwr ON rwr.object_id=t.object_id JOIN research_workspace_objects rwo ON rwo.id=t.object_id
  WHERE j.status='queued' AND j.available_at<=NOW() ORDER BY j.available_at,j.created_at LIMIT 1",[],7200);
if(!$job){release_worker_heartbeat($pdo,'research_transcription','success','No queued Research transcription jobs.');echo "No queued Research transcription jobs.\n";exit(0);}
$id=(int)$job['job_id'];$token=(string)$job['claim_token'];
$pdo->prepare("UPDATE research_workspace_recording_transcripts SET status='processing',provider=?,model=?,last_error=NULL,updated_at=NOW() WHERE id=?")->execute([$provider,$model?:null,(int)$job['transcript_id']]);
$input=storage_path_to_absolute($config,(string)$job['input_path']);$out=null;
try{
    if(!$input||!is_file($input))throw new RuntimeException('Research recording file not found.');
    if($command===''){
        $pdo->beginTransaction();job_claim_assert($pdo,'research_transcription_jobs',$id,$token);
        $pdo->prepare("UPDATE research_workspace_recording_transcripts SET status='blocked',last_error=?,updated_at=NOW() WHERE id=?")->execute(['No transcription command configured.',(int)$job['transcript_id']]);
        job_claim_complete($pdo,'research_transcription_jobs',$id,$token,'blocked');$pdo->commit();
        release_worker_heartbeat($pdo,'research_transcription','success','Job blocked: transcription command is not configured.',1);echo "Research transcription blocked: configure transcription.command.\n";exit(0);
    }
    $out=tempnam(sys_get_temp_dir(),'annotated-research-transcript-');if($out===false)throw new RuntimeException('Unable to create transcript temp file.');
    $cmd=str_replace(['{input}','{output}'],[escapeshellarg($input),escapeshellarg($out)],$command).' 2>&1';$lines=[];$code=0;exec($cmd,$lines,$code);
    if($code!==0)throw new RuntimeException('Transcription command failed: '.implode("\n",array_slice($lines,-8)));
    $raw=trim((string)@file_get_contents($out));if($raw==='')throw new RuntimeException('Transcription returned no text.');
    $decoded=json_decode($raw,true);$segments=null;$language=null;
    if(is_array($decoded)&&isset($decoded['text'])){$text=trim((string)$decoded['text']);$segments=is_array($decoded['segments']??null)?$decoded['segments']:null;$language=isset($decoded['language'])?mb_substr((string)$decoded['language'],0,32):null;}
    else $text=$raw;
    if($text==='')throw new RuntimeException('Transcription returned no text.');
    job_claim_renew($pdo,'research_transcription_jobs',$id,$token,7200);
    $pdo->beginTransaction();job_claim_assert($pdo,'research_transcription_jobs',$id,$token);
    $pdo->prepare("UPDATE research_workspace_recording_transcripts SET status='ready',raw_text=?,segments_json=?,language=COALESCE(?,language),last_error=NULL,updated_at=NOW() WHERE id=?")->execute([$text,$segments?json_encode($segments,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,$language,(int)$job['transcript_id']]);
    job_claim_complete($pdo,'research_transcription_jobs',$id,$token);$pdo->commit();
    if(function_exists('research_retrieval_queue_project'))research_retrieval_queue_project($pdo,(int)$job['project_id']);
    notify_user($pdo,(int)$job['created_by_user_id'],null,'research_transcript_ready','recording',(string)$job['public_id'],'Transcript ready: '.mb_substr((string)$job['title'],0,180));
    release_worker_heartbeat($pdo,'research_transcription','success','Research transcription completed.',1);echo "Research transcript ready.\n";
}catch(LostJobClaim $e){
    if($pdo->inTransaction())$pdo->rollBack();release_worker_heartbeat($pdo,'research_transcription','failure','Lease lost; stale result discarded.');fwrite(STDERR,"Research transcription lease lost.\n");exit(2);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();$msg=mb_substr($e->getMessage(),0,1000);
    try{$state=job_claim_retry_or_fail($pdo,'research_transcription_jobs',$id,$token,$msg,(int)$job['attempts'],3,120);$pdo->prepare("UPDATE research_workspace_recording_transcripts SET status=?,last_error=?,updated_at=NOW() WHERE id=?")->execute([$state==='failed'?'failed':'queued',$msg,(int)$job['transcript_id']]);}catch(LostJobClaim $lost){}
    release_worker_heartbeat($pdo,'research_transcription','failure',$msg);fwrite(STDERR,$msg."\n");exit(1);
}finally{if($out)@unlink($out);}
