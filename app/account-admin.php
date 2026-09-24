<?php
declare(strict_types=1);

function account_admin_ready(PDO $pdo): bool {
    try{return subscriptions_ready($pdo)&&installer_table_exists($pdo,'account_entitlement_overrides')&&installer_table_exists($pdo,'account_admin_events');}
    catch(Throwable $e){return false;}
}
function account_admin_require_admin(array $admin): void {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
}
function account_admin_reason(string $reason): string {
    $reason=trim($reason);if($reason==='')throw new InvalidArgumentException('A reason is required for account administration.');return mb_substr($reason,0,500);
}
function account_admin_get(PDO $pdo,string|int $identifier): ?array {
    if(!subscriptions_ready($pdo))return null;
    if(is_int($identifier)||ctype_digit((string)$identifier)){$q=$pdo->prepare("SELECT a.*,p.public_id package_public_id,p.slug package_slug,p.name package_name,p.status package_status,p.legacy_plan_tier,p.monthly_price_cents,p.monthly_ai_token_allowance,p.member_limit,p.feature_json FROM accounts a JOIN subscription_packages p ON p.id=a.package_id WHERE a.id=? LIMIT 1");$q->execute([(int)$identifier]);}
    else{$q=$pdo->prepare("SELECT a.*,p.public_id package_public_id,p.slug package_slug,p.name package_name,p.status package_status,p.legacy_plan_tier,p.monthly_price_cents,p.monthly_ai_token_allowance,p.member_limit,p.feature_json FROM accounts a JOIN subscription_packages p ON p.id=a.package_id WHERE a.public_id=? LIMIT 1");$q->execute([(string)$identifier]);}
    return $q->fetch()?:null;
}
function account_admin_event(PDO $pdo,int $accountId,?int $actorUserId,?int $subjectUserId,string $eventType,mixed $before,mixed $after,string $reason): void {
    if(!account_admin_ready($pdo))return;
    $encode=fn(mixed $v)=>$v===null?null:json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $pdo->prepare("INSERT INTO account_admin_events(public_id,account_id,actor_user_id,subject_user_id,event_type,before_json,after_json,reason) VALUES(?,?,?,?,?,?,?,?)")
      ->execute([ulid_like(),$accountId,$actorUserId,$subjectUserId,mb_substr($eventType,0,64),$encode($before),$encode($after),account_admin_reason($reason)]);
}
function account_admin_user_lookup(PDO $pdo,string $identifier): ?array {
    $identifier=trim($identifier);if($identifier==='')return null;
    $q=$pdo->prepare("SELECT id,public_id,username,display_name,email,status,role FROM users WHERE public_id=? OR username=? OR email=? LIMIT 1");$q->execute([$identifier,$identifier,$identifier]);return $q->fetch()?:null;
}
function account_admin_members(PDO $pdo,int $accountId): array {
    $q=$pdo->prepare("SELECT am.account_id,am.user_id,am.account_role,am.joined_at,u.public_id user_public_id,u.username,u.display_name,u.email,u.status user_status
      FROM account_members am JOIN users u ON u.id=am.user_id WHERE am.account_id=? ORDER BY FIELD(am.account_role,'owner','admin','member'),am.joined_at,u.id");$q->execute([$accountId]);return $q->fetchAll()?:[];
}
function account_admin_overrides(PDO $pdo,int $accountId,bool $activeOnly=false): array {
    if(!account_admin_ready($pdo))return [];
    $sql="SELECT o.*,cu.username created_by_username,uu.username updated_by_username FROM account_entitlement_overrides o LEFT JOIN users cu ON cu.id=o.created_by_user_id LEFT JOIN users uu ON uu.id=o.updated_by_user_id WHERE o.account_id=?";
    if($activeOnly)$sql.=" AND o.status='active' AND (o.expires_at IS NULL OR o.expires_at>NOW())";
    $sql.=" ORDER BY o.entitlement_key,o.id";$q=$pdo->prepare($sql);$q->execute([$accountId]);return $q->fetchAll()?:[];
}
function account_admin_effective_entitlements(PDO $pdo,string|int $accountIdentifier): array {
    $account=account_admin_get($pdo,$accountIdentifier);if(!$account)throw new RuntimeException('Account not found.');
    $features=[];if(!empty($account['feature_json'])){try{$decoded=json_decode((string)$account['feature_json'],true,512,JSON_THROW_ON_ERROR);if(is_array($decoded))$features=$decoded;}catch(Throwable $e){}}
    $values=['monthly_ai_token_allowance'=>$account['monthly_ai_token_allowance']===null?null:(int)$account['monthly_ai_token_allowance'],'member_limit'=>(int)$account['member_limit'],'features'=>$features];
    $sources=['monthly_ai_token_allowance'=>'package','member_limit'=>'package'];$overrides=[];
    foreach(account_admin_overrides($pdo,(int)$account['id'],true) as $o){
        $key=(string)$o['entitlement_key'];$value=json_decode((string)($o['value_json']??'null'),true);
        $overrides[$key]=['value'=>$value,'expires_at'=>$o['expires_at'],'reason'=>$o['reason'],'public_id'=>$o['public_id']];
        if($key==='monthly_ai_token_allowance'){$values[$key]=$value===null?null:max(0,(int)$value);$sources[$key]='override';}
        elseif($key==='member_limit'){$values[$key]=max(1,(int)$value);$sources[$key]='override';}
        elseif(str_starts_with($key,'feature.')){$feature=substr($key,8);if($feature!=='')$values['features'][$feature]=$value;}
    }
    return ['account'=>$account,'values'=>$values,'sources'=>$sources,'overrides'=>$overrides];
}
function account_admin_effective_member_limit(PDO $pdo,string|int $accountIdentifier): int {
    $e=account_admin_effective_entitlements($pdo,$accountIdentifier);return max(1,(int)$e['values']['member_limit']);
}
function account_admin_create(PDO $pdo,array $admin,array $input): array {
    account_admin_require_admin($admin);if(!account_admin_ready($pdo))throw new RuntimeException('Account administration requires the latest database upgrade.');
    $name=trim((string)($input['name']??''));if($name==='')throw new InvalidArgumentException('Account name is required.');
    $type=(string)($input['account_type']??'organization');if(!in_array($type,['organization','internal'],true))$type='organization';
    $owner=account_admin_user_lookup($pdo,(string)($input['owner']??''));if(!$owner||($owner['status']??'')==='deleted')throw new RuntimeException('Choose an existing active or suspended owner.');
    $package=subscription_package($pdo,(string)($input['package_id']??''));if(!$package||$package['status']!=='active')throw new RuntimeException('Choose an active package.');
    $reason=account_admin_reason((string)($input['reason']??''));$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));[$start,$end]=subscription_period_from($now);$trial=(int)$package['trial_days'];$trialEnds=$trial>0?$now->modify('+'.$trial.' days')->format('Y-m-d H:i:s'):null;$sub=$trialEnds?'trialing':'active';
    $pdo->beginTransaction();try{
        $public=ulid_like();$pdo->prepare("INSERT INTO accounts(public_id,account_type,name,owner_user_id,personal_user_id,package_id,subscription_status,billing_source,period_start,period_end,trial_ends_at,status) VALUES(?,?,?,?,NULL,?,?,?,?,?,?,'active')")
          ->execute([$public,$type,mb_substr($name,0,190),(int)$owner['id'],(int)$package['id'],$sub,$type==='internal'?'internal':'manual',$start,$end,$trialEnds]);$id=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO account_members(account_id,user_id,account_role) VALUES(?,?,'owner')")->execute([$id,(int)$owner['id']]);
        $pdo->prepare("INSERT INTO subscription_package_events(public_id,account_id,user_id,previous_package_id,new_package_id,actor_user_id,event_type,reason,metadata_json) VALUES(?,?,NULL,NULL,?,?, 'account_created',?,?)")
          ->execute([ulid_like(),$id,(int)$package['id'],(int)$admin['id'],$reason,json_encode(['source'=>'admin_account_create'],JSON_UNESCAPED_SLASHES)]);
        account_admin_event($pdo,$id,(int)$admin['id'],(int)$owner['id'],'account_created',null,['name'=>$name,'account_type'=>$type,'package_id'=>$package['public_id'],'subscription_status'=>$sub],$reason);
        $pdo->commit();return account_admin_get($pdo,$id)?:throw new RuntimeException('Created account could not be loaded.');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function account_admin_change_package(PDO $pdo,array $admin,string $accountPublicId,string $packagePublicId,string $reason): array {
    account_admin_require_admin($admin);$seed=account_admin_get($pdo,$accountPublicId);if(!$seed)throw new RuntimeException('Account not found.');$reason=account_admin_reason($reason);
    return commercial_account_with_lock($pdo,(int)$seed['id'],function()use($pdo,$admin,$accountPublicId,$packagePublicId,$reason){
        $account=account_admin_get($pdo,$accountPublicId);if(!$account)throw new RuntimeException('Account not found.');
        if(($account['billing_source']??'manual')==='stripe'&&function_exists('stripe_billing_current_subscription')&&stripe_billing_current_subscription($pdo,(int)$account['id']))throw new RuntimeException('Stripe-managed accounts must change packages through Stripe billing.');
        $package=subscription_package($pdo,$packagePublicId);if(!$package||$package['status']!=='active')throw new RuntimeException('Choose an active package.');if((int)$account['package_id']===(int)$package['id'])return $account;
        $prospectiveLimit=(int)$package['member_limit'];foreach(account_admin_overrides($pdo,(int)$account['id'],true) as $o)if($o['entitlement_key']==='member_limit'){$prospectiveLimit=max(1,(int)json_decode((string)$o['value_json'],true));break;}
        $q=$pdo->prepare('SELECT COUNT(*) FROM account_members WHERE account_id=?');$q->execute([(int)$account['id']]);$occupied=(int)$q->fetchColumn();$reserved=function_exists('account_membership_pending_reserved_count')?account_membership_pending_reserved_count($pdo,(int)$account['id']):0;if($occupied+$reserved>$prospectiveLimit)throw new RuntimeException('The selected package member limit is below current membership plus reserved invitations. Remove members/revoke invitations or add a member-limit override first.');
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));[$start,$end]=subscription_period_from($now);$trial=(int)$package['trial_days'];$trialEnds=$trial>0?$now->modify('+'.$trial.' days')->format('Y-m-d H:i:s'):null;$sub=$trialEnds?'trialing':'active';$before=['package_id'=>$account['package_id'],'package_public_id'=>$account['package_public_id'],'package_name'=>$account['package_name'],'subscription_status'=>$account['subscription_status']];
        $pdo->beginTransaction();try{
            $pdo->prepare('UPDATE accounts SET package_id=?,subscription_status=?,period_start=?,period_end=?,trial_ends_at=?,package_assigned_at=NOW() WHERE id=?')->execute([(int)$package['id'],$sub,$start,$end,$trialEnds,(int)$account['id']]);
            if($account['account_type']==='personal'&&!empty($account['personal_user_id']))$pdo->prepare('UPDATE users SET plan_tier=?,pro_expires_at=NULL WHERE id=?')->execute([(string)$package['legacy_plan_tier'],(int)$account['personal_user_id']]);
            $pdo->prepare("INSERT INTO subscription_package_events(public_id,account_id,user_id,previous_package_id,new_package_id,actor_user_id,event_type,reason,metadata_json) VALUES(?,?,?,?,?,?,'package_changed',?,?)")
              ->execute([ulid_like(),(int)$account['id'],$account['personal_user_id']?(int)$account['personal_user_id']:null,(int)$account['package_id'],(int)$package['id'],(int)$admin['id'],$reason,json_encode(['source'=>'admin_account'],JSON_UNESCAPED_SLASHES)]);
            account_admin_event($pdo,(int)$account['id'],(int)$admin['id'],null,'package_changed',$before,['package_id'=>(int)$package['id'],'package_public_id'=>$package['public_id'],'package_name'=>$package['name'],'subscription_status'=>$sub],$reason);
            $pdo->commit();return account_admin_get($pdo,(int)$account['id'])?:throw new RuntimeException('Updated account could not be loaded.');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}function account_admin_update_lifecycle(PDO $pdo,array $admin,string $accountPublicId,array $input): array {
    account_admin_require_admin($admin);$seed=account_admin_get($pdo,$accountPublicId);if(!$seed)throw new RuntimeException('Account not found.');$reason=account_admin_reason((string)($input['reason']??''));
    return commercial_account_with_lock($pdo,(int)$seed['id'],function()use($pdo,$admin,$accountPublicId,$input,$reason){
        $account=account_admin_get($pdo,$accountPublicId);if(!$account)throw new RuntimeException('Account not found.');
        $name=trim((string)($input['name']??$account['name']));if($name==='')throw new InvalidArgumentException('Account name is required.');
        $status=(string)($input['status']??$account['status']);if(!in_array($status,['active','suspended','closed'],true))throw new InvalidArgumentException('Invalid account status.');
        $sub=(string)($input['subscription_status']??$account['subscription_status']);if(!in_array($sub,['trialing','active','past_due','paused','canceled'],true))throw new InvalidArgumentException('Invalid subscription status.');
        $stripeManaged=($account['billing_source']??'manual')==='stripe'&&function_exists('stripe_billing_current_subscription')&&stripe_billing_current_subscription($pdo,(int)$account['id']);
        if($stripeManaged&&$sub!==(string)$account['subscription_status'])throw new RuntimeException('Stripe-managed subscription state is controlled by Stripe webhooks.');
        if($stripeManaged&&$status==='closed')throw new RuntimeException('Cancel the Stripe subscription before closing this account.');if($status==='closed')$sub='canceled';
        $before=['name'=>$account['name'],'status'=>$account['status'],'subscription_status'=>$account['subscription_status']];$after=['name'=>mb_substr($name,0,190),'status'=>$status,'subscription_status'=>$sub];if($before===$after)return $account;
        $pdo->beginTransaction();try{$pdo->prepare("UPDATE accounts SET name=?,status=?,subscription_status=? WHERE id=?")->execute([$after['name'],$status,$sub,(int)$account['id']]);if($status==='closed'&&function_exists('account_membership_revoke_all_pending'))account_membership_revoke_all_pending($pdo,(int)$account['id'],(int)$admin['id'],'Account closed; pending invitations revoked.');if($status==='closed'&&function_exists('billing_operations_close_account_dunning'))billing_operations_close_account_dunning($pdo,(int)$account['id'],'Account closed; open billing recovery cases closed.',(int)$admin['id']);account_admin_event($pdo,(int)$account['id'],(int)$admin['id'],null,'lifecycle_changed',$before,$after,$reason);$pdo->commit();return account_admin_get($pdo,(int)$account['id'])?:throw new RuntimeException('Updated account could not be loaded.');}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}function account_admin_add_member(PDO $pdo,array $admin,string $accountPublicId,string $userIdentifier,string $role,string $reason): array {
    account_admin_require_admin($admin);$seed=account_admin_get($pdo,$accountPublicId);if(!$seed)throw new RuntimeException('Account not found.');$reason=account_admin_reason($reason);
    return commercial_account_with_lock($pdo,(int)$seed['id'],function()use($pdo,$admin,$accountPublicId,$userIdentifier,$role,$reason){
        $account=account_admin_get($pdo,$accountPublicId);if(!$account)throw new RuntimeException('Account not found.');if($account['status']!=='active')throw new RuntimeException('Only active accounts can accept members.');
        $user=account_admin_user_lookup($pdo,$userIdentifier);if(!$user||($user['status']??'')==='deleted')throw new RuntimeException('Member user not found.');$newRole=in_array($role,['admin','member'],true)?$role:'member';
        $q=$pdo->prepare('SELECT account_role FROM account_members WHERE account_id=? AND user_id=?');$q->execute([(int)$account['id'],(int)$user['id']]);if($q->fetchColumn()!==false)throw new RuntimeException('That user is already an account member.');
        $pendingInvite=null;if(function_exists('account_membership_ready')&&account_membership_ready($pdo)){$q=$pdo->prepare("SELECT * FROM account_invitations WHERE account_id=? AND invited_email=? AND status='pending' AND expires_at>NOW() ORDER BY id DESC LIMIT 1");$q->execute([(int)$account['id'],strtolower((string)$user['email'])]);$pendingInvite=$q->fetch()?:null;}
        $countQ=$pdo->prepare('SELECT COUNT(*) FROM account_members WHERE account_id=?');$countQ->execute([(int)$account['id']]);$count=(int)$countQ->fetchColumn();$reserved=function_exists('account_membership_pending_reserved_count')?account_membership_pending_reserved_count($pdo,(int)$account['id'],$pendingInvite?(int)$pendingInvite['id']:null):0;$limit=account_admin_effective_member_limit($pdo,(int)$account['id']);if($count+$reserved>=$limit)throw new RuntimeException('The account has reached its effective member limit including reserved invitations.');
        $pdo->beginTransaction();try{$pdo->prepare('INSERT INTO account_members(account_id,user_id,account_role) VALUES(?,?,?)')->execute([(int)$account['id'],(int)$user['id'],$newRole]);if($pendingInvite)$pdo->prepare("UPDATE account_invitations SET status='accepted',accepted_by_user_id=?,accepted_at=NOW() WHERE id=? AND status='pending'")->execute([(int)$user['id'],(int)$pendingInvite['id']]);account_admin_event($pdo,(int)$account['id'],(int)$admin['id'],(int)$user['id'],'member_added',null,['account_role'=>$newRole],$reason);if(function_exists('account_membership_event'))account_membership_event($pdo,(int)$account['id'],(int)$admin['id'],(int)$user['id'],$pendingInvite?(int)$pendingInvite['id']:null,'member_added_direct',null,['account_role'=>$newRole],$reason);$pdo->commit();return account_admin_members($pdo,(int)$account['id']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}function account_admin_update_member_role(PDO $pdo,array $admin,string $accountPublicId,int $userId,string $role,string $reason): array {
    account_admin_require_admin($admin);$seed=account_admin_get($pdo,$accountPublicId);if(!$seed)throw new RuntimeException('Account not found.');$reason=account_admin_reason($reason);
    return commercial_account_with_lock($pdo,(int)$seed['id'],function()use($pdo,$admin,$accountPublicId,$userId,$role,$reason){
        $account=account_admin_get($pdo,$accountPublicId);if(!$account)throw new RuntimeException('Account not found.');$newRole=in_array($role,['admin','member'],true)?$role:'member';
        $q=$pdo->prepare('SELECT account_role FROM account_members WHERE account_id=? AND user_id=?');$q->execute([(int)$account['id'],$userId]);$old=$q->fetchColumn();if($old===false)throw new RuntimeException('Account member not found.');if($old==='owner')throw new RuntimeException('Transfer ownership before changing the owner role.');if($old===$newRole)return account_admin_members($pdo,(int)$account['id']);
        $pdo->beginTransaction();try{$pdo->prepare('UPDATE account_members SET account_role=? WHERE account_id=? AND user_id=?')->execute([$newRole,(int)$account['id'],$userId]);account_admin_event($pdo,(int)$account['id'],(int)$admin['id'],$userId,'member_role_changed',['account_role'=>$old],['account_role'=>$newRole],$reason);if(function_exists('account_membership_event'))account_membership_event($pdo,(int)$account['id'],(int)$admin['id'],$userId,null,'member_role_changed',['account_role'=>$old],['account_role'=>$newRole],$reason);$pdo->commit();return account_admin_members($pdo,(int)$account['id']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}function account_admin_remove_member(PDO $pdo,array $admin,string $accountPublicId,int $userId,string $reason): array {
    account_admin_require_admin($admin);$seed=account_admin_get($pdo,$accountPublicId);if(!$seed)throw new RuntimeException('Account not found.');$reason=account_admin_reason($reason);
    return commercial_account_with_lock($pdo,(int)$seed['id'],function()use($pdo,$admin,$accountPublicId,$userId,$reason){
        $account=account_admin_get($pdo,$accountPublicId);if(!$account)throw new RuntimeException('Account not found.');$q=$pdo->prepare('SELECT account_role FROM account_members WHERE account_id=? AND user_id=?');$q->execute([(int)$account['id'],$userId]);$role=$q->fetchColumn();if($role===false)throw new RuntimeException('Account member not found.');if($role==='owner'||(int)$account['owner_user_id']===$userId)throw new RuntimeException('The account owner cannot be removed.');
        $pdo->beginTransaction();try{$pdo->prepare('DELETE FROM account_members WHERE account_id=? AND user_id=?')->execute([(int)$account['id'],$userId]);account_admin_event($pdo,(int)$account['id'],(int)$admin['id'],$userId,'member_removed',['account_role'=>$role],null,$reason);if(function_exists('account_membership_event'))account_membership_event($pdo,(int)$account['id'],(int)$admin['id'],$userId,null,'member_removed',['account_role'=>$role],null,$reason);$pdo->commit();return account_admin_members($pdo,(int)$account['id']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}function account_admin_transfer_owner(PDO $pdo,array $admin,string $accountPublicId,int $newOwnerUserId,string $reason): array {
    account_admin_require_admin($admin);$seed=account_admin_get($pdo,$accountPublicId);if(!$seed)throw new RuntimeException('Account not found.');$reason=account_admin_reason($reason);
    return commercial_account_with_lock($pdo,(int)$seed['id'],function()use($pdo,$admin,$accountPublicId,$newOwnerUserId,$reason){
        $account=account_admin_get($pdo,$accountPublicId);if(!$account)throw new RuntimeException('Account not found.');if($account['account_type']==='personal')throw new RuntimeException('Personal account ownership follows its personal user and cannot be transferred.');
        $q=$pdo->prepare('SELECT account_role FROM account_members WHERE account_id=? AND user_id=?');$q->execute([(int)$account['id'],$newOwnerUserId]);$role=$q->fetchColumn();if($role===false)throw new RuntimeException('The new owner must already be an account member.');$oldOwner=(int)$account['owner_user_id'];if($oldOwner===$newOwnerUserId)return $account;
        $pdo->beginTransaction();try{$pdo->prepare("UPDATE account_members SET account_role='admin' WHERE account_id=? AND user_id=?")->execute([(int)$account['id'],$oldOwner]);$pdo->prepare("UPDATE account_members SET account_role='owner' WHERE account_id=? AND user_id=?")->execute([(int)$account['id'],$newOwnerUserId]);$pdo->prepare('UPDATE accounts SET owner_user_id=? WHERE id=?')->execute([$newOwnerUserId,(int)$account['id']]);account_admin_event($pdo,(int)$account['id'],(int)$admin['id'],$newOwnerUserId,'owner_transferred',['owner_user_id'=>$oldOwner],['owner_user_id'=>$newOwnerUserId],$reason);if(function_exists('account_membership_event'))account_membership_event($pdo,(int)$account['id'],(int)$admin['id'],$newOwnerUserId,null,'ownership_transferred',['owner_user_id'=>$oldOwner],['owner_user_id'=>$newOwnerUserId],$reason);$pdo->commit();return account_admin_get($pdo,(int)$account['id'])?:throw new RuntimeException('Transferred account could not be loaded.');}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}function account_admin_set_override(PDO $pdo,array $admin,string $accountPublicId,string $key,mixed $value,?string $expiresAt,string $reason): array {
    account_admin_require_admin($admin);$seed=account_admin_get($pdo,$accountPublicId);if(!$seed)throw new RuntimeException('Account not found.');if(!account_admin_ready($pdo))throw new RuntimeException('Entitlement overrides require the latest database upgrade.');$reason=account_admin_reason($reason);
    return commercial_account_with_lock($pdo,(int)$seed['id'],function()use($pdo,$admin,$accountPublicId,$key,$value,$expiresAt,$reason){
        $account=account_admin_get($pdo,$accountPublicId);if(!$account)throw new RuntimeException('Account not found.');$entitlementKey=trim($key);if(!preg_match('/^(monthly_ai_token_allowance|member_limit|feature\.[a-z0-9][a-z0-9_.-]{0,90})$/',$entitlementKey))throw new InvalidArgumentException('Unsupported entitlement key.');
        $normalized=$value;if($entitlementKey==='monthly_ai_token_allowance')$normalized=$value===null?null:max(0,(int)$value);elseif($entitlementKey==='member_limit')$normalized=max(1,(int)$value);
        if($expiresAt!==null&&trim($expiresAt)!==''){try{$expires=(new DateTimeImmutable($expiresAt,new DateTimeZone('UTC')))->format('Y-m-d H:i:s');}catch(Throwable $e){throw new InvalidArgumentException('Invalid override expiry.');}}else $expires=null;
        $q=$pdo->prepare('SELECT * FROM account_entitlement_overrides WHERE account_id=? AND entitlement_key=? LIMIT 1');$q->execute([(int)$account['id'],$entitlementKey]);$before=$q->fetch()?:null;$json=json_encode($normalized,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if($entitlementKey==='member_limit'){$countQ=$pdo->prepare('SELECT COUNT(*) FROM account_members WHERE account_id=?');$countQ->execute([(int)$account['id']]);$occupied=(int)$countQ->fetchColumn();$reserved=function_exists('account_membership_pending_reserved_count')?account_membership_pending_reserved_count($pdo,(int)$account['id']):0;if((int)$normalized<$occupied+$reserved)throw new RuntimeException('Member limit cannot be set below current members plus reserved invitations.');}
        $pdo->beginTransaction();try{$pdo->prepare("INSERT INTO account_entitlement_overrides(public_id,account_id,entitlement_key,value_json,status,expires_at,reason,created_by_user_id,updated_by_user_id) VALUES(?,?,?,?,'active',?,?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),status='active',expires_at=VALUES(expires_at),reason=VALUES(reason),updated_by_user_id=VALUES(updated_by_user_id)")->execute([ulid_like(),(int)$account['id'],$entitlementKey,$json,$expires,$reason,(int)$admin['id'],(int)$admin['id']]);$after=account_admin_effective_entitlements($pdo,(int)$account['id']);account_admin_event($pdo,(int)$account['id'],(int)$admin['id'],null,'entitlement_override_set',$before,['entitlement_key'=>$entitlementKey,'value'=>$normalized,'expires_at'=>$expires],$reason);$pdo->commit();return $after;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}function account_admin_revoke_override(PDO $pdo,array $admin,string $accountPublicId,string $key,string $reason): array {
    account_admin_require_admin($admin);$seed=account_admin_get($pdo,$accountPublicId);if(!$seed)throw new RuntimeException('Account not found.');$reason=account_admin_reason($reason);
    return commercial_account_with_lock($pdo,(int)$seed['id'],function()use($pdo,$admin,$accountPublicId,$key,$reason){
        $account=account_admin_get($pdo,$accountPublicId);if(!$account)throw new RuntimeException('Account not found.');$q=$pdo->prepare("SELECT * FROM account_entitlement_overrides WHERE account_id=? AND entitlement_key=? AND status='active' LIMIT 1");$q->execute([(int)$account['id'],$key]);$before=$q->fetch();if(!$before)throw new RuntimeException('Active entitlement override not found.');
        if($key==='member_limit'){$q=$pdo->prepare('SELECT COUNT(*) FROM account_members WHERE account_id=?');$q->execute([(int)$account['id']]);$occupied=(int)$q->fetchColumn();$reserved=function_exists('account_membership_pending_reserved_count')?account_membership_pending_reserved_count($pdo,(int)$account['id']):0;if($occupied+$reserved>(int)$account['member_limit'])throw new RuntimeException('Reduce members or reserved invitations to the package member limit before revoking this override.');}
        $pdo->beginTransaction();try{$pdo->prepare("UPDATE account_entitlement_overrides SET status='revoked',updated_by_user_id=? WHERE id=?")->execute([(int)$admin['id'],(int)$before['id']]);account_admin_event($pdo,(int)$account['id'],(int)$admin['id'],null,'entitlement_override_revoked',$before,['entitlement_key'=>$key,'status'=>'revoked'],$reason);$pdo->commit();return account_admin_effective_entitlements($pdo,(int)$account['id']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}function account_admin_events(PDO $pdo,int $accountId,int $limit=100): array {
    if(!account_admin_ready($pdo))return [];$limit=max(1,min(500,$limit));$q=$pdo->prepare("SELECT e.*,a.username actor_username,s.username subject_username FROM account_admin_events e LEFT JOIN users a ON a.id=e.actor_user_id LEFT JOIN users s ON s.id=e.subject_user_id WHERE e.account_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT ".$limit);$q->execute([$accountId]);return $q->fetchAll()?:[];
}
