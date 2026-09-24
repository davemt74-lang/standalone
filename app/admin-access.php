<?php
declare(strict_types=1);

function admin_access_ready(PDO $pdo): bool {
    try{
        foreach(['admin_roles','admin_operator_profiles','admin_approval_policies','admin_action_approvals','admin_security_audit_events'] as $table)if(!installer_table_exists($pdo,$table))return false;
        return true;
    }catch(Throwable $e){return false;}
}
function admin_access_capability_registry(): array {
    return [
        'admin.operations.view'=>['group'=>'Operations','label'=>'View Command Center','description'=>'View operational alerts, health summaries and Admin overview evidence.'],
        'admin.operations.manage'=>['group'=>'Operations','label'=>'Manage operational queue','description'=>'Assign, snooze, resolve and manage Command Center operational state.'],
        'admin.support.view'=>['group'=>'Support','label'=>'View Support Operations','description'=>'View support inbox, cases, customer 360, timelines, links and support analytics.'],
        'admin.support.manage'=>['group'=>'Support','label'=>'Manage Support Operations','description'=>'Create, assign, prioritize, communicate, escalate, resolve, reopen, link and deduplicate support cases.'],
        'admin.accounts.view'=>['group'=>'Accounts','label'=>'View accounts & users','description'=>'View accounts, users, packages, membership and Account 360 evidence.'],
        'admin.accounts.manage'=>['group'=>'Accounts','label'=>'Manage accounts & users','description'=>'Change account lifecycle, membership, entitlements, packages and user-account state on existing Admin surfaces.'],
        'admin.billing.view'=>['group'=>'Billing','label'=>'View billing','description'=>'View Stripe, revenue, dunning, promotions, credits, tax and invoice evidence.'],
        'admin.billing.manage'=>['group'=>'Billing','label'=>'Manage billing','description'=>'Use existing explicit billing, dunning, promotion, credit, tax and invoice mutation surfaces.'],
        'admin.billing.sync'=>['group'=>'Billing','label'=>'Resync Stripe subscription','description'=>'Request/execute governed Stripe subscription resynchronization.'],
        'admin.billing.overage'=>['group'=>'Billing','label'=>'Reconcile AI overage','description'=>'Request/execute governed AI overage reconciliation.'],
        'admin.billing.tax'=>['group'=>'Billing','label'=>'Sync tax policy','description'=>'Request/execute governed billing-profile and tax-policy synchronization.'],
        'admin.finance.view'=>['group'=>'Finance','label'=>'View financial reporting','description'=>'View finance dashboard, account ledger, receivables, reconciliation exceptions and immutable close evidence.'],
        'admin.finance.manage'=>['group'=>'Finance','label'=>'Manage reconciliation & close','description'=>'Run reconciliation scans, assign/resolve exceptions and create immutable period-close snapshots.'],
        'admin.finance.export'=>['group'=>'Finance','label'=>'Export financial evidence','description'=>'Create audited CSV exports from finance ledgers, receivables, exceptions and close records.'],
        'admin.customer_success.view'=>['group'=>'Customer Success','label'=>'View Customer Success','description'=>'View account health, onboarding, retention risks, opportunities, plans and follow-up evidence.'],
        'admin.customer_success.manage'=>['group'=>'Customer Success','label'=>'Manage Customer Success','description'=>'Refresh explainable health snapshots, assign account owners, and manage success plans, milestones and follow-ups.'],
        'admin.security.view'=>['group'=>'Security & Compliance','label'=>'View Security & Audit Center','description'=>'View normalized administrative audit evidence, security posture, cases and permission review.'],
        'admin.security.manage'=>['group'=>'Security & Compliance','label'=>'Manage Security cases','description'=>'Create, assign, investigate and resolve Security & Compliance cases.'],
        'admin.security.export'=>['group'=>'Security & Compliance','label'=>'Export audit evidence','description'=>'Create audited CSV or JSON exports of permission-scoped administrative audit evidence.'],
        'admin.privacy.view'=>['group'=>'Security & Compliance','label'=>'View privacy workflows','description'=>'View privacy, data-access, export, deletion, restriction and retention request state.'],
        'admin.privacy.manage'=>['group'=>'Security & Compliance','label'=>'Manage privacy workflows','description'=>'Create, assign and complete governed privacy and compliance requests.'],
        'admin.platform.view'=>['group'=>'Platform Governance','label'=>'View platform governance','description'=>'View platform configuration summary, feature rollouts, modules, integrations, drift and release readiness.'],
        'admin.platform.manage'=>['group'=>'Platform Governance','label'=>'Manage platform governance','description'=>'Manage governed feature rollout requests and module/integration governance metadata.'],
        'admin.platform.release'=>['group'=>'Platform Governance','label'=>'Capture release readiness','description'=>'Capture immutable release-readiness and configuration drift baselines without executing deployments.'],
        'admin.ai_usage.view'=>['group'=>'AI Usage','label'=>'View AI usage','description'=>'View AI token accounting, allowance and overage evidence.'],
        'admin.ai_usage.manage'=>['group'=>'AI Usage','label'=>'Manage AI usage','description'=>'Use Admin AI usage adjustment and allowance controls.'],
        'admin.models.view'=>['group'=>'AI & Models','label'=>'View AI & model operations','description'=>'View providers, evaluations, registry, training, release, deployment and observability.'],
        'admin.models.manage'=>['group'=>'AI & Models','label'=>'Manage AI & model operations','description'=>'Mutate providers, models, evaluations, training and governed model lifecycle state.'],
        'admin.research_data.view'=>['group'=>'Research & Data','label'=>'View research & data administration','description'=>'View source monitoring, governance, datasets and discovery entities.'],
        'admin.research_data.manage'=>['group'=>'Research & Data','label'=>'Manage research & data administration','description'=>'Mutate sources, governed data, datasets and discovery entities.'],
        'admin.trust.view'=>['group'=>'Trust & Operations','label'=>'View trust & release operations','description'=>'View moderation, rights, system health and release audit surfaces.'],
        'admin.trust.manage'=>['group'=>'Trust & Operations','label'=>'Manage trust & release operations','description'=>'Mutate moderation, rights and operational recovery state.'],
        'admin.actions.view'=>['group'=>'Governed Actions','label'=>'View Action Center','description'=>'View governed action previews, approvals, results and correlations.'],
        'admin.actions.request'=>['group'=>'Governed Actions','label'=>'Request governed actions','description'=>'Create governed action previews and submit approval requests.'],
        'admin.actions.approve'=>['group'=>'Governed Actions','label'=>'Approve governed actions','description'=>'Approve or reject actions when an approval policy requires a distinct reviewer.'],
        'admin.actions.execute'=>['group'=>'Governed Actions','label'=>'Execute governed actions','description'=>'Execute the requester’s governed action after policy approval requirements are satisfied.'],
        'admin.roles.view'=>['group'=>'Security','label'=>'View roles & approval policies','description'=>'View operator assignments, role capabilities, approval policies and security audit.'],
        'admin.roles.manage'=>['group'=>'Security','label'=>'Manage roles & approval policies','description'=>'Protected super-admin capability for role assignment and approval-policy administration.'],
    ];
}
function admin_access_security_audit(PDO $pdo,array $actor,string $subjectType,?string $subjectPublicId,string $eventType,mixed $before,mixed $after,string $reason): void {
    if(!admin_access_ready($pdo))return;if(function_exists('admin_security_ready')&&admin_security_ready($pdo)){admin_security_audit_record($pdo,$actor,['subject_type'=>$subjectType,'subject_public_id'=>$subjectPublicId,'event_type'=>$eventType,'source_domain'=>'security','sensitivity'=>'restricted','before'=>$before,'after'=>$after,'reason'=>$reason]);return;}admin_ops_require_admin($actor);$encode=fn(mixed $v)=>$v===null?null:json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $pdo->prepare('INSERT INTO admin_security_audit_events(public_id,actor_user_id,subject_type,subject_public_id,event_type,before_json,after_json,reason) VALUES(?,?,?,?,?,?,?,?)')->execute([ulid_like(),(int)$actor['id'],$subjectType,$subjectPublicId,mb_substr($eventType,0,100),$encode($before),$encode($after),admin_ops_reason($reason)]);
}
function admin_access_role_caps(array $role): array {
    $raw=$role['capabilities_json']??[];if(is_array($raw))return array_values(array_filter($raw,'is_string'));
    try{$v=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);return is_array($v)?array_values(array_filter($v,'is_string')):[];}catch(Throwable $e){return [];}
}
function admin_access_roles(PDO $pdo,bool $activeOnly=false): array {
    if(!admin_access_ready($pdo))return [];$sql='SELECT r.*,(SELECT COUNT(*) FROM admin_operator_profiles p WHERE p.role_id=r.id AND p.status=\'active\') assigned_count FROM admin_roles r';if($activeOnly)$sql.=" WHERE r.status='active'";$sql.=' ORDER BY r.is_system DESC,r.name,r.id';return $pdo->query($sql)->fetchAll()?:[];
}
function admin_access_role(PDO $pdo,string|int $identifier): ?array {
    if(!admin_access_ready($pdo))return null;if(is_int($identifier)||ctype_digit((string)$identifier)){$q=$pdo->prepare('SELECT * FROM admin_roles WHERE id=? LIMIT 1');$q->execute([(int)$identifier]);}else{$q=$pdo->prepare('SELECT * FROM admin_roles WHERE public_id=? OR role_key=? LIMIT 1');$q->execute([(string)$identifier,(string)$identifier]);}return $q->fetch()?:null;
}
function admin_access_effective_capabilities(array $profile): array {
    $caps=[];$append=function(array $list)use(&$caps){foreach($list as $cap)if(is_string($cap)&&$cap!==''&&!in_array($cap,$caps,true))$caps[]=$cap;};
    if(isset($profile['role_capabilities_json'])){$raw=$profile['role_capabilities_json'];try{$v=is_array($raw)?$raw:json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);if(is_array($v))$append($v);}catch(Throwable $e){}}
    $raw=$profile['profile_capabilities_json']??($profile['capabilities_json']??[]);try{$v=is_array($raw)?$raw:json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);if(is_array($v))$append($v);}catch(Throwable $e){}
    return $caps;
}
function admin_access_profile(PDO $pdo,array $admin): array {
    admin_ops_require_admin($admin);$fallback=['user_id'=>(int)$admin['id'],'role_id'=>null,'role_key'=>'super_admin','role_name'=>'Super Admin','status'=>'active','capabilities_json'=>'["admin.*"]','effective_capabilities'=>['admin.*']];
    if(!admin_access_ready($pdo))return $fallback;
    $q=$pdo->prepare("SELECT p.*,r.role_key resolved_role_key,r.name role_name,r.capabilities_json role_capabilities_json,r.status role_status,p.capabilities_json profile_capabilities_json FROM admin_operator_profiles p LEFT JOIN admin_roles r ON r.id=p.role_id WHERE p.user_id=? LIMIT 1");$q->execute([(int)$admin['id']]);$row=$q->fetch();
    if(!$row){
        $role=admin_access_role($pdo,'super_admin')??throw new RuntimeException('Super Admin role is unavailable.');
        $pdo->prepare("INSERT INTO admin_operator_profiles(user_id,role_id,role_key,capabilities_json,status,assigned_at) VALUES(?,?,?,'[]','active',NOW())")->execute([(int)$admin['id'],(int)$role['id'],'super_admin']);$q->execute([(int)$admin['id']]);$row=$q->fetch();
    }elseif(empty($row['role_id'])){
        $role=admin_access_role($pdo,(string)($row['role_key']??'super_admin'))?:admin_access_role($pdo,'super_admin');if($role){$pdo->prepare('UPDATE admin_operator_profiles SET role_id=?,role_key=?,assigned_at=COALESCE(assigned_at,NOW()) WHERE user_id=?')->execute([(int)$role['id'],(string)$role['role_key'],(int)$admin['id']]);$q->execute([(int)$admin['id']]);$row=$q->fetch();}
    }
    if(!$row)return $fallback;$row['role_key']=(string)($row['resolved_role_key']?:$row['role_key']?:'super_admin');$row['role_name']=(string)($row['role_name']?:ucwords(str_replace('_',' ',$row['role_key'])));$caps=admin_access_effective_capabilities($row);$row['effective_capabilities']=$caps;$row['capabilities_json']=json_encode($caps,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);return $row;
}
function admin_access_has_capability(PDO $pdo,array $admin,string $capability): bool {
    if(($admin['role']??'')!=='admin')return false;if(!admin_access_ready($pdo))return true;$profile=admin_access_profile($pdo,$admin);if(($profile['status']??'disabled')!=='active'||($profile['role_status']??'active')!=='active')return false;
    foreach($profile['effective_capabilities']??[] as $cap){if($cap==='admin.*'||$cap===$capability)return true;if(str_ends_with($cap,'.*')&&str_starts_with($capability,substr($cap,0,-1)))return true;}return false;
}
function admin_access_assert_capability(PDO $pdo,array $admin,string $capability): void {
    if(!admin_access_has_capability($pdo,$admin,$capability))throw new RuntimeException('Your Admin operator role does not permit this action.');
}
function admin_access_is_super_admin(PDO $pdo,array $admin): bool {
    if(($admin['role']??'')!=='admin')return false;if(!admin_access_ready($pdo))return true;$p=admin_access_profile($pdo,$admin);return ($p['role_key']??'')==='super_admin'&&($p['status']??'disabled')==='active';
}
function admin_access_require_super_admin(PDO $pdo,array $admin): void {
    if(!admin_access_is_super_admin($pdo,$admin))throw new RuntimeException('Super Admin authority is required.');
}
function admin_access_route_requirement(string $path,string $method='GET'): ?string {
    $path='/'.ltrim((string)(parse_url($path,PHP_URL_PATH)?:$path),'/');$method=strtoupper($method);$write=$method!=='GET'&&$method!=='HEAD';
    $map=[
        '/admin/'=>['admin.operations.view','admin.operations.manage'],'/admin/index.php'=>['admin.operations.view','admin.operations.manage'],'/admin/assistant.php'=>['admin.operations.view','admin.operations.view'],'/admin/action-center.php'=>['admin.actions.view','admin.actions.view'],'/admin/roles-permissions.php'=>['admin.roles.view','admin.roles.view'],'/admin/support.php'=>['admin.support.view','admin.support.manage'],'/admin/support-case.php'=>['admin.support.view','admin.support.manage'],
        '/admin/accounts.php'=>['admin.accounts.view','admin.accounts.manage'],'/admin/account.php'=>['admin.accounts.view','admin.accounts.manage'],'/admin/users.php'=>['admin.accounts.view','admin.accounts.manage'],'/admin/packages.php'=>['admin.accounts.view','admin.accounts.manage'],
        '/admin/billing.php'=>['admin.billing.view','admin.billing.manage'],'/admin/billing-analytics.php'=>['admin.billing.view','admin.billing.manage'],'/admin/overage-billing.php'=>['admin.billing.view','admin.billing.manage'],'/admin/promotions.php'=>['admin.billing.view','admin.billing.manage'],'/admin/tax-invoices.php'=>['admin.billing.view','admin.billing.manage'],
        '/admin/financial-reporting.php'=>['admin.finance.view','admin.finance.manage'],'/admin/financial-export.php'=>['admin.finance.export','admin.finance.export'],
        '/admin/customer-success.php'=>['admin.customer_success.view','admin.customer_success.manage'],'/admin/customer-success-account.php'=>['admin.customer_success.view','admin.customer_success.manage'],
        '/admin/security-compliance.php'=>['admin.security.view','admin.security.manage'],'/admin/security-export.php'=>['admin.security.export','admin.security.export'],
        '/admin/platform-governance.php'=>['admin.platform.view','admin.platform.manage'],
        '/admin/usage.php'=>['admin.ai_usage.view','admin.ai_usage.manage'],
        '/admin/ai.php'=>['admin.models.view','admin.models.manage'],'/admin/evaluations.php'=>['admin.models.view','admin.models.manage'],'/admin/model-registry.php'=>['admin.models.view','admin.models.manage'],'/admin/training.php'=>['admin.models.view','admin.models.manage'],'/admin/post-training.php'=>['admin.models.view','admin.models.manage'],'/admin/model-release.php'=>['admin.models.view','admin.models.manage'],'/admin/model-deployment.php'=>['admin.models.view','admin.models.manage'],'/admin/model-observability.php'=>['admin.models.view','admin.models.manage'],'/admin/model-improvements.php'=>['admin.models.view','admin.models.manage'],'/admin/model-campaigns.php'=>['admin.models.view','admin.models.manage'],
        '/admin/source-monitor.php'=>['admin.research_data.view','admin.research_data.manage'],'/admin/data-attribution.php'=>['admin.research_data.view','admin.research_data.manage'],'/admin/datasets.php'=>['admin.research_data.view','admin.research_data.manage'],'/admin/discovery-entities.php'=>['admin.research_data.view','admin.research_data.manage'],
        '/admin/moderation.php'=>['admin.trust.view','admin.trust.manage'],'/admin/system-health.php'=>['admin.trust.view','admin.trust.manage'],'/admin/intelligence-release-audit.php'=>['admin.trust.view','admin.trust.manage'],
    ];if(isset($map[$path]))return $map[$path][$write?1:0];if(str_starts_with($path,'/admin/'))return 'admin.*';return null;
}
function admin_access_authorize_request(PDO $pdo,array $admin): void {
    if(PHP_SAPI==='cli'||!admin_access_ready($pdo))return;$path=(string)($_SERVER['REQUEST_URI']??'');$cap=admin_access_route_requirement($path,(string)($_SERVER['REQUEST_METHOD']??'GET'));if($cap===null)return;if(!admin_access_has_capability($pdo,$admin,$cap)){http_response_code(403);exit('Your Admin operator role does not permit this surface or operation.');}
}
function admin_access_nav_capability(string $key): string {
    return match($key){
        'dashboard','assistant'=>'admin.operations.view','action_center'=>'admin.actions.view','roles_permissions'=>'admin.roles.view','support'=>'admin.support.view','support_case'=>'admin.support.view',
        'accounts','users','packages'=>'admin.accounts.view',
        'billing','billing_analytics','overage_billing','promotions','tax_invoices'=>'admin.billing.view',
        'financial_reporting'=>'admin.finance.view',
        'customer_success','customer_success_account'=>'admin.customer_success.view','security_compliance'=>'admin.security.view','platform_governance'=>'admin.platform.view',
        'usage'=>'admin.ai_usage.view',
        'ai','evaluations','model_registry','training','post_training','model_release','model_deployment','model_observability','model_improvements','model_campaigns'=>'admin.models.view',
        'source_monitor','data_attribution','datasets','discovery_entities'=>'admin.research_data.view',
        'moderation','system_health','release_audit'=>'admin.trust.view',
        default=>'admin.operations.view',
    };
}
function admin_access_nav_allowed(PDO $pdo,array $admin,string $key): bool {return admin_access_has_capability($pdo,$admin,admin_access_nav_capability($key));}
function admin_access_clean_capabilities(array $caps): array {
    $registry=admin_access_capability_registry();$out=[];foreach($caps as $cap){$cap=trim((string)$cap);if($cap===''||!isset($registry[$cap]))continue;if($cap==='admin.roles.manage')continue;if(!in_array($cap,$out,true))$out[]=$cap;}sort($out,SORT_STRING);return $out;
}
function admin_access_role_save(PDO $pdo,array $admin,array $input): array {
    admin_access_require_super_admin($pdo,$admin);$public=trim((string)($input['public_id']??''));$existing=$public!==''?admin_access_role($pdo,$public):null;if($existing&&!empty($existing['is_system']))throw new RuntimeException('System Admin roles are immutable.');
    $name=trim((string)($input['name']??''));if($name===''||mb_strlen($name)>120)throw new InvalidArgumentException('Role name is required and must be 120 characters or fewer.');$key=strtolower(trim((string)($input['role_key']??'')));$key=preg_replace('/[^a-z0-9_]+/','_',$key)?:'';$key=trim($key,'_');if($key===''||mb_strlen($key)>64)throw new InvalidArgumentException('Role key is required.');if(in_array($key,['super_admin'],true))throw new RuntimeException('Protected role key.');
    $description=mb_substr(trim((string)($input['description']??'')),0,500);$caps=admin_access_clean_capabilities((array)($input['capabilities']??[]));if(!$caps)throw new InvalidArgumentException('Select at least one capability.');$status=in_array((string)($input['status']??'active'),['active','archived'],true)?(string)$input['status']:'active';$reason=admin_ops_reason((string)($input['reason']??''));
    if($existing&&$status==='archived'){$q=$pdo->prepare("SELECT COUNT(*) FROM admin_operator_profiles WHERE role_id=? AND status='active'");$q->execute([(int)$existing['id']]);if((int)$q->fetchColumn()>0)throw new RuntimeException('Reassign active operators before archiving this role.');}
    $before=$existing?['name'=>$existing['name'],'role_key'=>$existing['role_key'],'description'=>$existing['description'],'capabilities'=>admin_access_role_caps($existing),'status'=>$existing['status']]:null;$json=json_encode($caps,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if($existing){$pdo->prepare('UPDATE admin_roles SET role_key=?,name=?,description=?,capabilities_json=?,status=?,updated_by_user_id=? WHERE id=?')->execute([$key,$name,$description!==''?$description:null,$json,$status,(int)$admin['id'],(int)$existing['id']]);$pdo->prepare('UPDATE admin_operator_profiles SET role_key=? WHERE role_id=?')->execute([$key,(int)$existing['id']]);$id=(int)$existing['id'];}
    else{$pid=ulid_like();$pdo->prepare('INSERT INTO admin_roles(public_id,role_key,name,description,capabilities_json,is_system,status,created_by_user_id,updated_by_user_id) VALUES(?,?,?,?,?,0,?,?,?)')->execute([$pid,$key,$name,$description!==''?$description:null,$json,$status,(int)$admin['id'],(int)$admin['id']]);$id=(int)$pdo->lastInsertId();}
    $role=admin_access_role($pdo,$id)??throw new RuntimeException('Role could not be reloaded.');admin_access_security_audit($pdo,$admin,'role',(string)$role['public_id'],$existing?'role_updated':'role_created',$before,['name'=>$role['name'],'role_key'=>$role['role_key'],'description'=>$role['description'],'capabilities'=>admin_access_role_caps($role),'status'=>$role['status']],$reason);return $role;
}
function admin_access_effective_role_key_for_user(PDO $pdo,int $userId): string {
    $q=$pdo->prepare("SELECT COALESCE(r.role_key,p.role_key,'super_admin') FROM users u LEFT JOIN admin_operator_profiles p ON p.user_id=u.id LEFT JOIN admin_roles r ON r.id=p.role_id WHERE u.id=? AND u.role='admin' AND u.status<>'deleted'");$q->execute([$userId]);$v=$q->fetchColumn();return $v===false?'':(string)$v;
}
function admin_access_active_super_admin_count(PDO $pdo): int {
    $sql="SELECT COUNT(*) FROM users u LEFT JOIN admin_operator_profiles p ON p.user_id=u.id LEFT JOIN admin_roles r ON r.id=p.role_id WHERE u.role='admin' AND u.status<>'deleted' AND COALESCE(p.status,'active')='active' AND COALESCE(r.role_key,p.role_key,'super_admin')='super_admin'";return (int)$pdo->query($sql)->fetchColumn();
}
function admin_access_assign_operator(PDO $pdo,array $admin,int $targetUserId,string $rolePublicId,string $status,string $reason): array {
    admin_access_require_super_admin($pdo,$admin);$reason=admin_ops_reason($reason);$q=$pdo->prepare("SELECT id,public_id,username,display_name,email,status,role FROM users WHERE id=? LIMIT 1");$q->execute([$targetUserId]);$user=$q->fetch();if(!$user||$user['role']!=='admin'||$user['status']!=='active')throw new RuntimeException('Only active site administrators can receive an operator role.');$role=admin_access_role($pdo,$rolePublicId);if(!$role||$role['status']!=='active')throw new RuntimeException('Select an active Admin role.');$status=in_array($status,['active','disabled'],true)?$status:'active';
    $beforeRole=admin_access_effective_role_key_for_user($pdo,$targetUserId);if($beforeRole==='super_admin'&&((string)$role['role_key']!=='super_admin'||$status!=='active')&&admin_access_active_super_admin_count($pdo)<=1)throw new RuntimeException('The last active Super Admin cannot be demoted or disabled.');
    $q=$pdo->prepare('SELECT p.*,r.name role_name FROM admin_operator_profiles p LEFT JOIN admin_roles r ON r.id=p.role_id WHERE p.user_id=?');$q->execute([$targetUserId]);$before=$q->fetch()?:['role_key'=>'super_admin','status'=>'active'];
    $pdo->prepare("INSERT INTO admin_operator_profiles(user_id,role_id,role_key,capabilities_json,status,assigned_by_user_id,assigned_at) VALUES(?,?,?,'[]',?,?,NOW()) ON DUPLICATE KEY UPDATE role_id=VALUES(role_id),role_key=VALUES(role_key),capabilities_json='[]',status=VALUES(status),assigned_by_user_id=VALUES(assigned_by_user_id),assigned_at=NOW()")->execute([$targetUserId,(int)$role['id'],(string)$role['role_key'],$status,(int)$admin['id']]);
    $after=['user_id'=>$targetUserId,'role_key'=>$role['role_key'],'role_name'=>$role['name'],'status'=>$status];admin_access_security_audit($pdo,$admin,'operator',(string)$user['public_id'],'operator_role_assigned',['role_key'=>$before['role_key']??'super_admin','status'=>$before['status']??'active'],$after,$reason);return $after;
}
function admin_access_admin_operators(PDO $pdo): array {
    if(!admin_access_ready($pdo))return [];$sql="SELECT u.id,u.public_id,u.username,u.display_name,u.email,u.status user_status,p.status operator_status,p.assigned_at,p.assigned_by_user_id,COALESCE(r.public_id,'ar-super-admin') role_public_id,COALESCE(r.role_key,p.role_key,'super_admin') role_key,COALESCE(r.name,'Super Admin') role_name,COALESCE(r.capabilities_json,p.capabilities_json,JSON_ARRAY('admin.*')) role_capabilities_json,au.username assigned_by_username FROM users u LEFT JOIN admin_operator_profiles p ON p.user_id=u.id LEFT JOIN admin_roles r ON r.id=p.role_id LEFT JOIN users au ON au.id=p.assigned_by_user_id WHERE u.role='admin' AND u.status<>'deleted' ORDER BY u.display_name,u.username,u.id";return $pdo->query($sql)->fetchAll()?:[];
}
function admin_access_approval_policies(PDO $pdo): array {
    if(!admin_access_ready($pdo))return [];$sql="SELECT p.*,cu.username created_by_username,uu.username updated_by_username FROM admin_approval_policies p LEFT JOIN users cu ON cu.id=p.created_by_user_id LEFT JOIN users uu ON uu.id=p.updated_by_user_id ORDER BY FIELD(p.risk_level,'high','elevated','routine'),p.action_type";return $pdo->query($sql)->fetchAll()?:[];
}
function admin_access_policy_for_action(PDO $pdo,string $actionType): ?array {
    if(!admin_access_ready($pdo))return null;$q=$pdo->prepare("SELECT * FROM admin_approval_policies WHERE action_type=? AND status='active' LIMIT 1");$q->execute([$actionType]);return $q->fetch()?:null;
}
function admin_access_approval_policy_save(PDO $pdo,array $admin,string $publicId,array $input): array {
    admin_access_require_super_admin($pdo,$admin);$q=$pdo->prepare('SELECT * FROM admin_approval_policies WHERE public_id=? LIMIT 1');$q->execute([$publicId]);$before=$q->fetch();if(!$before)throw new RuntimeException('Approval policy not found.');$catalog=admin_ops_action_catalog();if(!isset($catalog[(string)$before['action_type']]))throw new RuntimeException('Approval policy is not linked to a current governed action.');$required=max(0,min(3,(int)($input['required_approvals']??$before['required_approvals'])));$distinct=!empty($input['distinct_from_requester'])?1:0;$status=in_array((string)($input['status']??$before['status']),['active','disabled'],true)?(string)$input['status']:(string)$before['status'];$risk=in_array((string)($input['risk_level']??$before['risk_level']),['routine','elevated','high'],true)?(string)$input['risk_level']:(string)$before['risk_level'];$reason=admin_ops_reason((string)($input['reason']??''));
    $pdo->prepare('UPDATE admin_approval_policies SET risk_level=?,required_approvals=?,distinct_from_requester=?,status=?,updated_by_user_id=? WHERE id=?')->execute([$risk,$required,$distinct,$status,(int)$admin['id'],(int)$before['id']]);$q->execute([$publicId]);$after=$q->fetch()?:$before;admin_access_security_audit($pdo,$admin,'approval_policy',$publicId,'approval_policy_updated',['risk_level'=>$before['risk_level'],'required_approvals'=>(int)$before['required_approvals'],'distinct_from_requester'=>(int)$before['distinct_from_requester'],'status'=>$before['status']],['risk_level'=>$after['risk_level'],'required_approvals'=>(int)$after['required_approvals'],'distinct_from_requester'=>(int)$after['distinct_from_requester'],'status'=>$after['status']],$reason);return $after;
}
function admin_access_bind_action_policy(PDO $pdo,array $record): array {
    if(!admin_access_ready($pdo)||empty($record['id']))return $record;$policy=admin_access_policy_for_action($pdo,(string)$record['action_type']);if(!$policy)return $record;$pdo->prepare('UPDATE admin_action_records SET approval_policy_id=?,required_approvals=?,approval_distinct_from_requester=?,approval_approver_capability=?,risk_level=? WHERE id=?')->execute([(int)$policy['id'],(int)$policy['required_approvals'],(int)$policy['distinct_from_requester'],(string)$policy['approver_capability'],(string)$policy['risk_level'],(int)$record['id']]);return admin_ops_action_record($pdo,(string)$record['public_id'])??$record;
}
function admin_access_action_approval_rows(PDO $pdo,int $actionRecordId): array {
    if(!admin_access_ready($pdo))return [];$q=$pdo->prepare("SELECT a.*,u.username,u.display_name FROM admin_action_approvals a JOIN users u ON u.id=a.approver_user_id WHERE a.action_record_id=? ORDER BY a.created_at,a.id");$q->execute([$actionRecordId]);return $q->fetchAll()?:[];
}
function admin_access_action_approval_summary(PDO $pdo,array $record): array {
    $rows=admin_access_action_approval_rows($pdo,(int)($record['id']??0));$approved=0;$rejected=0;foreach($rows as $r){if($r['decision']==='approved')$approved++;else $rejected++;}return ['required'=>(int)($record['required_approvals']??0),'approved'=>$approved,'rejected'=>$rejected,'rows'=>$rows,'satisfied'=>$rejected===0&&$approved>=(int)($record['required_approvals']??0)];
}
function admin_access_action_request_approval(PDO $pdo,array $admin,string $recordPublicId): array {
    admin_access_assert_capability($pdo,$admin,'admin.actions.request');$record=admin_ops_action_record($pdo,$recordPublicId);if(!$record)throw new RuntimeException('Governed action not found.');if((int)$record['actor_user_id']!==(int)$admin['id'])throw new RuntimeException('Only the requester can submit this action for approval.');if($record['status']!=='previewed')throw new RuntimeException('Only a previewed action can be submitted for approval.');if((int)$record['required_approvals']<1)return $record;
    $pdo->prepare("UPDATE admin_action_records SET status='pending_approval',approval_requested_at=NOW() WHERE id=? AND status='previewed'")->execute([(int)$record['id']]);$next=admin_ops_action_record($pdo,$recordPublicId)??$record;admin_access_security_audit($pdo,$admin,'action',$recordPublicId,'approval_requested',['status'=>'previewed'],['status'=>'pending_approval','required_approvals'=>(int)$record['required_approvals']],(string)$record['reason']);return $next;
}
function admin_access_action_decide(PDO $pdo,array $admin,string $recordPublicId,string $decision,string $reason): array {
    admin_access_assert_capability($pdo,$admin,'admin.actions.approve');$decision=in_array($decision,['approved','rejected'],true)?$decision:'';if($decision==='')throw new InvalidArgumentException('Approval decision is invalid.');$reason=admin_ops_reason($reason);$record=admin_ops_action_record($pdo,$recordPublicId);if(!$record)throw new RuntimeException('Governed action not found.');if($record['status']!=='pending_approval')throw new RuntimeException('This action is not awaiting approval.');$approverCapability=(string)($record['approval_approver_capability']??'admin.actions.approve');if($approverCapability!=='')admin_access_assert_capability($pdo,$admin,$approverCapability);if(!empty($record['approval_distinct_from_requester'])&&(int)$record['actor_user_id']===(int)$admin['id'])throw new RuntimeException('Approval policy requires a reviewer different from the requester.');
    $pdo->beginTransaction();try{$q=$pdo->prepare('SELECT COUNT(*) FROM admin_action_approvals WHERE action_record_id=? AND approver_user_id=?');$q->execute([(int)$record['id'],(int)$admin['id']]);if((int)$q->fetchColumn()>0)throw new RuntimeException('You already decided this approval request.');$pdo->prepare('INSERT INTO admin_action_approvals(public_id,action_record_id,approver_user_id,decision,reason) VALUES(?,?,?,?,?)')->execute([ulid_like(),(int)$record['id'],(int)$admin['id'],$decision,$reason]);if($decision==='rejected'){$pdo->prepare("UPDATE admin_action_records SET status='rejected',rejected_at=NOW() WHERE id=? AND status='pending_approval'")->execute([(int)$record['id']]);}else{$q=$pdo->prepare("SELECT COUNT(*) FROM admin_action_approvals WHERE action_record_id=? AND decision='approved'");$q->execute([(int)$record['id']]);$count=(int)$q->fetchColumn();if($count>=(int)$record['required_approvals'])$pdo->prepare("UPDATE admin_action_records SET status='approved',approved_at=NOW() WHERE id=? AND status='pending_approval'")->execute([(int)$record['id']]);}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $next=admin_ops_action_record($pdo,$recordPublicId)??$record;admin_access_security_audit($pdo,$admin,'action',$recordPublicId,'approval_'.$decision,['status'=>$record['status']],['status'=>$next['status'],'decision'=>$decision],$reason);return $next;
}
function admin_access_assert_action_executable(PDO $pdo,array $admin,array $record): void {
    admin_access_assert_capability($pdo,$admin,'admin.actions.execute');$required=(int)($record['required_approvals']??0);if($required<1){if($record['status']!=='previewed')throw new RuntimeException('Governed action is not executable from its current state.');return;}if($record['status']!=='approved')throw new RuntimeException('Required Admin approvals must be satisfied before execution.');$summary=admin_access_action_approval_summary($pdo,$record);if(!$summary['satisfied'])throw new RuntimeException('Required Admin approvals are incomplete.');
}
function admin_access_pending_approvals(PDO $pdo,array $admin,int $limit=100): array {
    if(!admin_access_ready($pdo)||!admin_access_has_capability($pdo,$admin,'admin.actions.approve'))return [];$limit=max(1,min(300,$limit));$q=$pdo->query("SELECT r.*,a.public_id account_public_id,a.name account_name,u.username actor_username,r.approval_distinct_from_requester distinct_from_requester,p.name approval_policy_name FROM admin_action_records r LEFT JOIN accounts a ON a.id=r.account_id JOIN users u ON u.id=r.actor_user_id LEFT JOIN admin_approval_policies p ON p.id=r.approval_policy_id WHERE r.status='pending_approval' ORDER BY r.approval_requested_at,r.id LIMIT ".$limit);$rows=$q->fetchAll()?:[];foreach($rows as &$row)$row['approval_summary']=admin_access_action_approval_summary($pdo,$row);unset($row);return $rows;
}
function admin_access_security_events(PDO $pdo,int $limit=150): array {
    if(!admin_access_ready($pdo))return [];$limit=max(1,min(500,$limit));return $pdo->query("SELECT e.*,u.username actor_username,u.display_name actor_display_name FROM admin_security_audit_events e JOIN users u ON u.id=e.actor_user_id ORDER BY e.created_at DESC,e.id DESC LIMIT ".$limit)->fetchAll()?:[];
}
function admin_access_agent_context(PDO $pdo,array $viewer): string {
    if(($viewer['role']??'')!=='admin'||!admin_access_ready($pdo))return '';$profile=admin_access_profile($pdo,$viewer);$q=$pdo->query("SELECT COUNT(*) FROM admin_action_records WHERE status='pending_approval'");$pending=(int)$q->fetchColumn();return "[ADMIN V2.10 ACCESS — READ ONLY]\nOperator role: ".(string)$profile['role_name'].". Pending governed-action approvals: {$pending}. Role assignments, capabilities, approval policies and approval decisions are security controls. Never claim to change them or execute/approve an Admin action.";
}
