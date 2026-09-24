<?php
declare(strict_types=1);

function stripe_billing_ready(PDO $pdo): bool {
    try{
        return subscriptions_ready($pdo)
            && installer_table_exists($pdo,'stripe_billing_settings')
            && installer_table_exists($pdo,'stripe_package_prices')
            && installer_table_exists($pdo,'stripe_customers')
            && installer_table_exists($pdo,'stripe_subscriptions')
            && installer_table_exists($pdo,'stripe_invoices')
            && installer_table_exists($pdo,'stripe_webhook_events')
            && installer_table_exists($pdo,'account_billing_events')
            && installer_table_exists($pdo,'stripe_account_state_watermarks')
            && installer_table_exists($pdo,'stripe_checkout_sessions');
    }catch(Throwable $e){return false;}
}
function stripe_billing_crypto_key(array $config): string {
    $raw=(string)($config['app']['encryption_key']??'');
    if(strlen($raw)<24)throw new RuntimeException('Set app.encryption_key before storing Stripe secrets.');
    return hash('sha256',$raw,true);
}
function stripe_billing_encrypt_secret(array $config,string $plain): string {
    if($plain==='')return '';
    $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($plain,'aes-256-gcm',stripe_billing_crypto_key($config),OPENSSL_RAW_DATA,$iv,$tag,'annotated-stripe');
    if($cipher===false)throw new RuntimeException('Unable to encrypt Stripe secret.');
    return base64_encode($iv.$tag.$cipher);
}
function stripe_billing_decrypt_secret(array $config,?string $encoded): string {
    if(!$encoded)return '';
    $raw=base64_decode($encoded,true);if($raw===false||strlen($raw)<29)throw new RuntimeException('Invalid encrypted Stripe secret.');
    $iv=substr($raw,0,12);$tag=substr($raw,12,16);$cipher=substr($raw,28);
    $plain=openssl_decrypt($cipher,'aes-256-gcm',stripe_billing_crypto_key($config),OPENSSL_RAW_DATA,$iv,$tag,'annotated-stripe');
    if($plain===false)throw new RuntimeException('Unable to decrypt Stripe secret.');
    return $plain;
}
function stripe_billing_settings(PDO $pdo): array {
    if(!stripe_billing_ready($pdo))return ['id'=>1,'mode'=>'test','publishable_key'=>null,'secret_key_ciphertext'=>null,'webhook_secret_ciphertext'=>null,'checkout_success_path'=>'/billing.php?checkout=success','checkout_cancel_path'=>'/billing.php?checkout=cancelled','portal_return_path'=>'/billing.php'];
    $row=$pdo->query('SELECT * FROM stripe_billing_settings WHERE id=1')->fetch();
    return $row?:['id'=>1,'mode'=>'test','publishable_key'=>null,'secret_key_ciphertext'=>null,'webhook_secret_ciphertext'=>null,'checkout_success_path'=>'/billing.php?checkout=success','checkout_cancel_path'=>'/billing.php?checkout=cancelled','portal_return_path'=>'/billing.php'];
}
function stripe_billing_configured(PDO $pdo,array $config): bool {
    if(!stripe_billing_ready($pdo))return false;
    $s=stripe_billing_settings($pdo);
    try{return stripe_billing_decrypt_secret($config,$s['secret_key_ciphertext']??null)!==''&&stripe_billing_decrypt_secret($config,$s['webhook_secret_ciphertext']??null)!=='';}
    catch(Throwable $e){return false;}
}
function stripe_billing_save_settings(PDO $pdo,array $config,array $admin,array $input): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
    if(!stripe_billing_ready($pdo))throw new RuntimeException('Stripe Billing requires the latest database upgrade.');
    $current=stripe_billing_settings($pdo);$mode=($input['mode']??'test')==='live'?'live':'test';
    $publishable=trim((string)($input['publishable_key']??$current['publishable_key']??''));
    if($publishable!==''&&!str_starts_with($publishable,$mode==='live'?'pk_live_':'pk_test_'))throw new InvalidArgumentException('Publishable key does not match the selected Stripe mode.');
    $secret=trim((string)($input['secret_key']??''));$webhook=trim((string)($input['webhook_secret']??''));
    if($secret!==''){ $prefixes=$mode==='live'?['sk_live_','rk_live_']:['sk_test_','rk_test_'];$valid=false;foreach($prefixes as $prefix)if(str_starts_with($secret,$prefix)){$valid=true;break;}if(!$valid)throw new InvalidArgumentException('Secret or restricted key does not match the selected Stripe mode.'); }
    if($webhook!==''&&!str_starts_with($webhook,'whsec_'))throw new InvalidArgumentException('Webhook signing secret must begin with whsec_.');
    $secretCipher=$secret!==''?stripe_billing_encrypt_secret($config,$secret):($current['secret_key_ciphertext']??null);
    $webhookCipher=$webhook!==''?stripe_billing_encrypt_secret($config,$webhook):($current['webhook_secret_ciphertext']??null);
    if($secretCipher){$resolved=stripe_billing_decrypt_secret($config,$secretCipher);$prefixes=$mode==='live'?['sk_live_','rk_live_']:['sk_test_','rk_test_'];$valid=$resolved==='';foreach($prefixes as $prefix)if(str_starts_with($resolved,$prefix)){$valid=true;break;}if(!$valid)throw new InvalidArgumentException('Configured Stripe secret or restricted key does not match the selected mode.');}
    $cleanPath=function(mixed $value,string $fallback): string {$v=trim((string)$value);return $v!==''&&str_starts_with($v,'/')&&!str_starts_with($v,'//')?mb_substr($v,0,255):$fallback;};
    $success=$cleanPath($input['checkout_success_path']??null,'/billing.php?checkout=success');
    $cancel=$cleanPath($input['checkout_cancel_path']??null,'/billing.php?checkout=cancelled');
    $portal=$cleanPath($input['portal_return_path']??null,'/billing.php');
    $pdo->prepare("UPDATE stripe_billing_settings SET mode=?,publishable_key=?,secret_key_ciphertext=?,webhook_secret_ciphertext=?,checkout_success_path=?,checkout_cancel_path=?,portal_return_path=?,updated_by_user_id=? WHERE id=1")
      ->execute([$mode,$publishable!==''?$publishable:null,$secretCipher,$webhookCipher,$success,$cancel,$portal,(int)$admin['id']]);
    return stripe_billing_settings($pdo);
}
function stripe_billing_secret_key(array $config,array $settings): string {
    $key=stripe_billing_decrypt_secret($config,$settings['secret_key_ciphertext']??null);
    if($key==='')throw new RuntimeException('Stripe secret key is not configured.');
    return $key;
}
function stripe_billing_api_request(array $config,array $settings,string $method,string $path,array $params=[],?string $idempotencyKey=null): array {
    $method=strtoupper($method);$url='https://api.stripe.com/v1/'.ltrim($path,'/');$key=stripe_billing_secret_key($config,$settings);
    $headers=['Authorization: Basic '.base64_encode($key.':'),'Accept: application/json'];if($idempotencyKey)$headers[]='Idempotency-Key: '.mb_substr($idempotencyKey,0,255);
    $ch=curl_init();
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>45,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_HTTPHEADER=>$headers,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS];
    if($method==='GET'&&$params)$url.='?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
    elseif($method!=='GET'){$headers[]='Content-Type: application/x-www-form-urlencoded';$opts[CURLOPT_HTTPHEADER]=$headers;$opts[CURLOPT_CUSTOMREQUEST]=$method;$opts[CURLOPT_POSTFIELDS]=http_build_query($params,'','&',PHP_QUERY_RFC3986);}
    curl_setopt($ch,CURLOPT_URL,$url);curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($raw===false)throw new RuntimeException('Stripe connection failed'.($err?': '.$err:''));
    $json=json_decode((string)$raw,true);if(!is_array($json))$json=[];
    if($status<200||$status>=300){$message=(string)($json['error']['message']??'Stripe request failed.');throw new RuntimeException($message);}
    return $json;
}
function stripe_billing_test_connection(PDO $pdo,array $config): array {
    $settings=stripe_billing_settings($pdo);return stripe_billing_api_request($config,$settings,'GET','account');
}
function stripe_billing_active_price(PDO $pdo,int $packageId,?string $mode=null): ?array {
    if(!stripe_billing_ready($pdo))return null;$mode=$mode?:((string)(stripe_billing_settings($pdo)['mode']??'test'));
    $q=$pdo->prepare("SELECT spp.*,p.public_id package_public_id,p.slug package_slug,p.name package_name,p.monthly_price_cents,p.billing_interval FROM stripe_package_prices spp JOIN subscription_packages p ON p.id=spp.package_id WHERE spp.package_id=? AND spp.mode=? AND spp.active=1 ORDER BY spp.id DESC LIMIT 1");
    $q->execute([$packageId,$mode]);return $q->fetch()?:null;
}
function stripe_billing_price_for_stripe_id(PDO $pdo,string $stripePriceId,?string $mode=null): ?array {
    if(!stripe_billing_ready($pdo))return null;$mode=$mode?:((string)(stripe_billing_settings($pdo)['mode']??'test'));$q=$pdo->prepare("SELECT spp.*,p.public_id package_public_id,p.slug package_slug,p.name package_name,p.legacy_plan_tier,p.member_limit,p.monthly_ai_token_allowance FROM stripe_package_prices spp JOIN subscription_packages p ON p.id=spp.package_id WHERE spp.mode=? AND spp.stripe_price_id=? LIMIT 1");$q->execute([$mode,$stripePriceId]);return $q->fetch()?:null;
}
function stripe_billing_package_rows(PDO $pdo): array {
    $rows=subscription_packages($pdo,false);$mode=(string)(stripe_billing_settings($pdo)['mode']??'test');
    foreach($rows as &$row)$row['stripe_price']=stripe_billing_active_price($pdo,(int)$row['id'],$mode);unset($row);return $rows;
}
function stripe_billing_store_price_mapping(PDO $pdo,int $packageId,string $mode,string $productId,string $priceId,string $currency,int $amount,int $actorUserId): array {
    $pdo->beginTransaction();try{
        $q=$pdo->prepare('SELECT * FROM stripe_package_prices WHERE mode=? AND stripe_price_id=? LIMIT 1');$q->execute([$mode,$priceId]);$existing=$q->fetch();
        if($existing&&(int)$existing['package_id']!==$packageId)throw new RuntimeException('Stripe Price is already mapped to another Annotated package in this mode.');
        $pdo->prepare('UPDATE stripe_package_prices SET active=0 WHERE package_id=? AND mode=?')->execute([$packageId,$mode]);
        if($existing){
            $pdo->prepare("UPDATE stripe_package_prices SET stripe_product_id=?,currency=?,unit_amount_cents=?,billing_interval='month',active=1,created_by_user_id=? WHERE id=?")->execute([$productId,$currency,$amount,$actorUserId,(int)$existing['id']]);
        }else{
            $pdo->prepare("INSERT INTO stripe_package_prices(public_id,package_id,mode,stripe_product_id,stripe_price_id,currency,unit_amount_cents,billing_interval,active,created_by_user_id) VALUES(?,?,?,?,?,?,?,'month',1,?)")->execute([ulid_like(),$packageId,$mode,$productId,$priceId,$currency,$amount,$actorUserId]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return stripe_billing_active_price($pdo,$packageId,$mode)??throw new RuntimeException('Stripe Price mapping could not be saved.');
}
function stripe_billing_sync_package_price(PDO $pdo,array $config,array $admin,string $packagePublicId): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
    $package=subscription_package($pdo,$packagePublicId);if(!$package||$package['status']!=='active')throw new RuntimeException('Choose an active package.');
    $amount=(int)$package['monthly_price_cents'];if($amount<=0)throw new RuntimeException('Stripe recurring prices are only created for paid packages.');
    $settings=stripe_billing_settings($pdo);$mode=(string)$settings['mode'];$current=stripe_billing_active_price($pdo,(int)$package['id'],$mode);
    if($current&&(int)$current['unit_amount_cents']===$amount&&strtolower((string)$current['currency'])==='usd'){stripe_billing_api_request($config,$settings,'POST','products/'.rawurlencode((string)$current['stripe_product_id']),['name'=>(string)$package['name'],'description'=>(string)($package['description']??''),'metadata'=>['annotated_package_id'=>(string)$package['public_id'],'annotated_package_slug'=>(string)$package['slug']]]);return $current;}
    $productId=$current['stripe_product_id']??null;
    if(!$productId){
        $product=stripe_billing_api_request($config,$settings,'POST','products',['name'=>(string)$package['name'],'description'=>(string)($package['description']??''),'metadata'=>['annotated_package_id'=>(string)$package['public_id'],'annotated_package_slug'=>(string)$package['slug']]],'annotated-product-'.$mode.'-'.$package['public_id']);
        $productId=(string)($product['id']??'');if($productId==='')throw new RuntimeException('Stripe did not return a Product ID.');
    }else{
        stripe_billing_api_request($config,$settings,'POST','products/'.rawurlencode((string)$productId),['name'=>(string)$package['name'],'description'=>(string)($package['description']??''),'metadata'=>['annotated_package_id'=>(string)$package['public_id'],'annotated_package_slug'=>(string)$package['slug']]]);
    }
    $price=stripe_billing_api_request($config,$settings,'POST','prices',['product'=>$productId,'currency'=>'usd','unit_amount'=>$amount,'recurring'=>['interval'=>'month'],'metadata'=>['annotated_package_id'=>(string)$package['public_id']]],'annotated-price-'.$mode.'-'.$package['public_id'].'-'.$amount);
    $priceId=(string)($price['id']??'');if($priceId==='')throw new RuntimeException('Stripe did not return a Price ID.');
    $mapped=stripe_billing_store_price_mapping($pdo,(int)$package['id'],$mode,(string)$productId,$priceId,'usd',$amount,(int)$admin['id']);
    if($current&&(string)$current['stripe_price_id']!==$priceId){
        try{stripe_billing_api_request($config,$settings,'POST','prices/'.rawurlencode((string)$current['stripe_price_id']),['active'=>'false']);}
        catch(Throwable $e){$mapped['rotation_warning']='The new Stripe Price is active in Annotated, but the previous Stripe Price could not be archived automatically: '.$e->getMessage();}
    }
    return $mapped;
}
function stripe_billing_map_existing_price(PDO $pdo,array $config,array $admin,string $packagePublicId,string $priceId): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
    $package=subscription_package($pdo,$packagePublicId);if(!$package)throw new RuntimeException('Package not found.');$settings=stripe_billing_settings($pdo);$mode=(string)$settings['mode'];
    $price=stripe_billing_api_request($config,$settings,'GET','prices/'.rawurlencode(trim($priceId)));$productId=is_string($price['product']??null)?(string)$price['product']:'';
    if(empty($price['active'])||($price['type']??'')!=='recurring'||($price['recurring']['interval']??'')!=='month'||$productId==='')throw new RuntimeException('Choose an active recurring monthly Stripe Price.');
    $amount=(int)($price['unit_amount']??0);$currency=strtolower((string)($price['currency']??'usd'));if($currency!=='usd'||$amount!==(int)$package['monthly_price_cents'])throw new RuntimeException('Stripe Price currency/amount must match the Annotated monthly package price.');
    return stripe_billing_store_price_mapping($pdo,(int)$package['id'],$mode,$productId,(string)$price['id'],$currency,$amount,(int)$admin['id']);
}
function stripe_billing_account_owner(PDO $pdo,int $accountId): ?array {
    $q=$pdo->prepare("SELECT u.id,u.public_id,u.username,u.display_name,u.email,u.status FROM accounts a JOIN users u ON u.id=a.owner_user_id WHERE a.id=? LIMIT 1");$q->execute([$accountId]);return $q->fetch()?:null;
}
function stripe_billing_customer(PDO $pdo,int $accountId,?string $mode=null): ?array {
    if(!stripe_billing_ready($pdo))return null;$mode=$mode?:((string)(stripe_billing_settings($pdo)['mode']??'test'));$q=$pdo->prepare('SELECT * FROM stripe_customers WHERE account_id=? AND mode=? LIMIT 1');$q->execute([$accountId,$mode]);return $q->fetch()?:null;
}
function stripe_billing_link_customer(PDO $pdo,int $accountId,string $mode,string $customerId,?string $email=null,?string $name=null,array $metadata=[]): array {
    $q=$pdo->prepare('SELECT account_id FROM stripe_customers WHERE mode=? AND stripe_customer_id=? LIMIT 1');$q->execute([$mode,$customerId]);$existingByStripe=$q->fetch();
    if($existingByStripe&&(int)$existingByStripe['account_id']!==$accountId)throw new RuntimeException('Stripe Customer is already linked to a different Annotated account in this mode.');
    $existing=stripe_billing_customer($pdo,$accountId,$mode);
    if($existing){$pdo->prepare('UPDATE stripe_customers SET stripe_customer_id=?,email_snapshot=COALESCE(?,email_snapshot),name_snapshot=COALESCE(?,name_snapshot),metadata_json=?,last_synced_at=NOW() WHERE id=?')->execute([$customerId,$email,$name,json_encode($metadata,JSON_UNESCAPED_SLASHES),(int)$existing['id']]);}
    else{$pdo->prepare("INSERT INTO stripe_customers(public_id,account_id,mode,stripe_customer_id,email_snapshot,name_snapshot,metadata_json,last_synced_at) VALUES(?,?,?,?,?,?,?,NOW())")->execute([ulid_like(),$accountId,$mode,$customerId,$email,$name,json_encode($metadata,JSON_UNESCAPED_SLASHES)]);}
    return stripe_billing_customer($pdo,$accountId,$mode)??throw new RuntimeException('Stripe Customer mapping could not be saved.');
}
function stripe_billing_customer_ensure(PDO $pdo,array $config,array $account): array {
    $settings=stripe_billing_settings($pdo);$mode=(string)$settings['mode'];$existing=stripe_billing_customer($pdo,(int)$account['id'],$mode);if($existing)return $existing;
    $owner=stripe_billing_account_owner($pdo,(int)$account['id']);$customer=stripe_billing_api_request($config,$settings,'POST','customers',[
        'name'=>(string)$account['name'],'email'=>(string)($owner['email']??''),'metadata'=>['annotated_account_id'=>(string)$account['public_id'],'annotated_account_type'=>(string)$account['account_type']]
    ],'annotated-customer-'.$mode.'-'.$account['public_id']);
    $id=(string)($customer['id']??'');if($id==='')throw new RuntimeException('Stripe did not return a Customer ID.');
    return stripe_billing_link_customer($pdo,(int)$account['id'],$mode,$id,(string)($owner['email']??''),(string)$account['name'],['annotated_account_id'=>$account['public_id']]);
}
function stripe_billing_current_subscription(PDO $pdo,int $accountId,?string $mode=null): ?array {
    if(!stripe_billing_ready($pdo))return null;$mode=$mode?:((string)(stripe_billing_settings($pdo)['mode']??'test'));
    $q=$pdo->prepare("SELECT * FROM stripe_subscriptions WHERE account_id=? AND mode=? AND status NOT IN ('canceled','incomplete_expired') ORDER BY updated_at DESC,id DESC LIMIT 1");$q->execute([$accountId,$mode]);return $q->fetch()?:null;
}
function stripe_billing_absolute_url(array $config,string $path): string {
    $base=rtrim((string)($config['app']['base_url']??''),'/');$parts=parse_url($base);if(!$parts||strtolower((string)($parts['scheme']??''))!=='https'||empty($parts['host']))throw new RuntimeException('Stripe billing requires an HTTPS app.base_url.');
    if(!str_starts_with($path,'/')||str_starts_with($path,'//'))throw new RuntimeException('Invalid Stripe return path.');
    return $base.$path;
}
function stripe_billing_hosted_url(string $url): string {
    $parts=parse_url($url);$host=strtolower((string)($parts['host']??''));$scheme=strtolower((string)($parts['scheme']??''));
    if($scheme!=='https'||($host!=='stripe.com'&&!str_ends_with($host,'.stripe.com')))throw new RuntimeException('Stripe returned an invalid hosted redirect URL.');
    return $url;
}
function stripe_billing_user_accounts(PDO $pdo,array $user): array {
    if(!subscriptions_ready($pdo))return [];$q=$pdo->prepare("SELECT a.*,am.account_role,p.public_id package_public_id,p.slug package_slug,p.name package_name,p.description package_description,p.status package_status,p.is_public,p.legacy_plan_tier,p.billing_interval,p.monthly_price_cents,p.monthly_ai_token_allowance,p.member_limit,p.trial_days,p.feature_json
      FROM account_members am JOIN accounts a ON a.id=am.account_id JOIN subscription_packages p ON p.id=a.package_id
      WHERE am.user_id=? AND am.account_role IN ('owner','admin') AND a.status<>'closed'
      ORDER BY (a.personal_user_id=? ) DESC,a.name,a.id");$q->execute([(int)$user['id'],(int)$user['id']]);return $q->fetchAll()?:[];
}
function stripe_billing_account_for_user(PDO $pdo,array $user,?string $accountPublicId=null): array {
    if($accountPublicId===null||trim($accountPublicId)==='')return subscription_user_account($pdo,(int)$user['id'],true)??throw new RuntimeException('Personal account is unavailable.');
    $q=$pdo->prepare("SELECT a.*,am.account_role,p.public_id package_public_id,p.slug package_slug,p.name package_name,p.description package_description,p.status package_status,p.is_public,p.legacy_plan_tier,p.billing_interval,p.monthly_price_cents,p.monthly_ai_token_allowance,p.member_limit,p.trial_days,p.feature_json
      FROM account_members am JOIN accounts a ON a.id=am.account_id JOIN subscription_packages p ON p.id=a.package_id
      WHERE am.user_id=? AND a.public_id=? AND am.account_role IN ('owner','admin') LIMIT 1");$q->execute([(int)$user['id'],trim($accountPublicId)]);$account=$q->fetch();if(!$account)throw new RuntimeException('You do not have billing administration access to that account.');return $account;
}
function stripe_billing_checkout_member_limit(PDO $pdo,array $account,array $package): void {
    $limit=(int)$package['member_limit'];
    if(function_exists('account_admin_overrides')&&account_admin_ready($pdo)){foreach(account_admin_overrides($pdo,(int)$account['id'],true) as $o)if($o['entitlement_key']==='member_limit'){$limit=max(1,(int)json_decode((string)$o['value_json'],true));break;}}
    $q=$pdo->prepare('SELECT COUNT(*) FROM account_members WHERE account_id=?');$q->execute([(int)$account['id']]);if((int)$q->fetchColumn()>$limit)throw new RuntimeException('This package member limit is below the account’s current membership.');
}
function stripe_billing_checkout_pending(PDO $pdo,int $accountId,string $mode): ?array {
    $q=$pdo->prepare("SELECT * FROM stripe_checkout_sessions WHERE account_id=? AND mode=? AND status IN ('creating','open') ORDER BY id DESC LIMIT 1");$q->execute([$accountId,$mode]);return $q->fetch()?:null;
}
function stripe_billing_checkout_set_status(PDO $pdo,string $mode,string $sessionId,string $status): void {
    if(!in_array($status,['open','completed','expired'],true))throw new InvalidArgumentException('Invalid checkout status.');
    $sql=$status==='completed'?"UPDATE stripe_checkout_sessions SET status='completed',completed_at=NOW() WHERE mode=? AND stripe_checkout_session_id=?":"UPDATE stripe_checkout_sessions SET status=? WHERE mode=? AND stripe_checkout_session_id=?";
    if($status==='completed')$pdo->prepare($sql)->execute([$mode,$sessionId]);else $pdo->prepare($sql)->execute([$status,$mode,$sessionId]);
}
function stripe_billing_create_checkout_for_account(PDO $pdo,array $config,array $user,array $account,string $packagePublicId): array {
    if(!stripe_billing_configured($pdo,$config))throw new RuntimeException('Stripe billing is not configured.');
    return commercial_account_with_lock($pdo,(int)$account['id'],function()use($pdo,$config,$user,$account,$packagePublicId){
        $fresh=stripe_billing_account_by_public($pdo,(string)$account['public_id']);if(!$fresh)throw new RuntimeException('Billing account is unavailable.');
        if(($fresh['status']??'active')!=='active')throw new RuntimeException('This account is not active.');
        if(stripe_billing_current_subscription($pdo,(int)$fresh['id']))throw new RuntimeException('This account already has a Stripe subscription. Use Manage billing to change it.');
        $package=subscription_package($pdo,$packagePublicId);if(!$package||$package['status']!=='active'||!(int)$package['is_public'])throw new RuntimeException('That subscription package is unavailable.');
        if((int)$package['monthly_price_cents']<=0)throw new RuntimeException('Free packages do not require Stripe Checkout.');stripe_billing_checkout_member_limit($pdo,$fresh,$package);
        $settings=stripe_billing_settings($pdo);$mode=(string)$settings['mode'];$price=stripe_billing_active_price($pdo,(int)$package['id'],$mode);if(!$price)throw new RuntimeException('This package is not connected to Stripe yet.');
        $customer=stripe_billing_customer_ensure($pdo,$config,$fresh);
        $withAccount=function(string $path)use($fresh): string {$sep=str_contains($path,'?')?'&':'?';return $path.$sep.'account='.rawurlencode((string)$fresh['public_id']);};
        $params=['mode'=>'subscription','customer'=>(string)$customer['stripe_customer_id'],'client_reference_id'=>(string)$fresh['public_id'],'line_items'=>[['price'=>(string)$price['stripe_price_id'],'quantity'=>1]],'success_url'=>stripe_billing_absolute_url($config,$withAccount((string)$settings['checkout_success_path'])),'cancel_url'=>stripe_billing_absolute_url($config,$withAccount((string)$settings['checkout_cancel_path'])),'metadata'=>['annotated_account_id'=>(string)$fresh['public_id'],'annotated_package_id'=>(string)$package['public_id'],'requested_by_user_id'=>(string)$user['public_id']],'subscription_data'=>['metadata'=>['annotated_account_id'=>(string)$fresh['public_id'],'annotated_package_id'=>(string)$package['public_id']]]];
        if((int)$package['trial_days']>0)$params['subscription_data']['trial_period_days']=(int)$package['trial_days'];
        $pending=stripe_billing_checkout_pending($pdo,(int)$fresh['id'],$mode);$attemptPublic='';
        if($pending){
            if($pending['status']==='open'){
                $notExpired=empty($pending['expires_at'])||strtotime((string)$pending['expires_at'])>time();
                if((int)$pending['package_id']===(int)$package['id']&&$notExpired&&!empty($pending['checkout_url']))return ['id'=>$pending['stripe_checkout_session_id'],'url'=>stripe_billing_hosted_url((string)$pending['checkout_url']),'status'=>'open','reused'=>true];
                if(!empty($pending['stripe_checkout_session_id'])){
                    $remote=stripe_billing_api_request($config,$settings,'GET','checkout/sessions/'.rawurlencode((string)$pending['stripe_checkout_session_id']));$remoteStatus=(string)($remote['status']??'');
                    if($remoteStatus==='complete'){stripe_billing_checkout_set_status($pdo,$mode,(string)$pending['stripe_checkout_session_id'],'completed');throw new RuntimeException('Checkout already completed; billing is synchronizing.');}
                    if($remoteStatus==='open'&&(int)$pending['package_id']===(int)$package['id']&&!empty($remote['url'])){$url=stripe_billing_hosted_url((string)$remote['url']);$expires=stripe_billing_datetime($remote['expires_at']??null);$pdo->prepare("UPDATE stripe_checkout_sessions SET checkout_url=?,expires_at=?,status='open' WHERE id=?")->execute([$url,$expires,(int)$pending['id']]);return ['id'=>$pending['stripe_checkout_session_id'],'url'=>$url,'status'=>'open','reused'=>true];}
                    if($remoteStatus==='open')stripe_billing_api_request($config,$settings,'POST','checkout/sessions/'.rawurlencode((string)$pending['stripe_checkout_session_id']).'/expire');
                    stripe_billing_checkout_set_status($pdo,$mode,(string)$pending['stripe_checkout_session_id'],'expired');
                }else{$pdo->prepare("UPDATE stripe_checkout_sessions SET status='expired' WHERE id=?")->execute([(int)$pending['id']]);}
                $pending=null;
            }elseif($pending['status']==='creating'){
                if((int)$pending['package_id']!==(int)$package['id']&&strtotime((string)$pending['updated_at'])>time()-300)throw new RuntimeException('Another Stripe Checkout is already being prepared for this account.');
                if((int)$pending['package_id']===(int)$package['id']){$attemptPublic=(string)$pending['public_id'];$stored=json_decode((string)($pending['request_json']??''),true);if(is_array($stored)&&$stored)$params=$stored;}
                else{$pdo->prepare("UPDATE stripe_checkout_sessions SET status='expired' WHERE id=?")->execute([(int)$pending['id']]);$pending=null;}
            }
        }
        if($attemptPublic===''){
            $attemptPublic=ulid_like();$pdo->prepare("INSERT INTO stripe_checkout_sessions(public_id,account_id,package_id,created_by_user_id,mode,stripe_customer_id,stripe_price_id,request_json,status) VALUES(?,?,?,?,?,?,?,?, 'creating')")
              ->execute([$attemptPublic,(int)$fresh['id'],(int)$package['id'],(int)$user['id'],$mode,(string)$customer['stripe_customer_id'],(string)$price['stripe_price_id'],json_encode($params,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        }
        $session=stripe_billing_api_request($config,$settings,'POST','checkout/sessions',$params,'annotated-checkout-'.$mode.'-'.$attemptPublic);
        $sessionId=(string)($session['id']??'');$url=!empty($session['url'])?stripe_billing_hosted_url((string)$session['url']):'';if($sessionId===''||$url==='')throw new RuntimeException('Stripe Checkout did not return a valid hosted session.');
        $expires=stripe_billing_datetime($session['expires_at']??null);$pdo->prepare("UPDATE stripe_checkout_sessions SET stripe_checkout_session_id=?,checkout_url=?,status='open',expires_at=? WHERE public_id=?")->execute([$sessionId,$url,$expires,$attemptPublic]);
        $session['url']=$url;return $session;
    },10);
}
function stripe_billing_create_portal_for_account(PDO $pdo,array $config,array $user,array $account): array {
    if(!stripe_billing_configured($pdo,$config))throw new RuntimeException('Stripe billing is not configured.');$settings=stripe_billing_settings($pdo);$customer=stripe_billing_customer_ensure($pdo,$config,$account);
    $returnPath=(string)$settings['portal_return_path'];$sep=str_contains($returnPath,'?')?'&':'?';$returnPath.=$sep.'account='.rawurlencode((string)$account['public_id']);
    $session=stripe_billing_api_request($config,$settings,'POST','billing_portal/sessions',['customer'=>(string)$customer['stripe_customer_id'],'return_url'=>stripe_billing_absolute_url($config,$returnPath)]);
    if(empty($session['url']))throw new RuntimeException('Stripe Customer Portal did not return a redirect URL.');$session['url']=stripe_billing_hosted_url((string)$session['url']);return $session;
}
function stripe_billing_create_checkout(PDO $pdo,array $config,array $user,string $packagePublicId): array {
    $account=stripe_billing_account_for_user($pdo,$user,null);return stripe_billing_create_checkout_for_account($pdo,$config,$user,$account,$packagePublicId);
}
function stripe_billing_create_portal(PDO $pdo,array $config,array $user): array {
    $account=stripe_billing_account_for_user($pdo,$user,null);return stripe_billing_create_portal_for_account($pdo,$config,$user,$account);
}
function stripe_billing_verify_signature(string $payload,string $signatureHeader,string $secret,int $tolerance=300): array {
    if($payload===''||$signatureHeader===''||$secret==='')throw new RuntimeException('Stripe webhook signature is unavailable.');
    $timestamp=0;$signatures=[];foreach(explode(',',$signatureHeader) as $part){$pair=explode('=',trim($part),2);if(count($pair)!==2)continue;if($pair[0]==='t')$timestamp=(int)$pair[1];elseif($pair[0]==='v1')$signatures[]=$pair[1];}
    if($timestamp<=0||!$signatures)throw new RuntimeException('Invalid Stripe-Signature header.');
    if(abs(time()-$timestamp)>$tolerance)throw new RuntimeException('Stripe webhook timestamp is outside the allowed tolerance.');
    $expected=hash_hmac('sha256',$timestamp.'.'.$payload,$secret);$valid=false;foreach($signatures as $sig)if(hash_equals($expected,$sig)){$valid=true;break;}if(!$valid)throw new RuntimeException('Stripe webhook signature verification failed.');
    return ['timestamp'=>$timestamp,'signature_count'=>count($signatures)];
}
function stripe_billing_datetime(mixed $timestamp): ?string {
    $t=(int)$timestamp;return $t>0?gmdate('Y-m-d H:i:s',$t):null;
}
function stripe_billing_date(mixed $timestamp): ?string {
    $t=(int)$timestamp;return $t>0?gmdate('Y-m-d',$t):null;
}
function stripe_billing_account_by_customer(PDO $pdo,string $customerId,?string $mode=null): ?array {
    $mode=$mode?:((string)(stripe_billing_settings($pdo)['mode']??'test'));$q=$pdo->prepare("SELECT a.* FROM stripe_customers sc JOIN accounts a ON a.id=sc.account_id WHERE sc.mode=? AND sc.stripe_customer_id=? LIMIT 1");$q->execute([$mode,$customerId]);return $q->fetch()?:null;
}
function stripe_billing_account_by_public(PDO $pdo,string $publicId): ?array {
    $q=$pdo->prepare('SELECT * FROM accounts WHERE public_id=? LIMIT 1');$q->execute([$publicId]);return $q->fetch()?:null;
}
function stripe_billing_event(PDO $pdo,int $accountId,string $source,string $eventType,string $reason,?string $stripeEventId=null,?int $actorUserId=null,mixed $before=null,mixed $after=null): void {
    $enc=fn(mixed $v)=>$v===null?null:json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$stripeMode=null;if($source==='stripe'&&$stripeEventId!==null)$stripeMode=(string)(stripe_billing_settings($pdo)['mode']??'test');
    try{$pdo->prepare("INSERT INTO account_billing_events(public_id,account_id,actor_user_id,source,event_type,stripe_mode,stripe_event_id,before_json,after_json,reason) VALUES(?,?,?,?,?,?,?,?,?,?)")
      ->execute([ulid_like(),$accountId,$actorUserId,$source,$eventType,$stripeMode,$stripeEventId,$enc($before),$enc($after),mb_substr($reason,0,500)]);}
    catch(PDOException $e){$driver=(int)($e->errorInfo[1]??0);if((string)$e->getCode()!=='23000'||$driver!==1062)throw $e;}
}
function stripe_billing_account_state_is_stale(PDO $pdo,int $accountId,string $mode,?string $eventCreatedAt): bool {
    if(!$eventCreatedAt)return false;$q=$pdo->prepare('SELECT last_event_created_at FROM stripe_account_state_watermarks WHERE account_id=? AND mode=? LIMIT 1');$q->execute([$accountId,$mode]);$last=$q->fetchColumn();
    return $last!==false&&$last!==null&&strcmp((string)$last,$eventCreatedAt)>0;
}
function stripe_billing_account_state_mark(PDO $pdo,int $accountId,string $mode,?string $eventCreatedAt,?string $eventId,string $eventType): void {
    if(!$eventCreatedAt)return;
    $pdo->prepare("INSERT INTO stripe_account_state_watermarks(account_id,mode,last_event_created_at,last_event_id,last_event_type) VALUES(?,?,?,?,?)
      ON DUPLICATE KEY UPDATE last_event_created_at=VALUES(last_event_created_at),last_event_id=VALUES(last_event_id),last_event_type=VALUES(last_event_type)")
      ->execute([$accountId,$mode,$eventCreatedAt,$eventId,$eventType]);
}
function stripe_billing_subscription_status(string $stripeStatus): string {
    return match($stripeStatus){
        'trialing'=>'trialing','active'=>'active','past_due','incomplete'=>'past_due','paused'=>'paused','unpaid','canceled','incomplete_expired'=>'canceled',default=>'past_due'
    };
}
function stripe_billing_apply_package_and_state(PDO $pdo,array $account,?array $priceMap,string $stripeStatus,?string $periodStart,?string $periodEnd,?string $trialEnd,?string $eventId): array {
    $before=['package_id'=>(int)$account['package_id'],'subscription_status'=>(string)$account['subscription_status'],'period_start'=>$account['period_start'],'period_end'=>$account['period_end'],'billing_source'=>$account['billing_source']??'manual'];
    $packageId=$priceMap?(int)$priceMap['package_id']:(int)$account['package_id'];$status=stripe_billing_subscription_status($stripeStatus);
    $start=$periodStart?substr($periodStart,0,10):(string)$account['period_start'];$end=$periodEnd?substr($periodEnd,0,10):(string)$account['period_end'];
    $packageChanged=$packageId!==(int)$account['package_id'];
    $pdo->prepare("UPDATE accounts SET package_id=?,subscription_status=?,billing_source='stripe',period_start=?,period_end=?,trial_ends_at=?,package_assigned_at=IF(?,NOW(),package_assigned_at) WHERE id=?")
      ->execute([$packageId,$status,$start,$end,$trialEnd,$packageChanged?1:0,(int)$account['id']]);
    if($priceMap&&$packageId!==(int)$account['package_id']){
        $package=subscription_package($pdo,$packageId);
        if(($account['account_type']??'')==='personal'&&!empty($account['personal_user_id'])&&$package)$pdo->prepare('UPDATE users SET plan_tier=?,pro_expires_at=NULL WHERE id=?')->execute([(string)$package['legacy_plan_tier'],(int)$account['personal_user_id']]);
        $pdo->prepare("INSERT INTO subscription_package_events(public_id,account_id,user_id,previous_package_id,new_package_id,actor_user_id,event_type,reason,metadata_json) VALUES(?,?,?,?,?,NULL,'package_changed',?,?)")
          ->execute([ulid_like(),(int)$account['id'],$account['personal_user_id']?(int)$account['personal_user_id']:null,(int)$account['package_id'],$packageId,'Stripe subscription changed package.',json_encode(['source'=>'stripe','stripe_event_id'=>$eventId],JSON_UNESCAPED_SLASHES)]);
    }
    return ['package_id'=>$packageId,'subscription_status'=>$status,'period_start'=>$start,'period_end'=>$end,'trial_ends_at'=>$trialEnd,'billing_source'=>'stripe'];
}
function stripe_billing_sync_subscription(PDO $pdo,array $object,string $mode,?string $eventId=null,?string $eventCreatedAt=null): ?array {
    $subscriptionId=(string)($object['id']??'');$customerId=is_string($object['customer']??null)?(string)$object['customer']:'';if($subscriptionId===''||$customerId==='')return null;
    $metadata=is_array($object['metadata']??null)?$object['metadata']:[];$accountPublic=(string)($metadata['annotated_account_id']??'');$seed=$accountPublic!==''?stripe_billing_account_by_public($pdo,$accountPublic):null;if(!$seed)$seed=stripe_billing_account_by_customer($pdo,$customerId,$mode);if(!$seed)throw new RuntimeException('Stripe subscription could not be matched to an Annotated account.');
    $first=$object['items']['data'][0]??[];$priceId=(string)($first['price']['id']??$object['plan']['id']??'');$priceMap=$priceId!==''?stripe_billing_price_for_stripe_id($pdo,$priceId,$mode):null;$status=(string)($object['status']??'incomplete');
    if(!in_array($status,['canceled','unpaid','incomplete_expired'],true)&&($priceId===''||!$priceMap))throw new RuntimeException('Stripe subscription Price is not mapped to an Annotated package in this mode.');
    if($priceMap&&!subscription_package($pdo,(int)$priceMap['package_id']))throw new RuntimeException('Mapped Annotated package is unavailable.');
    $periodStart=stripe_billing_datetime($object['current_period_start']??$first['current_period_start']??null);$periodEnd=stripe_billing_datetime($object['current_period_end']??$first['current_period_end']??null);$trialEnd=stripe_billing_datetime($object['trial_end']??null);
    return commercial_account_with_lock($pdo,(int)$seed['id'],function()use($pdo,$object,$mode,$eventId,$eventCreatedAt,$subscriptionId,$customerId,$metadata,$accountPublic,$priceId,$priceMap,$status,$periodStart,$periodEnd,$trialEnd){
        $account=$accountPublic!==''?stripe_billing_account_by_public($pdo,$accountPublic):null;if(!$account)$account=stripe_billing_account_by_customer($pdo,$customerId,$mode);if(!$account)throw new RuntimeException('Stripe subscription account disappeared during synchronization.');
        stripe_billing_link_customer($pdo,(int)$account['id'],$mode,$customerId,null,(string)$account['name'],$metadata);
        $existingQ=$pdo->prepare('SELECT * FROM stripe_subscriptions WHERE mode=? AND stripe_subscription_id=? LIMIT 1');$existingQ->execute([$mode,$subscriptionId]);$existing=$existingQ->fetch()?:null;
        if($eventCreatedAt&&$existing&&!empty($existing['last_event_created_at'])&&strcmp((string)$existing['last_event_created_at'],$eventCreatedAt)>0)return $existing;
        $pdo->beginTransaction();try{
            $pdo->prepare("INSERT INTO stripe_subscriptions(public_id,account_id,mode,stripe_subscription_id,stripe_customer_id,stripe_price_id,status,cancel_at_period_end,current_period_start,current_period_end,trial_end,canceled_at,ended_at,latest_invoice_id,last_event_id,last_event_created_at,metadata_json)
              VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),stripe_customer_id=VALUES(stripe_customer_id),stripe_price_id=VALUES(stripe_price_id),status=VALUES(status),cancel_at_period_end=VALUES(cancel_at_period_end),current_period_start=VALUES(current_period_start),current_period_end=VALUES(current_period_end),trial_end=VALUES(trial_end),canceled_at=VALUES(canceled_at),ended_at=VALUES(ended_at),latest_invoice_id=VALUES(latest_invoice_id),last_event_id=VALUES(last_event_id),last_event_created_at=COALESCE(VALUES(last_event_created_at),last_event_created_at),metadata_json=VALUES(metadata_json)")
              ->execute([ulid_like(),(int)$account['id'],$mode,$subscriptionId,$customerId,$priceId!==''?$priceId:null,$status,!empty($object['cancel_at_period_end'])?1:0,$periodStart,$periodEnd,$trialEnd,stripe_billing_datetime($object['canceled_at']??null),stripe_billing_datetime($object['ended_at']??null),is_string($object['latest_invoice']??null)?$object['latest_invoice']:null,$eventId,$eventCreatedAt,json_encode($metadata,JSON_UNESCAPED_SLASHES)]);
            if(stripe_billing_account_state_is_stale($pdo,(int)$account['id'],$mode,$eventCreatedAt)){
                stripe_billing_event($pdo,(int)$account['id'],'stripe','subscription_stale_account_state_ignored','Older Stripe subscription state was preserved in the ledger but not applied to the account.',$eventId,null,['subscription_status'=>$account['subscription_status']],['incoming_status'=>$status,'incoming_event_created_at'=>$eventCreatedAt]);
            }else{
                $after=stripe_billing_apply_package_and_state($pdo,$account,$priceMap,$status,$periodStart,$periodEnd,$trialEnd,$eventId);
                stripe_billing_account_state_mark($pdo,(int)$account['id'],$mode,$eventCreatedAt,$eventId,'subscription_'.$status);
                stripe_billing_event($pdo,(int)$account['id'],'stripe','subscription_'.$status,'Stripe subscription synchronized.',$eventId,null,['package_id'=>(int)$account['package_id'],'subscription_status'=>$account['subscription_status']],$after);
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        $q=$pdo->prepare('SELECT * FROM stripe_subscriptions WHERE mode=? AND stripe_subscription_id=?');$q->execute([$mode,$subscriptionId]);return $q->fetch()?:null;
    });
}function stripe_billing_invoice_subscription_id(array $object): ?string {
    if(is_string($object['subscription']??null))return (string)$object['subscription'];
    $nested=$object['parent']['subscription_details']['subscription']??null;return is_string($nested)?$nested:null;
}
function stripe_billing_sync_invoice(PDO $pdo,array $object,string $eventType,string $mode,?string $eventId=null,?string $eventCreatedAt=null): ?array {
    $invoiceId=(string)($object['id']??'');$customerId=is_string($object['customer']??null)?(string)$object['customer']:'';if($invoiceId===''||$customerId==='')return null;$seed=stripe_billing_account_by_customer($pdo,$customerId,$mode);if(!$seed)throw new RuntimeException('Stripe invoice could not be matched to an Annotated account.');
    $subscriptionId=stripe_billing_invoice_subscription_id($object);$period=$object['lines']['data'][0]['period']??[];$paidAt=$object['status_transitions']['paid_at']??null;
    return commercial_account_with_lock($pdo,(int)$seed['id'],function()use($pdo,$object,$eventType,$mode,$eventId,$eventCreatedAt,$invoiceId,$customerId,$subscriptionId,$period,$paidAt){
        $account=stripe_billing_account_by_customer($pdo,$customerId,$mode);if(!$account)throw new RuntimeException('Stripe invoice account disappeared during synchronization.');
        $existingQ=$pdo->prepare('SELECT * FROM stripe_invoices WHERE mode=? AND stripe_invoice_id=? LIMIT 1');$existingQ->execute([$mode,$invoiceId]);$existing=$existingQ->fetch()?:null;
        if($eventCreatedAt&&$existing&&!empty($existing['last_event_created_at'])&&strcmp((string)$existing['last_event_created_at'],$eventCreatedAt)>0)return $existing;
        $pdo->beginTransaction();try{
            $pdo->prepare("INSERT INTO stripe_invoices(public_id,account_id,mode,stripe_invoice_id,stripe_subscription_id,stripe_customer_id,status,currency,amount_due_cents,amount_paid_cents,amount_remaining_cents,invoice_number,hosted_invoice_url,invoice_pdf_url,period_start,period_end,due_at,paid_at,last_event_id,last_event_created_at)
              VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE status=VALUES(status),currency=VALUES(currency),amount_due_cents=VALUES(amount_due_cents),amount_paid_cents=VALUES(amount_paid_cents),amount_remaining_cents=VALUES(amount_remaining_cents),invoice_number=VALUES(invoice_number),hosted_invoice_url=VALUES(hosted_invoice_url),invoice_pdf_url=VALUES(invoice_pdf_url),period_start=VALUES(period_start),period_end=VALUES(period_end),due_at=VALUES(due_at),paid_at=VALUES(paid_at),last_event_id=VALUES(last_event_id),last_event_created_at=COALESCE(VALUES(last_event_created_at),last_event_created_at)")
              ->execute([ulid_like(),(int)$account['id'],$mode,$invoiceId,$subscriptionId,$customerId,(string)($object['status']??''),(string)($object['currency']??''),(int)($object['amount_due']??0),(int)($object['amount_paid']??0),(int)($object['amount_remaining']??0),(string)($object['number']??''),(string)($object['hosted_invoice_url']??''),(string)($object['invoice_pdf']??''),stripe_billing_datetime($period['start']??null),stripe_billing_datetime($period['end']??null),stripe_billing_datetime($object['due_date']??null),stripe_billing_datetime($paidAt),$eventId,$eventCreatedAt]);
            $nextStatus=null;if(in_array($eventType,['invoice.payment_failed','invoice.payment_action_required','invoice.marked_uncollectible'],true))$nextStatus='past_due';elseif($eventType==='invoice.paid'){
                $nextStatus='active';if($subscriptionId){$q=$pdo->prepare('SELECT status FROM stripe_subscriptions WHERE mode=? AND stripe_subscription_id=? LIMIT 1');$q->execute([$mode,$subscriptionId]);$ss=(string)($q->fetchColumn()?:'');if(in_array($ss,['trialing','paused','canceled','unpaid','incomplete_expired'],true))$nextStatus=stripe_billing_subscription_status($ss);}
            }
            if($nextStatus!==null&&stripe_billing_account_state_is_stale($pdo,(int)$account['id'],$mode,$eventCreatedAt)){
                stripe_billing_event($pdo,(int)$account['id'],'stripe','invoice_stale_account_state_ignored','Older Stripe invoice state was preserved in the ledger but not applied to the account.',$eventId,null,['subscription_status'=>$account['subscription_status']],['incoming_status'=>$nextStatus,'incoming_event_created_at'=>$eventCreatedAt]);
            }else{
                if($nextStatus!==null){$pdo->prepare("UPDATE accounts SET subscription_status=?,billing_source='stripe' WHERE id=? AND status<>'closed'")->execute([$nextStatus,(int)$account['id']]);stripe_billing_account_state_mark($pdo,(int)$account['id'],$mode,$eventCreatedAt,$eventId,str_replace('.','_',$eventType));}
                stripe_billing_event($pdo,(int)$account['id'],'stripe',str_replace('.','_',$eventType),'Stripe invoice synchronized.',$eventId,null,['subscription_status'=>$account['subscription_status'],'amount_due_cents'=>(int)($object['amount_due']??0)],['subscription_status'=>$nextStatus??$account['subscription_status'],'amount_paid_cents'=>(int)($object['amount_paid']??0)]);
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        $q=$pdo->prepare('SELECT * FROM stripe_invoices WHERE mode=? AND stripe_invoice_id=?');$q->execute([$mode,$invoiceId]);return $q->fetch()?:null;
    });
}function stripe_billing_record_customer_event(PDO $pdo,array $config,array $settings,array $object,string $eventType,?string $eventId=null): void {
    $customerId=is_string($object['customer']??null)?(string)$object['customer']:'';
    if($customerId===''&&is_string($object['charge']??null)&&$object['charge']!==''){
        $charge=stripe_billing_api_request($config,$settings,'GET','charges/'.rawurlencode((string)$object['charge']));$customerId=is_string($charge['customer']??null)?(string)$charge['customer']:'';
    }
    if($customerId==='')return;$mode=(string)($settings['mode']??'test');$account=stripe_billing_account_by_customer($pdo,$customerId,$mode);if(!$account)return;
    stripe_billing_event($pdo,(int)$account['id'],'stripe',str_replace('.','_',$eventType),'Stripe billing event received.',$eventId,null,null,['stripe_object_id'=>$object['id']??null,'charge_id'=>$object['charge']??null]);
}
function stripe_billing_claim_webhook(PDO $pdo,string $eventId,string $mode,string $eventType,?string $objectId,string $hash,?string $eventCreatedAt=null): array {
    $q=$pdo->prepare('SELECT * FROM stripe_webhook_events WHERE mode=? AND stripe_event_id=? LIMIT 1');$q->execute([$mode,$eventId]);$existing=$q->fetch();
    if($existing){
        if(!hash_equals((string)$existing['payload_sha256'],$hash))throw new RuntimeException('Stripe event payload changed for an existing event ID.');
        if(in_array((string)$existing['status'],['processed','ignored'],true))return ['claimed'=>false,'row'=>$existing];
        if($existing['status']==='processing'&&strtotime((string)$existing['received_at'])>time()-300)return ['claimed'=>false,'row'=>$existing];
        $pdo->prepare("UPDATE stripe_webhook_events SET status='processing',attempt_count=attempt_count+1,error_text=NULL,received_at=NOW(),payload_sha256=?,event_type=?,object_id=?,mode=?,event_created_at=COALESCE(?,event_created_at) WHERE id=?")->execute([$hash,$eventType,$objectId,$mode,$eventCreatedAt,(int)$existing['id']]);
        return ['claimed'=>true,'row'=>$existing];
    }
    $pdo->prepare("INSERT INTO stripe_webhook_events(stripe_event_id,mode,event_type,object_id,payload_sha256,event_created_at,status) VALUES(?,?,?,?,?,?,'processing')")->execute([$eventId,$mode,$eventType,$objectId,$hash,$eventCreatedAt]);
    return ['claimed'=>true,'row'=>['id'=>(int)$pdo->lastInsertId()]];
}
function stripe_billing_finish_webhook(PDO $pdo,string $mode,string $eventId,string $status,?string $error=null): void {
    $status=in_array($status,['processed','failed','ignored'],true)?$status:'failed';$pdo->prepare('UPDATE stripe_webhook_events SET status=?,error_text=?,processed_at=NOW() WHERE mode=? AND stripe_event_id=?')->execute([$status,$error?mb_substr($error,0,1000):null,$mode,$eventId]);
}
function stripe_billing_process_webhook(PDO $pdo,array $config,string $payload,string $signatureHeader): array {
    if(!stripe_billing_ready($pdo))throw new RuntimeException('Stripe Billing requires the latest database upgrade.');
    $settings=stripe_billing_settings($pdo);$secret=stripe_billing_decrypt_secret($config,$settings['webhook_secret_ciphertext']??null);stripe_billing_verify_signature($payload,$signatureHeader,$secret);
    $event=json_decode($payload,true,512,JSON_THROW_ON_ERROR);$eventId=(string)($event['id']??'');$eventType=(string)($event['type']??'');$object=$event['data']['object']??null;if($eventId===''||$eventType===''||!is_array($object))throw new RuntimeException('Invalid Stripe webhook payload.');
    $mode=!empty($event['livemode'])?'live':'test';if($mode!==(string)$settings['mode'])throw new RuntimeException('Stripe webhook mode does not match the configured billing mode.');$eventCreatedAt=stripe_billing_datetime($event['created']??null);
    $claim=stripe_billing_claim_webhook($pdo,$eventId,$mode,$eventType,(string)($object['id']??''),hash('sha256',$payload),$eventCreatedAt);if(!$claim['claimed'])return ['ok'=>true,'duplicate'=>true,'event_id'=>$eventId,'type'=>$eventType];
    try{
        if($eventType==='checkout.session.completed'){
            $accountPublic=(string)($object['metadata']['annotated_account_id']??$object['client_reference_id']??'');$customerId=is_string($object['customer']??null)?(string)$object['customer']:'';$account=$accountPublic!==''?stripe_billing_account_by_public($pdo,$accountPublic):null;
            if($account&&$customerId!==''){commercial_account_with_lock($pdo,(int)$account['id'],function()use($pdo,$account,$mode,$customerId,$eventId,$object){stripe_billing_link_customer($pdo,(int)$account['id'],$mode,$customerId,null,(string)$account['name'],['annotated_account_id'=>$account['public_id']]);stripe_billing_event($pdo,(int)$account['id'],'stripe','checkout_completed','Stripe Checkout completed.',$eventId,null,null,['stripe_subscription_id'=>$object['subscription']??null]);});}
            if(is_string($object['subscription']??null)&&$object['subscription']!==''){$sub=stripe_billing_api_request($config,$settings,'GET','subscriptions/'.rawurlencode((string)$object['subscription']));stripe_billing_sync_subscription($pdo,$sub,$mode,$eventId,$eventCreatedAt);}
        }elseif(in_array($eventType,['customer.subscription.created','customer.subscription.updated','customer.subscription.deleted','customer.subscription.paused','customer.subscription.resumed','customer.subscription.trial_will_end'],true)){
            stripe_billing_sync_subscription($pdo,$object,$mode,$eventId,$eventCreatedAt);
        }elseif(in_array($eventType,['invoice.finalized','invoice.paid','invoice.payment_failed','invoice.payment_action_required','invoice.voided','invoice.marked_uncollectible'],true)){
            stripe_billing_sync_invoice($pdo,$object,$eventType,$mode,$eventId,$eventCreatedAt);
        }elseif(in_array($eventType,['charge.refunded','charge.dispute.created','charge.dispute.closed'],true)){
            stripe_billing_record_customer_event($pdo,$config,$settings,$object,$eventType,$eventId);
        }else{stripe_billing_finish_webhook($pdo,$mode,$eventId,'ignored');return ['ok'=>true,'ignored'=>true,'event_id'=>$eventId,'type'=>$eventType];}
        stripe_billing_finish_webhook($pdo,$mode,$eventId,'processed');return ['ok'=>true,'event_id'=>$eventId,'type'=>$eventType];
    }catch(Throwable $e){stripe_billing_finish_webhook($pdo,$mode,$eventId,'failed',$e->getMessage());throw $e;}
}
function stripe_billing_over_capacity_account_ids(PDO $pdo): array {
    if(!stripe_billing_ready($pdo))return [];$ids=[];
    $rows=$pdo->query("SELECT a.id,p.member_limit FROM accounts a JOIN subscription_packages p ON p.id=a.package_id WHERE a.status<>'closed'")->fetchAll()?:[];
    foreach($rows as $row){
        $limit=(int)$row['member_limit'];
        if(function_exists('account_admin_ready')&&account_admin_ready($pdo)){try{$limit=account_admin_effective_member_limit($pdo,(int)$row['id']);}catch(Throwable $e){}}
        $q=$pdo->prepare('SELECT COUNT(*) FROM account_members WHERE account_id=?');$q->execute([(int)$row['id']]);if((int)$q->fetchColumn()>$limit)$ids[]=(int)$row['id'];
    }
    return $ids;
}
function stripe_billing_account_summary(PDO $pdo,int $accountId): array {
    $settings=stripe_billing_settings($pdo);$mode=(string)$settings['mode'];$customer=stripe_billing_customer($pdo,$accountId,$mode);$subscription=stripe_billing_current_subscription($pdo,$accountId,$mode);
    $q=$pdo->prepare('SELECT * FROM stripe_invoices WHERE account_id=? AND mode=? ORDER BY created_at DESC,id DESC LIMIT 20');$q->execute([$accountId,$mode]);$invoices=$q->fetchAll()?:[];
    $q=$pdo->prepare('SELECT * FROM account_billing_events WHERE account_id=? ORDER BY created_at DESC,id DESC LIMIT 50');$q->execute([$accountId]);$events=$q->fetchAll()?:[];
    $subscriptionPrice=$subscription&&!empty($subscription['stripe_price_id'])?stripe_billing_price_for_stripe_id($pdo,(string)$subscription['stripe_price_id'],$mode):null;
    return ['mode'=>$mode,'customer'=>$customer,'subscription'=>$subscription,'subscription_price'=>$subscriptionPrice,'invoices'=>$invoices,'events'=>$events];
}
function stripe_billing_recent_webhooks(PDO $pdo,int $limit=100): array {
    if(!stripe_billing_ready($pdo))return [];$limit=max(1,min(500,$limit));return $pdo->query('SELECT * FROM stripe_webhook_events ORDER BY received_at DESC,id DESC LIMIT '.$limit)->fetchAll()?:[];
}
function stripe_billing_recent_events(PDO $pdo,int $limit=100): array {
    if(!stripe_billing_ready($pdo))return [];$limit=max(1,min(500,$limit));return $pdo->query("SELECT e.*,a.public_id account_public_id,a.name account_name,u.username actor_username FROM account_billing_events e JOIN accounts a ON a.id=e.account_id LEFT JOIN users u ON u.id=e.actor_user_id ORDER BY e.created_at DESC,e.id DESC LIMIT ".$limit)->fetchAll()?:[];
}
