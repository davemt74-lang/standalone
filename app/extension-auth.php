<?php
declare(strict_types=1);

function extension_allowed_ids(array $config): array {
    $ids=$config['extension']['allowed_ids']??[];
    if(!is_array($ids))return [];
    return array_values(array_unique(array_filter(array_map(static fn($v)=>strtolower(trim((string)$v)),$ids),static fn($v)=>preg_match('/^[a-p]{32}$/',$v)===1)));
}
function extension_id_allowed(string $id,array $config): bool {
    $id=strtolower(trim($id));
    if(!preg_match('/^[a-p]{32}$/',$id))return false;
    $configured=extension_allowed_ids($config);
    return !$configured||in_array($id,$configured,true);
}
function extension_redirect_id(string $redirect): ?string {
    $p=parse_url($redirect);
    if(!$p||strtolower((string)($p['scheme']??''))!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['query'])||isset($p['fragment']))return null;
    $host=strtolower((string)($p['host']??''));$path=(string)($p['path']??'');
    if($path!=='/annotated'||!preg_match('/^([a-p]{32})\.chromiumapp\.org$/',$host,$m))return null;
    return strtolower($m[1]);
}
function extension_redirect_allowed(string $redirect,array $config): bool {
    $id=extension_redirect_id($redirect);
    return $id!==null&&extension_id_allowed($id,$config);
}
function extension_origin_allowed(string $origin,array $config): bool {
    if(!preg_match('#^chrome-extension://([a-p]{32})$#i',trim($origin),$m))return false;
    return extension_id_allowed(strtolower($m[1]),$config);
}
function extension_api_headers(array $config): void {
    $origin=(string)($_SERVER['HTTP_ORIGIN']??'');$allowed=$origin!==''&&extension_origin_allowed($origin,$config);
    if($allowed){header('Access-Control-Allow-Origin: '.$origin);header('Vary: Origin');}
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    if($_SERVER['REQUEST_METHOD']==='OPTIONS'){if($origin!==''&&!$allowed){http_response_code(403);exit;}http_response_code(204);exit;}
}
function enforce_extension_bearer_session(PDO $pdo): void {
    $token=bearer_token();if(!$token)return;
    try{$q=$pdo->prepare('SELECT 1 FROM extension_sessions WHERE token_hash=? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>NOW())');$q->execute([hash('sha256',$token)]);if(!$q->fetchColumn())json_response(['ok'=>false,'error'=>['code'=>'SESSION_EXPIRED','message'=>'Reconnect the Annotated extension.']],401);}catch(PDOException $e){json_response(['ok'=>false,'error'=>['code'=>'UPGRADE_REQUIRED','message'=>'Run the Annotated database upgrade before reconnecting the extension.']],503);}
}
