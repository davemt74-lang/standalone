<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
if(!data_attribution_ready($pdo)){fwrite(STDERR,"Data & Attribution requires the Phase 37 database upgrade.\n");exit(2);}
$limit=max(1,min(1000,(int)($argv[1]??250)));$userId=isset($argv[2])?max(0,(int)$argv[2]):0;
$sql='SELECT id,public_id,username,display_name,email,role FROM users WHERE status=\'active\'';$params=[];if($userId){$sql.=' AND id=?';$params[]=$userId;}$sql.=' ORDER BY id LIMIT '.$limit;$q=$pdo->prepare($sql);$q->execute($params);
$users=$q->fetchAll();$total=0;$corpus=0;foreach($users as $u){$r=data_attribution_sync_user($pdo,$u,1000);$total+=(int)$r['captured'];$corpus+=(int)$r['corpus'];echo 'user '.$u['id'].' · checked '.$r['captured'].' contribution states · '.$r['corpus']." active corpus items\n";}
echo 'Data & Attribution sync complete · users='.count($users).' · checked='.$total.' · active_corpus='.$corpus."\n";
