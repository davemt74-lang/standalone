<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$limit=max(1,min(10000,(int)($argv[1]??500)));
if(!annotation_intelligence_ready($pdo)){fwrite(STDERR,"Phase 13 migration 020 is not installed.\n");exit(2);}
$q=$pdo->query("SELECT id FROM annotations WHERE status='published' ORDER BY id ASC");
$queued=0;$scanned=0;$skipped=0;
while(($id=$q->fetchColumn())!==false&&$scanned<$limit){
    $scanned++;
    try{if(annotation_intelligence_queue($pdo,(int)$id,null,6))$queued++;else$skipped++;}
    catch(Throwable $e){$skipped++;fwrite(STDERR,"annotation $id: ".$e->getMessage()."\n");}
}
echo "Scanned $scanned annotation(s); queued $queued; skipped $skipped.\n";
