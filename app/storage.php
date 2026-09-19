<?php
declare(strict_types=1);

function private_storage_root(array $config): string {
    $root=trim((string)($config['storage']['private_root']??''));
    if($root==='')$root=dirname(dirname(__DIR__)).'/annotated-private';
    if($root[0]!=='/' && !preg_match('/^[A-Za-z]:[\\\\\/]/',$root))$root=dirname(__DIR__).'/'.$root;
    return rtrim($root,"/\\");
}
function private_storage_allocate(array $config,string $prefix,string $ext): array {
    $rel=date('Y/m').'/'.$prefix.'-'.bin2hex(random_bytes(12)).'.'.ltrim(strtolower($ext),'.');
    $root=private_storage_root($config);$abs=$root.'/'.$rel;$dir=dirname($abs);
    if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Unable to create private storage directory.');
    return ['uri'=>'private://'.$rel,'path'=>$abs];
}
function private_storage_write(array $config,string $bytes,string $prefix,string $ext): string {
    $slot=private_storage_allocate($config,$prefix,$ext);
    if(file_put_contents($slot['path'],$bytes,LOCK_EX)===false)throw new RuntimeException('Unable to write private evidence file.');
    @chmod($slot['path'],0660);
    return $slot['uri'];
}
function profile_image_upload(array $file,int $userId): string {
    $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
    if($error===UPLOAD_ERR_NO_FILE)return '';
    if($error!==UPLOAD_ERR_OK)throw new RuntimeException('Profile photo upload failed.');
    $size=(int)($file['size']??0);
    if($size<=0||$size>5*1024*1024)throw new RuntimeException('Profile photo must be 5 MB or smaller.');
    $tmp=(string)($file['tmp_name']??'');
    if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Invalid profile photo upload.');
    $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($tmp);
    $ext=match($mime){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',default=>''};
    if($ext==='')throw new RuntimeException('Profile photo must be JPG, PNG, or WebP.');
    $info=@getimagesize($tmp);
    if(!$info||empty($info[0])||empty($info[1]))throw new RuntimeException('Profile photo is not a valid image.');
    if((int)$info[0]>8000||(int)$info[1]>8000||((int)$info[0]*(int)$info[1])>24_000_000)throw new RuntimeException('Profile photo dimensions are too large.');
    $root=dirname(__DIR__).'/uploads/profiles';
    if(!is_dir($root)&&!mkdir($root,0775,true)&&!is_dir($root))throw new RuntimeException('Unable to create profile upload directory.');
    $name='profile-'.$userId.'-'.bin2hex(random_bytes(12)).'.'.$ext;$dest=$root.'/'.$name;
    if(!move_uploaded_file($tmp,$dest))throw new RuntimeException('Unable to save profile photo.');
    @chmod($dest,0644);
    return '/uploads/profiles/'.$name;
}
function profile_image_delete_local(?string $url): void {
    $url=trim((string)$url);
    if(!preg_match('#^/uploads/profiles/([A-Za-z0-9._-]+)$#',$url,$m))return;
    $path=dirname(__DIR__).'/uploads/profiles/'.$m[1];
    if(is_file($path))@unlink($path);
}

function storage_path_to_absolute(array $config,string $stored): ?string {
    if(str_starts_with($stored,'private://')){
        $rel=substr($stored,10);if($rel===''||str_contains($rel,'..'))return null;
        return private_storage_root($config).'/'.ltrim($rel,'/');
    }
    if(str_starts_with($stored,'/storage/uploads/'))return dirname(__DIR__).$stored;
    if(str_starts_with($stored,'storage/uploads/'))return dirname(__DIR__).'/'.$stored;
    if($stored!==''&&$stored[0]==='/')return $stored;
    return $stored!==''?dirname(__DIR__).'/'.$stored:null;
}
function evidence_url(string $annotationPublicId,string $asset): string {
    return '/evidence.php?'.http_build_query(['annotation'=>$annotationPublicId,'asset'=>$asset]);
}
function source_evidence_url(string $sourcePublicId,int $versionId): string {
    return '/evidence.php?'.http_build_query(['source'=>$sourcePublicId,'version'=>$versionId,'asset'=>'source_snapshot']);
}
function evidence_content_type(string $path): string {
    return match(strtolower(pathinfo($path,PATHINFO_EXTENSION))){
        'png'=>'image/png','jpg','jpeg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif',
        'webm'=>'audio/webm','ogg'=>'audio/ogg','mp3'=>'audio/mpeg','m4a'=>'audio/mp4','wav'=>'audio/wav',
        'mp4'=>'video/mp4','mov'=>'video/quicktime',default=>'application/octet-stream'};
}
function stream_evidence_file(string $path): never {
    if(!is_file($path)||!is_readable($path)){http_response_code(404);exit('Evidence not found.');}
    $size=(int)filesize($path);$start=0;$end=max(0,$size-1);$status=200;
    $range=(string)($_SERVER['HTTP_RANGE']??'');
    if($range!==''&&preg_match('/bytes=(\d*)-(\d*)/',$range,$m)){
        if($m[1]===''&&$m[2]!==''){$len=min((int)$m[2],$size);$start=max(0,$size-$len);}else{$start=(int)($m[1]?:0);if($m[2]!=='')$end=min($end,(int)$m[2]);}
        if($start>$end||$start>=$size){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
        $status=206;
    }
    $length=$end-$start+1;http_response_code($status);
    header('Content-Type: '.evidence_content_type($path));header('X-Content-Type-Options: nosniff');header('Accept-Ranges: bytes');
    header('Content-Length: '.$length);header('Cache-Control: private, no-store, max-age=0');header('Content-Disposition: inline; filename="'.basename($path).'"');
    if($status===206)header("Content-Range: bytes $start-$end/$size");
    $fh=fopen($path,'rb');if(!$fh){http_response_code(404);exit;}fseek($fh,$start);$left=$length;
    while($left>0&&!feof($fh)){$chunk=fread($fh,min(8192,$left));if($chunk===false)break;echo $chunk;$left-=strlen($chunk);if(function_exists('fastcgi_finish_request')){}flush();}
    fclose($fh);exit;
}
