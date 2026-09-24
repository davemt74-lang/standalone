<?php
declare(strict_types=1);

function admin_ops_ready(PDO $pdo): bool {
    try{
        foreach(['admin_operator_profiles','admin_operations_alerts','admin_saved_views','admin_action_records'] as $table)if(!installer_table_exists($pdo,$table))return false;
        return true;
    }catch(Throwable $e){return false;}
}
function admin_ops_require_admin(array $admin): void {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
}
function admin_ops_reason(string $reason): string {
    $reason=trim($reason);if($reason==='')throw new InvalidArgumentException('A reason is required.');return mb_substr($reason,0,500);
}
function admin_ops_operator_profile(PDO $pdo,array $admin): array {
    admin_ops_require_admin($admin);
    if(function_exists('admin_access_ready')&&admin_access_ready($pdo))return admin_access_profile($pdo,$admin);
    $fallback=['user_id'=>(int)$admin['id'],'role_key'=>'super_admin','capabilities_json'=>'["admin.*"]','status'=>'active'];
    if(!admin_ops_ready($pdo))return $fallback;
    $q=$pdo->prepare('SELECT * FROM admin_operator_profiles WHERE user_id=? LIMIT 1');$q->execute([(int)$admin['id']]);$row=$q->fetch();
    if($row)return $row;
    $pdo->prepare("INSERT INTO admin_operator_profiles(user_id,role_key,capabilities_json,status) VALUES(?,'super_admin',?,'active')")->execute([(int)$admin['id'],json_encode(['admin.*'],JSON_UNESCAPED_SLASHES)]);
    $q->execute([(int)$admin['id']]);return $q->fetch()?:$fallback;
}
function admin_ops_capabilities(array $profile): array {
    $raw=$profile['capabilities_json']??[];if(is_array($raw))return $raw;
    try{$decoded=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);return is_array($decoded)?array_values(array_filter($decoded,'is_string')):[];}catch(Throwable $e){return [];}
}
function admin_ops_has_capability(array $profile,string $capability): bool {
    if(($profile['status']??'disabled')!=='active')return false;
    foreach(admin_ops_capabilities($profile) as $cap){if($cap==='admin.*'||$cap===$capability)return true;if(str_ends_with($cap,'.*')&&str_starts_with($capability,substr($cap,0,-1)))return true;}return false;
}
function admin_ops_account_id(PDO $pdo,?string $publicId): ?int {
    $publicId=trim((string)$publicId);if($publicId==='')return null;$q=$pdo->prepare('SELECT id FROM accounts WHERE public_id=? LIMIT 1');$q->execute([$publicId]);$id=$q->fetchColumn();return $id===false?null:(int)$id;
}
function admin_ops_alert_fingerprint(string $sourceType,?string $sourceId,?string $accountPublicId,string $title): string {
    return hash('sha256',implode('|',[$sourceType,(string)$sourceId,(string)$accountPublicId,$title]));
}
function admin_ops_emit_alert(PDO $pdo,string $scanToken,array $alert): void {
    $severityRaw=(string)($alert['severity']??'warn');$severity=in_array($severityRaw,['info','warn','danger'],true)?$severityRaw:'warn';
    $category=mb_substr(trim((string)($alert['category']??'operations')),0,80);if($category==='')$category='operations';
    $sourceType=mb_substr(trim((string)($alert['source_type']??$category)),0,80);if($sourceType==='')$sourceType=$category;
    $sourceId=trim((string)($alert['source_public_id']??''));$sourceId=$sourceId===''?null:mb_substr($sourceId,0,255);
    $accountPublic=trim((string)($alert['account_public_id']??''));$accountId=$accountPublic!==''?admin_ops_account_id($pdo,$accountPublic):($alert['account_id']??null);$accountId=$accountId===null?null:(int)$accountId;
    $title=mb_substr(trim((string)($alert['title']??'Operational issue')),0,255);$detail=trim((string)($alert['detail']??''));$url=trim((string)($alert['target_url']??''));$url=$url===''?null:mb_substr($url,0,500);
    $fingerprint=admin_ops_alert_fingerprint($sourceType,$sourceId,$accountPublic,$title);
    $sql="INSERT INTO admin_operations_alerts(public_id,fingerprint,account_id,severity,category,title,detail,source_type,source_public_id,target_url,status,last_scan_token)
      VALUES(?,?,?,?,?,?,?,?,?,?,'open',?)
      ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),severity=VALUES(severity),category=VALUES(category),title=VALUES(title),detail=VALUES(detail),source_type=VALUES(source_type),source_public_id=VALUES(source_public_id),target_url=VALUES(target_url),last_seen_at=NOW(),last_scan_token=VALUES(last_scan_token),resolved_at=CASE WHEN status='resolved' THEN NULL ELSE resolved_at END,resolution_note=CASE WHEN status='resolved' THEN NULL ELSE resolution_note END,status=CASE WHEN status='resolved' OR (status='snoozed' AND snoozed_until IS NOT NULL AND snoozed_until<=NOW()) THEN 'open' ELSE status END";
    $pdo->prepare($sql)->execute([ulid_like(),$fingerprint,$accountId,$severity,$category,$title,$detail!==''?$detail:null,$sourceType,$sourceId,$url,$scanToken]);
}
function admin_ops_refresh_alerts(PDO $pdo): array {
    if(!admin_ops_ready($pdo))return ['scan_token'=>null,'observed'=>0,'auto_resolved'=>0];$scan=ulid_like();$seen=0;
    $emit=function(array $a)use($pdo,$scan,&$seen){admin_ops_emit_alert($pdo,$scan,$a);$seen++;};
    if(function_exists('billing_operations_ready')&&billing_operations_ready($pdo)){
        foreach(billing_operations_alerts($pdo,300) as $a){
            $accountPublic=(string)($a['account_public_id']??'');$type=(string)($a['type']??'billing');
            $emit(['severity'=>$a['severity']??'warn','category'=>'billing','title'=>(string)($a['label']??'Billing issue'),'detail'=>(string)($a['detail']??''),'source_type'=>'billing_'.$type,'source_public_id'=>$accountPublic!==''?$accountPublic:(string)($a['detail']??$a['label']??''),'account_public_id'=>$accountPublic,'target_url'=>$accountPublic!==''?'/admin/account.php?id='.rawurlencode($accountPublic):'/admin/billing-analytics.php']);
        }
    }
    if(function_exists('ai_usage_ready')&&ai_usage_ready($pdo)){
        foreach(ai_usage_admin_accounts($pdo,500) as $row){$usage=$row['usage']??[];if(($usage['effective_allowance']??null)!==null&&(int)($usage['remaining_tokens']??1)<=0){$public=(string)($row['public_id']??$usage['account']['public_id']??'');$emit(['severity'=>'warn','category'=>'ai_usage','title'=>(string)($row['name']??'Account').' exhausted AI allowance','detail'=>'Current-period token allowance is exhausted.','source_type'=>'ai_allowance_exhausted','source_public_id'=>$public,'account_public_id'=>$public,'target_url'=>$public!==''?'/admin/account.php?id='.rawurlencode($public):'/admin/usage.php']);}}
    }
    if(function_exists('ai_overage_ready')&&ai_overage_ready($pdo)&&installer_table_exists($pdo,'ai_overage_report_batches')){
        $q=$pdo->query("SELECT b.public_id,b.last_error,a.public_id account_public_id,a.name account_name FROM ai_overage_report_batches b JOIN accounts a ON a.id=b.account_id WHERE b.status='failed' ORDER BY b.updated_at DESC,b.id DESC LIMIT 200");
        foreach($q->fetchAll()?:[] as $b)$emit(['severity'=>'danger','category'=>'ai_overage','title'=>$b['account_name'].' has failed AI overage reporting','detail'=>(string)($b['last_error']??''),'source_type'=>'ai_overage_failed_batch','source_public_id'=>(string)$b['public_id'],'account_public_id'=>(string)$b['account_public_id'],'target_url'=>'/admin/overage-billing.php']);
    }
    if(function_exists('commercial_billing_ready')&&commercial_billing_ready($pdo)){
        $mode=commercial_billing_mode($pdo);
        $q=$pdo->prepare("SELECT e.public_id,e.stripe_invoice_id,a.public_id account_public_id,a.name account_name FROM commercial_invoice_evidence e JOIN accounts a ON a.id=e.account_id WHERE e.stripe_mode=? AND e.drift_detected=1 ORDER BY e.updated_at DESC,e.id DESC LIMIT 200");$q->execute([$mode]);
        foreach($q->fetchAll()?:[] as $e)$emit(['severity'=>'danger','category'=>'invoice_integrity','title'=>$e['account_name'].' has finalized invoice evidence drift','detail'=>'Invoice '.(string)$e['stripe_invoice_id'].' differs from its finalized financial baseline.','source_type'=>'invoice_evidence_drift','source_public_id'=>(string)$e['public_id'],'account_public_id'=>(string)$e['account_public_id'],'target_url'=>'/admin/tax-invoices.php']);
        $settings=commercial_billing_settings($pdo,$mode);
        if(!empty($settings['automatic_tax_enabled'])){
            $q=$pdo->prepare("SELECT DISTINCT a.id account_id_sort,a.public_id account_public_id,a.name account_name FROM accounts a JOIN stripe_customers sc ON sc.account_id=a.id AND sc.mode=? LEFT JOIN account_billing_profiles bp ON bp.account_id=a.id WHERE a.status<>'closed' AND (bp.account_id IS NULL OR bp.address_country IS NULL OR bp.address_country='') ORDER BY a.name,account_id_sort LIMIT 300");$q->execute([$mode]);
            foreach($q->fetchAll()?:[] as $a)$emit(['severity'=>'warn','category'=>'tax_profile','title'=>$a['account_name'].' is missing billing-country data','detail'=>'Automatic tax is enabled, but this account billing profile has no country.','source_type'=>'tax_profile_missing_country','source_public_id'=>(string)$a['account_public_id'],'account_public_id'=>(string)$a['account_public_id'],'target_url'=>'/admin/account.php?id='.rawurlencode((string)$a['account_public_id'])]);
        }
    }
    $q=$pdo->query("SELECT public_id,name FROM accounts WHERE status='suspended' ORDER BY updated_at DESC,id DESC LIMIT 300");foreach($q->fetchAll()?:[] as $a)$emit(['severity'=>'warn','category'=>'account','title'=>$a['name'].' account is suspended','detail'=>'Review lifecycle and billing state before restoring access.','source_type'=>'account_suspended','source_public_id'=>(string)$a['public_id'],'account_public_id'=>(string)$a['public_id'],'target_url'=>'/admin/account.php?id='.rawurlencode((string)$a['public_id'])]);
    $q=$pdo->prepare("UPDATE admin_operations_alerts SET status='resolved',resolved_at=NOW(),resolution_note='Auto-resolved because the source condition is no longer active.' WHERE status IN ('open','snoozed') AND (last_scan_token IS NULL OR last_scan_token<>?)");$q->execute([$scan]);$resolved=$q->rowCount();
    return ['scan_token'=>$scan,'observed'=>$seen,'auto_resolved'=>$resolved];
}
function admin_ops_alert_filters(array $input): array {
    $out=[];foreach(['severity','category','status','assigned'] as $key){$v=trim((string)($input[$key]??''));if($v!=='')$out[$key]=$v;}return $out;
}
function admin_ops_alerts(PDO $pdo,array $filters=[],int $limit=200): array {
    if(!admin_ops_ready($pdo))return [];$limit=max(1,min(500,$limit));$where=[];$params=[];
    $severity=(string)($filters['severity']??'');if(in_array($severity,['info','warn','danger'],true)){$where[]='o.severity=?';$params[]=$severity;}
    $category=trim((string)($filters['category']??''));if($category!==''){$where[]='o.category=?';$params[]=$category;}
    $status=(string)($filters['status']??'');if(in_array($status,['open','snoozed','resolved'],true)){$where[]='o.status=?';$params[]=$status;}else{$where[]="(o.status='open' OR (o.status='snoozed' AND (o.snoozed_until IS NULL OR o.snoozed_until<=NOW())))";}
    $assigned=trim((string)($filters['assigned']??''));if($assigned==='me'&&!empty($filters['actor_user_id'])){$where[]='o.assigned_user_id=?';$params[]=(int)$filters['actor_user_id'];}elseif($assigned==='unassigned')$where[]='o.assigned_user_id IS NULL';
    $sql="SELECT o.*,a.public_id account_public_id,a.name account_name,u.username assigned_username FROM admin_operations_alerts o LEFT JOIN accounts a ON a.id=o.account_id LEFT JOIN users u ON u.id=o.assigned_user_id".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY FIELD(o.severity,'danger','warn','info'),o.last_seen_at DESC,o.id DESC LIMIT ".$limit;$q=$pdo->prepare($sql);$q->execute($params);return $q->fetchAll()?:[];
}
function admin_ops_alert_counts(PDO $pdo): array {
    $out=['open'=>0,'danger'=>0,'warn'=>0,'snoozed'=>0,'resolved'=>0];if(!admin_ops_ready($pdo))return $out;
    foreach($pdo->query("SELECT status,severity,COUNT(*) c FROM admin_operations_alerts GROUP BY status,severity")->fetchAll()?:[] as $r){$out[(string)$r['status']]=($out[(string)$r['status']]??0)+(int)$r['c'];if($r['status']==='open'&&isset($out[(string)$r['severity']]))$out[(string)$r['severity']]+=(int)$r['c'];}return $out;
}
function admin_ops_alert_update(PDO $pdo,array $admin,string $publicId,string $operation,array $input=[]): array {
    admin_ops_require_admin($admin);if(!admin_ops_ready($pdo))throw new RuntimeException('Admin V2.0 operations requires migration 073.');$q=$pdo->prepare('SELECT * FROM admin_operations_alerts WHERE public_id=? LIMIT 1');$q->execute([$publicId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Operational alert not found.');$reason=admin_ops_reason((string)($input['reason']??'Operational alert update.'));
    if($operation==='resolve'){$pdo->prepare("UPDATE admin_operations_alerts SET status='resolved',resolved_at=NOW(),resolution_note=?,snoozed_until=NULL WHERE id=?")->execute([$reason,(int)$row['id']]);}
    elseif($operation==='reopen'){$pdo->prepare("UPDATE admin_operations_alerts SET status='open',resolved_at=NULL,resolution_note=NULL,snoozed_until=NULL WHERE id=?")->execute([(int)$row['id']]);}
    elseif($operation==='snooze'){$hours=max(1,min(720,(int)($input['hours']??24)));$until=gmdate('Y-m-d H:i:s',time()+($hours*3600));$pdo->prepare("UPDATE admin_operations_alerts SET status='snoozed',snoozed_until=?,resolution_note=?,resolved_at=NULL WHERE id=?")->execute([$until,$reason,(int)$row['id']]);}
    elseif($operation==='assign'){$uid=(int)($input['user_id']??0);if($uid>0){$v=$pdo->prepare("SELECT COUNT(*) FROM users WHERE id=? AND role='admin' AND status<>'deleted'");$v->execute([$uid]);if(!(int)$v->fetchColumn())throw new RuntimeException('Assignee must be an active site administrator.');}$pdo->prepare('UPDATE admin_operations_alerts SET assigned_user_id=? WHERE id=?')->execute([$uid>0?$uid:null,(int)$row['id']]);}
    else throw new InvalidArgumentException('Unknown alert operation.');
    admin_ops_record_action($pdo,$admin,$row['account_id']===null?null:(int)$row['account_id'],'alert_'.$operation,'executed','routine',$reason,['alert_public_id'=>$publicId,'operation'=>$operation],['previous_status'=>$row['status']]);
    $q->execute([$publicId]);return $q->fetch()?:$row;
}
function admin_ops_saved_views(PDO $pdo,array $admin,string $scope='command_center'): array {
    admin_ops_require_admin($admin);if(!admin_ops_ready($pdo))return [];$scope=in_array($scope,['command_center','accounts'],true)?$scope:'command_center';$q=$pdo->prepare('SELECT * FROM admin_saved_views WHERE user_id=? AND scope=? ORDER BY is_default DESC,updated_at DESC,id DESC');$q->execute([(int)$admin['id'],$scope]);return $q->fetchAll()?:[];
}
function admin_ops_saved_view_save(PDO $pdo,array $admin,string $name,array $filters,bool $default=false,string $scope='command_center'): array {
    admin_ops_require_admin($admin);if(!admin_ops_ready($pdo))throw new RuntimeException('Admin V2.0 operations requires migration 073.');$name=trim($name);if($name===''||mb_strlen($name)>120)throw new InvalidArgumentException('Saved view name is required and must be 120 characters or fewer.');$scope=in_array($scope,['command_center','accounts'],true)?$scope:'command_center';$clean=admin_ops_alert_filters($filters);$json=json_encode($clean,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if($default)$pdo->prepare('UPDATE admin_saved_views SET is_default=0 WHERE user_id=? AND scope=?')->execute([(int)$admin['id'],$scope]);
    $pdo->prepare("INSERT INTO admin_saved_views(public_id,user_id,scope,name,filters_json,is_default) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE filters_json=VALUES(filters_json),is_default=VALUES(is_default),updated_at=NOW()")->execute([ulid_like(),(int)$admin['id'],$scope,$name,$json,$default?1:0]);$q=$pdo->prepare('SELECT * FROM admin_saved_views WHERE user_id=? AND scope=? AND name=?');$q->execute([(int)$admin['id'],$scope,$name]);return $q->fetch();
}
function admin_ops_saved_view_delete(PDO $pdo,array $admin,string $publicId): void {
    admin_ops_require_admin($admin);if(!admin_ops_ready($pdo))return;$pdo->prepare('DELETE FROM admin_saved_views WHERE public_id=? AND user_id=?')->execute([$publicId,(int)$admin['id']]);
}
function admin_ops_record_action(PDO $pdo,array $admin,?int $accountId,string $actionType,string $status,string $risk,string $reason,array $request=[],mixed $result=null,?string $correlation=null): array {
    admin_ops_require_admin($admin);if(!admin_ops_ready($pdo))return [];$status=in_array($status,['previewed','executing','executed','failed','cancelled'],true)?$status:'executed';$risk=in_array($risk,['routine','elevated','high'],true)?$risk:'routine';$correlation=$correlation?:hash('sha256',ulid_like().'|'.$actionType.'|'.(string)$admin['id']);
    $pdo->prepare('INSERT INTO admin_action_records(public_id,account_id,actor_user_id,action_type,status,risk_level,reason,request_json,result_json,correlation_id,executed_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([ulid_like(),$accountId,(int)$admin['id'],mb_substr($actionType,0,100),$status,$risk,admin_ops_reason($reason),json_encode($request,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$result===null?null:json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$correlation,$status==='executed'?gmdate('Y-m-d H:i:s'):null]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM admin_action_records WHERE id=?');$q->execute([$id]);return $q->fetch()?:[];
}
function admin_ops_action_catalog(): array {
    return [
        'resync_stripe_subscription'=>['label'=>'Resync Stripe subscription','risk'=>'routine','capability'=>'admin.billing.sync','surface'=>'account','description'=>'Read the linked Stripe subscription and resynchronize Annotated billing state.'],
        'reconcile_ai_overage'=>['label'=>'Reconcile AI overage','risk'=>'elevated','capability'=>'admin.billing.overage','surface'=>'account','description'=>'Report/retry this account’s pending billable AI overage according to existing V1.70 policy.'],
        'sync_tax_policy'=>['label'=>'Sync billing profile + tax policy','risk'=>'elevated','capability'=>'admin.billing.tax','surface'=>'account','description'=>'Synchronize the account billing profile and current automatic-tax policy to its linked Stripe subscription.'],
        'change_platform_feature'=>['label'=>'Change platform feature rollout','risk'=>'elevated','capability'=>'admin.platform.manage','surface'=>'platform','description'=>'Apply an approved feature lifecycle, enforcement, eligibility or rollout change through Admin V2.60.'],
    ];
}
function admin_ops_action_preview(PDO $pdo,array $admin,string $actionType,string $accountPublicId,string $reason): array {
    admin_ops_require_admin($admin);if(!admin_ops_ready($pdo))throw new RuntimeException('Admin V2.0 operations requires migration 073.');$catalog=admin_ops_action_catalog();if(!isset($catalog[$actionType]))throw new InvalidArgumentException('Unsupported governed action.');
    if(function_exists('admin_access_ready')&&admin_access_ready($pdo)){admin_access_assert_capability($pdo,$admin,'admin.actions.request');admin_access_assert_capability($pdo,$admin,(string)$catalog[$actionType]['capability']);}
    else{$profile=admin_ops_operator_profile($pdo,$admin);if(!admin_ops_has_capability($profile,(string)$catalog[$actionType]['capability'])&&!admin_ops_has_capability($profile,'admin.*'))throw new RuntimeException('Operator capability is not authorized for this action.');}
    $account=account_admin_get($pdo,$accountPublicId);if(!$account)throw new RuntimeException('Account not found.');$reason=admin_ops_reason($reason);
    $preview=['action_type'=>$actionType,'label'=>$catalog[$actionType]['label'],'risk_level'=>$catalog[$actionType]['risk'],'description'=>$catalog[$actionType]['description'],'account_public_id'=>$account['public_id'],'account_name'=>$account['name'],'package'=>$account['package_name'],'subscription_status'=>$account['subscription_status']];
    if($actionType==='resync_stripe_subscription'){$sub=stripe_billing_ready($pdo)?stripe_billing_current_subscription($pdo,(int)$account['id']):null;$preview['stripe_subscription_id']=$sub['stripe_subscription_id']??null;if(!$sub)$preview['warning']='No linked current Stripe subscription exists.';}
    elseif($actionType==='reconcile_ai_overage'){$summary=ai_overage_ready($pdo)?ai_overage_account_summary($pdo,(int)$account['id']):null;$preview['unreported_micros']=$summary['unreported_micros']??0;$preview['remaining_cap_micros']=$summary['remaining_cap_micros']??null;}
    elseif($actionType==='sync_tax_policy'){$sub=stripe_billing_ready($pdo)?stripe_billing_current_subscription($pdo,(int)$account['id']):null;$settings=commercial_billing_ready($pdo)?commercial_billing_settings($pdo):[];$preview['stripe_subscription_id']=$sub['stripe_subscription_id']??null;$preview['automatic_tax_enabled']=!empty($settings['automatic_tax_enabled']);if(!$sub)$preview['warning']='No linked current Stripe subscription exists.';}
    $record=admin_ops_record_action($pdo,$admin,(int)$account['id'],$actionType,'previewed',(string)$catalog[$actionType]['risk'],$reason,['account_public_id'=>$account['public_id'],'preview'=>$preview],null);if(function_exists('admin_access_bind_action_policy')&&admin_access_ready($pdo)){$record=admin_access_bind_action_policy($pdo,$record);$preview['risk_level']=(string)($record['risk_level']??$preview['risk_level']);$preview['required_approvals']=(int)($record['required_approvals']??0);}return ['record'=>$record,'preview'=>$preview];
}
function admin_ops_action_record(PDO $pdo,string $publicId): ?array {if(!admin_ops_ready($pdo))return null;$q=$pdo->prepare('SELECT r.*,a.public_id account_public_id,a.name account_name,u.username actor_username FROM admin_action_records r LEFT JOIN accounts a ON a.id=r.account_id JOIN users u ON u.id=r.actor_user_id WHERE r.public_id=? LIMIT 1');$q->execute([$publicId]);return $q->fetch()?:null;}
function admin_ops_action_execute(PDO $pdo,array $config,array $admin,string $recordPublicId): array {
    admin_ops_require_admin($admin);$record=admin_ops_action_record($pdo,$recordPublicId);if(!$record)throw new RuntimeException('Governed action preview not found.');if((int)$record['actor_user_id']!==(int)$admin['id'])throw new RuntimeException('Only the administrator who created the preview may execute it.');
    $catalog=admin_ops_action_catalog();$action=(string)$record['action_type'];if(!isset($catalog[$action]))throw new RuntimeException('Governed action is no longer supported.');
    if(function_exists('admin_access_ready')&&admin_access_ready($pdo)){admin_access_assert_capability($pdo,$admin,(string)$catalog[$action]['capability']);admin_access_assert_action_executable($pdo,$admin,$record);$expected=(int)($record['required_approvals']??0)>0?'approved':'previewed';}
    else{$profile=admin_ops_operator_profile($pdo,$admin);if(!admin_ops_has_capability($profile,(string)$catalog[$action]['capability'])&&!admin_ops_has_capability($profile,'admin.*'))throw new RuntimeException('Operator capability is not authorized for this action.');if($record['status']!=='previewed')throw new RuntimeException('This governed action is no longer executable.');$expected='previewed';}
    $claim=$pdo->prepare("UPDATE admin_action_records SET status='executing' WHERE id=? AND status=?");$claim->execute([(int)$record['id'],$expected]);if($claim->rowCount()!==1)throw new RuntimeException('Governed action was already claimed.');
    $request=json_decode((string)$record['request_json'],true)?:[];$accountPublic=(string)($request['account_public_id']??$record['account_public_id']??'');
    try{
        if($action==='resync_stripe_subscription')$result=billing_operations_resync_account($pdo,$config,$admin,$accountPublic);
        elseif($action==='reconcile_ai_overage')$result=ai_overage_reconcile_account($pdo,$config,$admin,$accountPublic);
        elseif($action==='sync_tax_policy')$result=commercial_billing_sync_subscription_policy($pdo,$config,$admin,$accountPublic);
        elseif($action==='change_platform_feature'){if(!function_exists('admin_platform_execute_feature_action'))throw new RuntimeException('Admin V2.60 platform governance is unavailable.');$result=admin_platform_execute_feature_action($pdo,$admin,$record);}
        else throw new RuntimeException('Unsupported governed action.');
        $safe=['ok'=>true,'action_type'=>$action];if($accountPublic!=='')$safe['account_public_id']=$accountPublic;if(is_array($result)){foreach(['public_id','feature_key','subscription_status','status','enforcement_mode','rollout_percent','report'] as $key)if(array_key_exists($key,$result))$safe[$key]=$result[$key];}
        $pdo->prepare("UPDATE admin_action_records SET status='executed',result_json=?,executed_at=NOW() WHERE id=?")->execute([json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),(int)$record['id']]);if(function_exists('admin_access_security_audit')&&admin_access_ready($pdo))admin_access_security_audit($pdo,$admin,'action',$recordPublicId,'action_executed',['status'=>$expected],['status'=>'executed'],$record['reason']);admin_ops_refresh_alerts($pdo);return admin_ops_action_record($pdo,$recordPublicId)??$record;
    }catch(Throwable $e){$pdo->prepare("UPDATE admin_action_records SET status='failed',result_json=?,executed_at=NOW() WHERE id=?")->execute([json_encode(['ok'=>false,'error'=>mb_substr($e->getMessage(),0,1000)],JSON_UNESCAPED_SLASHES),(int)$record['id']]);if(function_exists('admin_access_security_audit')&&admin_access_ready($pdo))admin_access_security_audit($pdo,$admin,'action',$recordPublicId,'action_failed',['status'=>'executing'],['status'=>'failed','error'=>mb_substr($e->getMessage(),0,500)],$record['reason']);throw $e;}
}
function admin_ops_recent_actions(PDO $pdo,int $limit=100,?int $accountId=null): array {
    if(!admin_ops_ready($pdo))return [];$limit=max(1,min(500,$limit));$where=$accountId!==null?' WHERE r.account_id=?':'';$sql="SELECT r.*,a.public_id account_public_id,a.name account_name,u.username actor_username FROM admin_action_records r LEFT JOIN accounts a ON a.id=r.account_id JOIN users u ON u.id=r.actor_user_id".$where." ORDER BY r.created_at DESC,r.id DESC LIMIT ".$limit;$q=$pdo->prepare($sql);$q->execute($accountId!==null?[$accountId]:[]);return $q->fetchAll()?:[];
}
function admin_ops_global_search(PDO $pdo,string $query,int $limit=40,?array $viewer=null): array {
    $query=trim($query);if(mb_strlen($query)<2)return [];$limit=max(5,min(100,$limit));$like='%'.$query.'%';$out=[];$push=function(string $type,string $title,string $subtitle,string $url,string $identifier)use(&$out,$limit){if(count($out)<$limit)$out[]=['type'=>$type,'title'=>$title,'subtitle'=>$subtitle,'url'=>$url,'identifier'=>$identifier];};
    $q=$pdo->prepare("SELECT a.public_id,a.name,a.subscription_status,p.name package_name,u.email owner_email FROM accounts a JOIN subscription_packages p ON p.id=a.package_id LEFT JOIN users u ON u.id=a.owner_user_id WHERE a.public_id LIKE ? OR a.name LIKE ? OR u.email LIKE ? ORDER BY a.updated_at DESC LIMIT 15");$q->execute([$like,$like,$like]);foreach($q->fetchAll()?:[] as $r)$push('Account',(string)$r['name'],(string)$r['package_name'].' · '.(string)$r['subscription_status'].' · '.(string)($r['owner_email']??''),'/admin/account.php?id='.rawurlencode((string)$r['public_id']),(string)$r['public_id']);
    $q=$pdo->prepare("SELECT public_id,username,display_name,email,status FROM users WHERE public_id LIKE ? OR username LIKE ? OR display_name LIKE ? OR email LIKE ? ORDER BY updated_at DESC,id DESC LIMIT 15");$q->execute([$like,$like,$like,$like]);foreach($q->fetchAll()?:[] as $r)$push('User',(string)($r['display_name']?:$r['username']),'@'.(string)$r['username'].' · '.(string)$r['email'].' · '.(string)$r['status'],'/admin/users.php?q='.rawurlencode((string)$r['username']),(string)$r['public_id']);
    if(stripe_billing_ready($pdo)){
        $q=$pdo->prepare("SELECT i.stripe_invoice_id,i.invoice_number,a.public_id account_public_id,a.name account_name,i.status FROM stripe_invoices i JOIN accounts a ON a.id=i.account_id WHERE i.stripe_invoice_id LIKE ? OR i.invoice_number LIKE ? OR i.stripe_customer_id LIKE ? OR i.stripe_subscription_id LIKE ? ORDER BY i.created_at DESC,i.id DESC LIMIT 15");$q->execute([$like,$like,$like,$like]);foreach($q->fetchAll()?:[] as $r)$push('Invoice',(string)($r['invoice_number']?:$r['stripe_invoice_id']),(string)$r['account_name'].' · '.(string)$r['status'],'/admin/account.php?id='.rawurlencode((string)$r['account_public_id']),(string)$r['stripe_invoice_id']);
        $q=$pdo->prepare("SELECT s.stripe_subscription_id,s.stripe_customer_id,s.status,a.public_id account_public_id,a.name account_name FROM stripe_subscriptions s JOIN accounts a ON a.id=s.account_id WHERE s.stripe_subscription_id LIKE ? OR s.stripe_customer_id LIKE ? OR s.stripe_price_id LIKE ? ORDER BY s.updated_at DESC,s.id DESC LIMIT 15");$q->execute([$like,$like,$like]);foreach($q->fetchAll()?:[] as $r)$push('Subscription',(string)$r['stripe_subscription_id'],(string)$r['account_name'].' · '.(string)$r['status'],'/admin/account.php?id='.rawurlencode((string)$r['account_public_id']),(string)$r['stripe_customer_id']);
    }
    if(function_exists('commercial_promotions_ready')&&commercial_promotions_ready($pdo)){$q=$pdo->prepare("SELECT public_id,name,code,status FROM commercial_promotions WHERE public_id LIKE ? OR name LIKE ? OR code LIKE ? ORDER BY updated_at DESC,id DESC LIMIT 15");$q->execute([$like,$like,$like]);foreach($q->fetchAll()?:[] as $r)$push('Promotion',(string)$r['name'],(string)$r['code'].' · '.(string)$r['status'],'/admin/promotions.php',(string)$r['public_id']);}
    if(admin_ops_ready($pdo)){$q=$pdo->prepare("SELECT public_id,action_type,status,correlation_id FROM admin_action_records WHERE public_id LIKE ? OR correlation_id LIKE ? OR action_type LIKE ? ORDER BY created_at DESC,id DESC LIMIT 10");$q->execute([$like,$like,$like]);foreach($q->fetchAll()?:[] as $r)$push('Admin action',ucwords(str_replace('_',' ',(string)$r['action_type'])),(string)$r['status'].' · '.substr((string)$r['correlation_id'],0,16).'…','/admin/?action_record='.rawurlencode((string)$r['public_id']),(string)$r['public_id']);}
    if($viewer!==null&&function_exists('admin_access_has_capability')&&admin_access_has_capability($pdo,$viewer,'admin.support.view')&&installer_table_exists($pdo,'admin_support_cases')){$q=$pdo->prepare("SELECT c.public_id,c.title,c.status,c.priority,a.name account_name,u.email customer_email FROM admin_support_cases c LEFT JOIN accounts a ON a.id=c.account_id LEFT JOIN users u ON u.id=c.user_id WHERE c.public_id LIKE ? OR c.title LIKE ? OR a.name LIKE ? OR u.email LIKE ? ORDER BY c.updated_at DESC,c.id DESC LIMIT 15");$q->execute([$like,$like,$like,$like]);foreach($q->fetchAll()?:[] as $r)$push('Support case',(string)$r['title'],(string)($r['account_name']?:($r['customer_email']?:'Unlinked')).' · '.(string)$r['priority'].' · '.(string)$r['status'],'/admin/support-case.php?id='.rawurlencode((string)$r['public_id']),(string)$r['public_id']);}
    return array_slice($out,0,$limit);
}
function admin_ops_account_timeline(PDO $pdo,int $accountId,int $limit=200,?array $viewer=null): array {
    $limit=max(1,min(500,$limit));$rows=[];$add=function(string $source,array $items,string $titleKey='event_type',string $detailKey='reason')use(&$rows){foreach($items as $r)$rows[]=['created_at'=>(string)($r['created_at']??''),'source'=>$source,'title'=>(string)($r[$titleKey]??$source),'detail'=>(string)($r[$detailKey]??''),'actor'=>(string)($r['actor_username']??'')];};
    if(installer_table_exists($pdo,'account_admin_events')){$q=$pdo->prepare("SELECT e.*,u.username actor_username FROM account_admin_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.account_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 150");$q->execute([$accountId]);$add('account',$q->fetchAll()?:[]);}
    if(installer_table_exists($pdo,'account_membership_events')){$q=$pdo->prepare("SELECT e.*,u.username actor_username FROM account_membership_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.account_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 150");$q->execute([$accountId]);$add('membership',$q->fetchAll()?:[]);}
    if(installer_table_exists($pdo,'account_billing_events')){$q=$pdo->prepare("SELECT e.*,u.username actor_username FROM account_billing_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.account_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 150");$q->execute([$accountId]);$add('billing',$q->fetchAll()?:[]);}
    if(installer_table_exists($pdo,'billing_dunning_events')){$q=$pdo->prepare("SELECT e.*,u.username actor_username FROM billing_dunning_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.account_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 100");$q->execute([$accountId]);$add('dunning',$q->fetchAll()?:[]);}
    if(installer_table_exists($pdo,'ai_overage_audit_events')){$q=$pdo->prepare("SELECT e.*,u.username actor_username FROM ai_overage_audit_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.account_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 100");$q->execute([$accountId]);$add('ai_overage',$q->fetchAll()?:[]);}
    if(installer_table_exists($pdo,'commercial_audit_events')){$q=$pdo->prepare("SELECT e.*,u.username actor_username FROM commercial_audit_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.account_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 100");$q->execute([$accountId]);$add('commercial',$q->fetchAll()?:[]);}
    if(installer_table_exists($pdo,'commercial_billing_audit_events')){$q=$pdo->prepare("SELECT e.*,u.username actor_username FROM commercial_billing_audit_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.account_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 100");$q->execute([$accountId]);$add('tax_invoice',$q->fetchAll()?:[]);}
    if(installer_table_exists($pdo,'commercial_credit_adjustments')){$q=$pdo->prepare("SELECT c.created_at,CONCAT('credit_',c.adjustment_type) event_type,c.reason,u.username actor_username FROM commercial_credit_adjustments c LEFT JOIN users u ON u.id=c.actor_user_id WHERE c.account_id=? ORDER BY c.created_at DESC,c.id DESC LIMIT 100");$q->execute([$accountId]);$add('credit',$q->fetchAll()?:[]);}
    if(admin_ops_ready($pdo)){$q=$pdo->prepare("SELECT r.created_at,CONCAT('admin_action_',r.action_type,'_',r.status) event_type,r.reason,u.username actor_username FROM admin_action_records r JOIN users u ON u.id=r.actor_user_id WHERE r.account_id=? ORDER BY r.created_at DESC,r.id DESC LIMIT 100");$q->execute([$accountId]);$add('admin_action',$q->fetchAll()?:[]);}
    if($viewer!==null&&function_exists('admin_access_has_capability')&&admin_access_has_capability($pdo,$viewer,'admin.support.view')&&installer_table_exists($pdo,'admin_support_cases')&&installer_table_exists($pdo,'admin_support_case_events')){$q=$pdo->prepare("SELECT e.created_at,CONCAT('support_',e.event_type) event_type,e.body reason,u.username actor_username FROM admin_support_case_events e JOIN admin_support_cases c ON c.id=e.case_id LEFT JOIN users u ON u.id=e.actor_user_id WHERE c.account_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT 100");$q->execute([$accountId]);$add('support',$q->fetchAll()?:[]);}
    usort($rows,fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));return array_slice($rows,0,$limit);
}
function admin_ops_account_360(PDO $pdo,int $accountId,?array $viewer=null): array {
    $account=account_admin_get($pdo,$accountId)??throw new RuntimeException('Account not found.');$stripe=stripe_billing_ready($pdo)?stripe_billing_account_summary($pdo,$accountId):['customer'=>null,'subscription'=>null,'invoices'=>[]];$profile=commercial_billing_ready($pdo)?commercial_billing_profile($pdo,$accountId):null;$benefits=commercial_promotions_ready($pdo)?commercial_account_benefits_summary($pdo,$accountId):null;$overage=ai_overage_ready($pdo)?ai_overage_account_summary($pdo,$accountId):null;$alerts=admin_ops_ready($pdo)?admin_ops_alerts($pdo,['status'=>'open'],500):[];$alerts=array_values(array_filter($alerts,fn($a)=>(int)($a['account_id']??0)===$accountId));$actions=admin_ops_recent_actions($pdo,20,$accountId);
    return ['account'=>$account,'stripe'=>$stripe,'billing_profile'=>$profile,'benefits'=>$benefits,'overage'=>$overage,'alerts'=>$alerts,'actions'=>$actions,'timeline'=>admin_ops_account_timeline($pdo,$accountId,200,$viewer)];
}
function admin_ops_admin_users(PDO $pdo): array {return $pdo->query("SELECT id,username,display_name FROM users WHERE role='admin' AND status<>'deleted' ORDER BY display_name,username,id")->fetchAll()?:[];}
function admin_ops_dashboard(PDO $pdo,array $admin,array $filters=[]): array {
    if(!admin_ops_ready($pdo))return ['ready'=>false,'alerts'=>[],'counts'=>[],'saved_views'=>[],'profile'=>admin_ops_operator_profile($pdo,$admin),'actions'=>[]];admin_ops_refresh_alerts($pdo);$views=admin_ops_saved_views($pdo,$admin);if(!$filters){foreach($views as $view)if(!empty($view['is_default'])){try{$decoded=json_decode((string)$view['filters_json'],true,512,JSON_THROW_ON_ERROR);if(is_array($decoded))$filters=admin_ops_alert_filters($decoded);}catch(Throwable $e){}break;}}$filters['actor_user_id']=(int)$admin['id'];return ['ready'=>true,'alerts'=>admin_ops_alerts($pdo,$filters,200),'counts'=>admin_ops_alert_counts($pdo),'saved_views'=>$views,'profile'=>admin_ops_operator_profile($pdo,$admin),'actions'=>admin_ops_recent_actions($pdo,30),'admin_users'=>admin_ops_admin_users($pdo),'effective_filters'=>admin_ops_alert_filters($filters)];
}
function admin_ops_agent_context(PDO $pdo,array $viewer): string {
    if(($viewer['role']??'')!=='admin'||!admin_ops_ready($pdo))return '';$counts=admin_ops_alert_counts($pdo);$q=$pdo->query("SELECT COUNT(*) FROM admin_action_records WHERE status='failed' AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)");$failed=(int)$q->fetchColumn();$base="[ADMIN V2.0 OPERATIONS — READ ONLY]\nOpen operational alerts: ".(int)$counts['open']." (danger ".(int)$counts['danger'].", warning ".(int)$counts['warn']."). Failed governed Admin actions in the last 7 days: {$failed}. Account 360 combines existing authoritative ledgers; the Agent may explain and prioritize supplied operational context but may not resolve/snooze alerts, execute governed actions, change assignments, or mutate account/billing state.";if(function_exists('admin_access_agent_context')){$access=admin_access_agent_context($pdo,$viewer);if($access!=='')$base.="\n".$access;}return $base;
}
