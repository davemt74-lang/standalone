<?php
declare(strict_types=1);

function rate_limit_ip_subject(): string {
    $ip=trim((string)($_SERVER['REMOTE_ADDR']??'unknown'));
    return $ip!==''?$ip:'unknown';
}

function rate_limit_subject(string $value): string {
    return strtolower(trim($value));
}

function rate_limit_consume(PDO $pdo,string $scope,string $subject,int $limit,int $windowSeconds): array {
    $limit=max(1,$limit);$windowSeconds=max(1,$windowSeconds);$now=time();$windowKey=intdiv($now,$windowSeconds);$bucket=hash('sha256',$scope."\0".$subject);
    $q=$pdo->prepare('INSERT INTO rate_limit_buckets(bucket_hash,window_key,hits) VALUES(?,?,1) ON DUPLICATE KEY UPDATE hits=hits+1,updated_at=NOW()');
    $q->execute([$bucket,$windowKey]);
    $q=$pdo->prepare('SELECT hits FROM rate_limit_buckets WHERE bucket_hash=? AND window_key=?');$q->execute([$bucket,$windowKey]);$hits=(int)$q->fetchColumn();
    return ['allowed'=>$hits<=$limit,'hits'=>$hits,'limit'=>$limit,'retry_after'=>max(1,$windowSeconds-($now%$windowSeconds))];
}

function rate_limit_api_or_429(PDO $pdo,string $scope,string $subject,int $limit,int $windowSeconds): void {
    $r=rate_limit_consume($pdo,$scope,$subject,$limit,$windowSeconds);if($r['allowed'])return;
    if(PHP_SAPI!=='cli')header('Retry-After: '.(string)$r['retry_after']);
    json_response(['ok'=>false,'error'=>['code'=>'RATE_LIMITED','message'=>'Too many requests. Try again later.','retry_after'=>$r['retry_after']]],429);
}

function rate_limit_page_message(PDO $pdo,string $scope,string $subject,int $limit,int $windowSeconds): ?string {
    $r=rate_limit_consume($pdo,$scope,$subject,$limit,$windowSeconds);if($r['allowed'])return null;
    if(PHP_SAPI!=='cli')header('Retry-After: '.(string)$r['retry_after']);
    return 'Too many attempts. Please try again later.';
}
