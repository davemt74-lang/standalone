<?php
declare(strict_types=1);
$root=dirname(__DIR__);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(['installer','storage','jobs','functions','concurrency','subscriptions','account-admin','account-membership','stripe-billing','billing-operations','ai-usage','ai-overage-billing','commercial-promotions','commercial-billing','admin-operations','admin-access','admin-support','admin-finance','notifications','admin-customer-success','admin-security-compliance','schema-health','release','release-operations','vp3-connector','admin-platform-governance','admin-agent-context','admin-ui','access','object-handoff','source-integrity','annotation-intelligence','conversations','rate-limit','research-workspace','agent-actions','admin-agent'] as $lib)require_once $root.'/app/'.$lib.'.php';
function av280(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}
$run='av280'.substr(bin2hex(random_bytes(6)),0,10);$pub=fn(string $p)=>$p.'-'.$run.'-'.substr(bin2hex(random_bytes(3)),0,6);
$make=function(string $name,string $role='admin')use($pdo,$run,$pub): array{$username=substr(strtolower($name).'_'.$run,0,48);$pdo->prepare("INSERT INTO users(public_id,username,display_name,email,status,role,plan_tier,live_presence_mode) VALUES(?,?,?,?, 'active',?,'pro','cloaked')")->execute([$pub('u'),$username,$name,$username.'@example.test',$role]);$id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();};
$owner=$make('ContextOwner');$reviewer=$make('ContextReviewer');$customer=$make('ContextCustomer','user');$limited=$make('ContextLimited');
admin_access_profile($pdo,$owner);admin_access_profile($pdo,$reviewer);
$limitedRole=admin_access_role_save($pdo,$owner,['name'=>'Context Limited '.$run,'role_key'=>'context_limited_'.$run,'description'=>'Operations-only V2.80 fixture.','capabilities'=>['admin.operations.view'],'status'=>'active','reason'=>'Verify contextual Admin Agent isolation.']);
admin_access_assign_operator($pdo,$owner,(int)$limited['id'],(string)$limitedRole['public_id'],'active','Verify contextual Admin Agent isolation.');

$basePackage=subscription_package_create($pdo,$owner,['name'=>'Context Base '.$run,'slug'=>'context-base-'.$run,'legacy_plan_tier'=>'pro','monthly_price'=>'20.00','monthly_ai_token_allowance'=>'50000','member_limit'=>'3','trial_days'=>'0','sort_order'=>'980','is_public'=>'1','status'=>'active']);
$targetPackage=subscription_package_create($pdo,$owner,['name'=>'Context Target '.$run,'slug'=>'context-target-'.$run,'legacy_plan_tier'=>'pro','monthly_price'=>'40.00','monthly_ai_token_allowance'=>'100000','member_limit'=>'5','trial_days'=>'0','sort_order'=>'981','is_public'=>'1','status'=>'active']);
$account=subscription_ensure_user_account($pdo,(int)$customer['id'],(int)$owner['id']);$account=subscription_assign_user_package($pdo,$owner,(int)$customer['id'],(string)$basePackage['public_id'],'V2.80 context fixture.');$account=account_admin_get($pdo,(int)$account['id']);

$ctx=admin_agent_page_context($pdo,$owner,'/admin/account.php',['id'=>$account['public_id']]);
av280(($ctx['type']??'')==='account'&&$ctx['account_public_id']===$account['public_id']&&str_contains((string)$ctx['text'],(string)$account['name']),'Account 360 resolves into server-side Admin Agent context.');
av280(admin_agent_page_context($pdo,$limited,'/admin/account.php',['id'=>$account['public_id']])===null,'Operations-only Admin cannot spoof Account 360 context without accounts.view.');

$case=admin_support_create($pdo,$owner,['account_public_id'=>$account['public_id'],'user_identifier'=>$customer['username'],'title'=>'Context support '.$run,'category'=>'access','priority'=>'high','source'=>'customer','description'=>'V2.80 support context fixture.']);
$caseCtx=admin_agent_page_context($pdo,$owner,'/admin/support-case.php',['id'=>$case['public_id']]);
av280(($caseCtx['type']??'')==='support_case'&&$caseCtx['public_id']===$case['public_id']&&$caseCtx['account_public_id']===$account['public_id'],'Support Case resolves title, state and linked account into authorized context.');
av280(admin_agent_page_context($pdo,$limited,'/admin/support-case.php',['id'=>$case['public_id']])===null,'Operations-only Admin cannot spoof Support context without support.view.');

