<?php
declare(strict_types=1);

function admin_ui_nav_sections(): array {
    return [
        'overview'=>[
            'label'=>'Overview',
            'items'=>[
                'dashboard'=>['label'=>'Command Center','url'=>'/admin/'],
                'support'=>['label'=>'Support Operations','url'=>'/admin/support.php'],
                'customer_success'=>['label'=>'Customer Success','url'=>'/admin/customer-success.php'],
                'action_center'=>['label'=>'Action Center','url'=>'/admin/action-center.php'],
                'roles_permissions'=>['label'=>'Roles & Permissions','url'=>'/admin/roles-permissions.php'],
                'security_compliance'=>['label'=>'Security & Compliance','url'=>'/admin/security-compliance.php'],
                'assistant'=>['label'=>'Admin Agent','url'=>'/admin/assistant.php'],
            ],
        ],
        'accounts'=>[
            'label'=>'Accounts & Billing',
            'items'=>[
                'accounts'=>['label'=>'Accounts','url'=>'/admin/accounts.php'],
                'users'=>['label'=>'Users','url'=>'/admin/users.php'],
                'packages'=>['label'=>'Packages','url'=>'/admin/packages.php'],
                'billing'=>['label'=>'Billing & Stripe','url'=>'/admin/billing.php'],
                'billing_analytics'=>['label'=>'Billing Analytics','url'=>'/admin/billing-analytics.php'],
                'financial_reporting'=>['label'=>'Financial Reporting','url'=>'/admin/financial-reporting.php'],
                'overage_billing'=>['label'=>'AI Overage Billing','url'=>'/admin/overage-billing.php'],
                'promotions'=>['label'=>'Promotions & Credits','url'=>'/admin/promotions.php'],
                'tax_invoices'=>['label'=>'Tax & Invoices','url'=>'/admin/tax-invoices.php'],
                'usage'=>['label'=>'AI Usage','url'=>'/admin/usage.php'],
            ],
        ],
        'models'=>[
            'label'=>'AI & Models',
            'items'=>[
                'ai'=>['label'=>'Providers & Routing','url'=>'/admin/ai.php'],
                'evaluations'=>['label'=>'Evaluation Harness','url'=>'/admin/evaluations.php'],
                'model_registry'=>['label'=>'Model Registry','url'=>'/admin/model-registry.php'],
                'training'=>['label'=>'Training Registry','url'=>'/admin/training.php'],
                'post_training'=>['label'=>'Post-Training','url'=>'/admin/post-training.php'],
                'model_release'=>['label'=>'Release Decisions','url'=>'/admin/model-release.php'],
                'model_deployment'=>['label'=>'Deployments','url'=>'/admin/model-deployment.php'],
                'model_observability'=>['label'=>'Model Health','url'=>'/admin/model-observability.php'],
                'model_improvements'=>['label'=>'Improvement Loop','url'=>'/admin/model-improvements.php'],
                'model_campaigns'=>['label'=>'Improvement Campaigns','url'=>'/admin/model-campaigns.php'],
            ],
        ],
        'research'=>[
            'label'=>'Research & Data',
            'items'=>[
                'source_monitor'=>['label'=>'Source Monitor','url'=>'/admin/source-monitor.php'],
                'data_attribution'=>['label'=>'Data Governance','url'=>'/admin/data-attribution.php'],
                'datasets'=>['label'=>'Dataset Registry','url'=>'/admin/datasets.php'],
                'discovery_entities'=>['label'=>'Discovery Entities','url'=>'/admin/discovery-entities.php'],
            ],
        ],
        'operations'=>[
            'label'=>'Trust & Operations',
            'items'=>[
                'moderation'=>['label'=>'Moderation & Rights','url'=>'/admin/moderation.php'],
                'system_health'=>['label'=>'System Health','url'=>'/admin/system-health.php'],
                'release_audit'=>['label'=>'Release Audit','url'=>'/admin/intelligence-release-audit.php'],
                'platform_governance'=>['label'=>'Platform Governance','url'=>'/admin/platform-governance.php'],
            ],
        ],
    ];
}
function admin_ui_agent_copilot(PDO $pdo,array $admin,string $active): string {
    if($active==='assistant'||!function_exists('admin_agent_page_context'))return '';
    $requestUrl=(string)($_SERVER['REQUEST_URI']??'/admin/');$context=admin_agent_page_context_from_url($pdo,$admin,$requestUrl);
    $encoded=h(json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $label=h((string)($context['label']??'Annotated Admin'));$meta=h((string)($context['meta']??'Current Admin page'));
    return '<section class="adminCopilot" data-admin-copilot data-api="/api/admin-agent.php" data-csrf="'.h(csrf_token()).'" data-context="'.$encoded.'">'
      .'<div class="adminCopilotPanel" data-admin-copilot-panel hidden><div class="adminCopilotPanelHead"><div><span class="eyebrow">ADMIN V2.90 · PROACTIVE COPILOT</span><strong>Admin Agent</strong></div><div class="adminCopilotPanelActions"><a href="/admin/assistant.php" data-admin-copilot-full>Open full canvas</a><button type="button" data-admin-copilot-close aria-label="Close Admin Agent">×</button></div></div><div class="adminCopilotMessages" data-admin-copilot-messages role="log" aria-live="polite"></div></div>'
      .'<div class="adminCopilotDock"><div class="adminCopilotContext" data-admin-copilot-context><span><b>Context:</b> '.$label.'</span><small>'.$meta.'</small><button type="button" data-admin-copilot-clear-context aria-label="Clear current page context">×</button></div>'
      .'<form class="adminCopilotComposer" data-admin-copilot-form><button type="button" class="adminCopilotToggle" data-admin-copilot-toggle aria-label="Open Admin Agent">A</button><textarea rows="1" maxlength="5000" data-admin-copilot-input placeholder="Ask Admin Agent…" aria-label="Ask Admin Agent"></textarea><button type="submit" data-admin-copilot-send aria-label="Send to Admin Agent">↑</button></form>'
      .'<div class="adminCopilotHint"><span data-admin-copilot-status>Uses your current Admin page as context.</span><span>Enter to send · Shift+Enter for a new line</span></div></div></section><script src="/assets/js/admin-agent-copilot.js?v=2.90" defer></script>';
}
function admin_ui_sidebar(string $active='dashboard'): string {
    $profile=null;$viewer=null;global $pdo;if(isset($pdo)&&$pdo instanceof PDO){try{$viewer=current_user($pdo);if($viewer&&($viewer['role']??'')==='admin'&&function_exists('admin_access_ready')&&admin_access_ready($pdo))$profile=admin_access_profile($pdo,$viewer);}catch(Throwable $e){}}
    $out='<aside class="adminSidebar" aria-label="Admin navigation"><div class="adminSidebarHead"><a class="adminSidebarBrand" href="/admin/">Annotated <span>Admin</span></a><a class="adminSidebarSite" href="/home.php">Open site</a></div><nav class="adminSidebarNav">';
    foreach(admin_ui_nav_sections() as $section){
        $items=$section['items'];if($profile!==null)$items=array_filter($items,fn($item,$key)=>admin_ops_has_capability($profile,admin_access_nav_capability((string)$key)),ARRAY_FILTER_USE_BOTH);if(!$items)continue;
        $contains=array_key_exists($active,$items);
        $out.='<details class="adminNavGroup"'.($contains?' open':'').'><summary>'.h((string)$section['label']).'</summary><div class="adminNavGroupLinks">';
        foreach($items as $key=>$item){$isActive=$active===$key;$out.='<a class="adminNavLink'.($isActive?' active':'').'" href="'.h((string)$item['url']).'"'.($isActive?' aria-current="page"':'').'>'.h((string)$item['label']).'</a>';}
        $out.='</div></details>';
    }
    $roleLabel=$profile!==null?' · '.h((string)($profile['role_name']??$profile['role_key']??'')):'';
    $out.='</nav><div class="adminSidebarFoot"><span>Admin V2.90 · Proactive Admin Intelligence · Admin V2.80 · Contextual Admin Agent · Admin V2.70 · Admin Agent · Admin V2.61 · Final Admin Hardening · Admin V2.60 · Admin V2.50 · Admin V2.40 · Admin V2.30 · Admin V2.20 · Admin V2.10 · Admin V2.0 · Admin V1.30 · V1.40 · V1.50 · V1.60 · V1.70 · V1.80 · V1.90'.$roleLabel.'</span><a href="/logout.php">Sign out</a></div></aside>';
    if($viewer&&($viewer['role']??'')==='admin')$out.=admin_ui_agent_copilot($pdo,$viewer,$active);
    return $out;
}
function admin_ui_scalar(PDO $pdo,string $sql): int {
    try{return (int)($pdo->query($sql)->fetchColumn()?:0);}catch(Throwable $e){return 0;}
}
function admin_ui_dashboard_snapshot(PDO $pdo): array {
    $counts=[
        'users'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM users WHERE status<>'deleted'"),
        'accounts'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM accounts WHERE status<>'closed'"),
        'packages'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM subscription_packages WHERE status='active'"),
        'annotations'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM annotations WHERE status='published'"),
        'sources'=>admin_ui_scalar($pdo,'SELECT COUNT(*) FROM sources'),
        'open_claims'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM rights_claims WHERE status IN ('submitted','under_review','appealed','reopened')"),
        'open_reports'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM moderation_reports WHERE status IN ('open','under_review')"),
        'failed_ai_jobs'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM ai_jobs WHERE status='failed'"),
        'queued_ai_jobs'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM ai_jobs WHERE status='queued'"),
        'failed_source_jobs'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM source_monitor_jobs WHERE status='failed'"),
        'suspended_accounts'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM accounts WHERE status='suspended'"),
        'paused_subscriptions'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM accounts WHERE subscription_status='paused' AND status<>'closed'"),
        'past_due_subscriptions'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM accounts WHERE subscription_status='past_due' AND status<>'closed'"),
        'failed_stripe_webhooks'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM stripe_webhook_events WHERE status='failed'"),
        'over_capacity_accounts'=>function_exists('stripe_billing_over_capacity_account_ids')?count(stripe_billing_over_capacity_account_ids($pdo)):0,
        'open_dunning_cases'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM billing_dunning_cases WHERE status IN ('open','action_required','grace','suspended')"),
        'overage_failed_batches'=>admin_ui_scalar($pdo,"SELECT COUNT(*) FROM ai_overage_report_batches WHERE status='failed'"),
        'trials_ending'=>function_exists('billing_operations_ready')&&billing_operations_ready($pdo)?count(billing_operations_trial_accounts($pdo)):0,
    ];
    $usage=['used_tokens'=>0,'capped_accounts'=>0,'exhausted_accounts'=>0,'system_tokens'=>0,'admin_tokens'=>0];
    if(function_exists('ai_usage_ready')&&ai_usage_ready($pdo)){
        foreach(ai_usage_admin_accounts($pdo,500) as $row){
            $s=$row['usage'];$usage['used_tokens']+=(int)$s['used_tokens'];$usage['system_tokens']+=(int)$s['system_tokens'];$usage['admin_tokens']+=(int)$s['admin_tokens'];
            if($s['effective_allowance']!==null){$usage['capped_accounts']++;if((int)$s['remaining_tokens']<=0)$usage['exhausted_accounts']++;}
        }
    }
    $workers=[];$queues=[];$pending=[];
    if(function_exists('release_worker_health')){try{$workers=release_worker_health($pdo);}catch(Throwable $e){}}
    if(function_exists('release_queue_health')){try{$queues=release_queue_health($pdo);}catch(Throwable $e){}}
    try{$pending=installer_pending_migrations($pdo,dirname(__DIR__).'/database/migrations');}catch(Throwable $e){}
    $workerProblems=0;foreach($workers as $w)if(in_array((string)($w['status']??''),['failure','stale'],true))$workerProblems++;
    $attention=[];
    $add=function(string $label,int $count,string $url,string $severity='warn')use(&$attention){if($count>0)$attention[]=['label'=>$label,'count'=>$count,'url'=>$url,'severity'=>$severity];};
    $add('Accounts with exhausted AI allowance',(int)$usage['exhausted_accounts'],'/admin/usage.php','warn');
    $add('Suspended commercial accounts',(int)$counts['suspended_accounts'],'/admin/accounts.php?status=suspended','warn');
    $add('Paused subscriptions',(int)$counts['paused_subscriptions'],'/admin/accounts.php?subscription_status=paused','warn');
    $add('Past-due Stripe subscriptions',(int)$counts['past_due_subscriptions'],'/admin/accounts.php?subscription_status=past_due','warn');
    $add('Failed Stripe webhook events',(int)$counts['failed_stripe_webhooks'],'/admin/billing.php','danger');
    $add('Accounts over seat capacity',(int)$counts['over_capacity_accounts'],'/admin/accounts.php','warn'); // V1.40 contract lineage: Accounts over effective member limit
    $add('Open billing dunning cases',(int)$counts['open_dunning_cases'],'/admin/billing-analytics.php','danger');
    $add('Failed AI overage reporting batches',(int)$counts['overage_failed_batches'],'/admin/overage-billing.php','danger');
    $add('Trials ending soon',(int)$counts['trials_ending'],'/admin/billing-analytics.php','warn');
    $add('Failed AI jobs',(int)$counts['failed_ai_jobs'],'/admin/system-health.php','danger');
    $add('Failed source-monitor jobs',(int)$counts['failed_source_jobs'],'/admin/source-monitor.php','danger');
    $add('Open moderation reports',(int)$counts['open_reports'],'/admin/moderation.php','warn');
    $add('Open rights claims',(int)$counts['open_claims'],'/admin/moderation.php','warn');
    $add('Workers stale or failing',$workerProblems,'/admin/system-health.php','danger');
    $add('Pending database migrations',count($pending),'/upgrade.php','danger');
    return ['counts'=>$counts,'usage'=>$usage,'workers'=>$workers,'queues'=>$queues,'pending_migrations'=>$pending,'worker_problems'=>$workerProblems,'attention'=>$attention];
}
function admin_ui_account_rows(PDO $pdo,array $filters=[],int $limit=250): array {
    if(!subscriptions_ready($pdo))return [];$limit=max(1,min(500,$limit));
    $where=[];$params=[];$q=trim((string)($filters['q']??''));$status=trim((string)($filters['status']??''));$subscription=trim((string)($filters['subscription_status']??''));$package=trim((string)($filters['package']??''));
    if($q!==''){$where[]='(a.name LIKE ? OR a.public_id LIKE ? OR u.username LIKE ? OR u.display_name LIKE ? OR u.email LIKE ?)';$needle='%'.$q.'%';array_push($params,$needle,$needle,$needle,$needle,$needle);}
    if(in_array($status,['active','suspended','closed'],true)){$where[]='a.status=?';$params[]=$status;}
    if(in_array($subscription,['trialing','active','past_due','paused','canceled'],true)){$where[]='a.subscription_status=?';$params[]=$subscription;}
    if($package!==''){$where[]='p.public_id=?';$params[]=$package;}
    $sql="SELECT a.*,u.username,u.display_name,u.email,owner.username owner_username,owner.display_name owner_display_name,owner.email owner_email,p.public_id package_public_id,p.slug package_slug,p.name package_name,p.monthly_ai_token_allowance,p.member_limit,
      (SELECT COUNT(*) FROM account_members am WHERE am.account_id=a.id) member_count
      FROM accounts a JOIN subscription_packages p ON p.id=a.package_id LEFT JOIN users u ON u.id=a.personal_user_id LEFT JOIN users owner ON owner.id=a.owner_user_id";
    if($where)$sql.=' WHERE '.implode(' AND ',$where);$sql.=' ORDER BY a.updated_at DESC,a.id DESC LIMIT '.$limit;
    $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll()?:[];
    foreach($rows as &$row){
        $row['effective_member_limit']=(int)$row['member_limit'];$row['override_count']=0;
        if(function_exists('account_admin_ready')&&account_admin_ready($pdo)){try{$ent=account_admin_effective_entitlements($pdo,(int)$row['id']);$row['effective_member_limit']=(int)$ent['values']['member_limit'];$row['override_count']=count($ent['overrides']);}catch(Throwable $e){}}
        $row['pending_reserved']=0;$row['seat_used']=(int)$row['member_count'];$row['over_capacity']=(int)$row['member_count']>(int)$row['effective_member_limit'];$row['over_reserved']=false;
        if(function_exists('account_membership_ready')&&account_membership_ready($pdo)){try{$seat=account_membership_seat_summary($pdo,(int)$row['id']);$row['pending_reserved']=(int)$seat['pending_reserved'];$row['seat_used']=(int)$seat['used_seats'];$row['over_capacity']=!empty($seat['over_capacity']);$row['over_reserved']=!empty($seat['over_reserved']);}catch(Throwable $e){}}
        if(function_exists('ai_usage_ready')&&ai_usage_ready($pdo))$row['usage']=ai_usage_account_summary($pdo,(int)$row['id']);
    }unset($row);
    return $rows;
}
