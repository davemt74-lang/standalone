<?php
declare(strict_types=1);

function sponsored_research_finance_ready(PDO $pdo): bool {
    try{return sponsored_research_reviews_ready($pdo)
      && installer_table_exists($pdo,'sponsored_research_finance_settings')
      && installer_table_exists($pdo,'sponsored_research_financial_transactions')
      && installer_table_exists($pdo,'sponsored_research_financial_entries')
      && installer_table_exists($pdo,'sponsored_research_compensation_reservations');}
    catch(Throwable $e){return false;}
}
function sponsored_research_finance_settings(PDO $pdo): array {
    $q=$pdo->query('SELECT * FROM sponsored_research_finance_settings WHERE id=1');return $q->fetch()?:['id'=>1,'platform_fee_bps'=>0];
}
function sponsored_research_finance_settings_update(PDO $pdo,array $admin,int $feeBps): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
    $feeBps=max(0,min(10000,$feeBps));$pdo->prepare('UPDATE sponsored_research_finance_settings SET platform_fee_bps=?,updated_by_user_id=?,updated_at=NOW() WHERE id=1')->execute([$feeBps,(int)$admin['id']]);return sponsored_research_finance_settings($pdo);
}
function sponsored_research_finance_currency(string $currency): string {
    $currency=strtoupper(trim($currency));if(!preg_match('/^[A-Z]{3}$/',$currency))throw new InvalidArgumentException('Currency must be a three-letter ISO code.');return $currency;
}
function sponsored_research_finance_transaction(PDO $pdo,string $type,int $campaignId,?int $researcherUserId,?int $reviewCaseId,?int $submissionId,string $currency,string $idempotencyKey,array $entries,?int $actorUserId=null,?string $referenceType=null,?string $referencePublicId=null,string $memo='',array $metadata=[]): array {
    if(!sponsored_research_finance_ready($pdo))throw new RuntimeException('Sponsored Research finance requires migration 121.');
    $currency=sponsored_research_finance_currency($currency);$idempotencyKey=mb_substr(trim($idempotencyKey),0,190);if($idempotencyKey==='')throw new InvalidArgumentException('Financial transaction idempotency key is required.');
    $q=$pdo->prepare('SELECT * FROM sponsored_research_financial_transactions WHERE idempotency_key=? LIMIT 1');$q->execute([$idempotencyKey]);$existing=$q->fetch();if($existing)return $existing;
    $sum=0;foreach($entries as $entry){$amount=(int)($entry['amount_cents']??0);if($amount===0)throw new InvalidArgumentException('Financial entries cannot be zero.');$sum+=$amount;}
    if($type!=='funding'&&$sum!==0)throw new RuntimeException('Internal Sponsored Research ledger transactions must balance to zero.');
    $public=ulid_like();$pdo->prepare('INSERT INTO sponsored_research_financial_transactions(public_id,transaction_type,campaign_id,researcher_user_id,review_case_id,submission_id,currency,reference_type,reference_public_id,idempotency_key,memo,metadata_json,created_by_user_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$public,$type,$campaignId,$researcherUserId,$reviewCaseId,$submissionId,$currency,$referenceType,$referencePublicId,$idempotencyKey,$memo!==''?mb_substr($memo,0,1000):null,$metadata?data_attribution_encode($metadata):null,$actorUserId]);$txId=(int)$pdo->lastInsertId();
    $allowed=['campaign_available','campaign_reserved','researcher_pending','researcher_held','platform_fee','platform_fee_held','sponsor_refundable'];
    foreach($entries as $entry){$account=(string)$entry['account_code'];if(!in_array($account,$allowed,true))throw new InvalidArgumentException('Invalid Sponsored Research financial account.');$pdo->prepare('INSERT INTO sponsored_research_financial_entries(transaction_id,campaign_id,researcher_user_id,account_code,amount_cents,currency) VALUES(?,?,?,?,?,?)')->execute([$txId,$campaignId,$entry['researcher_user_id']??$researcherUserId,$account,(int)$entry['amount_cents'],$currency]);}
    $q=$pdo->prepare('SELECT * FROM sponsored_research_financial_transactions WHERE id=?');$q->execute([$txId]);return $q->fetch()?:[];
}
function sponsored_research_finance_campaign_balances(PDO $pdo,int $campaignId,string $currency): array {
    $currency=sponsored_research_finance_currency($currency);$q=$pdo->prepare('SELECT account_code,COALESCE(SUM(amount_cents),0) amount_cents FROM sponsored_research_financial_entries WHERE campaign_id=? AND currency=? GROUP BY account_code');$q->execute([$campaignId,$currency]);$out=['campaign_available'=>0,'campaign_reserved'=>0,'platform_fee'=>0,'platform_fee_held'=>0,'sponsor_refundable'=>0];foreach($q->fetchAll()?:[] as $r)$out[(string)$r['account_code']]=(int)$r['amount_cents'];return $out;
}
function sponsored_research_finance_researcher_balances(PDO $pdo,int $userId,?string $currency=null): array {
    $params=[$userId];$sql="SELECT currency,account_code,COALESCE(SUM(amount_cents),0) amount_cents FROM sponsored_research_financial_entries WHERE researcher_user_id=? AND account_code IN ('researcher_pending','researcher_held')";if($currency!==null){$sql.=' AND currency=?';$params[]=sponsored_research_finance_currency($currency);}$sql.=' GROUP BY currency,account_code ORDER BY currency,account_code';$q=$pdo->prepare($sql);$q->execute($params);$out=[];foreach($q->fetchAll()?:[] as $r){$cur=(string)$r['currency'];$out[$cur]??=['pending_cents'=>0,'held_cents'=>0,'available_cents'=>0];if($r['account_code']==='researcher_pending')$out[$cur]['pending_cents']=(int)$r['amount_cents'];else$out[$cur]['held_cents']=(int)$r['amount_cents'];$out[$cur]['available_cents']=$out[$cur]['pending_cents'];}return $out;
}
function sponsored_research_finance_record_funding(PDO $pdo,array $admin,string $campaignPublicId,int $amountCents,string $source='manual_admin',string $reference=''): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required to record campaign funding.');
    $campaign=sponsored_research_campaign_by_public($pdo,$campaignPublicId);if(!$campaign)throw new RuntimeException('Campaign not found.');if($amountCents<=0)throw new InvalidArgumentException('Funding amount must be positive.');
    $currency=(string)$campaign['budget_currency'];$key='sponsored-funding-'.$campaign['public_id'].'-'.hash('sha256',$source.'|'.$reference.'|'.$amountCents.'|'.$currency);
    return sponsored_research_finance_transaction($pdo,'funding',(int)$campaign['id'],null,null,null,$currency,$key,[['account_code'=>'campaign_available','amount_cents'=>$amountCents]],(int)$admin['id'],$source,$reference!==''?$reference:null,'Sponsored Research campaign funding recorded.',['source'=>$source]);
}
function sponsored_research_compensation_reservation_for_submission(PDO $pdo,int $submissionId): ?array {
    $q=$pdo->prepare('SELECT * FROM sponsored_research_compensation_reservations WHERE submission_id=? LIMIT 1');$q->execute([$submissionId]);return $q->fetch()?:null;
}
function sponsored_research_compensation_reserve(PDO $pdo,array $viewer,string $submissionPublicId,int $grossAmountCents): array {
    $submission=sponsored_research_submission_get($pdo,$submissionPublicId);if(!$submission)throw new RuntimeException('Submission not found.');$campaign=sponsored_research_campaign_require_manage($pdo,$viewer,(string)$submission['campaign_public_id']);
    if($grossAmountCents<=0)throw new InvalidArgumentException('Reserved compensation must be positive.');$currency=(string)$campaign['budget_currency'];
    return app_with_advisory_lock($pdo,'sponsored-compensation-reserve',(int)$submission['id'],function() use($pdo,$viewer,$submission,$campaign,$grossAmountCents,$currency){
        $existing=sponsored_research_compensation_reservation_for_submission($pdo,(int)$submission['id']);if($existing&&in_array((string)$existing['status'],['reserved','settled'],true))return $existing;
        $balances=sponsored_research_finance_campaign_balances($pdo,(int)$campaign['id'],$currency);if((int)$balances['campaign_available']<$grossAmountCents)throw new RuntimeException('Campaign does not have enough funded balance for this compensation reservation.');
        $budget=(int)$campaign['budget_cents'];$q=$pdo->prepare("SELECT COALESCE(SUM(gross_amount_cents),0) FROM sponsored_research_compensation_reservations WHERE campaign_id=? AND status IN ('reserved','settled')");$q->execute([(int)$campaign['id']]);$committed=(int)$q->fetchColumn();if($budget>0&&$committed+$grossAmountCents>$budget)throw new RuntimeException('Compensation reservation would exceed the campaign budget.');
        $feeBps=(int)(sponsored_research_finance_settings($pdo)['platform_fee_bps']??0);$key='sponsored-reserve-'.$submission['public_id'].'-'.$grossAmountCents.'-'.$feeBps;
        $tx=sponsored_research_finance_transaction($pdo,'reservation',(int)$campaign['id'],(int)$submission['researcher_user_id'],null,(int)$submission['id'],$currency,$key,[['account_code'=>'campaign_available','amount_cents'=>-$grossAmountCents],['account_code'=>'campaign_reserved','amount_cents'=>$grossAmountCents]],(int)$viewer['id'],'sponsored_submission',(string)$submission['public_id'],'Reserved compensation for Sponsored Research submission.',['gross_amount_cents'=>$grossAmountCents,'platform_fee_bps'=>$feeBps]);
        $public=ulid_like();$pdo->prepare("INSERT INTO sponsored_research_compensation_reservations(public_id,campaign_id,submission_id,researcher_user_id,gross_amount_cents,platform_fee_bps,currency,status,reserve_transaction_id,created_by_user_id) VALUES(?,?,?,?,?,?,?,'reserved',?,?)")
          ->execute([$public,(int)$campaign['id'],(int)$submission['id'],(int)$submission['researcher_user_id'],$grossAmountCents,$feeBps,$currency,(int)$tx['id'],(int)$viewer['id']]);
        $q=$pdo->prepare('SELECT * FROM sponsored_research_compensation_reservations WHERE public_id=?');$q->execute([$public]);return $q->fetch()?:[];
    },5);
}
function sponsored_research_compensation_release(PDO $pdo,array $viewer,string $submissionPublicId,string $reason=''): array {
    $submission=sponsored_research_submission_get($pdo,$submissionPublicId);if(!$submission)throw new RuntimeException('Submission not found.');$campaign=sponsored_research_campaign_require_manage($pdo,$viewer,(string)$submission['campaign_public_id']);$res=sponsored_research_compensation_reservation_for_submission($pdo,(int)$submission['id']);if(!$res||$res['status']!=='reserved')throw new RuntimeException('No active compensation reservation exists.');
    $key='sponsored-reserve-release-'.$res['public_id'];$tx=sponsored_research_finance_transaction($pdo,'reservation_release',(int)$campaign['id'],(int)$res['researcher_user_id'],null,(int)$submission['id'],(string)$res['currency'],$key,[['account_code'=>'campaign_reserved','amount_cents'=>-(int)$res['gross_amount_cents']],['account_code'=>'campaign_available','amount_cents'=>(int)$res['gross_amount_cents']]],(int)$viewer['id'],'compensation_reservation',(string)$res['public_id'],$reason!==''?$reason:'Released compensation reservation.');
    $pdo->prepare("UPDATE sponsored_research_compensation_reservations SET status='released',released_at=NOW() WHERE id=?")->execute([(int)$res['id']]);$res['status']='released';$res['released_at']=date('Y-m-d H:i:s');$res['release_transaction_id']=$tx['id'];return $res;
}
function sponsored_research_compensation_require_reserved(PDO $pdo,int $submissionId): array {
    $res=sponsored_research_compensation_reservation_for_submission($pdo,$submissionId);if(!$res||$res['status']!=='reserved')throw new RuntimeException('Accepted Sponsored Research requires a funded compensation reservation.');return $res;
}
function sponsored_research_compensation_settle_review(PDO $pdo,array $case,array $actor): array {
    $res=sponsored_research_compensation_require_reserved($pdo,(int)$case['submission_id']);$gross=(int)$res['gross_amount_cents'];$fee=intdiv($gross*(int)$res['platform_fee_bps'],10000);$net=$gross-$fee;
    $key='sponsored-settlement-'.$case['public_id'].'-'.$res['public_id'];$tx=sponsored_research_finance_transaction($pdo,'acceptance_settlement',(int)$case['campaign_id'],(int)$res['researcher_user_id'],(int)$case['id'],(int)$case['submission_id'],(string)$res['currency'],$key,[['account_code'=>'campaign_reserved','amount_cents'=>-$gross],['account_code'=>'researcher_pending','amount_cents'=>$net],['account_code'=>'platform_fee','amount_cents'=>$fee?:1]],(int)$actor['id'],'sponsored_review',(string)$case['public_id'],'Accepted Sponsored Research compensation settled.',['gross_amount_cents'=>$gross,'platform_fee_cents'=>$fee,'researcher_net_cents'=>$net]);
    if($fee===0){
        $pdo->prepare("DELETE FROM sponsored_research_financial_entries WHERE transaction_id=? AND account_code='platform_fee' AND amount_cents=1")->execute([(int)$tx['id']]);
    }
    $pdo->prepare("UPDATE sponsored_research_compensation_reservations SET status='settled',settlement_transaction_id=?,settled_at=NOW() WHERE id=?")->execute([(int)$tx['id'],(int)$res['id']]);return ['transaction'=>$tx,'reservation'=>$res,'gross_cents'=>$gross,'fee_cents'=>$fee,'net_cents'=>$net];
}
function sponsored_research_compensation_hold_review(PDO $pdo,array $case,array $actor): ?array {
    $res=sponsored_research_compensation_reservation_for_submission($pdo,(int)$case['submission_id']);if(!$res||$res['status']!=='settled')return null;$gross=(int)$res['gross_amount_cents'];$fee=intdiv($gross*(int)$res['platform_fee_bps'],10000);$net=$gross-$fee;$entries=[];if($net>0){$entries[]=['account_code'=>'researcher_pending','amount_cents'=>-$net];$entries[]=['account_code'=>'researcher_held','amount_cents'=>$net];}if($fee>0){$entries[]=['account_code'=>'platform_fee','amount_cents'=>-$fee];$entries[]=['account_code'=>'platform_fee_held','amount_cents'=>$fee];}if(!$entries)return null;
    return sponsored_research_finance_transaction($pdo,'earning_hold',(int)$case['campaign_id'],(int)$res['researcher_user_id'],(int)$case['id'],(int)$case['submission_id'],(string)$res['currency'],'sponsored-hold-'.$case['public_id'],$entries,(int)$actor['id'],'sponsored_review',(string)$case['public_id'],'Compensation held during Sponsored Research dispute.');
}
function sponsored_research_compensation_release_hold(PDO $pdo,array $case,array $actor): ?array {
    $res=sponsored_research_compensation_reservation_for_submission($pdo,(int)$case['submission_id']);if(!$res||$res['status']!=='settled')return null;$gross=(int)$res['gross_amount_cents'];$fee=intdiv($gross*(int)$res['platform_fee_bps'],10000);$net=$gross-$fee;$entries=[];if($net>0){$entries[]=['account_code'=>'researcher_held','amount_cents'=>-$net];$entries[]=['account_code'=>'researcher_pending','amount_cents'=>$net];}if($fee>0){$entries[]=['account_code'=>'platform_fee_held','amount_cents'=>-$fee];$entries[]=['account_code'=>'platform_fee','amount_cents'=>$fee];}if(!$entries)return null;
    return sponsored_research_finance_transaction($pdo,'earning_release',(int)$case['campaign_id'],(int)$res['researcher_user_id'],(int)$case['id'],(int)$case['submission_id'],(string)$res['currency'],'sponsored-hold-release-'.$case['public_id'],$entries,(int)$actor['id'],'sponsored_review',(string)$case['public_id'],'Compensation hold released after Sponsored Research dispute resolution.');
}
function sponsored_research_compensation_reverse(PDO $pdo,array $admin,string $casePublicId,string $reason): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');$case=sponsored_research_review_case_get($pdo,$casePublicId);if(!$case)throw new RuntimeException('Review case not found.');$res=sponsored_research_compensation_reservation_for_submission($pdo,(int)$case['submission_id']);if(!$res||$res['status']!=='settled')throw new RuntimeException('No settled compensation exists to reverse.');
    $reason=trim($reason);if($reason==='')throw new InvalidArgumentException('Reversal reason is required.');$gross=(int)$res['gross_amount_cents'];$fee=intdiv($gross*(int)$res['platform_fee_bps'],10000);$net=$gross-$fee;$balances=sponsored_research_finance_researcher_balances($pdo,(int)$res['researcher_user_id'],(string)$res['currency']);$cur=$balances[(string)$res['currency']]??['pending_cents'=>0,'held_cents'=>0];
    $source='researcher_pending';if((int)$cur['pending_cents']<$net){if((int)$cur['held_cents']>=$net)$source='researcher_held';else throw new RuntimeException('Researcher balance is insufficient for reversal; payout recovery requires the payout section.');}
    $entries=[];if($net>0)$entries[]=['account_code'=>$source,'amount_cents'=>-$net];if($fee>0)$entries[]=['account_code'=>'platform_fee','amount_cents'=>-$fee];$entries[]=['account_code'=>'campaign_available','amount_cents'=>$gross];
    $tx=sponsored_research_finance_transaction($pdo,'reversal',(int)$case['campaign_id'],(int)$res['researcher_user_id'],(int)$case['id'],(int)$case['submission_id'],(string)$res['currency'],'sponsored-reversal-'.$case['public_id'],$entries,(int)$admin['id'],'sponsored_review',(string)$case['public_id'],$reason,['reason_hash'=>hash('sha256',$reason)]);
    $pdo->prepare("UPDATE sponsored_research_compensation_reservations SET status='reversed',reversed_at=NOW() WHERE id=?")->execute([(int)$res['id']]);return $tx;
}
function sponsored_research_finance_transactions_for_campaign(PDO $pdo,array $viewer,string $campaignPublicId,int $limit=200): array {
    $campaign=sponsored_research_campaign_require_manage($pdo,$viewer,$campaignPublicId);$q=$pdo->prepare('SELECT * FROM sponsored_research_financial_transactions WHERE campaign_id=? ORDER BY id DESC LIMIT '.max(1,min(500,$limit)));$q->execute([(int)$campaign['id']]);return $q->fetchAll()?:[];
}
function sponsored_research_finance_transactions_for_researcher(PDO $pdo,array $viewer,int $limit=200): array {
    research_account_require_approved($pdo,$viewer);$q=$pdo->prepare('SELECT t.*,c.title campaign_title FROM sponsored_research_financial_transactions t JOIN sponsored_research_campaigns c ON c.id=t.campaign_id WHERE t.researcher_user_id=? ORDER BY t.id DESC LIMIT '.max(1,min(500,$limit)));$q->execute([(int)$viewer['id']]);return $q->fetchAll()?:[];
}
