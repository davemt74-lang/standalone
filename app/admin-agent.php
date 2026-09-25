<?php
declare(strict_types=1);
require_once __DIR__.'/agent-chat.php';
require_once __DIR__.'/admin-agent-context.php';

function admin_agent_ready(PDO $pdo): bool {
    return conversation_runtime_ready($pdo);
}
function admin_agent_assert_admin(PDO $pdo,array $admin): void {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Admin Agent is available only to administrators.');
    if(function_exists('admin_access_ready')&&admin_access_ready($pdo))admin_access_assert_capability($pdo,$admin,'admin.operations.view');
}
function admin_agent_can(PDO $pdo,array $admin,string $capability): bool {
    return !function_exists('admin_access_ready')||!admin_access_ready($pdo)||admin_access_has_capability($pdo,$admin,$capability);
}
function admin_agent_search_type_capability(string $type): string {
    return match($type){
        'Account','User'=>'admin.accounts.view',
        'Invoice','Subscription','Promotion'=>'admin.billing.view',
        'Admin action'=>'admin.actions.view',
        'Support case'=>'admin.support.view',
        default=>'admin.operations.view',
    };
}
function admin_agent_filter_search_rows(PDO $pdo,array $admin,array $rows): array {
    return array_values(array_filter($rows,fn($row)=>admin_agent_can($pdo,$admin,admin_agent_search_type_capability((string)($row['type']??'')))));
}
function admin_agent_attention_capability(string $url): string {
    $path=(string)(parse_url($url,PHP_URL_PATH)?:$url);
    if($path==='/admin/usage.php')return 'admin.ai_usage.view';
    if(in_array($path,['/admin/accounts.php','/admin/account.php','/admin/users.php','/admin/packages.php'],true))return 'admin.accounts.view';
    if(in_array($path,['/admin/billing.php','/admin/billing-analytics.php','/admin/overage-billing.php','/admin/promotions.php','/admin/tax-invoices.php'],true))return 'admin.billing.view';
    if($path==='/admin/source-monitor.php')return 'admin.research_data.view';
    if($path==='/admin/customer-success.php'||$path==='/admin/customer-success-account.php')return 'admin.customer_success.view';
    if($path==='/admin/support.php'||$path==='/admin/support-case.php')return 'admin.support.view';
    if($path==='/admin/security-compliance.php')return 'admin.security.view';
    if($path==='/admin/platform-governance.php'||$path==='/upgrade.php')return 'admin.platform.view';
    if($path==='/admin/system-health.php'||$path==='/admin/moderation.php')return 'admin.trust.view';
    return 'admin.operations.view';
}
function admin_agent_safe_dashboard_snapshot(PDO $pdo,array $admin,array $snapshot): array {
    $counts=[];$all=(array)($snapshot['counts']??[]);
    $copy=function(array $keys)use(&$counts,$all){foreach($keys as $key)if(array_key_exists($key,$all))$counts[$key]=$all[$key];};
    if(admin_agent_can($pdo,$admin,'admin.accounts.view'))$copy(['users','accounts','packages','suspended_accounts','paused_subscriptions','past_due_subscriptions','over_capacity_accounts','trials_ending']);
    if(admin_agent_can($pdo,$admin,'admin.billing.view'))$copy(['failed_stripe_webhooks','open_dunning_cases','overage_failed_batches']);
    if(admin_agent_can($pdo,$admin,'admin.trust.view'))$copy(['open_claims','open_reports','failed_ai_jobs']);
    if(admin_agent_can($pdo,$admin,'admin.research_data.view'))$copy(['sources','failed_source_jobs']);
    if(admin_agent_can($pdo,$admin,'admin.ai_usage.view'))$copy(['queued_ai_jobs']);
    $safe=['counts'=>$counts,'attention'=>array_values(array_filter((array)($snapshot['attention']??[]),fn($row)=>admin_agent_can($pdo,$admin,admin_agent_attention_capability((string)($row['url']??'')))))];
    if(admin_agent_can($pdo,$admin,'admin.ai_usage.view'))$safe['usage']=$snapshot['usage']??[];
    if(admin_agent_can($pdo,$admin,'admin.trust.view'))$safe['worker_problems']=$snapshot['worker_problems']??0;
    if(admin_agent_can($pdo,$admin,'admin.platform.view'))$safe['pending_migrations']=$snapshot['pending_migrations']??[];
    return $safe;
}
function admin_agent_thread_access(PDO $pdo,array $admin,string $publicId): ?array {
    admin_agent_assert_admin($pdo,$admin);$publicId=trim($publicId);if($publicId==='')return null;
    $q=$pdo->prepare("SELECT c.* FROM conversations c JOIN conversation_members cm ON cm.conversation_id=c.id AND cm.user_id=? WHERE c.public_id=? AND c.conversation_type='admin_agent' AND c.created_by_user_id=? LIMIT 1");
    $q->execute([(int)$admin['id'],$publicId,(int)$admin['id']]);return $q->fetch()?:null;
}
function admin_agent_thread_create(PDO $pdo,array $admin,string $title='Admin Agent'): array {
    admin_agent_assert_admin($pdo,$admin);if(!admin_agent_ready($pdo))throw new RuntimeException('Admin Agent requires the conversation runtime.');
    $title=mb_substr(trim($title),0,190);if($title==='')$title='Admin Agent';$public=ulid_like();$pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO conversations(public_id,conversation_type,created_by_user_id,title) VALUES(?,'admin_agent',?,?)")->execute([$public,(int)$admin['id'],$title]);
        $id=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO conversation_members(conversation_id,user_id,member_role) VALUES(?,?,'owner')")->execute([$id,(int)$admin['id']]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return ['id'=>$id,'public_id'=>$public,'title'=>$title,'conversation_type'=>'admin_agent'];
}
function admin_agent_threads(PDO $pdo,array $admin,int $limit=40): array {
    admin_agent_assert_admin($pdo,$admin);$limit=max(1,min(100,$limit));
    $q=$pdo->prepare("SELECT c.public_id,c.title,c.last_message_at,c.created_at,c.updated_at,
      (SELECT body FROM conversation_messages m WHERE m.conversation_id=c.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1) last_message
      FROM conversations c JOIN conversation_members cm ON cm.conversation_id=c.id AND cm.user_id=?
      WHERE c.conversation_type='admin_agent' AND c.created_by_user_id=?
      ORDER BY COALESCE(c.last_message_at,c.updated_at) DESC,c.id DESC LIMIT ".$limit);
    $q->execute([(int)$admin['id'],(int)$admin['id']]);$rows=$q->fetchAll()?:[];
    foreach($rows as &$row)$row['last_message']=mb_substr(trim((string)($row['last_message']??'')),0,100);unset($row);return $rows;
}
function admin_agent_messages(PDO $pdo,array $admin,string $threadPublic,?int $before=null,int $limit=80): ?array {
    $thread=admin_agent_thread_access($pdo,$admin,$threadPublic);if(!$thread)return null;
    $data=conversation_message_rows($pdo,$admin,$threadPublic,$before,max(1,min(100,$limit)));if(!$data)return null;
    $ids=array_map(fn($m)=>(int)$m['id'],$data['messages']);$actions=[];
    if($ids&&admin_ops_ready($pdo)){
        $marks=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare("SELECT a.message_id,a.object_public_id,r.action_type,r.status,r.risk_level,r.request_json,r.required_approvals
          FROM conversation_message_attachments a JOIN admin_action_records r ON r.public_id=a.object_public_id
          WHERE a.message_id IN ($marks) AND a.attachment_type='admin_action' ORDER BY a.id");
        $q->execute($ids);foreach($q->fetchAll()?:[] as $row){$request=json_decode((string)$row['request_json'],true)?:[];$preview=$request['preview']??[];$actions[(int)$row['message_id']][]=[
            'public_id'=>(string)$row['object_public_id'],'action_type'=>(string)$row['action_type'],'status'=>(string)$row['status'],'risk_level'=>(string)$row['risk_level'],
            'required_approvals'=>(int)$row['required_approvals'],'label'=>(string)($preview['label']??ucwords(str_replace('_',' ',(string)$row['action_type']))),
            'account_name'=>(string)($preview['account_name']??''),'url'=>'/admin/action-center.php?record='.rawurlencode((string)$row['object_public_id'])
        ];}
    }
    $links=[];if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare("SELECT message_id,metadata_json FROM conversation_message_attachments WHERE message_id IN ($marks) AND attachment_type='admin_link' ORDER BY id");$q->execute($ids);foreach($q->fetchAll()?:[] as $row){$meta=json_decode((string)($row['metadata_json']??''),true);if(is_array($meta)&&!empty($meta['url']))$links[(int)$row['message_id']][]=$meta;}}
    foreach($data['messages'] as &$message){$message['role']=($message['sender_type']??'user')==='agent'?'assistant':'user';$message['admin_actions']=$actions[(int)$message['id']]??[];$message['admin_links']=$links[(int)$message['id']]??[];}unset($message);
    return $data;
}
function admin_agent_search_terms(string $prompt): array {
    $terms=[];$push=function(string $value)use(&$terms){$value=trim($value," \t\n\r\0\x0B\"'“”");if(mb_strlen($value)>=2&&!in_array(mb_strtolower($value),array_map('mb_strtolower',$terms),true))$terms[]=$value;};
    if(preg_match_all('/[“"]([^"”]{2,100})[”"]/u',$prompt,$m))foreach($m[1] as $v)$push((string)$v);
    if(preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',$prompt,$m))foreach($m[0] as $v)$push((string)$v);
    if(preg_match_all('/\b(?:acc|acct|account|u|usr|user|in|sub|cus|case|promo|aar|aop)[-_][A-Za-z0-9\-]{3,}\b/i',$prompt,$m))foreach($m[0] as $v)$push((string)$v);
    $stop=array_flip(['what','which','show','find','tell','give','about','with','from','that','this','there','have','does','need','needs','right','now','please','admin','agent','account','user','users','billing','support','system','check','summarize','summary','the','and','for','are','who','why','how','can','you','me','my','our','any','all']);
    $clean=preg_replace('/[^\pL\pN@._+\-]+/u',' ',mb_strtolower($prompt))??'';$words=[];
    foreach(preg_split('/\s+/u',trim($clean))?:[] as $word){if(mb_strlen($word)<3||isset($stop[$word]))continue;$words[]=$word;if(count($words)>=5)break;}
    if($words)$push(implode(' ',$words));if(mb_strlen(trim($prompt))<=70)$push(trim($prompt));return array_slice($terms,0,6);
}
function admin_agent_search(PDO $pdo,array $admin,string $prompt): array {
    $out=[];$seen=[];if(!function_exists('admin_ops_global_search'))return [];
    foreach(admin_agent_search_terms($prompt) as $term){try{$rows=admin_agent_filter_search_rows($pdo,$admin,admin_ops_global_search($pdo,$term,20,$admin));}catch(Throwable $e){$rows=[];}
        foreach($rows as $row){$key=(string)($row['type']??'').':'.(string)($row['identifier']??'');if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=$row;if(count($out)>=20)break 2;}
    }return $out;
}
function admin_agent_package_options(PDO $pdo,array $admin,string $prompt): array {
    $p=mb_strtolower($prompt);if(!admin_agent_can($pdo,$admin,'admin.accounts.view')||(!str_contains($p,'package')&&!str_contains($p,'plan')&&!str_contains($p,'upgrade')&&!str_contains($p,'downgrade')&&!str_contains($p,'move')))return [];
    if(!function_exists('subscription_packages'))return [];$out=[];foreach(subscription_packages($pdo,true) as $row)$out[]=['public_id'=>(string)$row['public_id'],'name'=>(string)$row['name'],'slug'=>(string)$row['slug'],'monthly_price'=>(string)$row['monthly_price'],'member_limit'=>(int)$row['member_limit'],'monthly_ai_token_allowance'=>$row['monthly_ai_token_allowance']===null?null:(int)$row['monthly_ai_token_allowance']];return array_slice($out,0,40);
}
function admin_agent_store_links(PDO $pdo,int $messageId,array $links): array {
    $stored=[];$seen=[];foreach(array_slice($links,0,8) as $row){if(!is_array($row)||empty($row['url']))continue;$url=(string)$row['url'];if(!str_starts_with($url,'/admin/')&&$url!=='/upgrade.php')continue;$key=hash('sha256',$url.'|'.(string)($row['identifier']??''));if(isset($seen[$key]))continue;$seen[$key]=true;$meta=['type'=>(string)($row['type']??'Admin'),'title'=>mb_substr((string)($row['title']??'Open Admin result'),0,180),'subtitle'=>mb_substr((string)($row['subtitle']??''),0,300),'url'=>$url,'identifier'=>mb_substr((string)($row['identifier']??''),0,255)];$pdo->prepare("INSERT INTO conversation_message_attachments(message_id,attachment_type,object_public_id,metadata_json) VALUES(?,'admin_link',?,?)")->execute([$messageId,substr($key,0,64),json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);$stored[]=$meta;}return $stored;
}
function admin_agent_context_bundle(PDO $pdo,array $config,array $admin,string $prompt,array $pageContextInput=[]): array {
    admin_agent_assert_admin($pdo,$admin);$snapshot=function_exists('admin_ui_dashboard_snapshot')?admin_ui_dashboard_snapshot($pdo):[];
    $safeSnapshot=admin_agent_safe_dashboard_snapshot($pdo,$admin,$snapshot);$pageContext=null;
    if($pageContextInput){$path=(string)($pageContextInput['path']??'');$query=is_array($pageContextInput['query']??null)?$pageContextInput['query']:[];if($path!=='')$pageContext=admin_agent_page_context($pdo,$admin,$path,$query);}

    $sections=[];$contextSources=[
        'operations'=>['admin_ops_agent_context','admin.operations.view'],
        'support'=>['admin_support_agent_context','admin.support.view'],
        'finance'=>['admin_finance_agent_context','admin.finance.view'],
        'customer_success'=>['admin_customer_success_agent_context','admin.customer_success.view'],
        'security'=>['admin_security_agent_context','admin.security.view'],
    ];
    foreach($contextSources as $label=>$source){[$fn,$capability]=$source;if(!admin_agent_can($pdo,$admin,$capability)||!function_exists($fn))continue;try{$value=$fn($pdo,$admin);if(trim((string)$value)!=='')$sections[$label]=(string)$value;}catch(Throwable $e){}}
    if(admin_agent_can($pdo,$admin,'admin.platform.view')&&function_exists('admin_platform_agent_context')){try{$value=admin_platform_agent_context($pdo,$config,$admin);if(trim((string)$value)!=='')$sections['platform']=(string)$value;}catch(Throwable $e){}}
    $search=admin_agent_search($pdo,$admin,$prompt);$allowedAccounts=[];
    foreach($search as $row)if(($row['type']??'')==='Account'&&!empty($row['identifier']))$allowedAccounts[]=(string)$row['identifier'];
    if($pageContext&&!empty($pageContext['account_public_id']))$allowedAccounts[]=(string)$pageContext['account_public_id'];
    $packages=admin_agent_package_options($pdo,$admin,$prompt);
    $catalog=[];if(function_exists('admin_ops_action_catalog'))foreach(admin_ops_action_catalog() as $key=>$meta){if(!in_array((string)($meta['surface']??'account'),['account','agent_account'],true))continue;$cap=(string)($meta['capability']??'admin.actions.request');if(function_exists('admin_access_ready')&&admin_access_ready($pdo)&&(!admin_access_has_capability($pdo,$admin,'admin.actions.request')||!admin_access_has_capability($pdo,$admin,$cap)))continue;$catalog[$key]=['label'=>$meta['label'],'risk'=>$meta['risk'],'description'=>$meta['description']];}
    $text="[ANNOTATED ADMIN SNAPSHOT]\n".json_encode($safeSnapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if($pageContext)$text.="\n\n".$pageContext['text'];
    foreach($sections as $label=>$value)$text.="\n\n[".strtoupper(str_replace('_',' ',$label))."]\n".$value;
    if($search)$text.="\n\n[ADMIN SEARCH RESULTS]\n".json_encode($search,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if($packages)$text.="\n\n[ACTIVE ADMIN PACKAGES]\n".json_encode($packages,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if($catalog)$text.="\n\n[GOVERNED ACTIONS AVAILABLE FOR PREVIEW]\n".json_encode($catalog,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    return ['text'=>mb_substr($text,0,46000),'search'=>$search,'page_context'=>$pageContext,'packages'=>$packages,'allowed_account_ids'=>array_values(array_unique(array_filter($allowedAccounts))),'action_catalog'=>$catalog];
}
function admin_agent_extract_actions(string $text): array {
    $marker='<<ANNOTATED_ADMIN_ACTIONS>>';$pos=strrpos($text,$marker);if($pos===false)return ['body'=>trim($text),'actions'=>[]];
    $body=trim(substr($text,0,$pos));$json=trim(substr($text,$pos+strlen($marker)));$actions=[];
    try{$decoded=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(is_array($decoded))$actions=array_values($decoded);}catch(Throwable $e){}
    return ['body'=>$body!==''?$body:'I prepared the requested Admin review.','actions'=>array_slice($actions,0,3)];
}
function admin_agent_action_intent_allowed(string $prompt,string $actionType): bool {
    $p=mb_strtolower($prompt);
    return match($actionType){
        'resync_stripe_subscription'=>(str_contains($p,'resync')||str_contains($p,'re-sync'))&&(str_contains($p,'stripe')||str_contains($p,'subscription')),
        'reconcile_ai_overage'=>str_contains($p,'reconcile')&&str_contains($p,'overage'),
        'sync_tax_policy'=>(str_contains($p,'sync')||str_contains($p,'synchronize'))&&(str_contains($p,'tax')||str_contains($p,'billing profile')||str_contains($p,'billing policy')),
        'change_account_package'=>(str_contains($p,'change')||str_contains($p,'switch')||str_contains($p,'move')||str_contains($p,'upgrade')||str_contains($p,'downgrade'))&&(str_contains($p,'package')||str_contains($p,'plan')),
        'set_account_lifecycle'=>str_contains($p,'suspend')||str_contains($p,'reactivate')||str_contains($p,'re-activate')||str_contains($p,'activate account'),
        default=>false,
    };
}
function admin_agent_create_action_previews(PDO $pdo,array $admin,int $assistantMessageId,array $requested,array $allowedAccountIds,string $prompt): array {
    $allowedTypes=['resync_stripe_subscription','reconcile_ai_overage','sync_tax_policy','change_account_package','set_account_lifecycle'];$out=[];$errors=[];
    foreach($requested as $action){if(!is_array($action))continue;$type=(string)($action['action_type']??'');$account=(string)($action['account_public_id']??'');$params=is_array($action['params']??null)?$action['params']:[];
        if(!in_array($type,$allowedTypes,true)||!in_array($account,$allowedAccountIds,true)||!admin_agent_action_intent_allowed($prompt,$type))continue;
        try{$preview=admin_ops_action_preview($pdo,$admin,$type,$account,mb_substr('Admin Agent request: '.preg_replace('/\s+/u',' ',trim($prompt)),0,500),$params);$record=$preview['record'];$meta=$preview['preview'];
            $pdo->prepare("INSERT INTO conversation_message_attachments(message_id,attachment_type,object_public_id,metadata_json) VALUES(?,'admin_action',?,?)")->execute([$assistantMessageId,(string)$record['public_id'],json_encode(['label'=>$meta['label']??$type,'risk_level'=>$record['risk_level']??'routine'],JSON_UNESCAPED_SLASHES)]);
            $out[]=['public_id'=>(string)$record['public_id'],'action_type'=>$type,'status'=>(string)$record['status'],'risk_level'=>(string)$record['risk_level'],'required_approvals'=>(int)($record['required_approvals']??0),'label'=>(string)($meta['label']??$type),'account_name'=>(string)($meta['account_name']??''),'url'=>'/admin/action-center.php?record='.rawurlencode((string)$record['public_id'])];
        }catch(Throwable $e){$errors[]=mb_substr($e->getMessage(),0,240);}
    }return ['previews'=>$out,'errors'=>$errors];
}
function admin_agent_send(PDO $pdo,array $config,array $admin,string $threadPublic,string $prompt,?string $clientMessageId=null,array $pageContextInput=[]): array {
    admin_agent_assert_admin($pdo,$admin);$prompt=trim($prompt);if($prompt===''||mb_strlen($prompt)>5000)throw new InvalidArgumentException('Message must be between 1 and 5000 characters.');
    $thread=admin_agent_thread_access($pdo,$admin,$threadPublic);if(!$thread)throw new RuntimeException('Admin Agent thread not found.');
    $userMessage=conversation_message_create($pdo,$admin,$threadPublic,$prompt,null,$clientMessageId);$userMessageId=(int)$userMessage['id'];
    if(!$userMessage['created']){$q=$pdo->prepare("SELECT id,public_id,body FROM conversation_messages WHERE conversation_id=? AND parent_message_id=? AND sender_type='agent' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");$q->execute([(int)$thread['id'],$userMessageId]);if($existing=$q->fetch())return ['thread'=>['public_id'=>$threadPublic,'title'=>$thread['title']],'user_message'=>$userMessage,'assistant_message'=>['id'=>(int)$existing['id'],'public_id'=>$existing['public_id'],'body'=>$existing['body'],'role'=>'assistant'],'deduplicated'=>true];}
    $quota=rate_limit_consume($pdo,'admin-agent','user:'.(string)$admin['id'],300,3600);if(!$quota['allowed'])throw new RuntimeException('Admin Agent request limit reached. Try again later.');
    $model=ai_setting_model_id($pdo,'admin',false);if(!$model)$model=ai_setting_model_id($pdo,'research',true);if(!$model)throw new RuntimeException('Configure an Admin default AI model first.');ai_interactive_model_record($pdo,$admin,$model);
    $bundle=admin_agent_context_bundle($pdo,$config,$admin,$prompt,$pageContextInput);$history=agent_chat_history_text($pdo,(int)$thread['id'],18);
    $system='You are the Annotated Admin Agent. You help an authorized administrator operate the Annotated standalone Admin section. Use only supplied Admin context and search results as factual application state. Separate observed facts from recommendations. Never invent users, accounts, invoices, support cases, security events, configuration, IDs, money, usage, worker state, or completed actions. Never reveal provider secrets, password hashes, tokens, private credentials, or raw sensitive configuration. You may explain and prioritize Admin work and link the administrator to the appropriate Admin surface. You do not directly mutate Admin state. Existing Admin permissions, previews, approval policies, separation of duties, execution handlers and audit ledgers remain authoritative. The CURRENT ADMIN PAGE context is authoritative only when supplied. Use it to answer references such as "this account", "this case" or "what is going on here". For an explicit request to resync a Stripe subscription, reconcile AI overage, sync billing/tax policy, change an account package, or suspend/reactivate an account, you may request a governed preview only when the exact account public ID appears in ADMIN SEARCH RESULTS or CURRENT ADMIN PAGE. Append exactly one machine-readable block at the END using <<ANNOTATED_ADMIN_ACTIONS>> followed by a JSON array. Each item must contain action_type and account_public_id. change_account_package additionally requires params.package_public_id from ACTIVE ADMIN PACKAGES. set_account_lifecycle additionally requires params.status and status may only be active or suspended. Allowed action_type values: resync_stripe_subscription, reconcile_ai_overage, sync_tax_policy, change_account_package, set_account_lifecycle. Never claim the action executed; tell the administrator it must be reviewed in Action Center. Package and lifecycle previews require a distinct authorized reviewer before execution. For every other mutation, explain which Admin surface controls it and do not emit an action block.';
    $aiPrompt="Conversation history:\n".$history."\n\nCURRENT AUTHORIZED ADMIN CONTEXT:\n".$bundle['text']."\n\nAnswer the latest administrator message.";
    $run=ai_run($pdo,$config,$admin,'admin','admin_agent',$model,$system,$aiPrompt,[['type'=>'conversation','id'=>$threadPublic]],'admin_agent_conversation',$threadPublic);
    $parsed=admin_agent_extract_actions((string)$run['text']);$assistant=agent_chat_insert_agent_message($pdo,$thread,(string)$parsed['body'],$userMessageId);
    if(function_exists('data_response_try_bind_message'))data_response_try_bind_message($pdo,(string)$run['public_id'],(int)$assistant['id']);
    $assistant['admin_links']=admin_agent_store_links($pdo,(int)$assistant['id'],(array)$bundle['search']);
    $assistant['page_context']=$bundle['page_context']??null;
    $actions=admin_agent_create_action_previews($pdo,$admin,(int)$assistant['id'],$parsed['actions'],(array)$bundle['allowed_account_ids'],$prompt);$assistant['admin_actions']=$actions['previews'];$assistant['role']='assistant';
    if(count((array)$parsed['actions'])&&!$actions['previews']){
        $detail=$actions['errors']?implode(' ',array_map(fn($e)=>trim((string)$e),$actions['errors'])):'The requested operation did not meet the deterministic account, capability, or explicit-intent checks.';
        $note="\n\nI did not create a governed preview: ".$detail;
        $assistant['body'].=$note;$assistant['action_errors']=$actions['errors'];$pdo->prepare('UPDATE conversation_messages SET body=? WHERE id=?')->execute([$assistant['body'],(int)$assistant['id']]);
    }
    $pdo->prepare("UPDATE conversations SET title=CASE WHEN title='Admin Agent' THEN ? ELSE title END,last_message_at=NOW(),updated_at=NOW() WHERE id=?")->execute([mb_substr(preg_replace('/\s+/u',' ',$prompt),0,72),(int)$thread['id']]);
    return ['thread'=>['public_id'=>$threadPublic,'title'=>$thread['title']],'user_message'=>$userMessage,'assistant_message'=>$assistant,'search_results'=>$bundle['search'],'page_context'=>$bundle['page_context']??null,'deduplicated'=>false];
}
