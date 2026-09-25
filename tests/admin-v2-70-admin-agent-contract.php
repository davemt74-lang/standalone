<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $file)use($root,&$fail): string{$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return '';}return (string)file_get_contents($path);};
$need=function(string $file,string $needle,string $message)use($read,&$fail): void{$body=$read($file);if($body!==''&&!str_contains($body,$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use($read,&$fail): void{$body=$read($file);if($body!==''&&str_contains($body,$needle))$fail[]=$message;};

foreach([
    'function admin_agent_thread_create',
    'function admin_agent_thread_access',
    'function admin_agent_threads',
    'function admin_agent_messages',
    'function admin_agent_context_bundle',
    'function admin_agent_send',
    "conversation_type='admin_agent'",
    'admin_ops_global_search',
    'admin_ui_dashboard_snapshot',
    'admin_ops_action_preview',
    '<<ANNOTATED_ADMIN_ACTIONS>>',
] as $needle)$need('app/admin-agent.php',$needle,'Admin Agent runtime missing '.$needle);

foreach([
    'admin_support_agent_context',
    'admin_finance_agent_context',
    'admin_customer_success_agent_context',
    'admin_security_agent_context',
    'admin_platform_agent_context',
] as $needle)$need('app/admin-agent.php',$needle,'Admin Agent must reuse existing Admin context authority: '.$needle);

$need('app/admin-agent.php',"['resync_stripe_subscription','reconcile_ai_overage','sync_tax_policy']", 'Admin Agent governed preview whitelist must remain bounded.');
$need('app/admin-agent.php','function admin_agent_action_intent_allowed', 'Governed previews must require deterministic administrator intent in addition to model output.');
$avoid('app/admin-agent.php','admin_ops_action_execute(', 'Admin Agent must not directly execute governed Admin actions.');
foreach(['UPDATE accounts SET','DELETE FROM accounts','INSERT INTO admin_support_cases','UPDATE admin_platform_features SET'] as $needle)$avoid('app/admin-agent.php',$needle,'Admin Agent must not create a shadow mutation path: '.$needle);

foreach([
    'data-admin-agent-root',
    'adminAgentCanvas',
    'adminAgentMessages',
    'adminAgentComposerDock',
    'data-admin-agent-form',
    'data-admin-agent-input',
    'Message Admin Agent…',
    '/assets/js/admin-agent.js?v=2.70',
] as $needle)$need('admin/assistant.php',$needle,'Admin Agent canvas missing '.$needle);
$avoid('admin/assistant.php','Ask the backend','Legacy one-shot Admin Assistant UI must be removed.');

foreach([
    "api('send'",
    "api('new'",
    "e.key==='Enter'&&!e.shiftKey",
    'form.requestSubmit()',
    'adminAgentActionCard',
    'history.replaceState',
] as $needle)$need('assets/js/admin-agent.js',$needle,'Admin Agent client missing '.$needle);

foreach([
    "if($action==='messages')",
    "if($action==='new')",
    "if($action==='send')",
    'require_api_mutation_auth',
    'admin_agent_assert_admin',
] as $needle)$need('api/admin-agent.php',$needle,'Admin Agent API missing '.$needle);

$need('app/admin-ui.php',"'assistant'=>['label'=>'Admin Agent','url'=>'/admin/assistant.php']", 'Shared Admin navigation must expose Admin Agent.');
$need('app/admin-ui.php','Admin V2.70 · Admin Agent','Shared Admin shell must identify V2.70.');
$need('app/admin-access.php',"'/admin/assistant.php'=>['admin.operations.view','admin.operations.view']", 'Admin Agent page must remain on explicit delegated read authority.');

$web=$read('assets/css/app.css');$ext=$read('extension/landing-app.css');if($web!==$ext)$fail[]='Extension landing base CSS must remain exactly synchronized with website base CSS.';
foreach([
    'Admin V2.70 — Admin Agent',
    '.adminAgentWorkspace',
    '.adminAgentComposerDock{position:sticky;bottom:0',
    '.adminAgentComposer textarea',
    '.adminAgentQuickPrompts',
    '@media(max-width:900px)',
] as $needle)if(!str_contains($web,$needle))$fail[]='Admin Agent styling missing '.$needle;

$need('tests/ci/run-static-contracts.sh','php tests/admin-v2-70-admin-agent-contract.php','Static CI must execute Admin V2.70 contract.');
$need('tests/ci/run-full-regression.sh','tests/admin-v2-70-admin-agent-db.php','Full regression must execute Admin V2.70 DB journey.');
$need('.github/workflows/full-regression.yml','php tests/admin-v2-70-admin-agent-db.php','MySQL 8 must execute Admin V2.70 DB journey.');
$need('.github/workflows/package-two-zips.yml','app/admin-agent.php','Production package must include Admin Agent runtime.');
$need('.github/workflows/package-two-zips.yml','assets/js/admin-agent.js','Production package must include Admin Agent client.');
$need('tests/ci/package-smoke.sh','admin-v2-70-admin-agent.md','Package smoke must require Admin V2.70 documentation.');
if(is_file($root.'/database/migrations/20260925_080_admin_agent.sql'))$fail[]='Admin V2.70 must reuse conversation runtime instead of adding an unnecessary migration.';

if($fail){foreach(array_values(array_unique($fail)) as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Admin V2.70 Admin Agent static contract passed.\n";
