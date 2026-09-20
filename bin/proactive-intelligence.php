<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$limit=200;foreach($argv??[] as $arg)if(str_starts_with((string)$arg,'--limit='))$limit=max(1,min(2000,(int)substr((string)$arg,8)));
if(!proactive_intelligence_ready($pdo)){fwrite(STDERR,"Phase 17 migration is required.\n");exit(2);}
$q=$pdo->query("SELECT * FROM users WHERE status='active' ORDER BY id ASC LIMIT ".(int)$limit);$users=$q->fetchAll();$evaluated=0;$notified=0;
foreach($users as $user){try{$r=proactive_intelligence_sync($pdo,$user);$evaluated+=(int)($r['evaluated']??0);$notified+=(int)($r['notified']??0);}catch(Throwable $e){fwrite(STDERR,'User '.$user['id'].': '.$e->getMessage()."\n");}}
echo "Proactive intelligence users=".count($users)." evaluated=$evaluated notified=$notified\n";
