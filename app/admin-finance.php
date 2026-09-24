<?php
declare(strict_types=1);

function admin_finance_ready(PDO $pdo): bool {
    try{
        foreach(['admin_finance_reconciliation_exceptions','admin_finance_reconciliation_events','admin_finance_period_closes','admin_finance_exports'] as $table)if(!installer_table_exists($pdo,$table))return false;
        return true;
    }catch(Throwable $e){return false;}
}
function admin_finance_mode(PDO $pdo): string {
    return function_exists('stripe_billing_settings')?(string)(stripe_billing_settings($pdo)['mode']??'test'):'test';
}
function admin_finance_reason(string $value,string $fallback='Finance record updated.'): string {
    $value=trim($value);if($value==='')$value=$fallback;return mb_substr($value,0,1000);
}
function admin_finance_range(?string $from=null,?string $to=null): array {
    $today=gmdate('Y-m-d');$to=trim((string)$to);$from=trim((string)$from);
    if($to===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))$to=$today;
    if($from===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from))$from=gmdate('Y-m-d',strtotime($to.' -29 days'));
    if($from>$to)[$from,$to]=[$to,$from];
    $startTs=strtotime($from.' 00:00:00 UTC');$endTs=strtotime($to.' 23:59:59 UTC');if($startTs===false||$endTs===false)throw new InvalidArgumentException('Invalid finance reporting date range.');
    if(($endTs-$startTs)>3660*86400)throw new InvalidArgumentException('Finance reporting range cannot exceed 10 years.');
    return ['from'=>$from,'to'=>$to,'from_at'=>$from.' 00:00:00','to_exclusive'=>gmdate('Y-m-d 00:00:00',strtotime($to.' +1 day'))];
}
function admin_finance_metrics(PDO $pdo,?string $from=null,?string $to=null): array {
    $range=admin_finance_range($from,$to);$mode=admin_finance_mode($pdo);$out=[
        'mode'=>$mode,'from'=>$range['from'],'to'=>$range['to'],'invoice_count'=>0,'paid_invoice_count'=>0,
        'gross_cents'=>0,'discount_cents'=>0,'tax_cents'=>0,'invoiced_cents'=>0,'collected_cents'=>0,'outstanding_cents'=>0,
        'refund_cents'=>0,'credit_issued_cents'=>0,'debit_issued_cents'=>0,'overage_reported_cents'=>0,'overage_accrued_micros'=>0,
        'mrr_cents'=>0,'arr_cents'=>0,'open_dunning_cases'=>0
    ];
    if(installer_table_exists($pdo,'stripe_invoices')){
        $q=$pdo->prepare("SELECT COUNT(*) invoice_count,SUM(status='paid') paid_count,
          COALESCE(SUM(COALESCE(subtotal_cents,amount_due_cents,0)),0) gross,
          COALESCE(SUM(COALESCE(discount_amount_cents,0)),0) discounts,
          COALESCE(SUM(COALESCE(tax_amount_cents,0)),0) tax,
          COALESCE(SUM(COALESCE(total_cents,amount_due_cents,0)),0) invoiced,
          COALESCE(SUM(amount_paid_cents),0) collected,
          COALESCE(SUM(amount_remaining_cents),0) outstanding
          FROM stripe_invoices WHERE mode=? AND created_at>=? AND created_at<?");
        $q->execute([$mode,$range['from_at'],$range['to_exclusive']]);$r=$q->fetch()?:[];
        $out['invoice_count']=(int)($r['invoice_count']??0);$out['paid_invoice_count']=(int)($r['paid_count']??0);$out['gross_cents']=(int)($r['gross']??0);$out['discount_cents']=(int)($r['discounts']??0);$out['tax_cents']=(int)($r['tax']??0);$out['invoiced_cents']=(int)($r['invoiced']??0);$out['collected_cents']=(int)($r['collected']??0);$out['outstanding_cents']=(int)($r['outstanding']??0);
    }
    if(installer_table_exists($pdo,'account_billing_events')){
        try{$q=$pdo->prepare("SELECT COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(after_json,'$.amount_cents')) AS SIGNED)),0) FROM account_billing_events WHERE stripe_mode=? AND event_type='charge_refunded' AND created_at>=? AND created_at<?");$q->execute([$mode,$range['from_at'],$range['to_exclusive']]);$out['refund_cents']=(int)$q->fetchColumn();}catch(Throwable $e){}
    }
    if(installer_table_exists($pdo,'commercial_credit_adjustments')){
        $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN amount_cents<0 THEN -amount_cents ELSE 0 END),0) credits,COALESCE(SUM(CASE WHEN amount_cents>0 THEN amount_cents ELSE 0 END),0) debits FROM commercial_credit_adjustments WHERE stripe_mode=? AND status='applied' AND created_at>=? AND created_at<?");$q->execute([$mode,$range['from_at'],$range['to_exclusive']]);$r=$q->fetch()?:[];$out['credit_issued_cents']=(int)($r['credits']??0);$out['debit_issued_cents']=(int)($r['debits']??0);
    }
    if(installer_table_exists($pdo,'ai_overage_report_batches')){
        $q=$pdo->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM ai_overage_report_batches WHERE stripe_mode=? AND status IN ('reported','invoiced') AND created_at>=? AND created_at<?");$q->execute([$mode,$range['from_at'],$range['to_exclusive']]);$out['overage_reported_cents']=(int)$q->fetchColumn();
    }
    if(installer_table_exists($pdo,'ai_overage_usage_ledger')){
        $q=$pdo->prepare("SELECT COALESCE(SUM(amount_micros),0) FROM ai_overage_usage_ledger WHERE stripe_mode=? AND billable=1 AND created_at>=? AND created_at<?");$q->execute([$mode,$range['from_at'],$range['to_exclusive']]);$out['overage_accrued_micros']=(int)$q->fetchColumn();
    }
    if(function_exists('billing_operations_current_metrics')&&billing_operations_ready($pdo)){$m=billing_operations_current_metrics($pdo,$mode);$out['mrr_cents']=(int)($m['mrr_cents']??0);$out['arr_cents']=(int)($m['arr_cents']??0);$out['open_dunning_cases']=(int)($m['open_dunning_cases']??0);}
    $out['net_revenue_cents']=$out['collected_cents']-$out['refund_cents']-$out['credit_issued_cents']+$out['debit_issued_cents'];
    return $out;
}
function admin_finance_receivables(PDO $pdo,int $limit=300): array {
    if(!installer_table_exists($pdo,'stripe_invoices'))return [];$limit=max(1,min(1000,$limit));$mode=admin_finance_mode($pdo);
    $q=$pdo->prepare("SELECT i.*,a.public_id account_public_id,a.name account_name,p.name package_name,
      CASE WHEN i.due_at IS NULL THEN NULL ELSE GREATEST(0,DATEDIFF(UTC_TIMESTAMP(),i.due_at)) END age_days
      FROM stripe_invoices i JOIN accounts a ON a.id=i.account_id JOIN subscription_packages p ON p.id=a.package_id
      WHERE i.mode=? AND i.amount_remaining_cents>0 AND i.status NOT IN ('void','paid')
      ORDER BY i.due_at IS NULL,i.due_at,i.amount_remaining_cents DESC,i.id DESC LIMIT ".$limit);$q->execute([$mode]);return $q->fetchAll()?:[];
}
function admin_finance_receivable_aging(PDO $pdo): array {
    $out=['current'=>0,'1_30'=>0,'31_60'=>0,'61_90'=>0,'90_plus'=>0,'total'=>0];
    foreach(admin_finance_receivables($pdo,1000) as $r){$v=(int)$r['amount_remaining_cents'];$days=$r['age_days']===null?0:(int)$r['age_days'];$out['total']+=$v;if($days<=0)$out['current']+=$v;elseif($days<=30)$out['1_30']+=$v;elseif($days<=60)$out['31_60']+=$v;elseif($days<=90)$out['61_90']+=$v;else $out['90_plus']+=$v;}return $out;
}
function admin_finance_event(PDO $pdo,?int $exceptionId,?int $accountId,?int $actorId,string $eventType,string $reason,mixed $before=null,mixed $after=null): void {
    if(!admin_finance_ready($pdo))return;$enc=fn(mixed $v)=>$v===null?null:json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $pdo->prepare("INSERT INTO admin_finance_reconciliation_events(public_id,exception_id,account_id,actor_user_id,event_type,reason,before_json,after_json) VALUES(?,?,?,?,?,?,?,?)")->execute([ulid_like(),$exceptionId,$accountId,$actorId,mb_substr($eventType,0,80),admin_finance_reason($reason),$enc($before),$enc($after)]);
}
function admin_finance_exception(PDO $pdo,string|int $id): ?array {
    if(!admin_finance_ready($pdo))return null;$sql="SELECT e.*,a.public_id account_public_id,a.name account_name,u.username assigned_username,u.display_name assigned_display_name FROM admin_finance_reconciliation_exceptions e LEFT JOIN accounts a ON a.id=e.account_id LEFT JOIN users u ON u.id=e.assigned_user_id WHERE ";
    if(is_int($id)||ctype_digit((string)$id)){$q=$pdo->prepare($sql.'e.id=? LIMIT 1');$q->execute([(int)$id]);}else{$q=$pdo->prepare($sql.'e.public_id=? LIMIT 1');$q->execute([(string)$id]);}
    return $q->fetch()?:null;
}
function admin_finance_exception_upsert(PDO $pdo,string $mode,?int $accountId,string $key,string $type,string $severity,string $sourceType,?string $sourcePublicId,string $title,string $detail,mixed $expected=null,mixed $observed=null,int $deltaCents=0): array {
    $severity=in_array($severity,['info','warning','critical'],true)?$severity:'warning';$enc=fn(mixed $v)=>$v===null?null:json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$key=mb_substr($key,0,190);
    $q=$pdo->prepare("SELECT * FROM admin_finance_reconciliation_exceptions WHERE stripe_mode=? AND exception_key=? LIMIT 1");$q->execute([$mode,$key]);$old=$q->fetch()?:null;
    if(!$old){$public=ulid_like();$pdo->prepare("INSERT INTO admin_finance_reconciliation_exceptions(public_id,stripe_mode,account_id,exception_key,exception_type,severity,status,source_type,source_public_id,title,detail,expected_json,observed_json,delta_cents) VALUES(?,?,?,?,?,?,'open',?,?,?,?,?,?,?)")->execute([$public,$mode,$accountId,$key,$type,$severity,$sourceType,$sourcePublicId,$title,$detail!==''?$detail:null,$enc($expected),$enc($observed),$deltaCents]);$row=admin_finance_exception($pdo,(int)$pdo->lastInsertId())??throw new RuntimeException('Finance exception could not be reloaded.');admin_finance_event($pdo,(int)$row['id'],$accountId,null,'exception_opened','Reconciliation scan opened finance exception.',null,['exception_type'=>$type,'severity'=>$severity]);return $row;}
    $reopen=in_array((string)$old['status'],['resolved'],true);$nextStatus=$reopen?'open':(string)$old['status'];$pdo->prepare("UPDATE admin_finance_reconciliation_exceptions SET account_id=?,exception_type=?,severity=?,status=?,source_type=?,source_public_id=?,title=?,detail=?,expected_json=?,observed_json=?,delta_cents=?,last_seen_at=NOW(),resolved_at=CASE WHEN ?='open' THEN NULL ELSE resolved_at END,resolution_reason=CASE WHEN ?='open' THEN NULL ELSE resolution_reason END WHERE id=?")->execute([$accountId,$type,$severity,$nextStatus,$sourceType,$sourcePublicId,$title,$detail!==''?$detail:null,$enc($expected),$enc($observed),$deltaCents,$nextStatus,$nextStatus,(int)$old['id']]);
    $row=admin_finance_exception($pdo,(int)$old['id'])??$old;if($reopen)admin_finance_event($pdo,(int)$old['id'],$accountId,null,'exception_reopened','Reconciliation source condition still exists after prior resolution.',['status'=>$old['status']],['status'=>'open']);return $row;
}
function admin_finance_scan(PDO $pdo,array $admin): array {
    admin_access_assert_capability($pdo,$admin,'admin.finance.manage');if(!admin_finance_ready($pdo))throw new RuntimeException('Admin V2.30 Financial Reporting requires migration 076.');$mode=admin_finance_mode($pdo);$active=[];$counts=[];
    $add=function(?int $accountId,string $key,string $type,string $severity,string $sourceType,?string $sourcePublicId,string $title,string $detail,mixed $expected=null,mixed $observed=null,int $delta=0)use($pdo,$mode,&$active,&$counts){$row=admin_finance_exception_upsert($pdo,$mode,$accountId,$key,$type,$severity,$sourceType,$sourcePublicId,$title,$detail,$expected,$observed,$delta);$active[(string)$row['exception_key']]=true;$counts[$type]=($counts[$type]??0)+1;};
    if(installer_table_exists($pdo,'commercial_invoice_evidence')){
        $q=$pdo->prepare("SELECT e.*,a.name account_name FROM commercial_invoice_evidence e JOIN accounts a ON a.id=e.account_id WHERE e.stripe_mode=? AND e.drift_detected=1");$q->execute([$mode]);foreach($q->fetchAll()?:[] as $r)$add((int)$r['account_id'],'invoice-drift:'.$r['stripe_invoice_id'],'invoice_financial_drift','critical','stripe_invoice',(string)$r['stripe_invoice_id'],'Finalized invoice financial evidence changed','A finalized Stripe invoice no longer matches its immutable financial evidence snapshot.',json_decode((string)$r['financial_snapshot_json'],true),json_decode((string)($r['drift_details_json']??'null'),true));
        $q=$pdo->prepare("SELECT i.* FROM stripe_invoices i LEFT JOIN commercial_invoice_evidence e ON e.stripe_mode=i.mode AND e.stripe_invoice_id=i.stripe_invoice_id WHERE i.mode=? AND i.finalized_at IS NOT NULL AND e.id IS NULL");$q->execute([$mode]);foreach($q->fetchAll()?:[] as $r)$add((int)$r['account_id'],'missing-evidence:'.$r['stripe_invoice_id'],'missing_invoice_evidence','warning','stripe_invoice',(string)$r['stripe_invoice_id'],'Finalized invoice is missing immutable evidence','A finalized Stripe invoice exists without a matching commercial invoice evidence snapshot.',null,['invoice_number'=>$r['invoice_number'],'total_cents'=>(int)($r['total_cents']??$r['amount_due_cents'])]);
    }
    if(installer_table_exists($pdo,'stripe_invoices')){
        $q=$pdo->prepare("SELECT * FROM stripe_invoices WHERE mode=? AND status='paid' AND amount_remaining_cents>0");$q->execute([$mode]);foreach($q->fetchAll()?:[] as $r)$add((int)$r['account_id'],'paid-remaining:'.$r['stripe_invoice_id'],'paid_invoice_remaining_balance','critical','stripe_invoice',(string)$r['stripe_invoice_id'],'Paid invoice still has an outstanding balance','Stripe ledger marks the invoice paid while the recorded remaining amount is greater than zero.',['amount_remaining_cents'=>0],['amount_remaining_cents'=>(int)$r['amount_remaining_cents']],(int)$r['amount_remaining_cents']);
        $q=$pdo->prepare("SELECT * FROM stripe_invoices WHERE mode=? AND amount_remaining_cents>0 AND status NOT IN ('paid','void') AND due_at IS NOT NULL AND due_at<UTC_TIMESTAMP()");$q->execute([$mode]);foreach($q->fetchAll()?:[] as $r)$add((int)$r['account_id'],'overdue-invoice:'.$r['stripe_invoice_id'],'overdue_receivable','warning','stripe_invoice',(string)$r['stripe_invoice_id'],'Invoice is past due with an outstanding balance','Receivable remains unpaid after its Stripe due date.',null,['due_at'=>$r['due_at'],'amount_remaining_cents'=>(int)$r['amount_remaining_cents']],(int)$r['amount_remaining_cents']);
    }
    if(installer_table_exists($pdo,'ai_overage_report_batches')){
        $q=$pdo->prepare("SELECT * FROM ai_overage_report_batches WHERE stripe_mode=? AND status='failed'");$q->execute([$mode]);foreach($q->fetchAll()?:[] as $r)$add((int)$r['account_id'],'overage-failed:'.$r['public_id'],'ai_overage_reporting_failed','critical','ai_overage_batch',(string)$r['public_id'],'AI overage reporting batch failed',(string)($r['last_error']??'Stripe overage reporting failed.'),['status'=>'reported_or_invoiced'],['status'=>'failed','amount_cents'=>(int)$r['amount_cents']],(int)$r['amount_cents']);
    }
    if(installer_table_exists($pdo,'ai_overage_usage_ledger')){
        $q=$pdo->prepare("SELECT l.account_id,l.period_start,l.period_end,COALESCE(SUM(l.amount_micros),0) accrued,COALESCE((SELECT SUM(b.amount_micros) FROM ai_overage_report_batches b WHERE b.account_id=l.account_id AND b.stripe_mode=l.stripe_mode AND b.period_start=l.period_start AND b.period_end=l.period_end AND b.status IN ('reported','invoiced')),0) reported FROM ai_overage_usage_ledger l WHERE l.stripe_mode=? AND l.billable=1 GROUP BY l.account_id,l.period_start,l.period_end,l.stripe_mode HAVING accrued-reported>=10000");$q->execute([$mode]);foreach($q->fetchAll()?:[] as $r){$delta=(int)ceil(((int)$r['accrued']-(int)$r['reported'])/10000);$add((int)$r['account_id'],'overage-unreported:'.$r['account_id'].':'.$r['period_start'].':'.$r['period_end'],'ai_overage_unreported','warning','ai_overage_period',(string)$r['account_id'].':'.$r['period_start'].':'.$r['period_end'],'AI overage remains unreported to Stripe','Billable AI overage exceeds the amount recorded as reported/invoiced for this account period.',['reported_micros'=>(int)$r['accrued']],['reported_micros'=>(int)$r['reported'],'accrued_micros'=>(int)$r['accrued']],$delta);}
    }
    if(installer_table_exists($pdo,'commercial_credit_adjustments')){
        $q=$pdo->prepare("SELECT * FROM commercial_credit_adjustments WHERE stripe_mode=? AND status='failed'");$q->execute([$mode]);foreach($q->fetchAll()?:[] as $r)$add((int)$r['account_id'],'credit-failed:'.$r['public_id'],'credit_adjustment_failed','critical','credit_adjustment',(string)$r['public_id'],'Customer credit/debit adjustment failed',(string)($r['last_error']??'Stripe customer balance adjustment failed.'),['status'=>'applied'],['status'=>'failed','amount_cents'=>(int)$r['amount_cents']],abs((int)$r['amount_cents']));
    }
    $q=$pdo->prepare("SELECT * FROM admin_finance_reconciliation_exceptions WHERE stripe_mode=? AND status IN ('open','acknowledged')");$q->execute([$mode]);$auto=0;foreach($q->fetchAll()?:[] as $r){if(isset($active[(string)$r['exception_key']]))continue;$pdo->prepare("UPDATE admin_finance_reconciliation_exceptions SET status='resolved',resolved_at=NOW(),resolution_reason='Source condition cleared during reconciliation scan.' WHERE id=?")->execute([(int)$r['id']]);admin_finance_event($pdo,(int)$r['id'],$r['account_id']!==null?(int)$r['account_id']:null,null,'exception_auto_resolved','Source condition cleared during reconciliation scan.',['status'=>$r['status']],['status'=>'resolved']);$auto++;}
    admin_finance_event($pdo,null,null,(int)$admin['id'],'reconciliation_scan_completed','Administrator ran finance reconciliation scan.',null,['active_count'=>count($active),'auto_resolved'=>$auto,'counts'=>$counts]);
    if(function_exists('admin_access_security_audit')&&admin_access_ready($pdo))admin_access_security_audit($pdo,$admin,'action',null,'finance_reconciliation_scan',null,['active_count'=>count($active),'auto_resolved'=>$auto,'counts'=>$counts],'Run finance reconciliation scan.');
    return ['active_count'=>count($active),'auto_resolved'=>$auto,'counts'=>$counts];
}
function admin_finance_exception_filters(array $input): array {
    $out=[];foreach(['status','severity','type','assigned','q'] as $key){$v=trim((string)($input[$key]??''));if($v!=='')$out[$key]=$v;}return $out;
}
function admin_finance_exceptions(PDO $pdo,array $viewer,array $filters=[],int $limit=300): array {
    if(!admin_finance_ready($pdo))return [];$filters=admin_finance_exception_filters($filters);$mode=admin_finance_mode($pdo);$where=['e.stripe_mode=?'];$params=[$mode];$limit=max(1,min(1000,$limit));
    if(isset($filters['status'])){$where[]='e.status=?';$params[]=$filters['status'];}else $where[]="e.status IN ('open','acknowledged')";
    if(isset($filters['severity'])){$where[]='e.severity=?';$params[]=$filters['severity'];}if(isset($filters['type'])){$where[]='e.exception_type=?';$params[]=$filters['type'];}
    if(isset($filters['assigned'])){if($filters['assigned']==='me'){$where[]='e.assigned_user_id=?';$params[]=(int)$viewer['id'];}elseif($filters['assigned']==='unassigned')$where[]='e.assigned_user_id IS NULL';elseif(ctype_digit($filters['assigned'])){$where[]='e.assigned_user_id=?';$params[]=(int)$filters['assigned'];}}
    if(isset($filters['q'])){$like='%'.$filters['q'].'%';$where[]='(e.public_id LIKE ? OR e.title LIKE ? OR e.source_public_id LIKE ? OR a.name LIKE ?)';array_push($params,$like,$like,$like,$like);}
    $sql="SELECT e.*,a.public_id account_public_id,a.name account_name,u.username assigned_username,u.display_name assigned_display_name FROM admin_finance_reconciliation_exceptions e LEFT JOIN accounts a ON a.id=e.account_id LEFT JOIN users u ON u.id=e.assigned_user_id WHERE ".implode(' AND ',$where)." ORDER BY FIELD(e.severity,'critical','warning','info'),e.last_seen_at DESC,e.id DESC LIMIT ".$limit;$q=$pdo->prepare($sql);$q->execute($params);return $q->fetchAll()?:[];
}
function admin_finance_exception_types(PDO $pdo): array {
    if(!admin_finance_ready($pdo))return [];$q=$pdo->prepare("SELECT exception_type,COUNT(*) total FROM admin_finance_reconciliation_exceptions WHERE stripe_mode=? GROUP BY exception_type ORDER BY total DESC,exception_type");$q->execute([admin_finance_mode($pdo)]);return $q->fetchAll()?:[];
}
function admin_finance_operator_candidates(PDO $pdo): array {
    $rows=$pdo->query("SELECT id,public_id,username,display_name,email,role,status FROM users WHERE role='admin' AND status='active' ORDER BY display_name,username,id")->fetchAll()?:[];$out=[];foreach($rows as $r)if(admin_access_has_capability($pdo,$r,'admin.finance.manage'))$out[]=$r;return $out;
}
function admin_finance_exception_update(PDO $pdo,array $admin,string $publicId,string $operation,array $input=[]): array {
    admin_access_assert_capability($pdo,$admin,'admin.finance.manage');$row=admin_finance_exception($pdo,$publicId);if(!$row)throw new RuntimeException('Finance reconciliation exception not found.');$reason=admin_finance_reason((string)($input['reason']??''));$before=['status'=>$row['status'],'assigned_user_id'=>$row['assigned_user_id'],'resolution_reason'=>$row['resolution_reason']];
    if($operation==='assign'){$uid=(int)($input['user_id']??0);if($uid<1)throw new InvalidArgumentException('Select a finance operator.');$q=$pdo->prepare("SELECT id,public_id,username,display_name,email,role,status FROM users WHERE id=? AND role='admin' AND status='active'");$q->execute([$uid]);$u=$q->fetch();if(!$u||!admin_access_has_capability($pdo,$u,'admin.finance.manage'))throw new RuntimeException('Selected operator cannot manage finance reconciliation.');$pdo->prepare("UPDATE admin_finance_reconciliation_exceptions SET assigned_user_id=? WHERE id=?")->execute([$uid,(int)$row['id']]);}
    elseif($operation==='acknowledge'){$pdo->prepare("UPDATE admin_finance_reconciliation_exceptions SET status='acknowledged',assigned_user_id=COALESCE(assigned_user_id,?) WHERE id=? AND status='open'")->execute([(int)$admin['id'],(int)$row['id']]);}
    elseif($operation==='resolve'){$pdo->prepare("UPDATE admin_finance_reconciliation_exceptions SET status='resolved',resolved_at=NOW(),resolution_reason=?,assigned_user_id=COALESCE(assigned_user_id,?) WHERE id=?")->execute([$reason,(int)$admin['id'],(int)$row['id']]);}
    elseif($operation==='ignore'){$pdo->prepare("UPDATE admin_finance_reconciliation_exceptions SET status='ignored',resolved_at=NOW(),resolution_reason=?,assigned_user_id=COALESCE(assigned_user_id,?) WHERE id=?")->execute([$reason,(int)$admin['id'],(int)$row['id']]);}
    elseif($operation==='reopen'){$pdo->prepare("UPDATE admin_finance_reconciliation_exceptions SET status='open',resolved_at=NULL,resolution_reason=NULL WHERE id=? AND status IN ('resolved','ignored')")->execute([(int)$row['id']]);}
    else throw new RuntimeException('Unknown finance exception operation.');
    $after=admin_finance_exception($pdo,$publicId)??$row;admin_finance_event($pdo,(int)$row['id'],$row['account_id']!==null?(int)$row['account_id']:null,(int)$admin['id'],'exception_'.$operation,$reason,$before,['status'=>$after['status'],'assigned_user_id'=>$after['assigned_user_id'],'resolution_reason'=>$after['resolution_reason']]);if(function_exists('admin_access_security_audit')&&admin_access_ready($pdo))admin_access_security_audit($pdo,$admin,'action',$publicId,'finance_exception_'.$operation,$before,['status'=>$after['status'],'assigned_user_id'=>$after['assigned_user_id']],$reason);return $after;
}
function admin_finance_period_close(PDO $pdo,array $admin,string $from,string $to,string $reason): array {
    admin_access_assert_capability($pdo,$admin,'admin.finance.manage');$range=admin_finance_range($from,$to);$reason=admin_finance_reason($reason,'Close finance reporting period.');$mode=admin_finance_mode($pdo);
    $q=$pdo->prepare("SELECT c.*,u.username closed_by_username FROM admin_finance_period_closes c JOIN users u ON u.id=c.closed_by_user_id WHERE c.stripe_mode=? AND c.period_start=? AND c.period_end=? LIMIT 1");$q->execute([$mode,$range['from'],$range['to']]);if($existing=$q->fetch())return $existing;
    $metrics=admin_finance_metrics($pdo,$range['from'],$range['to']);$aging=admin_finance_receivable_aging($pdo);$ex=$pdo->prepare("SELECT status,severity,COUNT(*) total,COALESCE(SUM(ABS(delta_cents)),0) delta_cents FROM admin_finance_reconciliation_exceptions WHERE stripe_mode=? GROUP BY status,severity");$ex->execute([$mode]);$payload=['metrics'=>$metrics,'receivable_aging'=>$aging,'exception_summary'=>$ex->fetchAll()?:[],'closed_schema'=>'admin-v2.30'];$json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);$public=ulid_like();$pdo->prepare("INSERT INTO admin_finance_period_closes(public_id,stripe_mode,period_start,period_end,metrics_json,metrics_sha256,closed_by_user_id,close_reason) VALUES(?,?,?,?,?,?,?,?)")->execute([$public,$mode,$range['from'],$range['to'],$json,$hash,(int)$admin['id'],$reason]);$q=$pdo->prepare("SELECT c.*,u.username closed_by_username FROM admin_finance_period_closes c JOIN users u ON u.id=c.closed_by_user_id WHERE c.id=?");$q->execute([(int)$pdo->lastInsertId()]);$row=$q->fetch()?:[];admin_finance_event($pdo,null,null,(int)$admin['id'],'period_closed',$reason,null,['period_start'=>$range['from'],'period_end'=>$range['to'],'metrics_sha256'=>$hash]);if(function_exists('admin_access_security_audit')&&admin_access_ready($pdo))admin_access_security_audit($pdo,$admin,'action',$public,'finance_period_closed',null,['period_start'=>$range['from'],'period_end'=>$range['to'],'metrics_sha256'=>$hash],$reason);return $row;
}
function admin_finance_period_closes(PDO $pdo,int $limit=50): array {
    if(!admin_finance_ready($pdo))return [];$limit=max(1,min(200,$limit));$q=$pdo->prepare("SELECT c.*,u.username closed_by_username FROM admin_finance_period_closes c JOIN users u ON u.id=c.closed_by_user_id WHERE c.stripe_mode=? ORDER BY c.period_end DESC,c.id DESC LIMIT ".$limit);$q->execute([admin_finance_mode($pdo)]);return $q->fetchAll()?:[];
}
function admin_finance_account_summary(PDO $pdo,int $accountId): array {
    $mode=admin_finance_mode($pdo);$q=$pdo->prepare("SELECT COUNT(*) invoice_count,COALESCE(SUM(amount_paid_cents),0) paid_cents,COALESCE(SUM(amount_remaining_cents),0) outstanding_cents,COALESCE(SUM(COALESCE(tax_amount_cents,0)),0) tax_cents,COALESCE(SUM(COALESCE(discount_amount_cents,0)),0) discount_cents FROM stripe_invoices WHERE account_id=? AND mode=?");$q->execute([$accountId,$mode]);$r=$q->fetch()?:[];$q=$pdo->prepare("SELECT COUNT(*) FROM admin_finance_reconciliation_exceptions WHERE account_id=? AND stripe_mode=? AND status IN ('open','acknowledged')");$q->execute([$accountId,$mode]);$r['open_exceptions']=(int)$q->fetchColumn();return $r;
}
function admin_finance_account_ledger(PDO $pdo,int $accountId,int $limit=300): array {
    $mode=admin_finance_mode($pdo);$rows=[];$push=function(string $date,string $type,string $label,int $amount,string $status,string $id,string $detail='')use(&$rows){$rows[]=['created_at'=>$date,'type'=>$type,'label'=>$label,'amount_cents'=>$amount,'status'=>$status,'identifier'=>$id,'detail'=>$detail];};
    $q=$pdo->prepare("SELECT * FROM stripe_invoices WHERE account_id=? AND mode=? ORDER BY created_at DESC,id DESC LIMIT 150");$q->execute([$accountId,$mode]);foreach($q->fetchAll()?:[] as $r)$push((string)$r['created_at'],'invoice','Invoice '.((string)($r['invoice_number']?:$r['stripe_invoice_id'])),(int)($r['total_cents']??$r['amount_due_cents']),(string)$r['status'],(string)$r['stripe_invoice_id'],'Paid '.(int)$r['amount_paid_cents'].' · Remaining '.(int)$r['amount_remaining_cents']);
    if(installer_table_exists($pdo,'commercial_credit_adjustments')){$q=$pdo->prepare("SELECT * FROM commercial_credit_adjustments WHERE account_id=? AND stripe_mode=? ORDER BY created_at DESC,id DESC LIMIT 100");$q->execute([$accountId,$mode]);foreach($q->fetchAll()?:[] as $r)$push((string)$r['created_at'],'credit_adjustment',ucwords(str_replace('_',' ',(string)$r['adjustment_type'])),(int)$r['amount_cents'],(string)$r['status'],(string)$r['public_id'],(string)$r['reason']);}
    if(installer_table_exists($pdo,'commercial_discount_events')){$q=$pdo->prepare("SELECT * FROM commercial_discount_events WHERE account_id=? AND stripe_mode=? ORDER BY created_at DESC,id DESC LIMIT 100");$q->execute([$accountId,$mode]);foreach($q->fetchAll()?:[] as $r)$push((string)$r['created_at'],'discount','Invoice discount',-(int)$r['discount_amount_cents'],'applied',(string)$r['public_id'],'Gross '.(int)$r['gross_subtotal_cents'].' · Net '.(int)$r['net_total_cents']);}
    if(installer_table_exists($pdo,'ai_overage_report_batches')){$q=$pdo->prepare("SELECT * FROM ai_overage_report_batches WHERE account_id=? AND stripe_mode=? ORDER BY created_at DESC,id DESC LIMIT 100");$q->execute([$accountId,$mode]);foreach($q->fetchAll()?:[] as $r)$push((string)$r['created_at'],'ai_overage','AI overage report',(int)$r['amount_cents'],(string)$r['status'],(string)$r['public_id'],(string)$r['period_start'].' → '.(string)$r['period_end']);}
    usort($rows,fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));return array_slice($rows,0,max(1,min(1000,$limit)));
}
function admin_finance_dashboard(PDO $pdo,array $viewer,array $filters=[]): array {
    $range=admin_finance_range((string)($filters['from']??''),(string)($filters['to']??''));return ['ready'=>admin_finance_ready($pdo),'range'=>$range,'metrics'=>admin_finance_metrics($pdo,$range['from'],$range['to']),'aging'=>admin_finance_receivable_aging($pdo),'receivables'=>admin_finance_receivables($pdo,250),'exceptions'=>admin_finance_exceptions($pdo,$viewer,$filters,300),'exception_types'=>admin_finance_exception_types($pdo),'operators'=>admin_finance_operator_candidates($pdo),'closes'=>admin_finance_period_closes($pdo,36),'packages'=>function_exists('billing_operations_revenue_by_package')?billing_operations_revenue_by_package($pdo):[]];
}
function admin_finance_export_rows(PDO $pdo,string $type,array $filters=[]): array {
    $type=trim($type);if($type==='exceptions')return admin_finance_exceptions($pdo,['id'=>0],array_merge($filters,['status'=>$filters['status']??'open']),1000);
    if($type==='receivables')return admin_finance_receivables($pdo,1000);
    if($type==='period_closes')return admin_finance_period_closes($pdo,200);
    if($type==='ledger'){
        $range=admin_finance_range((string)($filters['from']??''),(string)($filters['to']??''));$mode=admin_finance_mode($pdo);$where="i.mode=? AND i.created_at>=? AND i.created_at<?";$args=[$mode,$range['from_at'],$range['to_exclusive']];$accountPublic=trim((string)($filters['account']??''));if($accountPublic!==''){$where.=" AND a.public_id=?";$args[]=$accountPublic;}
        $q=$pdo->prepare("SELECT i.created_at,'invoice' record_type,a.public_id account_public_id,a.name account_name,i.stripe_invoice_id identifier,i.invoice_number label,COALESCE(i.total_cents,i.amount_due_cents,0) amount_cents,i.currency,i.status,i.amount_paid_cents,i.amount_remaining_cents,i.tax_amount_cents,i.discount_amount_cents FROM stripe_invoices i JOIN accounts a ON a.id=i.account_id WHERE ".$where." ORDER BY i.created_at DESC,i.id DESC");$q->execute($args);return $q->fetchAll()?:[];
    }
    throw new InvalidArgumentException('Unknown finance export type.');
}
function admin_finance_csv(array $rows): string {
    if(!$rows)return "no_records\n";$headers=array_keys($rows[0]);$safe=function(mixed $v): mixed {if(is_array($v)||is_object($v))$v=json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(is_string($v)&&$v!==''&&in_array($v[0],['=','+','-','@'],true))return "'".$v;return $v;};$f=fopen('php://temp','r+');fputcsv($f,$headers);foreach($rows as $row){$line=[];foreach($headers as $h)$line[]=$safe($row[$h]??null);fputcsv($f,$line);}rewind($f);$csv=(string)stream_get_contents($f);fclose($f);return $csv;
}
function admin_finance_export(PDO $pdo,array $admin,string $type,array $filters=[]): array {
    admin_access_assert_capability($pdo,$admin,'admin.finance.export');$rows=admin_finance_export_rows($pdo,$type,$filters);$csv=admin_finance_csv($rows);$sha=hash('sha256',$csv);$public=ulid_like();$mode=admin_finance_mode($pdo);$json=$filters?json_encode($filters,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null;$pdo->prepare("INSERT INTO admin_finance_exports(public_id,stripe_mode,requested_by_user_id,export_type,filters_json,row_count,content_sha256) VALUES(?,?,?,?,?,?,?)")->execute([$public,$mode,(int)$admin['id'],$type,$json,count($rows),$sha]);if(function_exists('admin_access_security_audit')&&admin_access_ready($pdo))admin_access_security_audit($pdo,$admin,'action',$public,'finance_export_created',null,['export_type'=>$type,'row_count'=>count($rows),'content_sha256'=>$sha],'Administrator exported finance evidence.');$suffix=$filters['from']??gmdate('Y-m-d');$filename='annotated-finance-'.$type.'-'.$suffix.'.csv';return ['public_id'=>$public,'filename'=>$filename,'content'=>$csv,'sha256'=>$sha,'row_count'=>count($rows)];
}
function admin_finance_recent_exports(PDO $pdo,int $limit=30): array {
    if(!admin_finance_ready($pdo))return [];$limit=max(1,min(100,$limit));$q=$pdo->prepare("SELECT e.*,u.username requested_by_username FROM admin_finance_exports e JOIN users u ON u.id=e.requested_by_user_id WHERE e.stripe_mode=? ORDER BY e.created_at DESC,e.id DESC LIMIT ".$limit);$q->execute([admin_finance_mode($pdo)]);return $q->fetchAll()?:[];
}
function admin_finance_agent_context(PDO $pdo,array $viewer): string {
    if(($viewer['role']??'')!=='admin'||!admin_finance_ready($pdo)||!admin_access_has_capability($pdo,$viewer,'admin.finance.view'))return '';$m=admin_finance_metrics($pdo);$q=$pdo->prepare("SELECT COUNT(*) FROM admin_finance_reconciliation_exceptions WHERE stripe_mode=? AND status IN ('open','acknowledged')");$q->execute([$m['mode']]);$open=(int)$q->fetchColumn();return "[ADMIN V2.30 FINANCE — READ ONLY]\nReporting period: {$m['from']} through {$m['to']}. Collected: $".number_format($m['collected_cents']/100,2)."; net after refunds/credits/debits: $".number_format($m['net_revenue_cents']/100,2)."; outstanding receivables: $".number_format($m['outstanding_cents']/100,2)."; open reconciliation exceptions: {$open}. Stripe-synchronized billing ledgers remain payment truth. The Agent may summarize anomalies and suggest investigation, but cannot reconcile, resolve exceptions, close periods, export finance data, issue credits/refunds, or execute financial mutations.";
}
