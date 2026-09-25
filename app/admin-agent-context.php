<?php
declare(strict_types=1);

function admin_agent_page_context_capability(string $path): string {
    return match($path){
        '/admin/account.php','/admin/accounts.php','/admin/users.php','/admin/packages.php'=>'admin.accounts.view',
        '/admin/billing.php','/admin/billing-analytics.php','/admin/overage-billing.php','/admin/promotions.php','/admin/tax-invoices.php'=>'admin.billing.view',
        '/admin/financial-reporting.php'=>'admin.finance.view',
        '/admin/support.php','/admin/support-case.php'=>'admin.support.view',
        '/admin/customer-success.php','/admin/customer-success-account.php'=>'admin.customer_success.view',
        '/admin/security-compliance.php'=>'admin.security.view',
        '/admin/platform-governance.php'=>'admin.platform.view',
        '/admin/usage.php'=>'admin.ai_usage.view',
        '/admin/ai.php','/admin/evaluations.php','/admin/model-registry.php','/admin/training.php','/admin/post-training.php','/admin/model-release.php','/admin/model-deployment.php','/admin/model-observability.php','/admin/model-improvements.php','/admin/model-campaigns.php'=>'admin.models.view',
        '/admin/source-monitor.php','/admin/data-attribution.php','/admin/datasets.php','/admin/discovery-entities.php'=>'admin.research_data.view',
        '/admin/moderation.php','/admin/system-health.php','/admin/intelligence-release-audit.php'=>'admin.trust.view',
        '/admin/action-center.php'=>'admin.actions.view','/admin/roles-permissions.php'=>'admin.roles.view',
        default=>'admin.operations.view',
    };
}
function admin_agent_page_context_surface(string $path): string {
    return match($path){
        '/admin/','/admin/index.php'=>'Command Center',
        '/admin/account.php'=>'Account 360','/admin/accounts.php'=>'Accounts','/admin/users.php'=>'Users','/admin/packages.php'=>'Packages',
        '/admin/billing.php'=>'Billing & Stripe','/admin/billing-analytics.php'=>'Billing Analytics','/admin/overage-billing.php'=>'AI Overage Billing','/admin/promotions.php'=>'Promotions & Credits','/admin/tax-invoices.php'=>'Tax & Invoices',
        '/admin/financial-reporting.php'=>'Financial Reporting','/admin/support.php'=>'Support Operations','/admin/support-case.php'=>'Support Case',
        '/admin/customer-success.php'=>'Customer Success','/admin/customer-success-account.php'=>'Customer Success Account',
        '/admin/security-compliance.php'=>'Security & Compliance','/admin/platform-governance.php'=>'Platform Governance','/admin/usage.php'=>'AI Usage',
        '/admin/ai.php'=>'Providers & Routing','/admin/evaluations.php'=>'Evaluation Harness','/admin/model-registry.php'=>'Model Registry',
        '/admin/training.php'=>'Training Registry','/admin/post-training.php'=>'Post-Training','/admin/model-release.php'=>'Release Decisions',
        '/admin/model-deployment.php'=>'Deployments','/admin/model-observability.php'=>'Model Health','/admin/model-improvements.php'=>'Improvement Loop',
        '/admin/model-campaigns.php'=>'Improvement Campaigns','/admin/source-monitor.php'=>'Source Monitor','/admin/data-attribution.php'=>'Data Governance',
        '/admin/datasets.php'=>'Dataset Registry','/admin/discovery-entities.php'=>'Discovery Entities','/admin/moderation.php'=>'Moderation & Rights',
        '/admin/system-health.php'=>'System Health','/admin/intelligence-release-audit.php'=>'Release Audit','/admin/action-center.php'=>'Action Center',
        '/admin/roles-permissions.php'=>'Roles & Permissions','/admin/assistant.php'=>'Admin Agent',
        default=>'Annotated Admin',
    };
}
function admin_agent_page_context_normalize_path(string $path): string {
    $path=(string)(parse_url($path,PHP_URL_PATH)?:$path);$path='/'.ltrim($path,'/');return str_starts_with($path,'/admin/')||$path==='/admin'?$path:'/admin/';
}
function admin_agent_page_context(PDO $pdo,array $admin,string $path,array $query=[]): ?array {
    $path=admin_agent_page_context_normalize_path($path);if($path==='/admin')$path='/admin/';
    $cap=admin_agent_page_context_capability($path);
    if(function_exists('admin_access_ready')&&admin_access_ready($pdo)&&!admin_access_has_capability($pdo,$admin,$cap))return null;
    $surface=admin_agent_page_context_surface($path);$url=$path;$q=[];foreach($query as $k=>$v){if(!is_scalar($v))continue;$key=preg_replace('/[^a-z0-9_-]/i','',(string)$k);if($key==='')continue;$value=mb_substr(trim((string)$v),0,255);if($value!=='')$q[$key]=$value;}
    if($q)$url.='?'.http_build_query($q);
    $ctx=['type'=>'surface','surface'=>$surface,'label'=>$surface,'meta'=>'Current Admin page','path'=>$path,'query'=>$q,'url'=>$url,'capability'=>$cap,'text'=>"Current Admin surface: {$surface}."];
    if($path==='/admin/account.php'&&!empty($q['id'])&&function_exists('account_admin_get')){
        $account=account_admin_get($pdo,(string)$q['id']);if($account){
            $ctx=['type'=>'account','surface'=>'Account 360','label'=>(string)$account['name'],'meta'=>(string)$account['package_name'].' · '.ucwords(str_replace('_',' ',(string)$account['status'])).' · '.ucwords(str_replace('_',' ',(string)$account['subscription_status'])),'path'=>$path,'query'=>['id'=>(string)$account['public_id']],'url'=>'/admin/account.php?id='.rawurlencode((string)$account['public_id']),'capability'=>$cap,'public_id'=>(string)$account['public_id'],'account_public_id'=>(string)$account['public_id'],'text'=>"[CURRENT ADMIN PAGE — ACCOUNT]\nAccount: ".(string)$account['name']."\nPublic ID: ".(string)$account['public_id']."\nPackage: ".(string)$account['package_name']."\nAccount status: ".(string)$account['status']."\nSubscription status: ".(string)$account['subscription_status']."\nBilling source: ".(string)($account['billing_source']??'manual')];
        }
    }elseif($path==='/admin/support-case.php'&&!empty($q['id'])&&function_exists('admin_support_case')){
        $case=admin_support_case($pdo,(string)$q['id']);if($case){
            $ctx=['type'=>'support_case','surface'=>'Support Case','label'=>(string)$case['title'],'meta'=>strtoupper((string)$case['priority']).' · '.ucwords(str_replace('_',' ',(string)$case['status'])),'path'=>$path,'query'=>['id'=>(string)$case['public_id']],'url'=>'/admin/support-case.php?id='.rawurlencode((string)$case['public_id']),'capability'=>$cap,'public_id'=>(string)$case['public_id'],'account_public_id'=>(string)($case['account_public_id']??''),'text'=>"[CURRENT ADMIN PAGE — SUPPORT CASE]\nCase: ".(string)$case['title']."\nPublic ID: ".(string)$case['public_id']."\nPriority: ".(string)$case['priority']."\nStatus: ".(string)$case['status']."\nAccount: ".(string)($case['account_name']??'Unlinked')."\nAccount public ID: ".(string)($case['account_public_id']??'')."\nAssigned operator: ".(string)($case['assigned_username']??'Unassigned')."\nSLA due: ".(string)($case['sla_due_at']??'')];
        }
    }elseif($path==='/admin/customer-success-account.php'&&!empty($q['id'])&&function_exists('account_admin_get')){
        $account=account_admin_get($pdo,(string)$q['id']);if($account){
            $health=null;if(function_exists('admin_customer_success_calculate_health'))try{$health=admin_customer_success_calculate_health($pdo,(int)$account['id']);}catch(Throwable $e){}
            $state=(string)($health['health_state']??'unknown');$ctx=['type'=>'customer_success_account','surface'=>'Customer Success Account','label'=>(string)$account['name'],'meta'=>'Health: '.ucfirst($state).' · '.(string)$account['package_name'],'path'=>$path,'query'=>['id'=>(string)$account['public_id']],'url'=>'/admin/customer-success-account.php?id='.rawurlencode((string)$account['public_id']),'capability'=>$cap,'public_id'=>(string)$account['public_id'],'account_public_id'=>(string)$account['public_id'],'text'=>"[CURRENT ADMIN PAGE — CUSTOMER SUCCESS]\nAccount: ".(string)$account['name']."\nPublic ID: ".(string)$account['public_id']."\nHealth state: {$state}\nPackage: ".(string)$account['package_name'];
            if($health&&isset($health['score']))$ctx['text'].="\nHealth score: ".(string)$health['score'];
        }
    }elseif($path==='/admin/action-center.php'&&!empty($q['record'])&&function_exists('admin_ops_action_record')){
        $record=admin_ops_action_record($pdo,(string)$q['record']);if($record){
            $ctx=['type'=>'admin_action','surface'=>'Action Center','label'=>ucwords(str_replace('_',' ',(string)$record['action_type'])),'meta'=>strtoupper((string)$record['risk_level']).' · '.ucwords(str_replace('_',' ',(string)$record['status'])),'path'=>$path,'query'=>['record'=>(string)$record['public_id']],'url'=>'/admin/action-center.php?record='.rawurlencode((string)$record['public_id']),'capability'=>$cap,'public_id'=>(string)$record['public_id'],'account_public_id'=>(string)($record['account_public_id']??''),'text'=>"[CURRENT ADMIN PAGE — GOVERNED ACTION]\nAction: ".(string)$record['action_type']."\nPublic ID: ".(string)$record['public_id']."\nStatus: ".(string)$record['status']."\nRisk: ".(string)$record['risk_level']."\nAccount: ".(string)($record['account_name']??'')];
        }
    }elseif(in_array($path,['/admin/accounts.php','/admin/users.php','/admin/support.php'],true)&&!empty($q['q'])){
        $ctx['meta']='Search: '.mb_substr((string)$q['q'],0,80);$ctx['text'].="\nCurrent list search: ".mb_substr((string)$q['q'],0,120);
    }
    return $ctx;
}
function admin_agent_page_context_from_url(PDO $pdo,array $admin,string $url): ?array {
    $url=trim($url);if($url==='')return null;$path=(string)(parse_url($url,PHP_URL_PATH)?:'');parse_str((string)(parse_url($url,PHP_URL_QUERY)?:''),$query);return admin_agent_page_context($pdo,$admin,$path,is_array($query)?$query:[]);
}
