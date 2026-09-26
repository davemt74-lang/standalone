<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{
    $path=$root.'/'.$file;if(!is_file($path)){$fail[]=$label.' file missing: '.$file;return;}
    $body=(string)file_get_contents($path);foreach($needles as $needle)if(!str_contains($body,$needle))$fail[]=$label.' missing in '.$file.': '.$needle;
};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{
    $path=$root.'/'.$file;if(!is_file($path))return;$body=(string)file_get_contents($path);
    foreach($needles as $needle)if(str_contains($body,$needle))$fail[]=$label.' must not contain '.$needle;
};

$must('database/migrations/20260926_083_research_intelligence_delivery_subscriptions.sql',[
  'research_report_subscriptions','research_report_deliveries','research_report_delivery_events',
  "delivery_policy ENUM('every_run','if_changed','material_change_only','if_stale')",
  "status ENUM('pending','delivered','suppressed','failed','viewed')",'dedupe_key CHAR(64) NOT NULL UNIQUE',
  'subscriber_user_id BIGINT UNSIGNED NULL','ON DELETE SET NULL'
],'Phase 69 migration');

$must('app/research-intelligence-delivery.php',[
  'research_intelligence_delivery_ready','research_report_subscription_create','research_report_subscription_update','research_report_subscription_set_status',
  'research_report_delivery_access','research_report_delivery_mark_viewed','research_intelligence_delivery_should_run',
  'research_intelligence_delivery_process_subscription','research_intelligence_delivery_process_program_run','research_intelligence_delivery_run_manual',
  'research_intelligence_delivery_agent_context','research_intelligence_delivery_cognitive_observations','retryable','conversation_message_attachments',
  "'every_run'","'if_changed'","'material_change_only'","'if_stale'","research_report_studio_run_preset",
  'research_report_studio_freshness','research_report_studio_compare','notification_create','conversation_message_attachments'
],'Phase 69 runtime');
$avoid('app/research-intelligence-delivery.php',[
  'research_report_studio_create_document(','research_agent_workspace_create_document(','mail(','PHPMailer','smtp'
],'Phase 69 delivery must not auto-create Documents or add email delivery');

$must('app/research-programs.php',[
  "research_intelligence_delivery_process_program_run(\$pdo,\$program,(int)\$run['id'],'program_quiet')",
  "research_intelligence_delivery_process_program_run(\$pdo,\$program,(int)\$runId,'program_completed')"
],'Phase 69 existing Program lifecycle integration');

$must('worker/research-program-worker.php',[
  'research_program_complete_quiet_run','research_program_reconcile_runs'
],'Phase 69 must continue using the existing Program worker');
$avoid('worker/research-program-worker.php',[
  'research_report_studio_run_preset','research_report_subscription'
],'Phase 69 Program worker must not grow a second report scheduler');

$must('app/notifications.php',[
  "'research_report_delivery'","research_report_delivery_access","view=inbox"
],'Phase 69 notifications');
$must('app/cognitive-feed.php',[
  "'research_report_delivery'=>84","research_intelligence_delivery_cognitive_observations"
],'Phase 69 Now integration');
$must('app/agent-chat.php',[
  'research_intelligence_delivery_agent_context',"empty(\$researchAgent['team_id'])"
],'Phase 69 Agent awareness and Team privacy');

$must('app/agent-actions.php',[
  "'research.create_report_subscription'","'research.update_report_subscription'","'research.set_report_subscription_status'",
  'research_report_subscription_validate_links','research_report_subscription_create','research_report_subscription_update','research_report_subscription_set_status'
],'Phase 69 governed Agent actions');

$must('api/research-intelligence-delivery.php',[
  "'create_subscription','update_subscription','set_status','deliver_now','mark_viewed'","METHOD_NOT_ALLOWED",'require_api_mutation_auth',
  "\$action==='subscriptions'","\$action==='deliveries'"
],'Phase 69 API');

$must('research-reports.php',[
  'Subscriptions','Intelligence Inbox','INTELLIGENCE SUBSCRIPTION','Delivery policy','Deliver now','Mark reviewed',
  'Phase 69 does not create another scheduler','Ask Agent what changed'
],'Phase 69 per-Agent delivery UI');

$must('app/bootstrap.php',[
  'research-report-studio.php','research-intelligence-delivery.php'
],'Phase 69 bootstrap');

$must('docs/phase-69-research-intelligence-delivery-subscriptions.md',[
  'existing **Research Program worker remains the only scheduler**','Every Program cycle','Intelligence Inbox',
  'Research knowledge → Report Run → intelligence delivery → optional Create Document','Migration 083'
],'Phase 69 architecture');

$css=(string)file_get_contents($root.'/assets/css/app.css');$ext=(string)file_get_contents($root.'/extension/landing-app.css');
if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';
foreach(['Phase 69 — Research Intelligence Delivery & Subscriptions','.reportSubscriptionLayout','.reportSubscriptionForm','.reportDeliveryDetail'] as $needle)if(!str_contains($css,$needle))$fail[]='Phase 69 CSS missing '.$needle;

foreach(['research-intelligence-delivery-worker.php','research-report-subscription-worker.php','research-delivery-worker.php'] as $worker)if(is_file($root.'/worker/'.$worker))$fail[]='Phase 69 must not add a new scheduler/worker: '.$worker;

$must('tests/ci/run-full-regression.sh',['tests/phase69-research-intelligence-delivery-subscriptions-db.php'],'Phase 69 regression gate');
$must('.github/workflows/full-regression.yml',['phase69-upgrade-from-082.php','phase69-research-intelligence-delivery-subscriptions-db.php'],'Phase 69 MySQL gate');
$must('.github/workflows/package-two-zips.yml',[
  '20260926_083_research_intelligence_delivery_subscriptions.sql','phase-69-research-intelligence-delivery-subscriptions.md',
  'research-intelligence-delivery.php','api/research-intelligence-delivery.php',
  'phase69-research-intelligence-delivery-subscriptions-contract.php','phase69-research-intelligence-delivery-subscriptions-db.php','phase69-upgrade-from-082.php'
],'Phase 69 production package');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 69 Research Intelligence Delivery & Subscriptions static contracts passed.\n";
