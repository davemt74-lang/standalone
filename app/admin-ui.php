<?php
declare(strict_types=1);

function admin_ui_nav_sections(): array {
    return [
        'overview'=>[
            'label'=>'Overview',
            'items'=>[
                'dashboard'=>['label'=>'Dashboard','url'=>'/admin/'],
                'assistant'=>['label'=>'Admin Assistant','url'=>'/admin/assistant.php'],
            ],
        ],
        'accounts'=>[
            'label'=>'Accounts & Billing',
            'items'=>[
                'accounts'=>['label'=>'Accounts','url'=>'/admin/accounts.php'],
                'users'=>['label'=>'Users','url'=>'/admin/users.php'],
                'packages'=>['label'=>'Packages','url'=>'/admin/packages.php'],
                'billing'=>['label'=>'Billing & Stripe','url'=>'/admin/billing.php'],
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
            ],
        ],
    ];
}
function admin_ui_sidebar(string $active='dashboard'): string {
    $out='<aside class="adminSidebar" aria-label="Admin navigation"><div class="adminSidebarHead"><a class="adminSidebarBrand" href="/admin/">Annotated <span>Admin</span></a><a class="adminSidebarSite" href="/home.php">Open site</a></div><nav class="adminSidebarNav">';
    foreach(admin_ui_nav_sections() as $section){
        $contains=array_key_exists($active,$section['items']);
        $out.='<details class="adminNavGroup"'.($contains?' open':'').'><summary>'.h((string)$section['label']).'</summary><div class="adminNavGroupLinks">';
        foreach($section['items'] as $key=>$item){
            $out.='<a class="adminNavLink'.($active===$key?' active':'').'" href="'.h((string)$item['url']).'">'.h((string)$item['label']).'</a>';
        }
        $out.='</div></details>';
    }
    return $out.'</nav><div class="adminSidebarFoot"><span>Admin V1.40</span><a href="/logout.php">Sign out</a></div></aside>';
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
        if(function_exists('ai_usage_ready')&&ai_usage_ready($pdo))$row['usage']=ai_usage_account_summary($pdo,(int)$row['id']);
    }unset($row);
    return $rows;
}
