<?php
declare(strict_types=1);

const ANNOTATED_VP3_PLUGIN_CONTRACT='annotated-vp3-plugin-v1';
const ANNOTATED_VP3_PLUGIN_VERSION='1.0.0';
const ANNOTATED_VP3_CONTEXT_CONTRACT='annotated-vp3-context-v1';
const ANNOTATED_VP3_REQUEST_WINDOW=300;

function vp3_host_env(string $key,string $fallback=''): string {
    $v=getenv($key);return is_string($v)&&trim($v)!==''?trim($v):$fallback;
}
function vp3_host_config(): array {
    global $config;
    $cfg=is_array($config['vp3']??null)?$config['vp3']:[];
    $key=preg_replace('/[^a-z0-9._-]+/','',strtolower(vp3_host_env('ANNOTATED_VP3_HOST_KEY',(string)($cfg['host_key']??'vp3'))))?:'vp3';
    $base=rtrim(vp3_host_env('ANNOTATED_VP3_BASE_URL',(string)($cfg['base_url']??'')),'/');
    $secret=vp3_host_env('ANNOTATED_VP3_SHARED_SECRET',(string)($cfg['shared_secret']??''));
    $enabledRaw=vp3_host_env('ANNOTATED_VP3_ENABLED',array_key_exists('enabled',$cfg)?(!empty($cfg['enabled'])?'1':'0'):'');
    $enabled=$enabledRaw===''?($base!==''&&strlen($secret)>=32):in_array(strtolower($enabledRaw),['1','true','yes','on'],true);
    return ['enabled'=>$enabled,'host_key'=>$key,'base_url'=>$base,'shared_secret'=>$secret,'configured'=>$enabled&&$base!==''&&strlen($secret)>=32];
}
function vp3_host_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'vp3_host_identities')&&installer_table_exists($pdo,'vp3_host_nonces')&&installer_table_exists($pdo,'vp3_host_audit');}catch(Throwable $e){return false;}
}
function vp3_host_configured(): bool {return !empty(vp3_host_config()['configured']);}
function vp3_host_b64e(string $raw): string {return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');}
function vp3_host_b64d(string $raw): string {
    if($raw===''||preg_match('/^[A-Za-z0-9_-]+$/',$raw)!==1)throw new InvalidArgumentException('Malformed VP3 token encoding.');
    $pad=(4-strlen($raw)%4)%4;$decoded=base64_decode(strtr($raw,'-_','+/').str_repeat('=',$pad),true);
    if($decoded===false)throw new InvalidArgumentException('Malformed VP3 token encoding.');return $decoded;
}
function vp3_host_token_sign(array $payload,?string $secret=null): string {
    $secret??=(string)vp3_host_config()['shared_secret'];if(strlen($secret)<32)throw new RuntimeException('VP3 shared secret is not configured.');
    $payload['v']=1;$encoded=vp3_host_b64e(json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    return $encoded.'.'.vp3_host_b64e(hash_hmac('sha256',$encoded,$secret,true));
}
function vp3_host_token_verify(string $token,?string $secret=null): array {
    $secret??=(string)vp3_host_config()['shared_secret'];if(strlen($secret)<32)throw new RuntimeException('VP3 shared secret is not configured.');
    $parts=explode('.',$token);if(count($parts)!==2)throw new InvalidArgumentException('Malformed VP3 launch token.');
    [$encoded,$signature]=$parts;$expected=vp3_host_b64e(hash_hmac('sha256',$encoded,$secret,true));if(!hash_equals($expected,$signature))throw new RuntimeException('VP3 launch signature is invalid.');
    $payload=json_decode(vp3_host_b64d($encoded),true,64,JSON_THROW_ON_ERROR);if(!is_array($payload))throw new RuntimeException('VP3 launch payload is invalid.');
    $cfg=vp3_host_config();$now=time();$iat=(int)($payload['iat']??0);$exp=(int)($payload['exp']??0);
    if((int)($payload['v']??0)!==1||($payload['iss']??'')!==$cfg['host_key']||($payload['aud']??'')!=='annotated'||($payload['plugin']??'')!=='annotated')throw new RuntimeException('VP3 launch claims are invalid.');
    if($iat<1||$iat>$now+60||$exp<$now||$exp>$now+600||$exp<=$iat)throw new RuntimeException('VP3 launch token is expired or outside its allowed window.');
    $sub=trim((string)($payload['sub']??''));$nonce=trim((string)($payload['nonce']??''));if($sub===''||strlen($sub)>190||preg_match('/^[A-Za-z0-9._:@-]+$/',$sub)!==1)throw new RuntimeException('VP3 launch identity is invalid.');
    if(strlen($nonce)<16||strlen($nonce)>160||preg_match('/^[A-Za-z0-9_-]+$/',$nonce)!==1)throw new RuntimeException('VP3 launch nonce is invalid.');
    return $payload;
}
function vp3_host_consume_nonce(PDO $pdo,string $hostKey,string $purpose,string $nonce,int $expiresAt): void {
    if(!in_array($purpose,['launch','api'],true))throw new InvalidArgumentException('Invalid VP3 nonce purpose.');
    $hash=hash('sha256',$nonce);try{$pdo->prepare('INSERT INTO vp3_host_nonces(host_key,purpose,nonce_hash,expires_at) VALUES(?,?,?,?)')->execute([$hostKey,$purpose,$hash,gmdate('Y-m-d H:i:s',$expiresAt)]);}
    catch(PDOException $e){if((string)$e->getCode()==='23000')throw new RuntimeException('VP3 request replay was rejected.');throw $e;}
    try{$pdo->exec("DELETE FROM vp3_host_nonces WHERE expires_at<DATE_SUB(NOW(),INTERVAL 1 DAY)");}catch(Throwable $e){}
}
function vp3_host_audit(PDO $pdo,?int $userId,string $hostUserId,string $event,array $detail=[],?string $requestId=null): void {
    if(!vp3_host_ready($pdo))return;$cfg=vp3_host_config();$pdo->prepare('INSERT INTO vp3_host_audit(public_id,user_id,host_key,host_user_id,event_type,request_id,detail_json) VALUES(?,?,?,?,?,?,?)')->execute([ulid_like(),$userId,$cfg['host_key'],$hostUserId!==''?$hostUserId:null,mb_substr($event,0,80),$requestId!==null?mb_substr($requestId,0,120):null,$detail?json_encode($detail,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null]);
}
function vp3_host_identity_for_host(PDO $pdo,string $hostUserId): ?array {
    if(!vp3_host_ready($pdo))return null;$cfg=vp3_host_config();$q=$pdo->prepare('SELECT h.*,u.public_id user_public_id,u.username,u.display_name,u.email,u.role,u.status,u.live_presence_mode,u.profile_image_url FROM vp3_host_identities h JOIN users u ON u.id=h.user_id WHERE h.host_key=? AND h.host_user_id=? AND u.status="active" LIMIT 1');$q->execute([$cfg['host_key'],$hostUserId]);return $q->fetch()?:null;
}
function vp3_host_identity_for_user(PDO $pdo,int $userId): ?array {
    if($userId<1||!vp3_host_ready($pdo))return null;$cfg=vp3_host_config();$q=$pdo->prepare('SELECT * FROM vp3_host_identities WHERE user_id=? AND host_key=? LIMIT 1');$q->execute([$userId,$cfg['host_key']]);return $q->fetch()?:null;
}
function vp3_host_unique_username(PDO $pdo,string $hostUserId,string $hint=''): string {
    $base=strtolower(trim($hint));$base=preg_replace('/[^a-z0-9._-]+/','-',$base)??'';$base=trim($base,'-._');if($base==='')$base='vp3-'.$hostUserId;$base=substr($base,0,42);if($base==='')$base='vp3-user';
    $candidate=$base;$n=1;$q=$pdo->prepare('SELECT 1 FROM users WHERE username=? LIMIT 1');while(true){$q->execute([$candidate]);if(!$q->fetchColumn())return $candidate;$n++;$candidate=substr($base,0,44-strlen((string)$n)).'-'.$n;}
}
function vp3_host_provision_user(PDO $pdo,array $claims): array {
    $hostUserId=(string)$claims['sub'];if($existing=vp3_host_identity_for_host($pdo,$hostUserId)){
        $pdo->prepare('UPDATE vp3_host_identities SET host_email=?,host_display_name=?,host_metadata_json=?,last_seen_at=NOW(),updated_at=NOW() WHERE id=?')->execute([filter_var((string)($claims['email']??''),FILTER_VALIDATE_EMAIL)?strtolower((string)$claims['email']):null,mb_substr(trim((string)($claims['name']??'')),0,190),json_encode(['roles'=>array_slice((array)($claims['roles']??[]),0,12)],JSON_UNESCAPED_SLASHES),(int)$existing['id']]);
        return vp3_host_identity_for_host($pdo,$hostUserId)??$existing;
    }
    $display=mb_substr(trim((string)($claims['name']??'')),0,100);if($display==='')$display='VP3 User';
    $email=filter_var((string)($claims['email']??''),FILTER_VALIDATE_EMAIL)?strtolower((string)$claims['email']):null;
    if($email!==null){$q=$pdo->prepare('SELECT 1 FROM users WHERE email=? LIMIT 1');$q->execute([$email]);if($q->fetchColumn())$email=null;}
    $username=vp3_host_unique_username($pdo,$hostUserId,(string)($claims['username']??''));
    $pdo->beginTransaction();try{
        $pdo->prepare("INSERT INTO users(public_id,username,display_name,email,password_hash,role,status,live_presence_mode) VALUES(?,?,?,?,NULL,'user','active','cloaked')")->execute([ulid_like(),$username,$display,$email]);$userId=(int)$pdo->lastInsertId();
        $cfg=vp3_host_config();$pdo->prepare('INSERT INTO vp3_host_identities(user_id,host_key,host_user_id,host_email,host_display_name,host_metadata_json) VALUES(?,?,?,?,?,?)')->execute([$userId,$cfg['host_key'],$hostUserId,$email,$display,json_encode(['roles'=>array_slice((array)($claims['roles']??[]),0,12)],JSON_UNESCAPED_SLASHES)]);
        $pdo->prepare('INSERT IGNORE INTO user_preferences(user_id) VALUES(?)')->execute([$userId]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $identity=vp3_host_identity_for_host($pdo,$hostUserId);if(!$identity)throw new RuntimeException('VP3 identity could not be provisioned.');return $identity;
}
function vp3_host_destination(mixed $value): string {
    $path=trim((string)$value);if($path===''||!str_starts_with($path,'/')||str_starts_with($path,'//')||str_contains($path,"")||str_contains($path,"
"))return '/home.php';
    $allowed=['/home.php','/research.php','/research-programs.php','/research-intelligence-portfolios.php','/research-intelligence-command-center.php','/research-publications.php','/research-reviews.php','/notifications.php'];
    foreach($allowed as $prefix)if($path===$prefix||str_starts_with($path,$prefix.'?')||str_starts_with($path,$prefix.'#'))return $path;return '/home.php';
}
function vp3_host_launch(PDO $pdo,string $token): array {
    if(!vp3_host_configured()||!vp3_host_ready($pdo))throw new RuntimeException('VP3 plugin hosting is not configured.');
    $claims=vp3_host_token_verify($token);$cfg=vp3_host_config();vp3_host_consume_nonce($pdo,$cfg['host_key'],'launch',(string)$claims['nonce'],(int)$claims['exp']);$identity=vp3_host_provision_user($pdo,$claims);
    if(PHP_SAPI!=='cli'){session_regenerate_id(true);$_SESSION['user_id']=(int)$identity['user_id'];$_SESSION['rotated_at']=time();$_SESSION['last_activity']=time();$_SESSION['auth_time']=time();$_SESSION['vp3_hosted']=true;}
    vp3_host_audit($pdo,(int)$identity['user_id'],(string)$claims['sub'],'launch',['destination'=>vp3_host_destination($claims['path']??'/home.php')],(string)($claims['request_id']??''));
    return ['identity'=>$identity,'destination'=>vp3_host_destination($claims['path']??'/home.php')];
}
function vp3_host_request_canonical(string $method,string $path,string $timestamp,string $nonce,string $hostUserId,string $body): string {
    return strtoupper($method)."\n".$path."\n".$timestamp."\n".$nonce."\n".$hostUserId."\n".hash('sha256',$body);
}
function vp3_host_verify_request(PDO $pdo,string $body=''): array {
    if(!vp3_host_configured()||!vp3_host_ready($pdo))throw new RuntimeException('VP3 plugin hosting is not configured.');
    $cfg=vp3_host_config();$hostKey=trim((string)($_SERVER['HTTP_X_VP3_KEY']??''));$tsText=trim((string)($_SERVER['HTTP_X_VP3_TIMESTAMP']??''));$nonce=trim((string)($_SERVER['HTTP_X_VP3_NONCE']??''));$hostUser=trim((string)($_SERVER['HTTP_X_VP3_USER']??''));$signature=trim((string)($_SERVER['HTTP_X_VP3_SIGNATURE']??''));
    if($hostKey!==$cfg['host_key']||!ctype_digit($tsText)||$nonce===''||$hostUser===''||$signature==='')throw new RuntimeException('VP3 signed request headers are incomplete.');
    $ts=(int)$tsText;if(abs(time()-$ts)>ANNOTATED_VP3_REQUEST_WINDOW)throw new RuntimeException('VP3 signed request is stale.');
    if(strlen($nonce)<16||strlen($nonce)>160||preg_match('/^[A-Za-z0-9_-]+$/',$nonce)!==1)throw new RuntimeException('VP3 request nonce is invalid.');
    if(strlen($hostUser)>190||preg_match('/^[A-Za-z0-9._:@-]+$/',$hostUser)!==1)throw new RuntimeException('VP3 request identity is invalid.');
    $path=(string)(parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/');$canonical=vp3_host_request_canonical((string)($_SERVER['REQUEST_METHOD']??'GET'),$path,$tsText,$nonce,$hostUser,$body);$expected=hash_hmac('sha256',$canonical,(string)$cfg['shared_secret']);
    if(!hash_equals($expected,strtolower($signature)))throw new RuntimeException('VP3 signed request signature is invalid.');
    vp3_host_consume_nonce($pdo,$cfg['host_key'],'api',$nonce,$ts+ANNOTATED_VP3_REQUEST_WINDOW);$identity=vp3_host_identity_for_host($pdo,$hostUser);if(!$identity)throw new RuntimeException('VP3 identity has not launched Annotated yet.');
    return $identity;
}
function vp3_host_local_user(array $identity): array {
    return ['id'=>(int)$identity['user_id'],'public_id'=>(string)$identity['user_public_id'],'username'=>(string)$identity['username'],'display_name'=>(string)$identity['display_name'],'email'=>$identity['email'],'role'=>(string)$identity['role'],'live_presence_mode'=>(string)$identity['live_presence_mode'],'profile_image_url'=>$identity['profile_image_url']??null];
}
function vp3_host_context(PDO $pdo,array $viewer): array {
    $agents=research_agent_list($pdo,$viewer,20);$programs=[];foreach($agents as $agent){try{foreach(research_program_list($pdo,$viewer,(string)$agent['public_id'],20) as $p){$programs[]=['public_id'=>(string)$p['public_id'],'title'=>(string)$p['title'],'status'=>(string)$p['status'],'priority'=>(string)$p['priority'],'agent_public_id'=>(string)$agent['public_id'],'agent_name'=>(string)$agent['name'],'next_run_at'=>$p['next_run_at']??null,'last_run_at'=>$p['last_run_at']??null];if(count($programs)>=30)break 2;}}catch(Throwable $e){}}
    $portfolios=research_intelligence_portfolio_list($pdo,$viewer,20,false);$portfolioRows=[];foreach($portfolios as $p)$portfolioRows[]=['public_id'=>(string)$p['public_id'],'title'=>(string)$p['title'],'program_count'=>(int)$p['program_count'],'briefing_count'=>(int)$p['briefing_count'],'latest_briefing_at'=>$p['latest_briefing_at']??null,'team_name'=>$p['team_name']??null];
    $center=['needs_attention'=>[],'new_since_last_briefing'=>[],'decisions_awaiting_follow_through'=>[],'emerging_opportunities'=>[],'briefings_awaiting_review'=>[]];if(function_exists('research_intelligence_organization_command_center')&&research_intelligence_portfolio_operations_ready($pdo)){try{$raw=research_intelligence_organization_command_center($pdo,$viewer);foreach(array_keys($center) as $key)$center[$key]=array_slice((array)($raw[$key]??[]),0,12);}catch(Throwable $e){}}
    return ['contract'=>ANNOTATED_VP3_CONTEXT_CONTRACT,'plugin'=>'annotated','version'=>ANNOTATED_VP3_PLUGIN_VERSION,'data_only'=>true,'instructions_authority'=>false,'generated_at'=>gmdate('c'),'summary'=>['research_agents'=>count($agents),'programs'=>count($programs),'portfolios'=>count($portfolioRows),'needs_attention'=>count($center['needs_attention']),'decisions_awaiting_follow_through'=>count($center['decisions_awaiting_follow_through']),'briefings_awaiting_review'=>count($center['briefings_awaiting_review'])],'agents'=>array_map(static fn($a)=>['public_id'=>(string)$a['public_id'],'name'=>(string)$a['name'],'status'=>(string)$a['status'],'monitoring_cadence'=>(string)$a['monitoring_cadence'],'team_name'=>$a['team_name']??null],$agents),'programs'=>$programs,'portfolios'=>$portfolioRows,'command_center'=>$center];
}
function vp3_host_base_url(): string {return (string)vp3_host_config()['base_url'];}
function vp3_host_return_url(): string {return vp3_host_base_url()!==''?vp3_host_base_url().'/home.php':'';}
function vp3_host_manifest(): array {
    return ['contract'=>ANNOTATED_VP3_PLUGIN_CONTRACT,'plugin_key'=>'annotated','label'=>'Annotated','version'=>ANNOTATED_VP3_PLUGIN_VERSION,'annotated_release'=>defined('ANNOTATED_RELEASE_VERSION')?ANNOTATED_RELEASE_VERSION:'','hosted_mode_supported'=>true,'identity_authority'=>'vp3','data_authority'=>'annotated','launch_path'=>'/vp3/launch.php','context_path'=>'/api/vp3/context.php','health_path'=>'/api/vp3/plugin-manifest.php','capabilities'=>['research.capture','research.agents','research.programs','research.claims','research.documents','research.review','research.publishing','research.portfolios','research.executive_briefing','research.decision_followthrough','cognitive.context.read'],'configured'=>vp3_host_configured()];
}
