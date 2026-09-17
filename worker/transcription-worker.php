<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$cfg=$config['transcription']??[];$command=trim((string)($cfg['command']??''));$provider=(string)($cfg['provider']??'local');$model=(string)($cfg['model']??'');
$q=$pdo->query("SELECT j.id job_id,j.transcript_id,j.input_path,t.annotation_id FROM transcription_jobs j JOIN annotation_transcripts t ON t.id=j.transcript_id WHERE j.status='queued' ORDER BY j.created_at ASC LIMIT 1");$job=$q->fetch();
if(!$job){echo "No queued transcription jobs.\n";exit(0);} 
$pdo->prepare("UPDATE transcription_jobs SET status='processing',attempts=attempts+1,started_at=NOW() WHERE id=?")->execute([$job['job_id']]);$pdo->prepare("UPDATE annotation_transcripts SET status='processing',provider=?,model=?,updated_at=NOW() WHERE id=?")->execute([$provider,$model?:null,$job['transcript_id']]);
$input=dirname(__DIR__).'/'.ltrim((string)$job['input_path'],'/');
try{
    if(!is_file($input))throw new RuntimeException('Audio commentary file not found.');
    if($command===''){
        $pdo->prepare("UPDATE transcription_jobs SET status='blocked',last_error=?,completed_at=NOW() WHERE id=?")->execute(['No transcription command configured.',$job['job_id']]);
        $pdo->prepare("UPDATE annotation_transcripts SET status='blocked',last_error=?,updated_at=NOW() WHERE id=?")->execute(['No transcription command configured.',$job['transcript_id']]);
        echo "Transcription blocked: configure transcription.command.\n";exit(0);
    }
    $out=tempnam(sys_get_temp_dir(),'annotated-transcript-');if($out===false)throw new RuntimeException('Unable to create transcript temp file.');
    $cmd=str_replace(['{input}','{output}'],[escapeshellarg($input),escapeshellarg($out)],$command).' 2>&1';$lines=[];$code=0;exec($cmd,$lines,$code);
    if($code!==0)throw new RuntimeException('Transcription command failed: '.implode("\n",array_slice($lines,-8)));
    $text=trim((string)@file_get_contents($out));@unlink($out);if($text==='')throw new RuntimeException('Transcription returned no text.');
    $pdo->prepare("UPDATE annotation_transcripts SET status='ready',raw_text=?,last_error=NULL,updated_at=NOW() WHERE id=?")->execute([$text,$job['transcript_id']]);
    $pdo->prepare("UPDATE transcription_jobs SET status='done',last_error=NULL,completed_at=NOW() WHERE id=?")->execute([$job['job_id']]);
    $q=$pdo->prepare('SELECT user_id,public_id FROM annotations WHERE id=?');$q->execute([$job['annotation_id']]);$a=$q->fetch();if($a)notify_user($pdo,(int)$a['user_id'],null,'transcript_ready','annotation',$a['public_id'],'Your audio commentary transcript is ready.');
    echo "Transcription ready.\n";
}catch(Throwable $e){$msg=mb_substr($e->getMessage(),0,1000);$pdo->prepare("UPDATE transcription_jobs SET status='failed',last_error=?,completed_at=NOW() WHERE id=?")->execute([$msg,$job['job_id']]);$pdo->prepare("UPDATE annotation_transcripts SET status='failed',last_error=?,updated_at=NOW() WHERE id=?")->execute([$msg,$job['transcript_id']]);fwrite(STDERR,$msg."\n");exit(1);} 
