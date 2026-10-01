<?php
declare(strict_types=1);

/**
 * Chrome Extension releases are immutable privately stored ZIPs. Admin publication
 * changes only the active channel pointer, never the bundled deployment artifact.
 */
function extension_releases_ready(PDO $pdo): bool {
    try{
        foreach(['extension_releases','extension_release_channels','extension_release_events'] as $table)
            if(!installer_table_exists($pdo,$table))return false;
        return true;
    }catch(Throwable $e){return false;}
}
function extension_release_channel(string $channel): string {
    if(!in_array($channel,['stable','beta'],true))throw new InvalidArgumentException('Unknown extension release channel.');
    return $channel;
}
function extension_release_manifest_validate(array $m): array {
    if(($m['manifest_version']??null)!==3)throw new InvalidArgumentException('Only Chrome Manifest V3 packages are supported.');
    $version=(string)($m['version']??'');
    if(!preg_match('/^(?:0|[1-9][0-9]*)(?:\\.(?:0|[1-9][0-9]*)){1,3}$/D',$version)||strlen($version)>32)
        throw new InvalidArgumentException('manifest.json must contain a valid numeric extension version.');
    if(trim((string)($m['name']??''))==='')throw new InvalidArgumentException('Extension name is required.');
    $min=(string)($m['minimum_chrome_version']??'');
    if($min!==''&&!preg_match('/^[0-9]{2,4}(?:\\.[0-9]+){0,3}$/D',$min))
        throw new InvalidArgumentException('Minimum Chrome version is malformed.');
    $required=['permissions','host_permissions'];
    foreach($required as $field)if(isset($m[$field])&&(!is_array($m[$field])||array_filter($m[$field],'is_string')!==$m[$field]))
        throw new InvalidArgumentException('Extension manifest permissions are malformed.');
    return [
      'manifest_version'=>3,'version'=>$version,
      'name'=>mb_substr(trim((string)$m['name']),0,190),
      'description'=>mb_substr((string)($m['description']??''),0,2000),
      'minimum_chrome_version'=>$min,
      'permissions'=>array_values((array)($m['permissions']??[])),
      'host_permissions'=>array_values((array)($m['host_permissions']??[])),
      'background'=>(array)($m['background']??[]),
      'side_panel'=>(array)($m['side_panel']??[]),
    ];
}
/** Reject ZIP traversal, symlinks, zip bombs, and missing runtime files. */
function extension_release_zip_inspect(string $file): array {
    if(!class_exists(ZipArchive::class))
        throw new RuntimeException('The PHP ZIP extension must be enabled before uploading extension builds.');
    $zip=new ZipArchive();
    if($zip->open($file)!==true)throw new InvalidArgumentException('Not a readable ZIP archive.');
    try{
        $names=[];$expanded=0;$manifestData=null;
        if($zip->numFiles===0||$zip->numFiles>512)throw new InvalidArgumentException('Extension ZIP has an invalid file count.');
        for($i=0;$i<$zip->numFiles;$i++){
            $stat=$zip->statIndex($i);$name=(string)($stat['name']??'');
            if($name===''||strlen($name)>255||str_contains($name,'\\')||str_starts_with($name,'/')||
              preg_match('#(?:^|/)\\.\\.(?:/|$)|^[A-Za-z]:#',$name))
                throw new InvalidArgumentException('ZIP contains an unsafe file path.');
            $lower=strtolower($name);if(isset($names[$lower]))throw new InvalidArgumentException('ZIP contains duplicate paths.');
            $names[$lower]=true;
            $size=(int)($stat['size']??0);$compressed=(int)($stat['comp_size']??0);
            $expanded+=$size;if($expanded>100*1024*1024||$size>35*1024*1024)
                throw new InvalidArgumentException('Extension ZIP exceeds the safe expanded-size limit.');
            if($size>1*1024*1024&&$compressed>0&&$size/$compressed>300)
                throw new InvalidArgumentException('Extension ZIP contains an excessive compression ratio.');
            $ops=0;$attrs=0;if($zip->getExternalAttributesIndex($i,$ops,$attrs)){
                if((($attrs>>16)&0170000)===0120000)
                    throw new InvalidArgumentException('ZIP symlinks are not permitted.');
            }
            if($name==='manifest.json'){
                if($size>256*1024)throw new InvalidArgumentException('Manifest file is too large.');
                $manifestData=$zip->getFromIndex($i);
            }
        }
        if(!is_string($manifestData))throw new InvalidArgumentException('manifest.json must be at the ZIP root.');
        try{$decoded=json_decode($manifestData,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){
            throw new InvalidArgumentException('Extension manifest contains invalid JSON.');
        }
        if(!is_array($decoded))throw new InvalidArgumentException('Extension manifest must be an object.');
        $manifest=extension_release_manifest_validate($decoded);
        foreach(['sidepanel.html','service-worker.js'] as $required)
            if(!isset($names[$required]))throw new InvalidArgumentException('Extension ZIP is missing '.$required.'.');
        if(($decoded['background']['service_worker']??null)!=='service-worker.js'||
           ($decoded['side_panel']['default_path']??null)!=='sidepanel.html')
            throw new InvalidArgumentException('Extension ZIP does not match the expected Annotated runtime paths.');
        return $manifest;
    }finally{$zip->close();}
}
function extension_release_meta_validate(array $input): array {
    $name=mb_substr(trim((string)($input['developer_name']??'')),0,190);
    if($name==='')throw new InvalidArgumentException('Developer or publisher name is required.');
    $url=trim((string)($input['developer_url']??''));
    if($url!==''&&(strlen($url)>512||!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'))
        throw new InvalidArgumentException('Developer URL must be an HTTPS URL.');
    $notes=mb_substr(trim((string)($input['release_notes']??'')),0,16000);
    if($notes==='')throw new InvalidArgumentException('Release notes are required.');
    $date=trim((string)($input['build_date']??''));
    if($date!==''){
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
        if(!$d||$d->format('Y-m-d')!==$date)throw new InvalidArgumentException('Build date must be YYYY-MM-DD.');
    }
    return ['developer_name'=>$name,'developer_url'=>$url?:null,'release_notes'=>$notes,'build_date'=>$date?:null,
      'channel'=>extension_release_channel((string)($input['channel']??'stable'))];
}
function extension_release_storage_path(array $config,string $uri,bool $create=false): string {
    if(!preg_match('#^private://(\\d{4}/\\d{2}/extension-release-[a-f0-9]{24}\\.zip)$#D',$uri,$match))
        throw new RuntimeException('Unknown extension artifact path.');
    $root=private_storage_root($config);
    if($create&&!is_dir($root)&&!mkdir($root,0770,true)&&!is_dir($root))
        throw new RuntimeException('Extension private storage is not writable.');
    $real=realpath($root);
    $site=realpath(dirname(__DIR__));
    $public=realpath((string)($_SERVER['DOCUMENT_ROOT']??''))?:null;
    if(!$real||($site&&str_starts_with(rtrim($real,'/').'/',rtrim($site,'/').'/'))||
       ($public&&str_starts_with(rtrim($real,'/').'/',rtrim($public,'/').'/')))
        throw new RuntimeException('Extension artifacts must use private storage outside the public website.');
    $path=$real.'/'.$match[1];
    if($create&&!is_dir(dirname($path))&&!mkdir(dirname($path),0770,true)&&!is_dir(dirname($path)))
        throw new RuntimeException('Extension artifact directory is not writable.');
    return $path;
}
function extension_release_upload(PDO $pdo,array $config,array $admin,array $file,array $input): array {
    admin_access_assert_capability($pdo,$admin,'admin.platform.manage');
    if(!extension_releases_ready($pdo))throw new RuntimeException('Run the extension release migration first.');
    if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)
        throw new InvalidArgumentException('Select an extension ZIP within the server upload limit.');
    $tmp=(string)($file['tmp_name']??'');$size=(int)($file['size']??0);
    if($tmp===''||!is_uploaded_file($tmp)||$size<100||$size>30*1024*1024)
        throw new InvalidArgumentException('Upload a real ZIP file of at most 30 MB.');
    $metadata=extension_release_meta_validate($input);
    $manifest=extension_release_zip_inspect($tmp);
    $sha=hash_file('sha256',$tmp);if(!is_string($sha))throw new RuntimeException('Unable to checksum extension ZIP.');
    $q=$pdo->prepare('SELECT public_id FROM extension_releases WHERE version=? AND channel=? LIMIT 1');
    $q->execute([$manifest['version'],$metadata['channel']]);
    if($q->fetch())throw new RuntimeException('This version and channel already exist. Published ZIPs are immutable.');
    $slot=private_storage_allocate($config,'extension-release','zip');
    $path=extension_release_storage_path($config,$slot['uri'],true);
    if(!move_uploaded_file($tmp,$path))throw new RuntimeException('Unable to move extension ZIP into private storage.');
    @chmod($path,0600);
    try{
        if(!hash_equals($sha,(string)hash_file('sha256',$path)))throw new RuntimeException('Uploaded extension ZIP failed its integrity check.');
        $pdo->beginTransaction();
        $public=ulid_like();
        $pdo->prepare('INSERT INTO extension_releases(public_id,version,channel,artifact_uri,sha256,size_bytes,manifest_json,developer_name,developer_url,release_notes,build_date,uploaded_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$public,$manifest['version'],$metadata['channel'],$slot['uri'],$sha,$size,json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
            $metadata['developer_name'],$metadata['developer_url'],$metadata['release_notes'],$metadata['build_date'],(int)$admin['id']]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO extension_release_events(release_id,actor_user_id,event_type,channel,reason) VALUES(?,?,?,?,?)')
          ->execute([$id,(int)$admin['id'],'uploaded',$metadata['channel'],'Private extension ZIP uploaded and validated.']);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();@unlink($path);throw $e;}
    return extension_release_by_public($pdo,$public)??[];
}
function extension_release_by_public(PDO $pdo,string $public): ?array {
    if(!extension_releases_ready($pdo))return null;
    $q=$pdo->prepare('SELECT * FROM extension_releases WHERE public_id=? LIMIT 1');$q->execute([$public]);return $q->fetch()?:null;
}
function extension_release_list(PDO $pdo,int $limit=100): array {
    if(!extension_releases_ready($pdo))return [];
    $limit=max(1,min(250,$limit));
    $q=$pdo->query('SELECT r.*,u.display_name uploader_name,c.channel active_channel FROM extension_releases r JOIN users u ON u.id=r.uploaded_by_user_id LEFT JOIN extension_release_channels c ON c.release_id=r.id ORDER BY r.created_at DESC,r.id DESC LIMIT '.$limit);
    return $q->fetchAll()?:[];
}
function extension_release_active(PDO $pdo,string $channel='stable'): ?array {
    if(!extension_releases_ready($pdo))return null;
    $q=$pdo->prepare('SELECT r.* FROM extension_release_channels c JOIN extension_releases r ON r.id=c.release_id WHERE c.channel=? AND r.published_at IS NOT NULL LIMIT 1');
    $q->execute([extension_release_channel($channel)]);return $q->fetch()?:null;
}
function extension_release_publish(PDO $pdo,array $admin,string $public,string $reason): array {
    admin_access_assert_capability($pdo,$admin,'admin.platform.manage');
    admin_access_assert_capability($pdo,$admin,'admin.platform.release');
    $reason=mb_substr(trim($reason),0,1000);
    if($reason==='')throw new InvalidArgumentException('A publication or rollback reason is required.');
    $target=extension_release_by_public($pdo,$public);
    if(!$target)throw new RuntimeException('Extension release not found.');
    $channel=extension_release_channel((string)$target['channel']);
    // Publish must never select a missing or corrupted private ZIP.
    global $config;
    $file=extension_release_storage_path($config,(string)$target['artifact_uri']);
    if(!is_file($file)||!hash_equals((string)$target['sha256'],(string)hash_file('sha256',$file)))
        throw new RuntimeException('Extension release integrity verification failed.');
    $pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT release_id FROM extension_release_channels WHERE channel=? FOR UPDATE');$lock->execute([$channel]);
        $previous=$lock->fetchColumn();$previous=$previous===false?null:(int)$previous;
        if($previous===(int)$target['id'])throw new RuntimeException('This release is already active.');
        // A channel row has an FK to immutable release metadata; rollback selects
        // a previously published row without re-uploading or rewriting its ZIP.
        $pdo->prepare('INSERT INTO extension_release_channels(channel,release_id,updated_by_user_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE release_id=VALUES(release_id),updated_by_user_id=VALUES(updated_by_user_id),published_at=NOW()')
          ->execute([$channel,(int)$target['id'],(int)$admin['id']]);
        $pdo->prepare('UPDATE extension_releases SET published_at=COALESCE(published_at,NOW()) WHERE id=?')->execute([(int)$target['id']]);
        $pdo->prepare('INSERT INTO extension_release_events(release_id,actor_user_id,event_type,channel,from_release_id,reason) VALUES(?,?,?,?,?,?)')
          ->execute([(int)$target['id'],(int)$admin['id'],$target['published_at']===null?'published':'restored',$channel,$previous,$reason]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return extension_release_active($pdo,$channel)??[];
}
function extension_release_download_row(PDO $pdo,string $public): ?array {
    $row=extension_release_by_public($pdo,$public);
    return $row&&$row['published_at']!==null?$row:null;
}
function extension_release_events(PDO $pdo,int $limit=60): array {
    if(!extension_releases_ready($pdo))return [];$limit=max(1,min(200,$limit));
    $q=$pdo->query('SELECT e.*,r.version,r.channel release_channel,u.display_name actor_name,prev.version previous_version FROM extension_release_events e JOIN extension_releases r ON r.id=e.release_id JOIN users u ON u.id=e.actor_user_id LEFT JOIN extension_releases prev ON prev.id=e.from_release_id ORDER BY e.id DESC LIMIT '.$limit);
    return $q->fetchAll()?:[];
}
