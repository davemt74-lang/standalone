<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','functions','subscriptions','ai-usage','release','admin-ui'] as $lib)require_once $root.'/app/'.$lib.'.php';
function av120(bool $ok,string $m): void {if(!$ok)throw new RuntimeException('FAIL: '.$m);echo "PASS: $m\n";}
$sections=admin_ui_nav_sections();av120(isset($sections['accounts']['items']['accounts'],$sections['models']['items']['model_registry'],$sections['research']['items']['datasets'],$sections['operations']['items']['system_health']),'Shared admin IA exposes canonical account, model, research and operations destinations.');
$run='av120'.substr(bin2hex(random_bytes(5)),0,10);$username='adminv120_'.$run;$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?, 'active','user','free','cloaked')")->execute(['u-'.$run,$username,'Admin V1.20 Account',$username.'@example.test']);$uid=(int)$pdo->lastInsertId();
$account=subscription_ensure_user_account($pdo,$uid);av120(($account['package_slug']??'')==='free-trial','V1.20 account fixture provisions through canonical subscriptions service.');
$rows=admin_ui_account_rows($pdo,['q'=>$username],25);av120(count($rows)===1&&(int)$rows[0]['personal_user_id']===$uid,'Accounts workspace can find a commercial account by owner identity.');
$rows=admin_ui_account_rows($pdo,['status'=>'closed','q'=>$username],25);av120(count($rows)===0,'Accounts workspace status filter is enforced.');
$snapshot=admin_ui_dashboard_snapshot($pdo);av120((int)$snapshot['counts']['users']>=1&&(int)$snapshot['counts']['accounts']>=1,'Admin dashboard aggregates live user and account counts.');
av120(array_key_exists('attention',$snapshot)&&array_key_exists('usage',$snapshot)&&array_key_exists('queues',$snapshot),'Admin dashboard snapshot includes attention, AI usage and queue domains.');
echo "Admin V1.20 dashboard database journey passed.\n";
