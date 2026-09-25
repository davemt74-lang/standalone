<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','functions','concurrency','subscriptions','account-admin','account-membership','stripe-billing','billing-operations','ai-usage','ai-overage-billing','commercial-promotions','commercial-billing','admin-operations','admin-access','admin-support','admin-finance','notifications','admin-customer-success','admin-security-compliance','schema-health','release','release-operations','vp3-connector','admin-platform-governance','admin-agent-context','admin-ui','access','object-handoff','source-integrity','annotation-intelligence','conversations','rate-limit','research-workspace','agent-actions','admin-agent','admin-agent-v290'] as $lib)require_once $root.'/app/'.$lib.'.php';
function av290(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
$run='av290'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$make=function(string $name,string $role='admin')use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?, 'active',?,'pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$make('V290Owner');$limited=$make('V290Limited');
admin_access_profile($pdo,$owner);
$limitedRole=admin_access_role_save($pdo,$owner,['name'=>'V290 Limited '.$run,'role_key'=>'v290_limited_'.$run,'description'=>'Operations-only V2.90 fixture.','capabilities'=>['admin.operations.view'],'status'=>'active','reason'=>'Verify V2.90 permission filtering.']);
admin_access_assign_operator($pdo,$owner,(int)$limited['id'],(string)$limitedRole['public_id'],'active','Verify V2.90 permission filtering.');

$pdo->exec("UPDATE accounts SET subscription_status='past_due' WHERE status<>'closed' LIMIT 1");
$brief=admin_agent_v290_proactive_brief($pdo,$owner);
av290(is_array($brief),'Proactive brief returns a deterministic signal collection.');

$thread=admin_agent_thread_create($pdo,$owner);
$userMessage=conversation_message_create($pdo,$owner,(string)$thread['public_id'],'Investigate what needs attention and give me next steps.',null,'av290-'.$run);
$conversation=admin_agent_thread_access($pdo,$owner,(string)$thread['public_id']);
$assistant=agent_chat_insert_agent_message($pdo,$conversation,'I found several areas to review.',(int)$userMessage['id']);
$extra=admin_agent_v290_enrich($pdo,$owner,(string)$thread['public_id'],(int)$assistant['id'],'Investigate what needs attention and give me next steps.',[]);
av290(!empty($extra['admin_plans']),'Investigation intent creates a durable non-executing plan.');
$plan=$extra['admin_plans'][0];av290(($plan['status']??'')==='active'&&!empty($plan['steps']),'Investigation plan starts active with trackable steps.');

$meta=admin_agent_v290_message_meta($pdo,$owner,(string)$thread['public_id'],[(int)$assistant['id']]);
av290(!empty($meta[(int)$assistant['id']]['admin_plans']),'Plan metadata survives message reload.');
$step=(string)$plan['steps'][0]['id'];$updated=admin_agent_v290_plan_step($pdo,$owner,(string)$thread['public_id'],(string)$plan['public_id'],$step,'done');
av290(($updated['steps'][0]['status']??'')==='done','Authorized Admin can mark an investigation step done.');

$blocked=false;try{admin_agent_v290_plan_step($pdo,$limited,(string)$thread['public_id'],(string)$plan['public_id'],$step,'pending');}catch(RuntimeException $e){$blocked=true;}av290($blocked,'Another Admin cannot mutate a plan in a thread they do not own.');
$count=(int)$pdo->query("SELECT COUNT(*) FROM admin_action_records WHERE actor_user_id=".(int)$owner['id'])->fetchColumn();
av290($count===0,'V2.90 intelligence and plan tracking do not create or execute governed Admin action records.');

echo "Admin V2.90 Proactive Admin Intelligence database journey passed.\n";
