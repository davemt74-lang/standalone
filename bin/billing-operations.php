<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(!billing_operations_ready($pdo))throw new RuntimeException('Billing operations require migration 069.');
$result=billing_operations_process_due_dunning($pdo,null);$overage=function_exists('ai_overage_report_pending')&&ai_overage_ready($pdo)?ai_overage_report_pending($pdo,$config):['reported'=>0,'failed'=>0,'skipped'=>1,'amount_cents'=>0];$snapshot=billing_operations_snapshot_day($pdo);
echo json_encode(['ok'=>true,'dunning'=>$result,'ai_overage'=>$overage,'snapshot_date'=>$snapshot['snapshot_date']??null,'mrr_cents'=>$snapshot['mrr_cents']??null,'generated_at'=>gmdate(DATE_ATOM)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
