<?php
declare(strict_types=1);

function billing_operations_ready(PDO $pdo): bool {
    try{
        return stripe_billing_ready($pdo)
            && installer_table_exists($pdo,'billing_operations_settings')
            && installer_table_exists($pdo,'billing_daily_snapshots')
            && installer_table_exists($pdo,'billing_account_snapshots')
            && installer_table_exists($pdo,'billing_dunning_cases')
            && installer_table_exists($pdo,'billing_dunning_events')
            && installer_table_exists($pdo,'billing_notes')
            && installer_table_exists($pdo,'billing_cancellation_feedback');
    }catch(Throwable $e){return false;}
}
function billing_operations_settings(PDO $pdo): array {
    if(!billing_operations_ready($pdo))return ['id'=>1,'dunning_grace_days'=>7,'trial_reminder_days'=>3,'suspend_after_grace'=>0,'restore_after_payment'=>1];
    $row=$pdo->query('SELECT * FROM billing_operations_settings WHERE id=1')->fetch();
    return $row?:['id'=>1,'dunning_grace_days'=>7,'trial_reminder_days'=>3,'suspend_after_grace'=>0,'restore_after_payment'=>1];
}
function billing_operations_save_settings(PDO $pdo,array $admin,array $input): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
    if(!billing_operations_ready($pdo))throw new RuntimeException('Billing operations require the latest database upgrade.');
    $grace=max(0,min(60,(int)($input['dunning_grace_days']??7)));$trial=max(1,min(30,(int)($input['trial_reminder_days']??3)));
    $suspend=!empty($input['suspend_after_grace'])?1:0;$restore=!empty($input['restore_after_payment'])?1:0;
    $pdo->prepare('UPDATE billing_operations_settings SET dunning_grace_days=?,trial_reminder_days=?,suspend_after_grace=?,restore_after_payment=?,updated_by_user_id=? WHERE id=1')
      ->execute([$grace,$trial,$suspend,$restore,(int)$admin['id']]);
    return billing_operations_settings($pdo);
}
function billing_operations_json(mixed $value): ?string {
    return $value===null?null:json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
function billing_operations_dunning_event(PDO $pdo,int $caseId,int $accountId,string $source,string $eventType,string $reason,?int $actorUserId=null,?string $stripeEventId=null,mixed $before=null,mixed $after=null): void {
    $source=in_array($source,['stripe','admin','system'],true)?$source:'system';
    $pdo->prepare('INSERT INTO billing_dunning_events(public_id,case_id,account_id,actor_user_id,source,event_type,stripe_event_id,before_json,after_json,reason) VALUES(?,?,?,?,?,?,?,?,?,?)')
      ->execute([ulid_like(),$caseId,$accountId,$actorUserId,$source,mb_substr($eventType,0,100),$stripeEventId,billing_operations_json($before),billing_operations_json($after),mb_substr(trim($reason),0,500)]);
}
function billing_operations_account_from_stripe_object(PDO $pdo,array $object,string $mode): ?array {
    $customer=is_string($object['customer']??null)?(string)$object['customer']:'';
    if($customer!=='')return stripe_billing_account_by_customer($pdo,$customer,$mode);
    $metadata=is_array($object['metadata']??null)?$object['metadata']:[];
    $public=trim((string)($metadata['annotated_account_id']??''));
    return $public!==''?stripe_billing_account_by_public($pdo,$public):null;
}
function billing_operations_case(PDO $pdo,string $mode,string $invoiceId): ?array {
    if(!billing_operations_ready($pdo))return null;$q=$pdo->prepare('SELECT * FROM billing_dunning_cases WHERE stripe_mode=? AND stripe_invoice_id=? LIMIT 1');$q->execute([$mode,$invoiceId]);return $q->fetch()?:null;
}
function billing_operations_open_dunning_for_account(PDO $pdo,int $accountId): array {
    if(!billing_operations_ready($pdo))return [];$q=$pdo->prepare("SELECT * FROM billing_dunning_cases WHERE account_id=? AND status IN ('open','action_required','grace','suspended') ORDER BY opened_at DESC,id DESC");$q->execute([$accountId]);return $q->fetchAll()?:[];
}
function billing_operations_handle_invoice_event(PDO $pdo,array $object,string $eventType,string $mode,?string $eventId,?string $eventCreatedAt): void {
    if(!billing_operations_ready($pdo))return;$invoiceId=(string)($object['id']??'');if($invoiceId==='')return;$account=billing_operations_account_from_stripe_object($pdo,$object,$mode);if(!$account)return;
    commercial_account_with_lock($pdo,(int)$account['id'],function()use($pdo,$object,$eventType,$mode,$eventId,$eventCreatedAt,$invoiceId,$account){
        $existing=billing_operations_case($pdo,$mode,$invoiceId);
        if($eventCreatedAt&&$existing&&!empty($existing['last_event_created_at'])&&strcmp((string)$existing['last_event_created_at'],$eventCreatedAt)>0)return;
        $settings=billing_operations_settings($pdo);$now=$eventCreatedAt?:gmdate('Y-m-d H:i:s');$subscriptionId=stripe_billing_invoice_subscription_id($object);
        $amountDue=(int)($object['amount_due']??0);$remaining=(int)($object['amount_remaining']??0);$pdo->beginTransaction();try{
        if(in_array($eventType,['invoice.payment_failed','invoice.payment_action_required'],true)){
            $status=$eventType==='invoice.payment_action_required'?'action_required':'open';$graceUntil=$existing&&!empty($existing['grace_until'])?(string)$existing['grace_until']:(new DateTimeImmutable($now,new DateTimeZone('UTC')))->modify('+'.(int)$settings['dunning_grace_days'].' days')->format('Y-m-d H:i:s');
            $before=$existing?['status'=>$existing['status'],'failure_count'=>(int)$existing['failure_count'],'grace_until'=>$existing['grace_until']]:null;
            if($existing){
                $increment=$eventId!==null&&!hash_equals((string)($existing['last_event_id']??''),$eventId)?1:0;
                $pdo->prepare('UPDATE billing_dunning_cases SET stripe_subscription_id=?,status=?,stage=GREATEST(stage,1),failure_count=failure_count+?,amount_due_cents=?,amount_remaining_cents=?,last_failure_at=?,grace_until=?,next_review_at=?,last_event_id=?,last_event_created_at=COALESCE(?,last_event_created_at),recovered_at=NULL,closed_at=NULL WHERE id=?')
                  ->execute([$subscriptionId,$status,$increment,$amountDue,$remaining,$now,$graceUntil,$graceUntil,$eventId,$eventCreatedAt,(int)$existing['id']]);$caseId=(int)$existing['id'];
            }else{
                $pdo->prepare("INSERT INTO billing_dunning_cases(public_id,account_id,stripe_mode,stripe_invoice_id,stripe_subscription_id,status,stage,failure_count,amount_due_cents,amount_remaining_cents,opened_at,last_failure_at,grace_until,next_review_at,last_event_id,last_event_created_at) VALUES(?,?,?,?,?,?,1,1,?,?,?,?,?,?,?,?)")
                  ->execute([ulid_like(),(int)$account['id'],$mode,$invoiceId,$subscriptionId,$status,$amountDue,$remaining,$now,$now,$graceUntil,$graceUntil,$eventId,$eventCreatedAt]);$caseId=(int)$pdo->lastInsertId();
            }
            $after=['status'=>$status,'amount_due_cents'=>$amountDue,'amount_remaining_cents'=>$remaining,'grace_until'=>$graceUntil];
            billing_operations_dunning_event($pdo,$caseId,(int)$account['id'],'stripe',str_replace('.','_',$eventType),'Stripe invoice requires payment recovery.',null,$eventId,$before,$after);
        }elseif($eventType==='invoice.marked_uncollectible'){
            if(!$existing){
                $pdo->prepare("INSERT INTO billing_dunning_cases(public_id,account_id,stripe_mode,stripe_invoice_id,stripe_subscription_id,status,stage,failure_count,amount_due_cents,amount_remaining_cents,opened_at,last_failure_at,closed_at,last_event_id,last_event_created_at) VALUES(?,?,?,?,?,'uncollectible',3,1,?,?,?,?,?,?,?)")
                  ->execute([ulid_like(),(int)$account['id'],$mode,$invoiceId,$subscriptionId,$amountDue,$remaining,$now,$now,$now,$eventId,$eventCreatedAt]);$caseId=(int)$pdo->lastInsertId();$before=null;
            }else{$caseId=(int)$existing['id'];$before=['status'=>$existing['status']];$pdo->prepare("UPDATE billing_dunning_cases SET status='uncollectible',stage=3,amount_due_cents=?,amount_remaining_cents=?,closed_at=?,next_review_at=NULL,last_event_id=?,last_event_created_at=COALESCE(?,last_event_created_at) WHERE id=?")->execute([$amountDue,$remaining,$now,$eventId,$eventCreatedAt,$caseId]);}
            billing_operations_dunning_event($pdo,$caseId,(int)$account['id'],'stripe','invoice_marked_uncollectible','Stripe marked the invoice uncollectible.',null,$eventId,$before,['status'=>'uncollectible']);
        }elseif($eventType==='invoice.voided'&&$existing){
            $before=['status'=>$existing['status'],'amount_remaining_cents'=>(int)$existing['amount_remaining_cents']];$pdo->prepare("UPDATE billing_dunning_cases SET status='closed',stage=4,amount_remaining_cents=0,closed_at=?,next_review_at=NULL,last_event_id=?,last_event_created_at=COALESCE(?,last_event_created_at) WHERE id=?")->execute([$now,$eventId,$eventCreatedAt,(int)$existing['id']]);billing_operations_dunning_event($pdo,(int)$existing['id'],(int)$account['id'],'stripe','invoice_voided','Stripe voided the invoice; dunning is closed.',null,$eventId,$before,['status'=>'closed','amount_remaining_cents'=>0]);
        }elseif($eventType==='invoice.paid'&&$existing){
            $before=['status'=>$existing['status'],'suspended_by_dunning'=>(int)$existing['suspended_by_dunning']];$pdo->prepare("UPDATE billing_dunning_cases SET status='recovered',stage=4,amount_remaining_cents=0,recovered_at=?,closed_at=?,next_review_at=NULL,last_event_id=?,last_event_created_at=COALESCE(?,last_event_created_at) WHERE id=?")->execute([$now,$now,$eventId,$eventCreatedAt,(int)$existing['id']]);
            $restored=false;if((int)$existing['suspended_by_dunning']===1&&!empty($settings['restore_after_payment'])&&!empty($existing['suspended_at'])){
                $fresh=account_admin_get($pdo,(int)$account['id']);$otherQ=$pdo->prepare("SELECT COUNT(*) FROM billing_dunning_cases WHERE account_id=? AND id<>? AND status IN ('open','action_required','grace','suspended')");$otherQ->execute([(int)$account['id'],(int)$existing['id']]);$other=(int)$otherQ->fetchColumn();
                $overrideQ=$pdo->prepare("SELECT COUNT(*) FROM account_admin_events WHERE account_id=? AND event_type='lifecycle_changed' AND created_at>=? AND reason<>'Billing dunning grace expired.'");$overrideQ->execute([(int)$account['id'],(string)$existing['suspended_at']]);$laterLifecycle=(int)$overrideQ->fetchColumn();
                if($fresh&&$fresh['status']==='suspended'&&$other===0&&$laterLifecycle===0){$pdo->prepare("UPDATE accounts SET status='active' WHERE id=? AND status='suspended'")->execute([(int)$account['id']]);$restored=true;}
            }
            billing_operations_dunning_event($pdo,(int)$existing['id'],(int)$account['id'],'stripe','payment_recovered','Stripe invoice was paid and the dunning case recovered.',null,$eventId,$before,['status'=>'recovered','account_restored'=>$restored]);
        }
        $pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}
function billing_operations_close_subscription_dunning(PDO $pdo,array $object,string $mode,?string $eventId,?string $eventCreatedAt): void {
    if(!billing_operations_ready($pdo))return;$subscriptionId=(string)($object['id']??'');$account=billing_operations_account_from_stripe_object($pdo,$object,$mode);if(!$account)return;$now=$eventCreatedAt?:gmdate('Y-m-d H:i:s');
    commercial_account_with_lock($pdo,(int)$account['id'],function()use($pdo,$subscriptionId,$account,$mode,$eventId,$eventCreatedAt,$now){
        $pdo->beginTransaction();try{$sql="SELECT * FROM billing_dunning_cases WHERE account_id=? AND stripe_mode=? AND status IN ('open','action_required','grace','suspended')";$args=[(int)$account['id'],$mode];if($subscriptionId!==''){$sql.=" AND (stripe_subscription_id=? OR stripe_subscription_id IS NULL)";$args[]=$subscriptionId;}$sql.=" FOR UPDATE";$q=$pdo->prepare($sql);$q->execute($args);foreach($q->fetchAll()?:[] as $case){if($eventCreatedAt&&!empty($case['last_event_created_at'])&&strcmp((string)$case['last_event_created_at'],$eventCreatedAt)>0)continue;$before=['status'=>$case['status'],'amount_remaining_cents'=>(int)$case['amount_remaining_cents']];$pdo->prepare("UPDATE billing_dunning_cases SET status='closed',stage=4,closed_at=?,next_review_at=NULL,last_event_id=?,last_event_created_at=COALESCE(?,last_event_created_at) WHERE id=?")->execute([$now,$eventId,$eventCreatedAt,(int)$case['id']]);billing_operations_dunning_event($pdo,(int)$case['id'],(int)$account['id'],'stripe','subscription_closed_dunning','Stripe subscription ended; open dunning was closed.',null,$eventId,$before,['status'=>'closed']);}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}
function billing_operations_handle_stripe_event(PDO $pdo,array $object,string $eventType,string $mode,?string $eventId=null,?string $eventCreatedAt=null): void {
    if(!billing_operations_ready($pdo))return;
    if(in_array($eventType,['invoice.payment_failed','invoice.payment_action_required','invoice.marked_uncollectible','invoice.paid','invoice.voided'],true))billing_operations_handle_invoice_event($pdo,$object,$eventType,$mode,$eventId,$eventCreatedAt);
    elseif($eventType==='customer.subscription.deleted'||(str_starts_with($eventType,'customer.subscription.')&&in_array((string)($object['status']??''),['canceled','unpaid','incomplete_expired'],true)))billing_operations_close_subscription_dunning($pdo,$object,$mode,$eventId,$eventCreatedAt);
    elseif($eventType==='customer.subscription.trial_will_end'){
        $account=billing_operations_account_from_stripe_object($pdo,$object,$mode);if($account)stripe_billing_event($pdo,(int)$account['id'],'stripe','trial_will_end','Stripe subscription trial is nearing its end.',$eventId,null,null,['trial_end'=>stripe_billing_datetime($object['trial_end']??null)]);
    }
}
function billing_operations_process_due_dunning(PDO $pdo,?array $admin=null): array {
    if(!billing_operations_ready($pdo))return ['reviewed'=>0,'suspended'=>0,'grace'=>0];$settings=billing_operations_settings($pdo);$reviewed=0;$suspended=0;$grace=0;
    $rows=$pdo->query("SELECT * FROM billing_dunning_cases WHERE status IN ('open','action_required','grace') AND grace_until IS NOT NULL AND grace_until<=NOW() AND (next_review_at IS NULL OR next_review_at<=NOW()) ORDER BY grace_until,id LIMIT 250")->fetchAll()?:[];
    foreach($rows as $row){commercial_account_with_lock($pdo,(int)$row['account_id'],function()use($pdo,$row,$settings,$admin,&$reviewed,&$suspended,&$grace){
        $pdo->beginTransaction();try{$q=$pdo->prepare('SELECT * FROM billing_dunning_cases WHERE id=? FOR UPDATE');$q->execute([(int)$row['id']]);$case=$q->fetch();if(!$case||!in_array($case['status'],['open','action_required','grace'],true)){$pdo->commit();return;}$reviewed++;
        $account=account_admin_get($pdo,(int)$case['account_id']);if(!$account){$pdo->commit();return;}$before=['status'=>$case['status'],'account_status'=>$account['status'],'grace_until'=>$case['grace_until']];
        if(in_array((string)$account['subscription_status'],['canceled','paused'],true)){$pdo->prepare("UPDATE billing_dunning_cases SET status='closed',stage=4,closed_at=NOW(),next_review_at=NULL WHERE id=?")->execute([(int)$case['id']]);billing_operations_dunning_event($pdo,(int)$case['id'],(int)$account['id'],$admin?'admin':'system','dunning_closed_nonbillable','Dunning review found a canceled or paused subscription and closed the case.',$admin?(int)$admin['id']:null,null,$before,['status'=>'closed']);$pdo->commit();return;}
        if(!empty($settings['suspend_after_grace'])&&$account['status']==='active'){
            $pdo->prepare("UPDATE accounts SET status='suspended' WHERE id=? AND status='active'")->execute([(int)$account['id']]);$pdo->prepare("UPDATE billing_dunning_cases SET status='suspended',stage=2,suspended_by_dunning=1,suspended_at=NOW(),next_review_at=NULL WHERE id=?")->execute([(int)$case['id']]);billing_operations_dunning_event($pdo,(int)$case['id'],(int)$account['id'],$admin?'admin':'system','dunning_suspended','Dunning grace expired and the configured policy suspended account access.',$admin?(int)$admin['id']:null,null,$before,['status'=>'suspended','account_status'=>'suspended']);if(function_exists('account_admin_event'))account_admin_event($pdo,(int)$account['id'],$admin?(int)$admin['id']:null,null,'lifecycle_changed',['status'=>'active'],['status'=>'suspended'],'Billing dunning grace expired.');$suspended++;
        }else{
            $next=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');$pdo->prepare("UPDATE billing_dunning_cases SET status='grace',stage=2,next_review_at=? WHERE id=?")->execute([$next,(int)$case['id']]);billing_operations_dunning_event($pdo,(int)$case['id'],(int)$account['id'],$admin?'admin':'system','dunning_grace_overdue','Dunning grace has expired; account access was preserved by policy.',$admin?(int)$admin['id']:null,null,$before,['status'=>'grace','next_review_at'=>$next]);$grace++;
        }$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });}
    return ['reviewed'=>$reviewed,'suspended'=>$suspended,'grace'=>$grace];
}
function billing_operations_grant_grace(PDO $pdo,array $admin,string $casePublicId,int $days,string $reason): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');$days=max(1,min(60,$days));$q=$pdo->prepare('SELECT * FROM billing_dunning_cases WHERE public_id=? LIMIT 1');$q->execute([trim($casePublicId)]);$seed=$q->fetch();if(!$seed)throw new RuntimeException('Dunning case not found.');
    return commercial_account_with_lock($pdo,(int)$seed['account_id'],function()use($pdo,$admin,$seed,$days,$reason){
        $pdo->beginTransaction();try{$q=$pdo->prepare('SELECT * FROM billing_dunning_cases WHERE id=? FOR UPDATE');$q->execute([(int)$seed['id']]);$case=$q->fetch()?:throw new RuntimeException('Dunning case not found.');if(in_array($case['status'],['recovered','uncollectible','closed'],true))throw new RuntimeException('Closed dunning cases cannot receive grace.');
        $base=max(time(),!empty($case['grace_until'])?strtotime((string)$case['grace_until']):0);$until=gmdate('Y-m-d H:i:s',$base+$days*86400);$account=account_admin_get($pdo,(int)$case['account_id'])??throw new RuntimeException('Account not found.');$restored=false;
        if($case['status']==='suspended'&&(int)$case['suspended_by_dunning']===1&&$account['status']==='suspended'&&!empty($case['suspended_at'])){$overrideQ=$pdo->prepare("SELECT COUNT(*) FROM account_admin_events WHERE account_id=? AND event_type='lifecycle_changed' AND created_at>=? AND reason<>'Billing dunning grace expired.'");$overrideQ->execute([(int)$account['id'],(string)$case['suspended_at']]);if((int)$overrideQ->fetchColumn()===0){$pdo->prepare("UPDATE accounts SET status='active' WHERE id=?")->execute([(int)$account['id']]);$restored=true;}}
            $pdo->prepare("UPDATE billing_dunning_cases SET status='grace',stage=2,grace_until=?,next_review_at=?,suspended_by_dunning=0,suspended_at=NULL WHERE id=?")->execute([$until,$until,(int)$case['id']]);billing_operations_dunning_event($pdo,(int)$case['id'],(int)$case['account_id'],'admin','grace_granted',$reason,(int)$admin['id'],null,['status'=>$case['status'],'grace_until'=>$case['grace_until']],['status'=>'grace','grace_until'=>$until,'account_restored'=>$restored]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        $q=$pdo->prepare('SELECT * FROM billing_dunning_cases WHERE id=?');$q->execute([(int)$case['id']]);return $q->fetch();
    });
}
function billing_operations_add_note(PDO $pdo,array $admin,int|string $accountId,string $type,string $body): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');$account=account_admin_get($pdo,$accountId)??throw new RuntimeException('Account not found.');$type=in_array($type,['general','dunning','trial','cancellation','dispute'],true)?$type:'general';$body=trim($body);if($body==='')throw new InvalidArgumentException('Billing note cannot be empty.');$body=mb_substr($body,0,8000);
    $pdo->prepare('INSERT INTO billing_notes(public_id,account_id,actor_user_id,note_type,body) VALUES(?,?,?,?,?)')->execute([ulid_like(),(int)$account['id'],(int)$admin['id'],$type,$body]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM billing_notes WHERE id=?');$q->execute([$id]);return $q->fetch();
}
function billing_operations_notes(PDO $pdo,int $accountId,int $limit=100): array {
    if(!billing_operations_ready($pdo))return [];$limit=max(1,min(300,$limit));$q=$pdo->prepare("SELECT n.*,u.username actor_username FROM billing_notes n LEFT JOIN users u ON u.id=n.actor_user_id WHERE n.account_id=? ORDER BY n.created_at DESC,n.id DESC LIMIT ".$limit);$q->execute([$accountId]);return $q->fetchAll()?:[];
}
function billing_operations_record_cancellation_feedback(PDO $pdo,?array $actor,int|string $accountId,string $source,string $reasonCode,?string $feedback,bool $winBack=true): array {
    if(!billing_operations_ready($pdo))throw new RuntimeException('Billing operations require the latest database upgrade.');$account=account_admin_get($pdo,$accountId)??throw new RuntimeException('Account not found.');$source=in_array($source,['customer','admin','system'],true)?$source:'customer';$reasons=['price','missing_features','not_using','temporary','support','competitor','business_closed','other'];$reasonCode=in_array($reasonCode,$reasons,true)?$reasonCode:'other';$feedback=trim((string)$feedback);$sub=stripe_billing_current_subscription($pdo,(int)$account['id']);
    $pdo->prepare('INSERT INTO billing_cancellation_feedback(public_id,account_id,stripe_subscription_id,source,actor_user_id,reason_code,feedback_text,effective_at,win_back_eligible) VALUES(?,?,?,?,?,?,?,?,?)')
      ->execute([ulid_like(),(int)$account['id'],$sub['stripe_subscription_id']??null,$source,$actor?(int)$actor['id']:null,$reasonCode,$feedback!==''?mb_substr($feedback,0,2000):null,!empty($sub['current_period_end'])?$sub['current_period_end']:null,$winBack?1:0]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM billing_cancellation_feedback WHERE id=?');$q->execute([$id]);return $q->fetch();
}
function billing_operations_feedback(PDO $pdo,?int $accountId=null,int $limit=200): array {
    if(!billing_operations_ready($pdo))return [];$limit=max(1,min(500,$limit));$sql="SELECT f.*,a.public_id account_public_id,a.name account_name,u.username actor_username FROM billing_cancellation_feedback f JOIN accounts a ON a.id=f.account_id LEFT JOIN users u ON u.id=f.actor_user_id";$args=[];if($accountId!==null){$sql.=' WHERE f.account_id=?';$args[]=$accountId;}$sql.=" ORDER BY f.created_at DESC,f.id DESC LIMIT ".$limit;$q=$pdo->prepare($sql);$q->execute($args);return $q->fetchAll()?:[];
}
function billing_operations_extend_trial(PDO $pdo,array $config,array $admin,int|string $accountId,int $days,string $reason): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');$days=max(1,min(90,$days));$account=account_admin_get($pdo,$accountId)??throw new RuntimeException('Account not found.');$settings=stripe_billing_settings($pdo);$sub=stripe_billing_current_subscription($pdo,(int)$account['id'],(string)$settings['mode']);if(!$sub||$sub['status']!=='trialing')throw new RuntimeException('The account does not have a trialing Stripe subscription.');
    $base=max(time(),!empty($sub['trial_end'])?strtotime((string)$sub['trial_end']):0);$trialEnd=$base+$days*86400;$remote=stripe_billing_api_request($config,$settings,'POST','subscriptions/'.rawurlencode((string)$sub['stripe_subscription_id']),['trial_end'=>$trialEnd]);
    stripe_billing_sync_subscription($pdo,$remote,(string)$settings['mode'],null,null);billing_operations_add_note($pdo,$admin,(int)$account['id'],'trial',$reason.' Extended trial by '.$days.' day(s) to '.gmdate('Y-m-d H:i:s',$trialEnd).' UTC.');
    return $remote;
}
function billing_operations_resync_account(PDO $pdo,array $config,array $admin,int|string $accountId): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');$account=account_admin_get($pdo,$accountId)??throw new RuntimeException('Account not found.');$settings=stripe_billing_settings($pdo);$mode=(string)$settings['mode'];$sub=stripe_billing_current_subscription($pdo,(int)$account['id'],$mode);if(!$sub)throw new RuntimeException('No Stripe subscription is linked to this account in the selected mode.');
    $remote=stripe_billing_api_request($config,$settings,'GET','subscriptions/'.rawurlencode((string)$sub['stripe_subscription_id']));stripe_billing_sync_subscription($pdo,$remote,$mode,null,null);billing_operations_add_note($pdo,$admin,(int)$account['id'],'general','Administrator explicitly resynced the current subscription from Stripe without advancing webhook ordering.');
    return account_admin_get($pdo,(int)$account['id'])??$account;
}
function billing_operations_price(PDO $pdo,?int $packageId): int {
    if(!$packageId)return 0;$q=$pdo->prepare('SELECT monthly_price_cents FROM subscription_packages WHERE id=?');$q->execute([$packageId]);return (int)($q->fetchColumn()?:0);
}
function billing_operations_recurring_rows(PDO $pdo,string $mode,bool $includeClosed=false): array {
    $sql="SELECT a.*,p.public_id package_public_id,p.name package_name,p.monthly_price_cents package_price_cents,
      ss.stripe_subscription_id,ss.stripe_price_id,ss.status stripe_status,ss.cancel_at_period_end,ss.current_period_end stripe_period_end,ss.trial_end stripe_trial_end,
      spp.unit_amount_cents stripe_price_cents,spp.currency stripe_currency
      FROM accounts a JOIN subscription_packages p ON p.id=a.package_id
      LEFT JOIN stripe_subscriptions ss ON ss.id=(SELECT sx.id FROM stripe_subscriptions sx WHERE sx.account_id=a.id AND sx.mode=? ORDER BY sx.updated_at DESC,sx.id DESC LIMIT 1)
      LEFT JOIN stripe_package_prices spp ON spp.mode=? AND spp.stripe_price_id=ss.stripe_price_id";
    if(!$includeClosed)$sql.=" WHERE a.status<>'closed'";$sql.=" ORDER BY a.id";$q=$pdo->prepare($sql);$q->execute([$mode,$mode]);$rows=$q->fetchAll()?:[];
    foreach($rows as &$row){
        $source=(string)($row['billing_source']??'manual');$visible=true;$status=(string)$row['subscription_status'];$price=0;
        if($source==='stripe'){
            if(empty($row['stripe_subscription_id']))$visible=false;
            else{$status=stripe_billing_subscription_status((string)$row['stripe_status']);$price=$row['stripe_price_cents']===null?0:(int)$row['stripe_price_cents'];}
        }elseif($source==='manual')$price=(int)$row['package_price_cents'];
        else $price=0;
        $row['billing_visible']=$visible;$row['effective_subscription_status']=$status;$row['recurring_price_cents']=$price;
    }unset($row);return $rows;
}
function billing_operations_row_mrr(array $row): int {
    if(empty($row['billing_visible'])||($row['status']??'closed')==='closed')return 0;$status=(string)($row['effective_subscription_status']??$row['subscription_status']??'');
    return in_array($status,['active','past_due'],true)?max(0,(int)($row['recurring_price_cents']??$row['monthly_price_cents']??0)):0;
}
function billing_operations_current_metrics(PDO $pdo,?string $mode=null): array {
    if(!billing_operations_ready($pdo))return [];$mode=$mode?:((string)(stripe_billing_settings($pdo)['mode']??'test'));$mrr=0;$counts=['active'=>0,'trialing'=>0,'past_due'=>0,'paused'=>0,'canceled'=>0];
    foreach(billing_operations_recurring_rows($pdo,$mode,true) as $row){if(empty($row['billing_visible']))continue;$source=(string)$row['billing_source'];if(!in_array($source,['stripe','manual'],true))continue;$status=(string)$row['effective_subscription_status'];if(($row['status']??'closed')==='closed'){if($status==='canceled')$counts['canceled']++;continue;}if(isset($counts[$status]))$counts[$status]++;$mrr+=billing_operations_row_mrr($row);}
    $q=$pdo->prepare("SELECT COALESCE(SUM(amount_paid_cents),0) FROM stripe_invoices WHERE mode=? AND status='paid' AND paid_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)");$q->execute([$mode]);$collected=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT COUNT(*) FROM billing_dunning_cases WHERE stripe_mode=? AND status IN ('open','action_required','grace','suspended')");$q->execute([$mode]);$open=(int)$q->fetchColumn();
    return ['mode'=>$mode,'mrr_cents'=>$mrr,'arr_cents'=>$mrr*12,'active_subscriptions'=>$counts['active'],'trialing_subscriptions'=>$counts['trialing'],'past_due_subscriptions'=>$counts['past_due'],'paused_subscriptions'=>$counts['paused'],'canceled_subscriptions'=>$counts['canceled'],'collected_30d_cents'=>$collected,'open_dunning_cases'=>$open];
}
function billing_operations_revenue_by_package(PDO $pdo,?string $mode=null): array {
    if(!billing_operations_ready($pdo))return [];$mode=$mode?:((string)(stripe_billing_settings($pdo)['mode']??'test'));$packages=subscription_packages($pdo,false);$out=[];foreach($packages as $p)$out[(int)$p['id']]=['package_public_id'=>$p['public_id'],'package_name'=>$p['name'],'monthly_price_cents'=>(int)$p['monthly_price_cents'],'billable_accounts'=>0,'mrr_cents'=>0,'trialing_accounts'=>0,'past_due_accounts'=>0];
    foreach(billing_operations_recurring_rows($pdo,$mode,true) as $row){if(empty($row['billing_visible'])||($row['status']??'closed')==='closed')continue;$pid=(int)$row['package_id'];if(!isset($out[$pid]))continue;$status=(string)$row['effective_subscription_status'];$mrr=billing_operations_row_mrr($row);if($mrr>0){$out[$pid]['billable_accounts']++;$out[$pid]['mrr_cents']+=$mrr;}if($status==='trialing')$out[$pid]['trialing_accounts']++;if($status==='past_due')$out[$pid]['past_due_accounts']++;}
    $rows=array_values($out);usort($rows,fn($a,$b)=>($b['mrr_cents']<=>$a['mrr_cents'])?:strcmp((string)$a['package_name'],(string)$b['package_name']));return $rows;
}
function billing_operations_snapshot_day(PDO $pdo,?string $date=null,?string $mode=null): array {
    if(!billing_operations_ready($pdo))throw new RuntimeException('Billing operations require the latest database upgrade.');$date=$date?:gmdate('Y-m-d');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new InvalidArgumentException('Snapshot date must be YYYY-MM-DD.');$mode=$mode?:((string)(stripe_billing_settings($pdo)['mode']??'test'));$start=$date.' 00:00:00';$end=gmdate('Y-m-d H:i:s',strtotime($date.' 00:00:00 UTC')+86400);$metrics=billing_operations_current_metrics($pdo,$mode);
    $current=[];foreach(billing_operations_recurring_rows($pdo,$mode,false) as $row)if(!empty($row['billing_visible'])||$row['billing_source']!=='stripe')$current[(int)$row['id']]=$row;
    $prevDateQ=$pdo->prepare('SELECT MAX(snapshot_date) FROM billing_account_snapshots WHERE stripe_mode=? AND snapshot_date<?');$prevDateQ->execute([$mode,$date]);$prevDate=$prevDateQ->fetchColumn()?:null;$previous=[];
    if($prevDate){$q=$pdo->prepare('SELECT * FROM billing_account_snapshots WHERE stripe_mode=? AND snapshot_date=?');$q->execute([$mode,$prevDate]);foreach($q->fetchAll()?:[] as $row)$previous[(int)$row['account_id']]=$row;}
    $new=$expansion=$contraction=$churn=$recovered=0;$recoveredAccounts=[];if($prevDate){$ids=array_unique(array_merge(array_keys($current),array_keys($previous)));foreach($ids as $id){$cur=$current[$id]??null;$prev=$previous[$id]??null;$curMrr=$cur?billing_operations_row_mrr($cur):0;$prevMrr=0;if($prev&&($prev['account_status']??'closed')!=='closed'&&in_array((string)$prev['subscription_status'],['active','past_due'],true))$prevMrr=(int)$prev['monthly_price_cents'];if($prevMrr===0&&$curMrr>0)$new+=$curMrr;elseif($curMrr>$prevMrr&&$prevMrr>0)$expansion+=($curMrr-$prevMrr);elseif($prevMrr>$curMrr&&$curMrr>0)$contraction+=($prevMrr-$curMrr);elseif($prevMrr>0&&$curMrr===0)$churn+=$prevMrr;if($prev&&$cur&&$prev['subscription_status']==='past_due'&&$cur['effective_subscription_status']==='active'){$recovered+=$curMrr;$recoveredAccounts[(int)$id]=true;}}}
    $rq=$pdo->prepare("SELECT DISTINCT e.account_id FROM billing_dunning_events e JOIN billing_dunning_cases d ON d.id=e.case_id WHERE d.stripe_mode=? AND e.event_type='payment_recovered' AND e.created_at>=? AND e.created_at<?");$rq->execute([$mode,$start,$end]);foreach($rq->fetchAll(PDO::FETCH_COLUMN)?:[] as $rid){$rid=(int)$rid;if(isset($recoveredAccounts[$rid])||!isset($current[$rid]))continue;$amount=billing_operations_row_mrr($current[$rid]);if($amount>0){$recovered+=$amount;$recoveredAccounts[$rid]=true;}}
    $gross=$failed=$refunded=$disputed=0;$q=$pdo->prepare("SELECT e.* FROM account_billing_events e WHERE e.created_at>=? AND e.created_at<? AND (e.source<>'stripe' OR e.stripe_mode=?) ORDER BY e.id");$q->execute([$start,$end,$mode]);foreach($q->fetchAll()?:[] as $e){$before=json_decode((string)($e['before_json']??''),true)?:[];$after=json_decode((string)($e['after_json']??''),true)?:[];$type=(string)$e['event_type'];if($type==='invoice_paid')$gross+=(int)($after['amount_paid_cents']??0);elseif(in_array($type,['invoice_payment_failed','invoice_payment_action_required'],true))$failed+=(int)($before['amount_due_cents']??0);elseif($type==='charge_refunded')$refunded+=(int)($after['amount_cents']??0);elseif($type==='charge_dispute_created')$disputed+=(int)($after['amount_cents']??0);}
    $q=$pdo->prepare("SELECT COUNT(*) FROM billing_dunning_cases WHERE stripe_mode=? AND status IN ('open','action_required','grace','suspended')");$q->execute([$mode]);$open=(int)$q->fetchColumn();$over=function_exists('stripe_billing_over_capacity_account_ids')?count(stripe_billing_over_capacity_account_ids($pdo)):0;
    $pdo->beginTransaction();try{
        $pdo->prepare("INSERT INTO billing_daily_snapshots(public_id,snapshot_date,stripe_mode,mrr_cents,arr_cents,active_subscriptions,trialing_subscriptions,past_due_subscriptions,paused_subscriptions,canceled_subscriptions,new_mrr_cents,expansion_mrr_cents,contraction_mrr_cents,churned_mrr_cents,recovered_mrr_cents,gross_collected_cents,failed_amount_cents,refunded_amount_cents,disputed_amount_cents,open_dunning_cases,over_capacity_accounts)
          VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE mrr_cents=VALUES(mrr_cents),arr_cents=VALUES(arr_cents),active_subscriptions=VALUES(active_subscriptions),trialing_subscriptions=VALUES(trialing_subscriptions),past_due_subscriptions=VALUES(past_due_subscriptions),paused_subscriptions=VALUES(paused_subscriptions),canceled_subscriptions=VALUES(canceled_subscriptions),new_mrr_cents=VALUES(new_mrr_cents),expansion_mrr_cents=VALUES(expansion_mrr_cents),contraction_mrr_cents=VALUES(contraction_mrr_cents),churned_mrr_cents=VALUES(churned_mrr_cents),recovered_mrr_cents=VALUES(recovered_mrr_cents),gross_collected_cents=VALUES(gross_collected_cents),failed_amount_cents=VALUES(failed_amount_cents),refunded_amount_cents=VALUES(refunded_amount_cents),disputed_amount_cents=VALUES(disputed_amount_cents),open_dunning_cases=VALUES(open_dunning_cases),over_capacity_accounts=VALUES(over_capacity_accounts),generated_at=NOW()")
          ->execute([ulid_like(),$date,$mode,$metrics['mrr_cents'],$metrics['arr_cents'],$metrics['active_subscriptions'],$metrics['trialing_subscriptions'],$metrics['past_due_subscriptions'],$metrics['paused_subscriptions'],$metrics['canceled_subscriptions'],$new,$expansion,$contraction,$churn,$recovered,$gross,$failed,$refunded,$disputed,$open,$over]);
        $up=$pdo->prepare("INSERT INTO billing_account_snapshots(public_id,snapshot_date,stripe_mode,account_id,package_id,billing_source,account_status,subscription_status,monthly_price_cents,member_count,seat_limit,pending_reserved_seats,trial_ends_at,period_end,stripe_subscription_id,cancel_at_period_end,dunning_status)
          VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE package_id=VALUES(package_id),billing_source=VALUES(billing_source),account_status=VALUES(account_status),subscription_status=VALUES(subscription_status),monthly_price_cents=VALUES(monthly_price_cents),member_count=VALUES(member_count),seat_limit=VALUES(seat_limit),pending_reserved_seats=VALUES(pending_reserved_seats),trial_ends_at=VALUES(trial_ends_at),period_end=VALUES(period_end),stripe_subscription_id=VALUES(stripe_subscription_id),cancel_at_period_end=VALUES(cancel_at_period_end),dunning_status=VALUES(dunning_status),generated_at=NOW()");
        foreach($current as $a){$seat=function_exists('account_membership_ready')&&account_membership_ready($pdo)?account_membership_seat_summary($pdo,(int)$a['id']):null;$cases=billing_operations_open_dunning_for_account($pdo,(int)$a['id']);$d=$cases?(string)$cases[0]['status']:null;$trial=$a['billing_source']==='stripe'?($a['stripe_trial_end']??null):($a['trial_ends_at']??null);$periodEnd=$a['billing_source']==='stripe'?($a['stripe_period_end']??null):($a['period_end']??null);$up->execute([ulid_like(),$date,$mode,(int)$a['id'],(int)$a['package_id'],(string)$a['billing_source'],(string)$a['status'],(string)$a['effective_subscription_status'],(int)$a['recurring_price_cents'],$seat?(int)$seat['member_count']:0,$seat?(int)$seat['member_limit']:0,$seat?(int)$seat['pending_reserved']:0,$trial,$periodEnd,$a['stripe_subscription_id']??null,(int)($a['cancel_at_period_end']??0),$d]);}
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $q=$pdo->prepare('SELECT * FROM billing_daily_snapshots WHERE stripe_mode=? AND snapshot_date=?');$q->execute([$mode,$date]);return $q->fetch()?:[];
}
function billing_operations_daily_snapshots(PDO $pdo,int $days=60): array {
    if(!billing_operations_ready($pdo))return [];$days=max(1,min(365,$days));$mode=(string)(stripe_billing_settings($pdo)['mode']??'test');$q=$pdo->prepare('SELECT * FROM billing_daily_snapshots WHERE stripe_mode=? ORDER BY snapshot_date DESC,id DESC LIMIT '.$days);$q->execute([$mode]);return $q->fetchAll()?:[];
}
function billing_operations_dunning_cases(PDO $pdo,int $limit=250): array {
    if(!billing_operations_ready($pdo))return [];$limit=max(1,min(500,$limit));$mode=(string)(stripe_billing_settings($pdo)['mode']??'test');$q=$pdo->prepare("SELECT d.*,a.public_id account_public_id,a.name account_name,p.name package_name FROM billing_dunning_cases d JOIN accounts a ON a.id=d.account_id JOIN subscription_packages p ON p.id=a.package_id WHERE d.stripe_mode=? ORDER BY FIELD(d.status,'suspended','action_required','open','grace','uncollectible','recovered','closed'),d.updated_at DESC,d.id DESC LIMIT ".$limit);$q->execute([$mode]);return $q->fetchAll()?:[];
}
function billing_operations_trial_accounts(PDO $pdo): array {
    if(!billing_operations_ready($pdo))return [];$mode=(string)(stripe_billing_settings($pdo)['mode']??'test');$days=(int)billing_operations_settings($pdo)['trial_reminder_days'];$q=$pdo->prepare("SELECT s.*,a.public_id account_public_id,a.name account_name,p.name package_name FROM stripe_subscriptions s JOIN accounts a ON a.id=s.account_id JOIN subscription_packages p ON p.id=a.package_id WHERE s.mode=? AND a.status<>'closed' AND s.status='trialing' AND s.trial_end IS NOT NULL AND s.trial_end<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? DAY) ORDER BY s.trial_end,a.id");$q->execute([$mode,$days]);return $q->fetchAll()?:[];
}
function billing_operations_alerts(PDO $pdo,int $limit=100): array {
    if(!billing_operations_ready($pdo))return [];$alerts=[];$mode=(string)(stripe_billing_settings($pdo)['mode']??'test');
    $q=$pdo->prepare("SELECT d.*,a.public_id account_public_id,a.name account_name FROM billing_dunning_cases d JOIN accounts a ON a.id=d.account_id WHERE d.stripe_mode=? AND d.status IN ('open','action_required','grace','suspended') ORDER BY d.grace_until,d.id LIMIT 30");$q->execute([$mode]);foreach($q->fetchAll()?:[] as $d)$alerts[]=['severity'=>$d['status']==='suspended'?'danger':'warn','type'=>'dunning','label'=>$d['account_name'].' — '.$d['status'],'detail'=>'Invoice '.$d['stripe_invoice_id'].' · grace '.($d['grace_until']?:'not set'),'account_public_id'=>$d['account_public_id']];
    foreach(billing_operations_trial_accounts($pdo) as $t)$alerts[]=['severity'=>'warn','type'=>'trial','label'=>$t['account_name'].' trial ending','detail'=>(string)$t['trial_end'],'account_public_id'=>$t['account_public_id']];
    $q=$pdo->prepare("SELECT p.public_id,p.name FROM subscription_packages p LEFT JOIN stripe_package_prices spp ON spp.package_id=p.id AND spp.mode=? AND spp.active=1 WHERE p.status='active' AND p.monthly_price_cents>0 AND spp.id IS NULL ORDER BY p.name");$q->execute([$mode]);foreach($q->fetchAll()?:[] as $p)$alerts[]=['severity'=>'danger','type'=>'mapping','label'=>$p['name'].' has no Stripe Price','detail'=>'Paid package is not mapped in '.$mode.' mode.','account_public_id'=>null];
    foreach(array_slice(stripe_billing_recent_webhooks($pdo,100),0,100) as $w)if($w['status']==='failed')$alerts[]=['severity'=>'danger','type'=>'webhook','label'=>'Failed Stripe webhook '.$w['event_type'],'detail'=>(string)($w['error_text']??$w['stripe_event_id']),'account_public_id'=>null];
    foreach(stripe_billing_over_capacity_account_ids($pdo) as $id){$a=account_admin_get($pdo,(int)$id);if($a)$alerts[]=['severity'=>'warn','type'=>'capacity','label'=>$a['name'].' is over seat capacity','detail'=>'Resolve seats or package capacity.','account_public_id'=>$a['public_id']];}
    $openDisputes=[];$q=$pdo->query("SELECT e.*,a.public_id account_public_id,a.name account_name FROM account_billing_events e JOIN accounts a ON a.id=e.account_id WHERE e.event_type IN ('charge_dispute_created','charge_dispute_closed') ORDER BY e.created_at DESC,e.id DESC LIMIT 500");foreach($q->fetchAll()?:[] as $e){$after=json_decode((string)($e['after_json']??''),true)?:[];$key=(string)($after['stripe_object_id']??'');if($key===''||isset($openDisputes[$key]))continue;$openDisputes[$key]=$e['event_type']==='charge_dispute_created'?$e:false;}foreach($openDisputes as $e)if($e)$alerts[]=['severity'=>'danger','type'=>'dispute','label'=>$e['account_name'].' has an unresolved dispute','detail'=>(string)$e['created_at'],'account_public_id'=>$e['account_public_id']];
    return array_slice($alerts,0,max(1,min(300,$limit)));
}
function billing_operations_close_account_dunning(PDO $pdo,int $accountId,string $reason,?int $actorUserId=null): int {
    if(!billing_operations_ready($pdo))return 0;$q=$pdo->prepare("SELECT * FROM billing_dunning_cases WHERE account_id=? AND status IN ('open','action_required','grace','suspended') ORDER BY id");$q->execute([$accountId]);$rows=$q->fetchAll()?:[];$closed=0;$now=gmdate('Y-m-d H:i:s');
    foreach($rows as $case){$before=['status'=>$case['status']];$pdo->prepare("UPDATE billing_dunning_cases SET status='closed',closed_at=?,next_review_at=NULL,suspended_by_dunning=0,suspended_at=NULL WHERE id=?")->execute([$now,(int)$case['id']]);billing_operations_dunning_event($pdo,(int)$case['id'],$accountId,$actorUserId?'admin':'system','account_closed',$reason,$actorUserId,null,$before,['status'=>'closed']);$closed++;}
    return $closed;
}
function billing_operations_account_timeline(PDO $pdo,int $accountId,int $limit=150): array {
    if(!billing_operations_ready($pdo))return [];$limit=max(1,min(400,$limit));$sql="SELECT created_at,'billing' source,event_type title,reason detail FROM account_billing_events WHERE account_id=?
      UNION ALL SELECT created_at,'dunning' source,event_type title,reason detail FROM billing_dunning_events WHERE account_id=?
      UNION ALL SELECT created_at,'note' source,CONCAT('note_',note_type) title,body detail FROM billing_notes WHERE account_id=?
      UNION ALL SELECT created_at,'cancellation' source,CONCAT('cancellation_',reason_code) title,COALESCE(feedback_text,'') detail FROM billing_cancellation_feedback WHERE account_id=?
      ORDER BY created_at DESC LIMIT ".$limit;$q=$pdo->prepare($sql);$q->execute([$accountId,$accountId,$accountId,$accountId]);return $q->fetchAll()?:[];
}
function billing_operations_agent_context(PDO $pdo,array $viewer): string {
    if(($viewer['role']??'')!=='admin'||!billing_operations_ready($pdo))return '';$m=billing_operations_current_metrics($pdo);$alerts=billing_operations_alerts($pdo,12);
    $lines=["[ADMIN BILLING OPERATIONS — READ ONLY]\nFinancial mutations are never autonomous. Do not claim billing, dunning, trial, package, payment, refund or account actions were executed. Direct explicit changes to Admin billing surfaces."];
    $lines[]='Current MRR $'.number_format(((int)$m['mrr_cents'])/100,2).' · ARR $'.number_format(((int)$m['arr_cents'])/100,2).' · active '.$m['active_subscriptions'].' · trialing '.$m['trialing_subscriptions'].' · past due '.$m['past_due_subscriptions'].' · open dunning '.$m['open_dunning_cases'].' · collected last 30d $'.number_format(((int)$m['collected_30d_cents'])/100,2).'.';
    foreach($alerts as $a)$lines[]='Alert: '.$a['label'].' — '.$a['detail'];
    return implode("\n",$lines);
}
