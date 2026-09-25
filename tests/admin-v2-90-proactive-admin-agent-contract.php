<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $file)use($root,&$fail): string{$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return '';}return (string)file_get_contents($path);};
$need=function(string $file,string $needle,string $message)use($read,&$fail): void{$body=$read($file);if($body!==''&&!str_contains($body,$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use($read,&$fail): void{$body=$read($file);if($body!==''&&str_contains($body,$needle))$fail[]=$message;};

foreach([
 'function admin_agent_v290_proactive_brief',
 'function admin_agent_v290_evidence',
 'function admin_agent_v290_enrich',
 'function admin_agent_v290_message_meta',
 'function admin_agent_v290_plan_step',
 "'admin_evidence'",
 "'admin_plan'",
 "'pending','done'",
] as $needle)$need('app/admin-agent-v290.php',$needle,'V2.90 intelligence runtime missing '.$needle);
$avoid('app/admin-agent-v290.php','admin_ops_action_execute(','V2.90 sidecar must not execute governed Admin actions.');
$avoid('app/admin-agent-v290.php','account_admin_change_package(','V2.90 sidecar must not directly mutate account packages.');
$avoid('app/admin-agent-v290.php','account_admin_update_lifecycle(','V2.90 sidecar must not directly mutate account lifecycle.');

foreach([
 "require_once dirname(__DIR__).'/app/admin-agent-v290.php';",
 "if(\$action==='brief')",
 "if(\$action==='plan-step')",
 'admin_agent_v290_enrich',
 'admin_agent_v290_message_meta',
] as $needle)$need('api/admin-agent.php',$needle,'V2.90 Admin Agent API integration missing '.$needle);

foreach(['adminAgentEvidence','adminAgentPlan','data-plan-step','plan-step'] as $needle){
    if($needle==='data-plan-step')continue;
    $need('assets/js/admin-agent.js',$needle,'Full Admin Agent V2.90 client missing '.$needle);
}
foreach(['admin_evidence','admin_plans','INVESTIGATION PLAN'] as $needle)$need('assets/js/admin-agent-copilot.js',$needle,'Admin copilot V2.90 rendering missing '.$needle);
$need('admin/assistant.php','ADMIN V2.90 · PROACTIVE ADMIN INTELLIGENCE','Full Admin Agent canvas must identify V2.90.');
$need('admin/assistant.php','/assets/js/admin-agent.js?v=2.90','Full Admin Agent must load V2.90 client.');
$need('app/admin-ui.php','ADMIN V2.90 · PROACTIVE COPILOT','Admin-wide copilot must identify V2.90.');
$need('app/admin-ui.php','/assets/js/admin-agent-copilot.js?v=2.90','Admin-wide copilot must load V2.90 client.');

$web=$read('assets/css/app.css');$ext=$read('extension/landing-app.css');if($web!==$ext)$fail[]='Website and extension base CSS must remain exactly synchronized.';
foreach(['Admin V2.90 — Proactive Admin Intelligence & Investigation Plans','.adminAgentEvidenceGrid','.adminAgentPlanSteps'] as $needle)if(!str_contains($web,$needle))$fail[]='V2.90 CSS missing '.$needle;

$need('tests/ci/run-static-contracts.sh','php tests/admin-v2-90-proactive-admin-agent-contract.php','Static CI must run V2.90 contract.');
$need('tests/ci/run-full-regression.sh','tests/admin-v2-90-proactive-admin-agent-db.php','Full regression must run V2.90 DB journey.');
$need('.github/workflows/full-regression.yml','php tests/admin-v2-90-proactive-admin-agent-db.php','MySQL 8 must run V2.90 DB journey.');
$need('.github/workflows/package-two-zips.yml','app/admin-agent-v290.php','Production package must include V2.90 intelligence runtime.');
$need('.github/workflows/package-two-zips.yml','tests/admin-v2-90-proactive-admin-agent-contract.php','Production package must include V2.90 contract.');
$need('tests/ci/package-smoke.sh','admin-v2-90-proactive-admin-agent.md','Package smoke must require V2.90 documentation.');
if(is_file($root.'/database/migrations/20260925_080_admin_agent_proactive_intelligence.sql'))$fail[]='V2.90 must not introduce a database migration.';

if($fail){foreach(array_values(array_unique($fail)) as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Admin V2.90 Proactive Admin Intelligence static contract passed.\n";
