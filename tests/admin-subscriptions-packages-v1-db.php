<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','functions','subscriptions'] as $lib)require_once $root.'/app/'.$lib.'.php';
function spv1(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
function spv1throws(callable $fn,string $m): void {try{$fn();}catch(Throwable $e){echo "PASS: $m\n";return;}throw new RuntimeException('FAIL: '.$m);}

spv1(subscriptions_ready($pdo),'Migration 062 subscriptions/accounts schema is ready.');
$packages=subscription_packages($pdo,false);$slugs=array_column($packages,'slug');
foreach(['free-trial','basic-user','team-builder'] as $slug)spv1(in_array($slug,$slugs,true),'Seed package exists: '.$slug);
$teamBuilder=subscription_package($pdo,'team-builder');spv1($teamBuilder!==null&&(int)$teamBuilder['member_limit']===5,'Team Builder is seeded as a multi-member commercial account package.');
$run='spv1'.substr(bin2hex(random_bytes(5)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$makeUser=function(string $name,string $role='user')use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?, 'active',?,'free','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$admin=$makeUser('PackageAdmin','admin');$user=$makeUser('PackageUser');$nonAdmin=$makeUser('PackageOther');

$teamCountBefore=(int)$pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();
$account=subscription_ensure_user_account($pdo,(int)$user['id'],(int)$admin['id']);
spv1(($account['package_slug']??'')==='free-trial'&&$account['account_type']==='personal','New personal account defaults to Free Trial.');
$q=$pdo->prepare('SELECT account_role FROM account_members WHERE account_id=? AND user_id=?');$q->execute([(int)$account['id'],(int)$user['id']]);spv1($q->fetchColumn()==='owner','Personal account creator is its account owner.');

$basic=subscription_package($pdo,'basic-user');$assigned=subscription_assign_user_package($pdo,$admin,(int)$user['id'],(string)$basic['public_id'],'Move customer to Basic User.');
spv1(($assigned['package_slug']??'')==='basic-user','Admin can assign Basic User from the package catalog.');
$q=$pdo->prepare('SELECT plan_tier FROM users WHERE id=?');$q->execute([(int)$user['id']]);spv1($q->fetchColumn()==='pro','Package assignment synchronizes the legacy access gate.');
$q=$pdo->prepare("SELECT COUNT(*) FROM subscription_package_events WHERE user_id=? AND event_type='package_changed' AND actor_user_id=?");$q->execute([(int)$user['id'],(int)$admin['id']]);$eventCount=(int)$q->fetchColumn();spv1($eventCount===1,'User package assignment writes an attributable audit event.');

subscription_assign_user_package($pdo,$admin,(int)$user['id'],(string)$basic['public_id'],'Same package no-op.');
$q->execute([(int)$user['id'],(int)$admin['id']]);spv1((int)$q->fetchColumn()===$eventCount,'Reassigning the same package is an idempotent no-op.');

$team=subscription_assign_user_package($pdo,$admin,(int)$user['id'],(string)$teamBuilder['public_id'],'Upgrade account to Team Builder.');
spv1(($team['package_slug']??'')==='team-builder'&&(int)$team['member_limit']===5,'Admin can move a user account to Team Builder.');
$teamCountAfter=(int)$pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();spv1($teamCountAfter===$teamCountBefore,'Package changes never create or modify manual Research Teams.');

$created=subscription_package_create($pdo,$admin,['name'=>'Custom Researcher','slug'=>'custom-researcher','description'=>'Custom package','legacy_plan_tier'=>'pro','monthly_price'=>'29.95','monthly_ai_token_allowance'=>'1234567','member_limit'=>'2','trial_days'=>'7','sort_order'=>'80']);
spv1(($created['slug']??'')==='custom-researcher'&&(int)$created['monthly_price_cents']===2995&&(int)$created['monthly_ai_token_allowance']===1234567,'Admin can create a new configurable package.');
$updated=subscription_package_update($pdo,$admin,(string)$created['public_id'],['name'=>'Custom Researcher Plus','description'=>'Updated custom package','status'=>'archived','legacy_plan_tier'=>'free','monthly_price'=>'39.50','monthly_ai_token_allowance'=>'7654321','member_limit'=>'3','trial_days'=>'5','sort_order'=>'85']);
spv1(($updated['name']??'')==='Custom Researcher Plus'&&$updated['status']==='archived'&&(int)$updated['monthly_price_cents']===3950,'Admin can edit and archive a package without deleting history.');
$q=$pdo->prepare('SELECT COUNT(*) FROM subscription_package_admin_events WHERE package_id=? AND actor_user_id=?');$q->execute([(int)$created['id'],(int)$admin['id']]);spv1((int)$q->fetchColumn()>=2,'Package create/update operations are audited.');

$basicBefore=subscription_package($pdo,'basic-user');subscription_assign_user_package($pdo,$admin,(int)$nonAdmin['id'],(string)$basicBefore['public_id'],'Assign test Basic package.');
subscription_package_update($pdo,$admin,(string)$basicBefore['public_id'],['name'=>$basicBefore['name'],'description'=>$basicBefore['description'],'status'=>'active','is_public'=>'1','legacy_plan_tier'=>'free','monthly_price'=>number_format(((int)$basicBefore['monthly_price_cents'])/100,2,'.',''),'monthly_ai_token_allowance'=>$basicBefore['monthly_ai_token_allowance']===null?'':(string)$basicBefore['monthly_ai_token_allowance'],'member_limit'=>$basicBefore['member_limit'],'trial_days'=>$basicBefore['trial_days'],'sort_order'=>$basicBefore['sort_order']]);
$q=$pdo->prepare('SELECT plan_tier FROM users WHERE id=?');$q->execute([(int)$nonAdmin['id']]);spv1($q->fetchColumn()==='free','Changing package legacy access synchronizes users currently assigned to that package.');
subscription_package_update($pdo,$admin,(string)$basicBefore['public_id'],['name'=>$basicBefore['name'],'description'=>$basicBefore['description'],'status'=>'active','is_public'=>'1','legacy_plan_tier'=>'pro','monthly_price'=>number_format(((int)$basicBefore['monthly_price_cents'])/100,2,'.',''),'monthly_ai_token_allowance'=>$basicBefore['monthly_ai_token_allowance']===null?'':(string)$basicBefore['monthly_ai_token_allowance'],'member_limit'=>$basicBefore['member_limit'],'trial_days'=>$basicBefore['trial_days'],'sort_order'=>$basicBefore['sort_order']]);

spv1throws(fn()=>subscription_assign_user_package($pdo,$nonAdmin,(int)$user['id'],(string)$basic['public_id'],'Unauthorized'),'Non-admin users cannot assign subscription packages.');
echo "Admin Subscriptions & Packages V1 database journey passed.\n";
