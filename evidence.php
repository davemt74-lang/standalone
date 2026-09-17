<?php
declare(strict_types=1);require __DIR__.'/app/bootstrap.php';$viewer=current_user($pdo);$asset=(string)($_GET['asset']??'');$stored='';
if(!empty($_GET['annotation'])){
    $public=(string)$_GET['annotation'];$access=annotation_access($pdo,$public,$viewer);if(!$access){http_response_code(404);exit('Evidence not found.');}
    $q=$pdo->prepare('SELECT a.audio_commentary_path,c.screenshot_target_path,c.screenshot_context_path,sv.screenshot_path source_snapshot,md.storage_path media_path FROM annotations a JOIN captures c ON c.id=a.capture_id JOIN source_versions sv ON sv.id=a.source_version_id LEFT JOIN media_derivatives md ON md.capture_id=c.id WHERE a.id=? LIMIT 1');$q->execute([$access['id']]);$r=$q->fetch();
    if(!$r){http_response_code(404);exit('Evidence not found.');}
    $stored=(string)match($asset){'target'=>$r['screenshot_target_path']??'','context'=>$r['screenshot_context_path']??'','audio'=>$r['audio_commentary_path']??'','media'=>$r['media_path']??'','source_snapshot'=>$r['source_snapshot']??'',default=>''};
}elseif(!empty($_GET['source'])&&!empty($_GET['version'])&&$asset==='source_snapshot'){
    $source=source_access($pdo,(string)$_GET['source'],$viewer);if(!$source){http_response_code(404);exit('Evidence not found.');}
    $q=$pdo->prepare('SELECT screenshot_path FROM source_versions WHERE source_id=? AND id=?');$q->execute([$source['id'],(int)$_GET['version']]);$stored=(string)($q->fetchColumn()?:'');
}
if($stored===''){http_response_code(404);exit('Evidence not found.');}$abs=storage_path_to_absolute($config,$stored);if(!$abs){http_response_code(404);exit('Evidence not found.');}stream_evidence_file($abs);
