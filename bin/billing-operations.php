<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(!billing_operations_ready($pdo))throw new RuntimeException('Billing operations require migration 069.');
$result=billing_operations_process_due_dunning($pdo,null);$snapshot=billing_operations_snapshot_day($pdo);
echo json_encode(['ok'=>true,'dunning'=>$result,'snapshot_date'=>$snapshot['snapshot_date']??null,'mrr_cents'=>$snapshot['mrr_cents']??null,'generated_at'=>gmdate(DATE_ATOM)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
