<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','functions','concurrency','subscriptions','account-admin','account-membership','stripe-billing','billing-operations','ai-usage','ai-overage-billing','commercial-promotions','commercial-billing','admin-operations','admin-access','admin-support','admin-finance','admin-customer-success','admin-security-compliance','admin-platform-governance','admin-ui','access','object-handoff','notifications','source-integrity','annotation-intelligence','conversations','rate-limit','research-workspace','agent-actions','admin-agent'] as $lib)require_once $root.'/app/'.$lib.'.php';
function av270(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
$run='av270'.substr(bin2hex(random_bytes(6)),0,10);
$make=function(string $name)use($pdo,$run): array{$username=strtolower($name).'_'.$run;$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?, 'active','admin','pro','cloaked')")->execute(['u-'.$username,$username,$name,$username.'@example.test']);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$make('AdminAgentOwner');$other=$make('AdminAgentOther');admin_access_profile($pdo,$owner);admin_access_profile($pdo,$other);
$limited=$make('AdminAgentLimited');
$limitedRole=admin_access_role_save($pdo,$owner,['name'=>'Admin Agent Limited '.$run,'role_key'=>'admin_agent_limited_'.$run,'description'=>'Operations-only Admin Agent fixture.','capabilities'=>['admin.operations.view'],'status'=>'active','reason'=>'Verify V2.70 delegated context isolation.']);
admin_access_assign_operator($pdo,$owner,(int)$limited['id'],(string)$limitedRole['public_id'],'active','Verify V2.70 delegated context isolation.');

av270(admin_agent_ready($pdo),'Admin Agent reuses the existing conversation runtime.');
$thread=admin_agent_thread_create($pdo,$owner);av270(($thread['conversation_type']??'')==='admin_agent','New Admin Agent thread uses an isolated conversation type.');
av270(admin_agent_thread_access($pdo,$owner,(string)$thread['public_id'])!==null,'Creating administrator can reopen the Admin Agent thread.');
av270(admin_agent_thread_access($pdo,$other,(string)$thread['public_id'])===null,'Another administrator cannot read an Admin Agent thread they do not own.');

$userMessage=conversation_message_create($pdo,$owner,(string)$thread['public_id'],'What needs my attention?',null,'av270-'.$run);
$conversation=admin_agent_thread_access($pdo,$owner,(string)$thread['public_id']);$assistant=agent_chat_insert_agent_message($pdo,$conversation,'Two operational items need review.',(int)$userMessage['id']);
$messages=admin_agent_messages($pdo,$owner,(string)$thread['public_id']);
av270(count($messages['messages']??[])===2,'Admin Agent conversation history is durable.');
$roles=array_column($messages['messages'],'role');av270($roles===['user','assistant'],'Admin Agent message history preserves user/assistant roles.');

$threads=admin_agent_threads($pdo,$owner);$match=array_values(array_filter($threads,fn($row)=>$row['public_id']===$thread['public_id']));
av270(count($match)===1&&str_contains((string)$match[0]['last_message'],'operational items'),'Thread list exposes the latest Admin Agent conversation state.');

$terms=admin_agent_search_terms('Find "Acme North" account and user jane@example.test');
av270(in_array('Acme North',$terms,true)&&in_array('jane@example.test',$terms,true),'Admin Agent derives bounded search terms from explicit administrator identifiers.');

$parsed=admin_agent_extract_actions('Review this first. <<ANNOTATED_ADMIN_ACTIONS>>[{"action_type":"resync_stripe_subscription","account_public_id":"acc-example"}]');
av270($parsed['body']==='Review this first.'&&count($parsed['actions'])===1&&$parsed['actions'][0]['action_type']==='resync_stripe_subscription','Admin Agent separates human response text from governed action requests.');
$bad=admin_agent_extract_actions('No action <<ANNOTATED_ADMIN_ACTIONS>>not-json');
av270($bad['actions']===[],'Malformed model action payload cannot create a governed Admin preview.');
av270(admin_agent_action_intent_allowed('Please resync the Stripe subscription for this account.','resync_stripe_subscription'),'Explicit administrator resync request authorizes only the matching preview intent.');
av270(!admin_agent_action_intent_allowed('Tell me why this Stripe subscription is past due.','resync_stripe_subscription'),'Read-only Stripe questions cannot be upgraded into a governed preview by model output alone.');
av270(admin_agent_action_intent_allowed('Reconcile the AI overage for this account.','reconcile_ai_overage'),'Explicit overage reconciliation intent is recognized deterministically.');


$q=$pdo->prepare("SELECT COUNT(*) FROM conversations WHERE conversation_type='admin_agent' AND created_by_user_id=?");$q->execute([(int)$owner['id']]);av270((int)$q->fetchColumn()===1,'Admin Agent persists no shadow thread store outside the conversation runtime.');
$sampleRows=[
 ['type'=>'Account','identifier'=>'acc-private','title'=>'Private account','subtitle'=>''],
 ['type'=>'Invoice','identifier'=>'in-private','title'=>'Private invoice','subtitle'=>''],
 ['type'=>'Support case','identifier'=>'case-private','title'=>'Private support case','subtitle'=>''],
 ['type'=>'Admin action','identifier'=>'aar-private','title'=>'Action','subtitle'=>''],
];
$filtered=admin_agent_filter_search_rows($pdo,$limited,$sampleRows);
av270($filtered===[],'Operations-only Admin Agent cannot pass Account, Billing, Support or Action Center search records into model context.');
$safe=admin_agent_safe_dashboard_snapshot($pdo,$limited,['counts'=>['users'=>10,'accounts'=>4,'failed_stripe_webhooks'=>2,'open_reports'=>3],'usage'=>['used_tokens'=>999],'worker_problems'=>2,'pending_migrations'=>['080'],'attention'=>[
 ['label'=>'Billing','url'=>'/admin/billing.php'],['label'=>'Health','url'=>'/admin/system-health.php']
]]);
av270(($safe['counts']??[])===[]&&!isset($safe['usage'])&&!isset($safe['worker_problems'])&&!isset($safe['pending_migrations'])&&($safe['attention']??[])===[],'Operations-only Admin Agent receives no cross-domain dashboard evidence without the corresponding view capabilities.');

av270(admin_access_route_requirement('/admin/assistant.php','GET')==='admin.operations.view','Admin Agent surface remains governed by delegated Admin operations visibility.');

echo "Admin V2.70 Admin Agent database journey passed.\n";
