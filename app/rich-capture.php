<?php
declare(strict_types=1);

function rich_capture_max_bytes(): int { return 24*1024*1024; }
function rich_capture_chunk_bytes(): int { return 768*1024; }
function rich_capture_normalize_mime(string $mime): string {
    $mime=strtolower(trim(explode(';',$mime,2)[0]??''));
    return in_array($mime,['video/webm','audio/webm'],true)?$mime:'';
}
function rich_capture_media_type_for_mime(string $mime): ?string {
    $mime=rich_capture_normalize_mime($mime);
    return $mime==='video/webm'?'video':($mime==='audio/webm'?'audio':null);
}
function rich_capture_assert_ebml(string $bytes): void {
    if(strlen($bytes)<4||substr($bytes,0,4)!=="\x1A\x45\xDF\xA3")throw new InvalidArgumentException('Captured media is not a valid WebM stream.');
}
function rich_capture_cleanup_stale_uploads(PDO $pdo,array $config,int $userId): void {
    $q=$pdo->prepare('SELECT id,storage_path FROM media_uploads WHERE user_id=? AND consumed_at IS NULL AND expires_at<NOW() ORDER BY id LIMIT 20');$q->execute([$userId]);
    foreach($q->fetchAll() as $row){$abs=storage_path_to_absolute($config,(string)$row['storage_path']);if($abs&&is_file($abs))@unlink($abs);$pdo->prepare('DELETE FROM media_uploads WHERE id=? AND consumed_at IS NULL')->execute([$row['id']]);}
}
function rich_capture_upload_start(PDO $pdo,array $config,int $userId,string $mediaType,string $mimeType,?int $expectedBytes=null): array {
    rich_capture_cleanup_stale_uploads($pdo,$config,$userId);$mime=rich_capture_normalize_mime($mimeType);$derived=rich_capture_media_type_for_mime($mime);
    if(!$mime||!in_array($mediaType,['video','audio'],true)||$derived!==$mediaType)throw new InvalidArgumentException('Only Chrome WebM tab captures are accepted.');
    $max=rich_capture_max_bytes();if($expectedBytes!==null&&($expectedBytes<1||$expectedBytes>$max))throw new InvalidArgumentException('Captured media is too large.');
    $slot=private_storage_allocate($config,'media-input','webm');if(file_put_contents($slot['path'],'',LOCK_EX)===false)throw new RuntimeException('Unable to initialize private media upload.');@chmod($slot['path'],0660);
    $public=ulid_like();try{$q=$pdo->prepare('INSERT INTO media_uploads(public_id,user_id,media_type,mime_type,expected_bytes,max_bytes,storage_path,expires_at) VALUES(?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))');$q->execute([$public,$userId,$mediaType,$mime,$expectedBytes,$max,$slot['uri']]);}catch(Throwable $e){@unlink($slot['path']);throw $e;}
    return ['upload_id'=>$public,'max_bytes'=>$max,'chunk_bytes'=>rich_capture_chunk_bytes(),'expires_in_seconds'=>1800];
}
function rich_capture_upload_append(PDO $pdo,array $config,int $userId,string $publicId,int $offset,string $chunkBase64): array {
    if($publicId===''||$offset<0||$chunkBase64==='')throw new InvalidArgumentException('Invalid media upload chunk.');if(strlen($chunkBase64)>1100000)throw new InvalidArgumentException('Media upload chunk is too large.');$bytes=base64_decode($chunkBase64,true);if($bytes===false||$bytes==='')throw new InvalidArgumentException('Invalid media upload encoding.');
    $len=strlen($bytes);if($len>rich_capture_chunk_bytes())throw new InvalidArgumentException('Media upload chunk is too large.');$pdo->beginTransaction();$fh=null;$original=null;
    try{$q=$pdo->prepare('SELECT * FROM media_uploads WHERE public_id=? AND user_id=? FOR UPDATE');$q->execute([$publicId,$userId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Media upload not found.');if($row['consumed_at'])throw new RuntimeException('Media upload has already been used.');if(strtotime((string)$row['expires_at'])<=time())throw new RuntimeException('Media upload expired.');$received=(int)$row['received_bytes'];if($offset!==$received)throw new RuntimeException('Media upload offset mismatch.');$next=$received+$len;if($next>(int)$row['max_bytes'])throw new RuntimeException('Captured media exceeds the upload limit.');if($row['expected_bytes']!==null&&$next>(int)$row['expected_bytes'])throw new RuntimeException('Captured media exceeds the declared size.');if($received===0)rich_capture_assert_ebml($bytes);
        $abs=storage_path_to_absolute($config,(string)$row['storage_path']);if(!$abs)throw new RuntimeException('Invalid private media path.');$fh=fopen($abs,'c+b');if(!$fh||!flock($fh,LOCK_EX))throw new RuntimeException('Unable to lock private media upload.');$actual=fstat($fh)['size']??-1;if((int)$actual!==$received)throw new RuntimeException('Media upload state mismatch.');$original=$received;fseek($fh,0,SEEK_END);$written=fwrite($fh,$bytes);if($written!==$len)throw new RuntimeException('Unable to write complete media chunk.');fflush($fh);$pdo->prepare('UPDATE media_uploads SET received_bytes=? WHERE id=?')->execute([$next,$row['id']]);$pdo->commit();flock($fh,LOCK_UN);fclose($fh);return ['upload_id'=>$publicId,'received_bytes'=>$next,'complete'=>$row['expected_bytes']!==null&&$next===(int)$row['expected_bytes']];
    }catch(Throwable $e){if($fh){if($original!==null){ftruncate($fh,(int)$original);fflush($fh);}flock($fh,LOCK_UN);fclose($fh);}if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function rich_capture_upload_for_publish(PDO $pdo,array $config,int $userId,string $publicId,string $expectedMediaType): array {
    $q=$pdo->prepare('SELECT * FROM media_uploads WHERE public_id=? AND user_id=? FOR UPDATE');$q->execute([$publicId,$userId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Captured media upload not found.');if($row['consumed_at'])throw new RuntimeException('Captured media upload has already been published.');if(strtotime((string)$row['expires_at'])<=time())throw new RuntimeException('Captured media upload expired.');if((string)$row['media_type']!==$expectedMediaType)throw new RuntimeException('Captured media type does not match the annotation.');$received=(int)$row['received_bytes'];if($received<4||$received>(int)$row['max_bytes'])throw new RuntimeException('Captured media upload is incomplete.');if($row['expected_bytes']!==null&&$received!==(int)$row['expected_bytes'])throw new RuntimeException('Captured media upload is incomplete.');$abs=storage_path_to_absolute($config,(string)$row['storage_path']);if(!$abs||!is_file($abs)||(int)filesize($abs)!==$received)throw new RuntimeException('Captured media file is unavailable.');$head=(string)file_get_contents($abs,false,null,0,4);rich_capture_assert_ebml($head);$row['checksum']=hash_file('sha256',$abs);return $row;
}
function rich_capture_mark_consumed(PDO $pdo,int $uploadId,string $checksum): void {
    $q=$pdo->prepare('UPDATE media_uploads SET checksum=?,consumed_at=NOW() WHERE id=? AND consumed_at IS NULL');$q->execute([$checksum,$uploadId]);if($q->rowCount()!==1)throw new RuntimeException('Captured media upload could not be consumed.');
}
function rich_capture_project_for_publish(PDO $pdo,int $userId,string $projectPublicId,string $visibility,?int $annotationTeamId): ?array {
    if(trim($projectPublicId)==='')return null;$project=project_access($pdo,$userId,$projectPublicId);if(!$project||!project_can_write($project))throw new RuntimeException('Research project is not writable.');
    $projectTeam=!empty($project['team_id'])?(int)$project['team_id']:null;if($projectTeam&&$visibility==='private')throw new RuntimeException('Private captures cannot be added directly to a Team Research project.');if($projectTeam&&$visibility==='team'&&$annotationTeamId!==$projectTeam)throw new RuntimeException('Team capture and Research project must use the same team.');return $project;
}
