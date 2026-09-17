<?php
declare(strict_types=1);

function h(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function require_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Invalid CSRF token.'); } }
function bearer_token(): ?string { $h=$_SERVER['HTTP_AUTHORIZATION']??''; return preg_match('/^Bearer\s+(.+)$/i',$h,$m)?trim($m[1]):null; }
function current_user(PDO $pdo): ?array {
    $userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $token=bearer_token();
    if(!$userId && $token){
        try{
            $q=$pdo->prepare('SELECT user_id FROM extension_sessions WHERE token_hash=? AND revoked_at IS NULL');
            $q->execute([hash('sha256',$token)]);$userId=(int)($q->fetchColumn()?:0);
            if($userId){$pdo->prepare('UPDATE extension_sessions SET last_used_at=NOW() WHERE token_hash=?')->execute([hash('sha256',$token)]);}
        }catch(PDOException $e){$userId=0;}
    }
    if(!$userId)return null;
    $s=$pdo->prepare('SELECT id, public_id, username, display_name, email, role, live_presence_mode FROM users WHERE id=? AND status="active"'); $s->execute([$userId]); return $s->fetch() ?: null;
}
function require_user(PDO $pdo): array { $u=current_user($pdo); if(!$u){ header('Location: /login.php'); exit; } return $u; }
function require_api_user(PDO $pdo): array { $u=current_user($pdo); if(!$u)json_response(['ok'=>false,'error'=>['code'=>'AUTH_REQUIRED','message'=>'Sign in to Annotated.']],401); return $u; }
function require_api_mutation_auth(PDO $pdo): array { $u=require_api_user($pdo); if(!bearer_token()){ $sent=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''); if($sent===''||!hash_equals((string)($_SESSION['csrf']??''),$sent))json_response(['ok'=>false,'error'=>['code'=>'CSRF_FAILED']],419); } return $u; }
function users_exist(PDO $pdo): bool { return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0; }
function ulid_like(): string { return bin2hex(random_bytes(13)); }
function json_response(array $data, int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function api_headers(): void { $origin=$_SERVER['HTTP_ORIGIN']??''; if($origin && preg_match('#^chrome-extension://[a-p]{32}$#',$origin)){header('Access-Control-Allow-Origin: '.$origin);header('Vary: Origin');} header('Access-Control-Allow-Headers: Content-Type, Authorization');header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;} }
function canonicalize_url(string $url): string { $parts=parse_url(trim($url)); if(!$parts || empty($parts['host'])) return trim($url); $scheme=strtolower($parts['scheme'] ?? 'https'); $host=strtolower($parts['host']); $path=$parts['path'] ?? '/'; $query=[]; if (!empty($parts['query'])) { parse_str($parts['query'],$query); foreach(array_keys($query) as $k){ if(str_starts_with(strtolower($k),'utm_')||in_array(strtolower($k),['fbclid','gclid'],true)) unset($query[$k]); } } return $scheme.'://'.$host.$path.($query?'?'.http_build_query($query):''); }
function source_type_from_url(string $url, ?string $mediaType=null): string { $host=strtolower((string)parse_url($url,PHP_URL_HOST)); if(str_contains($host,'youtube.com')||str_contains($host,'youtu.be'))return 'youtube'; if($mediaType==='video')return 'video'; if($mediaType==='audio')return 'audio'; return 'webpage'; }
function ensure_source(PDO $pdo,string $url,?string $title=null,?string $mediaType=null): array { $canonical=canonicalize_url($url);$hash=hash('sha256',$canonical);$q=$pdo->prepare('SELECT id,public_id,title,canonical_url,status,current_version_id FROM sources WHERE canonical_url_hash=?');$q->execute([$hash]);$s=$q->fetch();if($s)return $s;$host=(string)(parse_url($canonical,PHP_URL_HOST)?:'');$q=$pdo->prepare('INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title) VALUES(?,?,?,?,?,?)');$q->execute([ulid_like(),source_type_from_url($canonical,$mediaType),$canonical,$hash,$host,$title]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT id,public_id,title,canonical_url,status,current_version_id FROM sources WHERE id=?');$q->execute([$id]);return $q->fetch(); }
function save_data_url_image(string $dataUrl,string $prefix): ?string { if($dataUrl==='')return null;if(!preg_match('#^data:image/(png|jpeg);base64,(.+)$#s',$dataUrl,$m))throw new InvalidArgumentException('Invalid screenshot format.');$bytes=base64_decode($m[2],true);if($bytes===false||strlen($bytes)>8*1024*1024)throw new InvalidArgumentException('Screenshot is too large.');$ext=$m[1]==='jpeg'?'jpg':'png';$rel='storage/uploads/'.date('Y/m').'/'.$prefix.'-'.bin2hex(random_bytes(12)).'.'.$ext;$abs=dirname(__DIR__).'/'.$rel;if(!is_dir(dirname($abs)))mkdir(dirname($abs),0775,true);if(file_put_contents($abs,$bytes)===false)throw new RuntimeException('Unable to save screenshot.');return '/'.$rel; }
function is_blocked(PDO $pdo,int $a,int $b): bool { if($a===$b)return false;try{$q=$pdo->prepare('SELECT 1 FROM blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?) LIMIT 1');$q->execute([$a,$b,$b,$a]);return (bool)$q->fetchColumn();}catch(PDOException $e){return false;} }
function post_login_destination(): string { $next=$_SESSION['after_login']??'/'; unset($_SESSION['after_login']); return is_string($next)&&str_starts_with($next,'/')&&!str_starts_with($next,'//')?$next:'/'; }

function save_data_url_audio(string $dataUrl): ?string {
    if ($dataUrl==='') return null;
    if (!preg_match('#^data:audio/(webm|ogg|mp4|mpeg|wav|x-wav);base64,(.+)$#s',$dataUrl,$m)) throw new InvalidArgumentException('Invalid audio commentary format.');
    $bytes=base64_decode($m[2],true);
    if($bytes===false||strlen($bytes)>16*1024*1024) throw new InvalidArgumentException('Audio commentary is too large.');
    $ext=match($m[1]){'webm'=>'webm','ogg'=>'ogg','mp4'=>'m4a','mpeg'=>'mp3','wav','x-wav'=>'wav',default=>'bin'};
    $rel='storage/uploads/'.date('Y/m').'/commentary-'.bin2hex(random_bytes(12)).'.'.$ext;
    $abs=dirname(__DIR__).'/'.$rel;
    if(!is_dir(dirname($abs)))mkdir(dirname($abs),0775,true);
    if(file_put_contents($abs,$bytes)===false)throw new RuntimeException('Unable to save audio commentary.');
    return '/'.$rel;
}
function notify_user(PDO $pdo,int $userId,?int $actorUserId,string $type,?string $objectType,?string $objectPublicId,?string $body): void {
    try{$q=$pdo->prepare('INSERT INTO notifications(user_id,actor_user_id,notification_type,object_type,object_public_id,body) VALUES(?,?,?,?,?,?)');$q->execute([$userId,$actorUserId,$type,$objectType,$objectPublicId,$body]);}catch(PDOException $e){}
}
function simple_text_diff(string $old,string $new,int $maxLines=400): array {
    $a=array_slice(preg_split('/\R/u',$old)?:[],0,$maxLines);$b=array_slice(preg_split('/\R/u',$new)?:[],0,$maxLines);
    $n=count($a);$m=count($b);$dp=array_fill(0,$n+1,array_fill(0,$m+1,0));
    for($i=$n-1;$i>=0;$i--)for($j=$m-1;$j>=0;$j--)$dp[$i][$j]=$a[$i]===$b[$j]?1+$dp[$i+1][$j+1]:max($dp[$i+1][$j],$dp[$i][$j+1]);
    $out=[];$i=0;$j=0;while($i<$n&&$j<$m){if($a[$i]===$b[$j]){$out[]=['type'=>'same','text'=>$a[$i]];$i++;$j++;}elseif($dp[$i+1][$j]>=$dp[$i][$j+1]){$out[]=['type'=>'removed','text'=>$a[$i++]];}else{$out[]=['type'=>'added','text'=>$b[$j++]];}}
    while($i<$n)$out[]=['type'=>'removed','text'=>$a[$i++]];while($j<$m)$out[]=['type'=>'added','text'=>$b[$j++]];return $out;
}
function normalize_match_text(string $text): string { return mb_strtolower(trim((string)preg_replace('/\s+/u',' ',$text))); }
