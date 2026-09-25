<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $file)use($root,&$fail): string{$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return '';}return (string)file_get_contents($path);};
$need=function(string $file,string $needle,string $message)use($read,&$fail): void{$body=$read($file);if($body!==''&&!str_contains($body,$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use($read,&$fail): void{$body=$read($file);if($body!==''&&str_contains($body,$needle))$fail[]=$message;};

foreach([
 'function admin_agent_page_context',
 'function admin_agent_page_context_from_url',
 "'/admin/account.php'=>'admin.accounts.view'",
 "'/admin/support-case.php'=>'admin.support.view'",
 "'/admin/customer-success-account.php'=>'admin.customer_success.view'",
 '[CURRENT ADMIN PAGE — ACCOUNT]',
 '[CURRENT ADMIN PAGE — SUPPORT CASE]',
] as $needle)$need('app/admin-agent-context.php',$needle,'Context resolver missing '.$needle);
$need('app/bootstrap.php',"require_once __DIR__ . '/admin-agent-context.php';",'Shared Admin bootstrap must load page-context resolver.');

foreach([
 'function admin_ui_agent_copilot',
 'data-admin-copilot',
 'data-admin-copilot-panel',
 'data-admin-copilot-form',
 'data-admin-copilot-input',
 'Ask Admin Agent…',
 '/assets/js/admin-agent-copilot.js?v=2.80',
 'Admin V2.80 · Contextual Admin Agent',
] as $needle)$need('app/admin-ui.php',$needle,'Shared Admin shell missing V2.80 copilot contract '.$needle);

foreach([
 "localStorage.setItem(storageKey,thread)",
 "page_context:currentContext()",
 "api('state'",
 "api('send'",
 'data-admin-copilot-full',
 'adminCopilotResult',
 "e.key==='Enter'&&!e.shiftKey",
] as $needle)$need('assets/js/admin-agent-copilot.js',$needle,'Admin-wide copilot client missing '.$needle);

foreach([
 'function admin_agent_package_options',
 'function admin_agent_store_links',
 'admin_link',
 'page_context',
 'change_account_package',
 'set_account_lifecycle',
 'ACTIVE ADMIN PACKAGES',
 'CURRENT ADMIN PAGE',
] as $needle)$need('app/admin-agent.php',$needle,'Admin Agent V2.80 runtime missing '.$needle);
$need('api/admin-agent.php',"\$pageContext=is_array(\$input['page_context']??null)?\$input['page_context']:[]",'Admin Agent API must accept contextual page descriptor and re-resolve it server-side.');
$need('api/admin-agent.php','admin_agent_page_context_from_url','Admin Agent state must resolve current page context server-side.');

foreach([
 "'change_account_package'=>",
 "'set_account_lifecycle'=>",
 "surface'=>'agent_account'",
 'array $params=[]',
 "required_approvals=1",
 "approval_distinct_from_requester=1",
 "account_admin_change_package",
 "account_admin_update_lifecycle",
] as $needle)$need('app/admin-operations.php',$needle,'Governed V2.80 action adapter missing '.$needle);
$avoid('app/admin-agent.php','account_admin_change_package(','Admin Agent model runtime must never directly execute package changes.');
$avoid('app/admin-agent.php','account_admin_update_lifecycle(','Admin Agent model runtime must never directly execute lifecycle changes.');

foreach([
 'data-context=',
 'ADMIN V2.80 · CONTEXTUAL ADMIN AGENT',
 'adminAgentCurrentContext',
 '/assets/js/admin-agent.js?v=2.80',
] as $needle)$need('admin/assistant.php',$needle,'Full Admin Agent canvas missing contextual continuity '.$needle);
foreach(['admin_links','page_context:pageContext','adminAgentResultLink','annotated.adminAgent.activeThread'] as $needle)$need('assets/js/admin-agent.js',$needle,'Full Agent client missing contextual continuity '.$needle);

$web=$read('assets/css/app.css');$ext=$read('extension/landing-app.css');if($web!==$ext)$fail[]='Website and extension base CSS must remain exactly synchronized.';
foreach([
 'Admin V2.80 — Contextual Admin Agent & Admin-wide Copilot',
 '.adminCopilot{position:fixed',
 '.adminCopilotPanel',
 '.adminCopilotComposer',
 '.adminAgentCurrentContext',
 '.adminAgentResultLink',
] as $needle)if(!str_contains($web,$needle))$fail[]='V2.80 CSS missing '.$needle;

$need('admin/action-center.php','Admin Agent requested change','Action Center must display exact V2.80 Agent preview payload.');
$need('tests/ci/run-static-contracts.sh','php tests/admin-v2-80-contextual-admin-agent-contract.php','Static CI must run V2.80 contract.');
$need('tests/ci/run-full-regression.sh','tests/admin-v2-80-contextual-admin-agent-db.php','Full regression must run V2.80 DB journey.');
$need('.github/workflows/full-regression.yml','php tests/admin-v2-80-contextual-admin-agent-db.php','MySQL 8 must run V2.80 DB journey.');
$need('.github/workflows/package-two-zips.yml','assets/js/admin-agent-copilot.js','Production package must include V2.80 copilot client.');
$need('.github/workflows/package-two-zips.yml','app/admin-agent-context.php','Production package must include V2.80 context resolver.');
$need('tests/ci/package-smoke.sh','admin-v2-80-contextual-admin-agent.md','Package smoke must require V2.80 documentation.');
if(is_file($root.'/database/migrations/20260925_080_admin_agent_contextual_copilot.sql'))$fail[]='V2.80 must not introduce an unnecessary database migration.';

if($fail){foreach(array_values(array_unique($fail)) as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Admin V2.80 Contextual Admin Agent static contract passed.\n";