$thread=admin_agent_thread_create($pdo,$owner);$userMessage=conversation_message_create($pdo,$owner,(string)$thread['public_id'],'Find this account.',null,'av280-'.$run);$conversation=admin_agent_thread_access($pdo,$owner,(string)$thread['public_id']);$assistant=agent_chat_insert_agent_message($pdo,$conversation,'I found the account.',(int)$userMessage['id']);
$stored=admin_agent_store_links($pdo,(int)$assistant['id'],[['type'=>'Account','title'=>$account['name'],'subtitle'=>$account['package_name'],'url'=>'/admin/account.php?id='.rawurlencode((string)$account['public_id']),'identifier'=>$account['public_id']],['type'=>'Unsafe','title'=>'External','url'=>'https://example.test','identifier'=>'x']]);
av280(count($stored)===1&&str_starts_with($stored[0]['url'],'/admin/'),'Admin navigation attachment store rejects non-Admin URLs.');
$history=admin_agent_messages($pdo,$owner,(string)$thread['public_id']);$historyRows=$history['messages']??[];$last=$historyRows?end($historyRows):null;av280(is_array($last)&&count($last['admin_links']??[])===1&&($last['admin_links'][0]['identifier']??'')===$account['public_id'],'Authorized Admin navigation cards persist with the Agent message.');

$packagePreview=admin_ops_action_preview($pdo,$owner,'change_account_package',(string)$account['public_id'],'Move context fixture to target package.',['package_public_id'=>$targetPackage['public_id']]);
$record=$packagePreview['record'];av280((int)$record['required_approvals']===1&&!empty($record['approval_distinct_from_requester'])&&($packagePreview['preview']['target_package_public_id']??'')===$targetPackage['public_id'],'Agent package-change preview requires one distinct reviewer and snapshots exact target package.');
$record=admin_access_action_request_approval($pdo,$owner,(string)$record['public_id']);av280($record['status']==='pending_approval','Package change enters normal Action Center approval workflow.');
$blocked=false;try{admin_access_action_decide($pdo,$owner,(string)$record['public_id'],'approved','Requester should not self-approve.');}catch(RuntimeException $e){$blocked=true;}av280($blocked,'Requester cannot approve its own contextual package change.');
$record=admin_access_action_decide($pdo,$reviewer,(string)$record['public_id'],'approved','Independent V2.80 package review.');av280($record['status']==='approved','Distinct reviewer can approve contextual package change.');
$record=admin_ops_action_execute($pdo,[],$owner,(string)$record['public_id']);$account=account_admin_get($pdo,(string)$account['public_id']);av280($record['status']==='executed'&&$account['package_public_id']===$targetPackage['public_id'],'Approved package change executes through authoritative Account 360 package handler.');

$lifecycle=admin_ops_action_preview($pdo,$owner,'set_account_lifecycle',(string)$account['public_id'],'Suspend context fixture for review.',['status'=>'suspended']);$lr=$lifecycle['record'];
av280((int)$lr['required_approvals']===1&&($lifecycle['preview']['target_status']??'')==='suspended','Suspend preview is bounded to explicit lifecycle target and distinct review.');
$lr=admin_access_action_request_approval($pdo,$owner,(string)$lr['public_id']);$lr=admin_access_action_decide($pdo,$reviewer,(string)$lr['public_id'],'approved','Independent V2.80 lifecycle review.');$lr=admin_ops_action_execute($pdo,[],$owner,(string)$lr['public_id']);$account=account_admin_get($pdo,(string)$account['public_id']);
av280($lr['status']==='executed'&&$account['status']==='suspended','Approved lifecycle action executes through authoritative Account 360 lifecycle handler.');

$invalid=false;try{admin_ops_action_preview($pdo,$owner,'set_account_lifecycle',(string)$account['public_id'],'Attempt unsupported close.',['status'=>'closed']);}catch(InvalidArgumentException $e){$invalid=true;}av280($invalid,'Admin Agent lifecycle adapter cannot close an account.');
av280(admin_agent_action_intent_allowed('Suspend this account.','set_account_lifecycle')&&!admin_agent_action_intent_allowed('What is the account status?','set_account_lifecycle'),'Lifecycle preview requires explicit administrator mutation intent.');
av280(admin_agent_action_intent_allowed('Change this account to the Team package.','change_account_package'),'Package preview recognizes explicit package-change intent.');

echo "Admin V2.80 Contextual Admin Agent database journey passed.\n";
