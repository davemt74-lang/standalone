<?php
declare(strict_types=1);require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(1);} $root=dirname(__DIR__);$mapping=[];$moved=0;$updated=0;
$targets=[['source_versions','id','screenshot_path'],['captures','id','screenshot_target_path'],['captures','id','screenshot_context_path'],['annotations','id','audio_commentary_path'],['media_derivatives','id','storage_path'],['transcription_jobs','id','input_path'],['media_jobs','id','input_path']];
foreach($targets as [$table,$pk,$column]){
    try{$q=$pdo->query("SELECT $pk row_id,$column stored_path FROM $table WHERE $column LIKE '/storage/uploads/%' OR $column LIKE 'storage/uploads/%'");}catch(Throwable $e){continue;}
    foreach($q->fetchAll() as $row){$old=(string)$row['stored_path'];if($old==='')continue;
        if(!isset($mapping[$old])){$src=storage_path_to_absolute($config,$old);if(!$src||!is_file($src)){fwrite(STDERR,"Missing legacy file: $old\n");continue;}$ext=pathinfo($src,PATHINFO_EXTENSION)?:'bin';$bytes=file_get_contents($src);if($bytes===false){fwrite(STDERR,"Unreadable legacy file: $old\n");continue;}$mapping[$old]=private_storage_write($config,$bytes,'legacy',$ext);$moved++;}
        $u=$pdo->prepare("UPDATE $table SET $column=? WHERE $pk=? AND $column=?");$u->execute([$mapping[$old],$row['row_id'],$old]);$updated+=$u->rowCount();
    }
}
foreach($mapping as $old=>$new){$src=storage_path_to_absolute($config,$old);if($src&&is_file($src))@unlink($src);}echo "Migrated $moved evidence files and updated $updated database references.\n";
