<?php
declare(strict_types=1);

final class RateLimitExceeded extends RuntimeException {
    public function __construct(public readonly int $retryAfter, string $message='Too many requests.') { parent::__construct($message); }
}

function rate_limit_client_ip(): string {
    $ip=trim((string)($_SERVER['REMOTE_ADDR']??''));
    return filter_var($ip,FILTER_VALIDATE_IP)?$ip:'unknown';
}
function rate_limit_subject_user(array $user): string { return 'user:'.(int)($user['id']??0); }
function rate_limit_subject_email(string $email): string { return 'email:'.strtolower(trim($email)); }
function rate_limit_subject_ip(): string { return 'ip:'.rate_limit_client_ip(); }
function rate_limit_policy(array $config,string $name,int $defaultLimit,int $defaultWindow): array {
    $p=$config['rate_limits'][$name]??[];
    $limit=max(1,min(100000,(int)($p['limit']??$defaultLimit)));
    $window=max(1,min(604800,(int)($p['window_seconds']??$defaultWindow)));
    return [$limit,$window];
}
function rate_limit_hit(PDO $pdo,string $bucket,string $subject,int $limit,int $windowSeconds): array {
    if($bucket===''||strlen($bucket)>80)throw new InvalidArgumentException('Invalid rate-limit bucket.');
    $hash=hash('sha256',$subject);$expires=gmdate('Y-m-d H:i:s',time()+$windowSeconds);
    $sql="INSERT INTO rate_limit_counters(bucket,subject_hash,hits,window_started_at,window_expires_at) VALUES(?,?,1,NOW(),?) ON DUPLICATE KEY UPDATE hits=IF(window_expires_at<=NOW(),1,hits+1),window_started_at=IF(window_expires_at<=NOW(),NOW(),window_started_at),window_expires_at=IF(window_expires_at<=NOW(),VALUES(window_expires_at),window_expires_at),updated_at=NOW()";
    $q=$pdo->prepare($sql);$q->execute([$bucket,$hash,$expires]);
    $q=$pdo->prepare('SELECT hits,GREATEST(0,TIMESTAMPDIFF(SECOND,NOW(),window_expires_at)) retry_after FROM rate_limit_counters WHERE bucket=? AND subject_hash=?');$q->execute([$bucket,$hash]);$r=$q->fetch()?:['hits'=>1,'retry_after'=>$windowSeconds];
    if(random_int(1,200)===1){try{$pdo->exec("DELETE FROM rate_limit_counters WHERE window_expires_at<DATE_SUB(NOW(),INTERVAL 1 DAY)");}catch(Throwable $e){}}
    return ['allowed'=>(int)$r['hits']<=$limit,'hits'=>(int)$r['hits'],'limit'=>$limit,'retry_after'=>max(1,(int)$r['retry_after'])];
}
function rate_limit_enforce(PDO $pdo,array $config,string $policy,string $subject,int $defaultLimit,int $defaultWindow): void {
    [$limit,$window]=rate_limit_policy($config,$policy,$defaultLimit,$defaultWindow);$r=rate_limit_hit($pdo,$policy,$subject,$limit,$window);
    if(!$r['allowed'])throw new RateLimitExceeded($r['retry_after']);
}
function rate_limit_api(PDO $pdo,array $config,string $policy,string $subject,int $defaultLimit,int $defaultWindow): void {
    try{rate_limit_enforce($pdo,$config,$policy,$subject,$defaultLimit,$defaultWindow);}catch(RateLimitExceeded $e){header('Retry-After: '.$e->retryAfter);json_response(['ok'=>false,'error'=>['code'=>'RATE_LIMITED','message'=>'Too many requests. Try again later.','retry_after'=>$e->retryAfter]],429);}
}
function rate_limit_web_error(RateLimitExceeded $e): string { header('Retry-After: '.$e->retryAfter);http_response_code(429);return 'Too many attempts. Please try again later.'; }
