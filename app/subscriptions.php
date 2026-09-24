<?php
declare(strict_types=1);

function subscriptions_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'subscription_packages')&&installer_table_exists($pdo,'subscription_package_admin_events')&&installer_table_exists($pdo,'accounts')&&installer_table_exists($pdo,'account_members')&&installer_table_exists($pdo,'subscription_package_events');}
    catch(Throwable $e){return false;}
}
function subscription_packages(PDO $pdo,bool $activeOnly=false): array {
    if(!subscriptions_ready($pdo))return [];
    $sql='SELECT * FROM subscription_packages'.($activeOnly?" WHERE status='active'":'').' ORDER BY sort_order,name,id';
    return $pdo->query($sql)->fetchAll()?:[];
}
function subscription_package(PDO $pdo,string|int $identifier): ?array {
    if(!subscriptions_ready($pdo))return null;
    if(is_int($identifier)||ctype_digit((string)$identifier)){$q=$pdo->prepare('SELECT * FROM subscription_packages WHERE id=? LIMIT 1');$q->execute([(int)$identifier]);}
    else{$q=$pdo->prepare('SELECT * FROM subscription_packages WHERE public_id=? OR slug=? LIMIT 1');$q->execute([(string)$identifier,(string)$identifier]);}
    return $q->fetch()?:null;
}
function subscription_default_package(PDO $pdo): array {
    $p=subscription_package($pdo,'free-trial');if(!$p)throw new RuntimeException('Free Trial package is not configured.');return $p;
}
function subscription_period_from(DateTimeImmutable $start): array {
    return [$start->format('Y-m-d'),$start->modify('+1 month')->format('Y-m-d')];
}
function subscription_ensure_user_account(PDO $pdo,int $userId,?int $actorUserId=null): array {
    if(!subscriptions_ready($pdo))throw new RuntimeException('Subscriptions & Packages requires the latest database upgrade.');
    $q=$pdo->prepare("SELECT a.*,p.public_id package_public_id,p.slug package_slug,p.name package_name,p.legacy_plan_tier,p.monthly_price_cents,p.monthly_ai_token_allowance,p.member_limit,p.trial_days
      FROM accounts a JOIN subscription_packages p ON p.id=a.package_id WHERE a.personal_user_id=? LIMIT 1");$q->execute([$userId]);$row=$q->fetch();if($row)return $row;
    return app_with_advisory_lock($pdo,'personal-account',$userId,function()use($pdo,$userId,$actorUserId){
        $q=$pdo->prepare("SELECT a.*,p.public_id package_public_id,p.slug package_slug,p.name package_name,p.legacy_plan_tier,p.monthly_price_cents,p.monthly_ai_token_allowance,p.member_limit,p.trial_days
          FROM accounts a JOIN subscription_packages p ON p.id=a.package_id WHERE a.personal_user_id=? LIMIT 1");$q->execute([$userId]);$existing=$q->fetch();if($existing)return $existing;
        $q=$pdo->prepare("SELECT id,public_id,display_name,created_at FROM users WHERE id=? AND status IN ('active','suspended') LIMIT 1");$q->execute([$userId]);$user=$q->fetch();if(!$user)throw new RuntimeException('User account not found.');
        $package=subscription_default_package($pdo);$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));[$start,$end]=subscription_period_from($now);$trialEnds=(int)$package['trial_days']>0?$now->modify('+'.(int)$package['trial_days'].' days')->format('Y-m-d H:i:s'):null;
        $pdo->beginTransaction();try{
            $public=ulid_like();$q=$pdo->prepare("INSERT INTO accounts(public_id,account_type,name,owner_user_id,personal_user_id,package_id,subscription_status,period_start,period_end,trial_ends_at) VALUES(?,'personal',?,?,?,?,?,?,?,?)");
            $q->execute([$public,(string)$user['display_name'].' Account',$userId,$userId,(int)$package['id'],$trialEnds?'trialing':'active',$start,$end,$trialEnds]);$accountId=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO account_members(account_id,user_id,account_role) VALUES(?,?,'owner')")->execute([$accountId,$userId]);
            $pdo->prepare("INSERT INTO subscription_package_events(public_id,account_id,user_id,new_package_id,actor_user_id,event_type,reason,metadata_json) VALUES(?,?,?,?,?,'account_created',?,?)")
              ->execute([ulid_like(),$accountId,$userId,(int)$package['id'],$actorUserId,'Automatic personal account creation.',json_encode(['source'=>'runtime'],JSON_UNESCAPED_SLASHES)]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        $q=$pdo->prepare("SELECT a.*,p.public_id package_public_id,p.slug package_slug,p.name package_name,p.legacy_plan_tier,p.monthly_price_cents,p.monthly_ai_token_allowance,p.member_limit,p.trial_days
          FROM accounts a JOIN subscription_packages p ON p.id=a.package_id WHERE a.personal_user_id=? LIMIT 1");$q->execute([$userId]);return $q->fetch()?:throw new RuntimeException('Personal account creation failed.');
    });
}
function subscription_user_account(PDO $pdo,int $userId,bool $ensure=true): ?array {
    if(!subscriptions_ready($pdo))return null;
    $q=$pdo->prepare("SELECT a.*,p.public_id package_public_id,p.slug package_slug,p.name package_name,p.description package_description,p.status package_status,p.is_public,p.legacy_plan_tier,p.billing_interval,p.monthly_price_cents,p.monthly_ai_token_allowance,p.member_limit,p.trial_days,p.feature_json
      FROM accounts a JOIN subscription_packages p ON p.id=a.package_id WHERE a.personal_user_id=? LIMIT 1");$q->execute([$userId]);$row=$q->fetch();
    return $row?:($ensure?subscription_ensure_user_account($pdo,$userId):null);
}
function subscription_assign_user_package(PDO $pdo,array $admin,int $userId,string $packagePublicId,string $reason=''): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
    $package=subscription_package($pdo,$packagePublicId);if(!$package||$package['status']!=='active')throw new RuntimeException('Select an active subscription package.');
    $account=subscription_ensure_user_account($pdo,$userId,(int)$admin['id']);$oldId=(int)$account['package_id'];$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));[$start,$end]=subscription_period_from($now);$trialEnds=(int)$package['trial_days']>0?$now->modify('+'.(int)$package['trial_days'].' days')->format('Y-m-d H:i:s'):null;$status=$trialEnds?'trialing':'active';
    if($oldId===(int)$package['id'])return subscription_user_account($pdo,$userId,true)??$account;
    $pdo->beginTransaction();try{
        $pdo->prepare('UPDATE accounts SET package_id=?,subscription_status=?,period_start=?,period_end=?,trial_ends_at=?,package_assigned_at=NOW() WHERE id=?')->execute([(int)$package['id'],$status,$start,$end,$trialEnds,(int)$account['id']]);
        $pdo->prepare('UPDATE users SET plan_tier=?,pro_expires_at=NULL WHERE id=?')->execute([(string)$package['legacy_plan_tier'],$userId]);
        $pdo->prepare("INSERT INTO subscription_package_events(public_id,account_id,user_id,previous_package_id,new_package_id,actor_user_id,event_type,reason,metadata_json) VALUES(?,?,?,?,?,?,'package_changed',?,?)")
          ->execute([ulid_like(),(int)$account['id'],$userId,$oldId,(int)$package['id'],(int)$admin['id'],trim($reason)!==''?trim($reason):'Admin changed user package.',json_encode(['source'=>'admin_users'],JSON_UNESCAPED_SLASHES)]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return subscription_user_account($pdo,$userId,true)??throw new RuntimeException('Updated package could not be loaded.');
}
function subscription_package_create(PDO $pdo,array $admin,array $input): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
    $name=trim((string)($input['name']??''));if($name==='')throw new InvalidArgumentException('Package name is required.');
    $slug=strtolower(trim((string)($input['slug']??'')));$slug=preg_replace('/[^a-z0-9]+/','-',$slug)?:'';$slug=trim($slug,'-');if($slug===''||strlen($slug)>80)throw new InvalidArgumentException('Enter a valid package slug.');
    if(subscription_package($pdo,$slug))throw new RuntimeException('That package slug already exists.');
    $description=trim((string)($input['description']??''));$legacy=($input['legacy_plan_tier']??'free')==='pro'?'pro':'free';$price=max(0,(int)round(((float)($input['monthly_price']??0))*100));$tokens=trim((string)($input['monthly_ai_token_allowance']??''));$tokenValue=$tokens===''?null:max(0,(int)$tokens);$members=max(1,min(100000,(int)($input['member_limit']??1)));$trial=max(0,min(3650,(int)($input['trial_days']??0)));$sort=(int)($input['sort_order']??100);
    $pdo->beginTransaction();try{
        $public=ulid_like();$pdo->prepare("INSERT INTO subscription_packages(public_id,slug,name,description,status,is_public,legacy_plan_tier,monthly_price_cents,monthly_ai_token_allowance,member_limit,trial_days,sort_order) VALUES(?,?,?,?,'active',1,?,?,?,?,?,?)")
          ->execute([$public,$slug,$name,$description!==''?$description:null,$legacy,$price,$tokenValue,$members,$trial,$sort]);$id=(int)$pdo->lastInsertId();
        $after=subscription_package($pdo,$id);$pdo->prepare("INSERT INTO subscription_package_admin_events(public_id,package_id,actor_user_id,event_type,after_json,reason) VALUES(?,?,?,'created',?,?)")
          ->execute([ulid_like(),$id,(int)$admin['id'],json_encode($after,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'Admin created package.']);$pdo->commit();
        return $after?:throw new RuntimeException('Package creation failed.');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function subscription_package_update(PDO $pdo,array $admin,string $publicId,array $input): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
    $p=subscription_package($pdo,$publicId);if(!$p)throw new RuntimeException('Package not found.');
    $name=trim((string)($input['name']??''));if($name==='')throw new InvalidArgumentException('Package name is required.');
    $description=trim((string)($input['description']??''));$status=($input['status']??'active')==='archived'?'archived':'active';$isPublic=!empty($input['is_public'])?1:0;$legacy=($input['legacy_plan_tier']??'free')==='pro'?'pro':'free';
    $price=max(0,(int)round(((float)($input['monthly_price']??0))*100));$tokens=trim((string)($input['monthly_ai_token_allowance']??''));$tokenValue=$tokens===''?null:max(0,(int)$tokens);$members=max(1,min(100000,(int)($input['member_limit']??1)));$trial=max(0,min(3650,(int)($input['trial_days']??0)));$sort=(int)($input['sort_order']??0);
    $before=$p;$pdo->beginTransaction();try{
        $pdo->prepare('UPDATE subscription_packages SET name=?,description=?,status=?,is_public=?,legacy_plan_tier=?,monthly_price_cents=?,monthly_ai_token_allowance=?,member_limit=?,trial_days=?,sort_order=? WHERE id=?')
          ->execute([$name,$description!==''?$description:null,$status,$isPublic,$legacy,$price,$tokenValue,$members,$trial,$sort,(int)$p['id']]);
        if($legacy!==(string)$p['legacy_plan_tier']){$pdo->prepare('UPDATE users u JOIN accounts a ON a.personal_user_id=u.id SET u.plan_tier=? WHERE a.package_id=?')->execute([$legacy,(int)$p['id']]);}
        $after=subscription_package($pdo,$publicId);$event=$status!==$p['status']?($status==='archived'?'archived':'reactivated'):'updated';
        $pdo->prepare('INSERT INTO subscription_package_admin_events(public_id,package_id,actor_user_id,event_type,before_json,after_json,reason) VALUES(?,?,?,?,?,?,?)')
          ->execute([ulid_like(),(int)$p['id'],(int)$admin['id'],$event,json_encode($before,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),json_encode($after,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'Admin updated package definition.']);
        $pdo->commit();return $after?:throw new RuntimeException('Package update failed.');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function subscription_package_account_count(PDO $pdo,int $packageId): int {
    $q=$pdo->prepare("SELECT COUNT(*) FROM accounts WHERE package_id=? AND status='active'");$q->execute([$packageId]);return (int)$q->fetchColumn();
}
function subscription_recent_events(PDO $pdo,int $limit=50): array {
    if(!subscriptions_ready($pdo))return [];$limit=max(1,min(250,$limit));
    $q=$pdo->query("SELECT e.*,a.public_id account_public_id,a.name account_name,u.username,u.display_name,actor.username actor_username,op.name old_package_name,np.name new_package_name
      FROM subscription_package_events e JOIN accounts a ON a.id=e.account_id LEFT JOIN users u ON u.id=e.user_id LEFT JOIN users actor ON actor.id=e.actor_user_id
      LEFT JOIN subscription_packages op ON op.id=e.previous_package_id LEFT JOIN subscription_packages np ON np.id=e.new_package_id ORDER BY e.created_at DESC,e.id DESC LIMIT ".$limit);
    return $q->fetchAll()?:[];
}

function subscription_package_admin_events(PDO $pdo,int $limit=50): array {
    if(!subscriptions_ready($pdo))return [];$limit=max(1,min(250,$limit));
    $q=$pdo->query("SELECT e.*,p.name package_name,p.slug package_slug,u.username actor_username FROM subscription_package_admin_events e JOIN subscription_packages p ON p.id=e.package_id LEFT JOIN users u ON u.id=e.actor_user_id ORDER BY e.created_at DESC,e.id DESC LIMIT ".$limit);
    return $q->fetchAll()?:[];
}
