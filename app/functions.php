<?php
declare(strict_types=1);

function h(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function profile_path(string $username): string { return '/'.rawurlencode(trim($username)); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function require_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Invalid CSRF token.'); } }
function bearer_token(): ?string { $h=$_SERVER['HTTP_AUTHORIZATION']??''; return preg_match('/^Bearer\s+(.+)$/i',$h,$m)?trim($m[1]):null; }
function current_user(PDO $pdo): ?array {
    $sessionUserId=!empty($_SESSION['user_id'])?(int)$_SESSION['user_id']:0;$token=bearer_token();$userId=0;
    if($token){
        try{$q=$pdo->prepare('SELECT user_id FROM extension_sessions WHERE token_hash=? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>NOW())');$q->execute([hash('sha256',$token)]);$userId=(int)($q->fetchColumn()?:0);if($userId)$pdo->prepare('UPDATE extension_sessions SET last_used_at=NOW() WHERE token_hash=?')->execute([hash('sha256',$token)]);}catch(PDOException $e){$userId=0;}
        if(!$userId)return null;
    }else{$userId=$sessionUserId;}
    if(!$userId)return null;
    try{$s=$pdo->prepare('SELECT id,public_id,username,display_name,email,role,live_presence_mode,profile_image_url,sessions_revoked_before FROM users WHERE id=? AND status="active"');$s->execute([$userId]);$user=$s->fetch();}catch(PDOException $e){$s=$pdo->prepare('SELECT id,public_id,username,display_name,email,role,live_presence_mode FROM users WHERE id=? AND status="active"');$s->execute([$userId]);$user=$s->fetch();if($user){$user['profile_image_url']=null;$user['sessions_revoked_before']=null;}}if(!$user)return null;
    if($sessionUserId&&!$token&&!empty($user['sessions_revoked_before'])){$revoked=strtotime((string)$user['sessions_revoked_before']);$auth=(int)($_SESSION['auth_time']??0);if(!$auth||($revoked&&$auth<$revoked)){$_SESSION=[];if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);return null;}}
    unset($user['sessions_revoked_before']);if(function_exists('app_shell_activate'))app_shell_activate($pdo,$user);return $user;
}
function require_user(PDO $pdo): array { $u=current_user($pdo); if(!$u){ header('Location: /login.php'); exit; } return $u; }
function require_api_user(PDO $pdo): array { $u=current_user($pdo); if(!$u)json_response(['ok'=>false,'error'=>['code'=>'AUTH_REQUIRED','message'=>'Sign in to Annotated.']],401); return $u; }
function require_api_mutation_auth(PDO $pdo): array { $u=require_api_user($pdo); if(!bearer_token()){ $sent=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''); if($sent===''||!hash_equals((string)($_SESSION['csrf']??''),$sent))json_response(['ok'=>false,'error'=>['code'=>'CSRF_FAILED']],419); } return $u; }
function post_auth_destination(PDO $pdo,int $userId): string {
    $after=(string)($_SESSION['after_login']??'');
    unset($_SESSION['after_login']);
    if($after!==''&&str_starts_with($after,'/')&&!str_starts_with($after,'//')&&!str_contains($after,"\r")&&!str_contains($after,"\n"))return $after;
    return onboarding_post_login_destination($pdo,$userId);
}
function users_exist(PDO $pdo): bool { return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0; }
function ulid_like(): string { return bin2hex(random_bytes(13)); }
function app_advisory_lock_name(string $namespace,string|int $key): string {
    $namespace=preg_replace('/[^a-z0-9_-]+/i','-',trim($namespace))?:'lock';
    return 'annotated:'.substr($namespace,0,16).':'.substr(hash('sha256',$namespace.':'.(string)$key),0,32);
}
function app_advisory_lock(PDO $pdo,string $namespace,string|int $key,int $timeoutSeconds=5): string {
    $name=app_advisory_lock_name($namespace,$key);$timeout=max(0,min(30,$timeoutSeconds));
    $q=$pdo->prepare('SELECT GET_LOCK(?,?)');$q->execute([$name,$timeout]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('This governed operation is already in progress. Please retry.');
    return $name;
}
function app_advisory_unlock(PDO $pdo,string $name): void {
    try{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}catch(Throwable $ignored){}
}
function app_with_advisory_lock(PDO $pdo,string $namespace,string|int $key,callable $callback,int $timeoutSeconds=5): mixed {
    $name=app_advisory_lock($pdo,$namespace,$key,$timeoutSeconds);try{return $callback();}finally{app_advisory_unlock($pdo,$name);}
}
function json_response(array $data, int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function api_headers(): void { $origin=$_SERVER['HTTP_ORIGIN']??''; if($origin && preg_match('#^chrome-extension://[a-p]{32}$#',$origin)){header('Access-Control-Allow-Origin: '.$origin);header('Vary: Origin');} header('Access-Control-Allow-Headers: Content-Type, Authorization');header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;} }
function canonicalize_url_legacy(string $url): string {
    $parts=parse_url(trim($url));if(!$parts||empty($parts['host']))return trim($url);
    $scheme=strtolower($parts['scheme']??'https');$host=strtolower($parts['host']);$path=$parts['path']??'/';$query=[];
    if(!empty($parts['query'])){parse_str($parts['query'],$query);foreach(array_keys($query) as $k){if(str_starts_with(strtolower($k),'utm_')||in_array(strtolower($k),['fbclid','gclid'],true))unset($query[$k]);}}
    return $scheme.'://'.$host.$path.($query?'?'.http_build_query($query):'');
}
function canonicalize_url(string $url): string {
    $raw=trim($url);$parts=parse_url($raw);if(!$parts||empty($parts['host']))return $raw;
    $scheme=strtolower((string)($parts['scheme']??'https'));if(!in_array($scheme,['http','https'],true))return $raw;
    $host=strtolower((string)$parts['host']);$path=(string)($parts['path']??'/');if($path==='')$path='/';$query=[];
    if(!empty($parts['query']))parse_str((string)$parts['query'],$query);
    foreach(array_keys($query) as $k){$lk=strtolower((string)$k);if(str_starts_with($lk,'utm_')||in_array($lk,['fbclid','gclid','mc_cid','mc_eid','si'],true))unset($query[$k]);}
    $youtubeId=null;$youtubeHosts=['youtube.com','www.youtube.com','m.youtube.com','music.youtube.com','youtu.be','www.youtu.be'];
    if(in_array($host,$youtubeHosts,true)){
        if(str_ends_with($host,'youtu.be'))$youtubeId=trim(explode('/',ltrim($path,'/'))[0]??'');
        elseif(isset($query['v']))$youtubeId=trim((string)$query['v']);
        elseif(preg_match('#^/(?:shorts|embed)/([^/?]+)#',$path,$m))$youtubeId=$m[1];
        $host='www.youtube.com';
        if($youtubeId!=='')return 'https://www.youtube.com/watch?v='.rawurlencode($youtubeId);
    }
    if($query)ksort($query,SORT_STRING);
    $port='';if(isset($parts['port'])){if(!(($scheme==='https'&&(int)$parts['port']===443)||($scheme==='http'&&(int)$parts['port']===80)))$port=':'.(int)$parts['port'];}
    return $scheme.'://'.$host.$port.$path.($query?'?'.http_build_query($query):'');
}
function source_identity_host_key(string $host): string {
    $host=strtolower(trim($host));return str_starts_with($host,'www.')?substr($host,4):$host;
}
function source_identity_url(string $pageUrl,?string $declaredCanonical=null): string {
    $page=trim($pageUrl);$candidate=trim((string)$declaredCanonical);if($candidate===''||!filter_var($candidate,FILTER_VALIDATE_URL))return $page;
    $pageHost=(string)(parse_url($page,PHP_URL_HOST)?:'');$candidateHost=(string)(parse_url($candidate,PHP_URL_HOST)?:'');
    $candidateScheme=strtolower((string)(parse_url($candidate,PHP_URL_SCHEME)?:''));
    if(!in_array($candidateScheme,['http','https'],true))return $page;
    if(source_identity_host_key($pageHost)!==source_identity_host_key($candidateHost))return $page;
    return $candidate;
}
function source_alias_remember(PDO $pdo,int $sourceId,string $url): void {
    $canonical=canonicalize_url($url);if($canonical==='')return;$hash=hash('sha256',$canonical);
    try{$q=$pdo->prepare('INSERT IGNORE INTO source_url_aliases(source_id,normalized_url,url_hash) VALUES(?,?,?)');$q->execute([$sourceId,$canonical,$hash]);$q=$pdo->prepare('UPDATE source_url_aliases SET last_seen_at=NOW() WHERE source_id=? AND url_hash=?');$q->execute([$sourceId,$hash]);}catch(PDOException $e){}
}
function source_resolve_url(PDO $pdo,string $url,bool $rememberAlias=true): ?array {
    $canonical=canonicalize_url($url);$hash=hash('sha256',$canonical);$select='SELECT id,public_id,title,canonical_url,status,current_version_id FROM sources WHERE id=?';
    try{$q=$pdo->prepare('SELECT source_id FROM source_url_aliases WHERE url_hash=? LIMIT 1');$q->execute([$hash]);$id=(int)($q->fetchColumn()?:0);if($id){$q=$pdo->prepare($select);$q->execute([$id]);$s=$q->fetch();if($s)return $s;}}catch(PDOException $e){}
    $q=$pdo->prepare('SELECT id,public_id,title,canonical_url,status,current_version_id FROM sources WHERE canonical_url_hash=? LIMIT 1');$q->execute([$hash]);$s=$q->fetch();if($s){if($rememberAlias)source_alias_remember($pdo,(int)$s['id'],$canonical);return $s;}
    $legacy=canonicalize_url_legacy($url);$legacyHashes=[hash('sha256',$legacy)];
    if(preg_match('#^https://www\.youtube\.com/watch\?v=([^&]+)$#',$canonical,$m)){
        $vid=rawurldecode($m[1]);foreach(['https://www.youtube.com/watch?v='.$vid,'https://youtube.com/watch?v='.$vid,'https://m.youtube.com/watch?v='.$vid,'https://youtu.be/'.$vid] as $candidate)$legacyHashes[]=hash('sha256',canonicalize_url_legacy($candidate));
    }
    foreach(array_unique($legacyHashes) as $legacyHash){$q=$pdo->prepare('SELECT id,public_id,title,canonical_url,status,current_version_id FROM sources WHERE canonical_url_hash=? LIMIT 1');$q->execute([$legacyHash]);$s=$q->fetch();if($s){if($rememberAlias)source_alias_remember($pdo,(int)$s['id'],$canonical);return $s;}}
    $host=(string)(parse_url($canonical,PHP_URL_HOST)?:'');if($host!==''){
        $hostKey=source_identity_host_key($host);$q=$pdo->prepare('SELECT id,public_id,title,canonical_url,status,current_version_id,domain FROM sources WHERE domain IN (?,?,?) ORDER BY id DESC LIMIT 250');$q->execute([$hostKey,'www.'.$hostKey,$host]);
        foreach($q->fetchAll() as $candidate){if(canonicalize_url((string)$candidate['canonical_url'])===$canonical){unset($candidate['domain']);if($rememberAlias)source_alias_remember($pdo,(int)$candidate['id'],$canonical);return $candidate;}}
    }
    return null;
}
function source_type_from_url(string $url, ?string $mediaType=null): string { $host=strtolower((string)parse_url($url,PHP_URL_HOST)); if(str_contains($host,'youtube.com')||str_contains($host,'youtu.be'))return 'youtube'; if($mediaType==='video')return 'video'; if($mediaType==='audio')return 'audio'; return 'webpage'; }
function ensure_source(PDO $pdo,string $url,?string $title=null,?string $mediaType=null): array {
    $existing=source_resolve_url($pdo,$url,true);if($existing)return $existing;
    $canonical=canonicalize_url($url);$hash=hash('sha256',$canonical);$host=(string)(parse_url($canonical,PHP_URL_HOST)?:'');
    try{$q=$pdo->prepare('INSERT INTO sources(public_id,source_type,canonical_url,canonical_url_hash,domain,title) VALUES(?,?,?,?,?,?)');$q->execute([ulid_like(),source_type_from_url($canonical,$mediaType),$canonical,$hash,$host,$title]);$id=(int)$pdo->lastInsertId();}
    catch(PDOException $e){if((string)$e->getCode()!=='23000')throw $e;$q=$pdo->prepare('SELECT id FROM sources WHERE canonical_url_hash=? FOR UPDATE');$q->execute([$hash]);$id=(int)($q->fetchColumn()?:0);if(!$id)throw $e;}
    source_alias_remember($pdo,$id,$canonical);$q=$pdo->prepare('SELECT id,public_id,title,canonical_url,status,current_version_id FROM sources WHERE id=?');$q->execute([$id]);return $q->fetch();
}
function save_data_url_image(string $dataUrl,string $prefix,array $config): ?string {
    if($dataUrl==='')return null;
    if(!preg_match('#^data:image/(png|jpeg);base64,(.+)$#s',$dataUrl,$m))throw new InvalidArgumentException('Invalid screenshot format.');
    $bytes=base64_decode($m[2],true);if($bytes===false||strlen($bytes)>8*1024*1024)throw new InvalidArgumentException('Screenshot is too large.');
    return private_storage_write($config,$bytes,$prefix,$m[1]==='jpeg'?'jpg':'png');
}
function is_blocked(PDO $pdo,int $a,int $b): bool { if($a===$b)return false;try{$q=$pdo->prepare('SELECT 1 FROM blocks WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?) LIMIT 1');$q->execute([$a,$b,$b,$a]);return (bool)$q->fetchColumn();}catch(PDOException $e){return false;} }
function post_login_destination(): string { $next=$_SESSION['after_login']??'/'; unset($_SESSION['after_login']); return is_string($next)&&str_starts_with($next,'/')&&!str_starts_with($next,'//')?$next:'/'; }

function save_data_url_audio(string $dataUrl,array $config): ?string {
    if ($dataUrl==='') return null;
    if (!preg_match('#^data:audio/(webm|ogg|mp4|mpeg|wav|x-wav);base64,(.+)$#s',$dataUrl,$m)) throw new InvalidArgumentException('Invalid audio commentary format.');
    $bytes=base64_decode($m[2],true);
    if($bytes===false||strlen($bytes)>16*1024*1024) throw new InvalidArgumentException('Audio commentary is too large.');
    $ext=match($m[1]){'webm'=>'webm','ogg'=>'ogg','mp4'=>'m4a','mpeg'=>'mp3','wav','x-wav'=>'wav',default=>'bin'};
    return private_storage_write($config,$bytes,'commentary',$ext);
}
function notify_user(PDO $pdo,int $userId,?int $actorUserId,string $type,?string $objectType,?string $objectPublicId,?string $body,array $options=[]): void {
    if(function_exists('notification_create')){notification_create($pdo,$userId,$actorUserId,$type,$objectType,$objectPublicId,$body,$options);return;}
    try{
        $prefColumn = match(true) {
            str_starts_with($type,'source_') => 'notify_sources',
            str_starts_with($type,'research_') || str_starts_with($type,'project_') => 'notify_research',
            str_starts_with($type,'live_') => 'notify_live',
            default => 'notify_social',
        };
        $q=$pdo->prepare("SELECT $prefColumn FROM user_preferences WHERE user_id=?");$q->execute([$userId]);$pref=$q->fetchColumn();
        if($pref!==false && (int)$pref===0)return;
    }catch(PDOException $e){}
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

function require_admin(PDO $pdo): array {
    $u=require_user($pdo);
    if(($u['role']??'')!=='admin'){http_response_code(403);exit('Administrator access required.');}
    return $u;
}
function user_plan(PDO $pdo,array $user): string {
    if(($user['role']??'')==='admin') return 'admin';
    try{
        if(function_exists('subscriptions_ready')&&function_exists('subscription_user_account')&&subscriptions_ready($pdo)){
            $account=subscription_user_account($pdo,(int)$user['id'],false);
            if($account&&(($account['status']??'active')!=='active'||in_array((string)($account['subscription_status']??''),['paused','canceled'],true)))return 'free';
        }
        $q=$pdo->prepare('SELECT plan_tier,pro_expires_at FROM users WHERE id=?');$q->execute([$user['id']]);$r=$q->fetch();
        if(!$r)return 'free';
        if(($r['plan_tier']??'free')==='pro' && (empty($r['pro_expires_at']) || strtotime((string)$r['pro_expires_at'])>time())) return 'pro';
    }catch(PDOException $e){}
    return 'free';
}
function user_is_pro(PDO $pdo,array $user): bool { return in_array(user_plan($pdo,$user),['pro','admin'],true); }
function project_access(PDO $pdo,int $userId,string $publicId): ?array {
    $q=$pdo->prepare("SELECT DISTINCT rp.*,CASE WHEN rp.owner_user_id=? THEN 'owner' ELSE COALESCE(tm.role,'viewer') END access_role FROM research_projects rp LEFT JOIN team_members tm ON tm.team_id=rp.team_id AND tm.user_id=? WHERE rp.public_id=? AND (rp.owner_user_id=? OR tm.user_id=?) LIMIT 1");
    $q->execute([$userId,$userId,$publicId,$userId,$userId]);return $q->fetch()?:null;
}
function public_http_url_resolve(string $url): ?array {
    $parts=parse_url($url);$scheme=strtolower((string)($parts['scheme']??''));$host=strtolower((string)($parts['host']??''));
    if(!$parts||!in_array($scheme,['http','https'],true)||$host===''||isset($parts['user'])||isset($parts['pass']))return null;
    if($host==='localhost'||str_ends_with($host,'.localhost')||str_ends_with($host,'.local'))return null;
    $ips=[];if(filter_var($host,FILTER_VALIDATE_IP))$ips[]=$host;else{$resolved=gethostbynamel($host);if(is_array($resolved))$ips=array_values(array_unique($resolved));}
    if(!$ips)return null;
    foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;
    $port=(int)($parts['port']??($scheme==='https'?443:80));if($port<1||$port>65535)return null;
    return ['parts'=>$parts,'scheme'=>$scheme,'host'=>$host,'port'=>$port,'ips'=>$ips];
}
function public_http_url_allowed(string $url): bool {
    return public_http_url_resolve($url)!==null;
}
function fetch_public_url(string $url,int $maxBytes=3145728): array {
    $current=$url;
    for($hop=0;$hop<4;$hop++){
        $resolved=public_http_url_resolve($current);if(!$resolved)throw new RuntimeException('Source URL is not a permitted public HTTP(S) destination.');
        $parts=$resolved['parts'];$host=(string)$resolved['host'];$port=(int)$resolved['port'];$pin=(string)$resolved['ips'][0];
        $ch=curl_init($current);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_USERAGENT=>'AnnotatedSourceMonitor/1.0',CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml;q=0.9,text/plain;q=0.8'],CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$host.':'.$port.':'.$pin]]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$headerSize=(int)curl_getinfo($ch,CURLINFO_HEADER_SIZE);$contentType=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$err=curl_error($ch);curl_close($ch);
        if($raw===false)throw new RuntimeException('Source request failed'.($err?': '.$err:''));
        $headers=substr($raw,0,$headerSize);$body=substr($raw,$headerSize);
        if(in_array($status,[301,302,303,307,308],true)&&preg_match('/^Location:\s*(.+)$/mi',$headers,$m)){
            $loc=trim($m[1]);if(!preg_match('#^https?://#i',$loc)){
                $authority=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.(int)$parts['port']:'');
                if(str_starts_with($loc,'/'))$loc=$authority.$loc;else{$dir=dirname((string)($parts['path']??'/'));$dir=$dir==='.'?'':'/'.trim($dir,'/');$loc=$authority.$dir.'/'.ltrim($loc,'/');}
            }
            $current=$loc;continue;
        }
        if(strlen($body)>$maxBytes)$body=substr($body,0,$maxBytes);
        return ['url'=>$current,'status'=>$status,'content_type'=>$contentType,'body'=>$body];
    }
    throw new RuntimeException('Too many source redirects.');
}
function html_to_research_text(string $html): array {
    $title='';$text='';
    if(trim($html)==='')return ['title'=>'','text'=>''];
    $prev=libxml_use_internal_errors(true);$dom=new DOMDocument();@$dom->loadHTML($html,LIBXML_NOERROR|LIBXML_NOWARNING);libxml_clear_errors();libxml_use_internal_errors($prev);
    $nodes=$dom->getElementsByTagName('title');if($nodes->length)$title=trim((string)$nodes->item(0)?->textContent);
    foreach(['script','style','noscript','svg'] as $tag){$list=$dom->getElementsByTagName($tag);for($i=$list->length-1;$i>=0;$i--){$n=$list->item($i);if($n&&$n->parentNode)$n->parentNode->removeChild($n);}}
    $text=trim((string)preg_replace('/\s+/u',' ',$dom->textContent??''));
    return ['title'=>$title,'text'=>$text];
}
