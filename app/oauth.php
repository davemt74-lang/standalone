<?php
declare(strict_types=1);
function oauth_config(array $config,string $provider): array {
    $p=$config['oauth'][$provider]??[];
    foreach(['client_id','client_secret','redirect_uri'] as $k) if(empty($p[$k])) throw new RuntimeException(ucfirst($provider).' OAuth is not configured.');
    $parts=parse_url((string)$p['redirect_uri']);
    if(!$parts||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host'])) throw new RuntimeException('OAuth redirect URI must use HTTPS.');
    return $p;
}
function oauth_http(string $url,array $options=[]): array {
    if(!str_starts_with(strtolower($url),'https://')) throw new RuntimeException('OAuth transport must use HTTPS.');
    $ch=curl_init($url);$headers=$options['headers']??[];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    if(isset($options['post'])){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($options['post']));$headers[]='Content-Type: application/x-www-form-urlencoded';}
    if($headers)curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($raw===false||$status<200||$status>=300) throw new RuntimeException('OAuth provider request failed'.($err?' (transport error)':''));
    $data=json_decode($raw,true);if(!is_array($data))throw new RuntimeException('Invalid OAuth provider response.');return $data;
}
function unique_username(PDO $pdo,string $base): string {
    $base=preg_replace('/[^A-Za-z0-9_]/','_',strtolower($base))?:'user';$base=substr(trim($base,'_'),0,24)?:'user';$candidate=$base;$n=0;
    while(true){$s=$pdo->prepare('SELECT 1 FROM users WHERE username=?');$s->execute([$candidate]);if(!$s->fetchColumn())return $candidate;$n++;$candidate=substr($base,0,20).'_'.$n;}
}
function oauth_finish_login(PDO $pdo,string $provider,string $providerId,?string $email,string $displayName,string $usernameHint,bool $emailVerified,?int $linkUserId=null): int {
    if($providerId==='')throw new RuntimeException('OAuth provider returned no account identifier.');$email=$email?strtolower(trim($email)):null;
    $q=$pdo->prepare('SELECT user_id FROM user_identities WHERE provider=? AND provider_user_id=?');$q->execute([$provider,$providerId]);$identityUser=(int)($q->fetchColumn()?:0);
    if($linkUserId){
        $q=$pdo->prepare('SELECT id,email FROM users WHERE id=? AND status="active"');$q->execute([$linkUserId]);$linkUser=$q->fetch();if(!$linkUser)throw new RuntimeException('The account-linking session is no longer valid.');
        if($identityUser&&$identityUser!==$linkUserId)throw new RuntimeException('That provider account is already connected to another Annotated account.');
        if($emailVerified&&$email){$q=$pdo->prepare('SELECT id FROM users WHERE email=? AND id<>?');$q->execute([$email,$linkUserId]);if($q->fetchColumn())throw new RuntimeException('That verified provider email belongs to another Annotated account.');}
        if(!$identityUser){$q=$pdo->prepare('INSERT INTO user_identities(user_id,provider,provider_user_id,provider_email) VALUES(?,?,?,?)');$q->execute([$linkUserId,$provider,$providerId,$email]);}
        if($emailVerified&&$email&&strtolower((string)($linkUser['email']??''))===$email)$pdo->prepare('UPDATE users SET email_verified_at=COALESCE(email_verified_at,NOW()) WHERE id=?')->execute([$linkUserId]);
        return $linkUserId;
    }
    if($identityUser)return $identityUser;
    if($emailVerified&&$email){
        $q=$pdo->prepare('SELECT id,email_verified_at FROM users WHERE email=? AND status="active"');$q->execute([$email]);$existing=$q->fetch();
        if($existing){
            if(empty($existing['email_verified_at']))throw new RuntimeException('An Annotated account already uses this email but has not verified it. Sign in with its password first, then connect this provider from Connected accounts.');
            $uid=(int)$existing['id'];$q=$pdo->prepare('INSERT INTO user_identities(user_id,provider,provider_user_id,provider_email) VALUES(?,?,?,?)');$q->execute([$uid,$provider,$providerId,$email]);return $uid;
        }
    }
    $username=unique_username($pdo,$usernameHint?:($email?strstr($email,'@',true):$provider.'_user'));$public=ulid_like();$pdo->beginTransaction();
    try{$q=$pdo->prepare('INSERT INTO users(public_id,username,display_name,email,email_verified_at,password_hash) VALUES(?,?,?,?,?,NULL)');$q->execute([$public,$username,$displayName?:$username,$emailVerified?$email:null,$emailVerified?date('Y-m-d H:i:s'):null]);$uid=(int)$pdo->lastInsertId();$q=$pdo->prepare('INSERT INTO user_identities(user_id,provider,provider_user_id,provider_email) VALUES(?,?,?,?)');$q->execute([$uid,$provider,$providerId,$email]);$pdo->commit();if(function_exists('subscriptions_ready')&&subscriptions_ready($pdo))subscription_ensure_user_account($pdo,$uid);if(function_exists('onboarding_ensure'))onboarding_ensure($pdo,$uid);return $uid;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
