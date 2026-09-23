<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$fail=[];
$must=function(bool $ok,string $message)use(&$fail): void {if(!$ok)$fail[]=$message;};

$required=[
 'database/migrations/20260923_054_continuous_research_monitoring.sql',
 'app/research-monitoring.php',
 'api/research-monitoring.php',
 'worker/research-monitor-worker.php',
 'research-monitoring.php',
 'tests/phase56-continuous-research-monitoring-db.php',
 'docs/phase-56-continuous-research-monitoring.md',
];
foreach($required as $path)$must(is_file($root.'/'.$path),'Phase 56 required file missing: '.$path);

$migration=(string)file_get_contents($root.'/database/migrations/20260923_054_continuous_research_monitoring.sql');
foreach([
 'research_monitor_watches','research_monitor_jobs','research_monitor_runs','research_monitor_candidates',
 'research_monitor_events','research_monitor_claim_states','research_monitor_claim_assessments',
 "ENUM('updated','edited','moved','unavailable','restored')",'rerun_requested'
] as $needle)$must(str_contains($migration,$needle),'Phase 56 migration contract missing: '.$needle);

$runtime=(string)file_get_contents($root.'/app/research-monitoring.php');
foreach([
 'research_monitor_create','research_monitor_queue_due','research_monitor_candidate_ingest',
 'research_monitor_candidate_promote','research_monitor_sync_source_changes','research_monitor_sync_claim',
 'research_monitor_queue_claim_assessments','research_monitor_claim_assessment_apply',
 'research_monitor_chat_updates','research_monitor_run','research_monitor_summary','auto_promote_limit_per_run',
 "PHP exec is unavailable","{input} and {output} placeholders"
] as $needle)$must(str_contains($runtime,$needle),'Phase 56 runtime contract missing: '.$needle);
$must(!str_contains($runtime,"UPDATE research_claims SET status="),'Phase 56 claim intelligence must not silently rewrite saved claim status.');

$worker=(string)file_get_contents($root.'/worker/research-monitor-worker.php');
foreach(["release_worker_heartbeat($pdo,'research_monitor'","job_claim($pdo,'research_monitor_jobs'","rerun_requested","research_monitor_run"] as $needle)$must(str_contains($worker,$needle),'Phase 56 worker contract missing: '.$needle);

$sourceWorker=(string)file_get_contents($root.'/worker/source-monitor-worker.php');
$must(str_contains($sourceWorker,'research_monitor_queue_for_source'),'Existing Source Monitor must hand source changes into Phase 56 monitoring.');

$aiWorker=(string)file_get_contents($root.'/worker/ai-worker.php');
foreach(['research_monitor_claim_assessment','research_monitor_claim_assessment_apply','supports|weakens|contradicts|unrelated'] as $needle)$must(str_contains($aiWorker,$needle),'Phase 56 AI claim-intelligence contract missing: '.$needle);

$config=(string)file_get_contents($root.'/config.example.php');
foreach(["'research_monitoring'","'discovery_command'","'auto_promote_limit_per_run'","{input}","{output}"] as $needle)$must(str_contains($config,$needle),'Phase 56 discovery-provider contract missing: '.$needle);

$home=(string)file_get_contents($root.'/home.php');
foreach(['data-research-library-filter="monitoring"','/assets/css/app.css?v=56.0','/assets/js/research-agent-workspace-ui.js?v=56.0'] as $needle)$must(str_contains($home,$needle),'Phase 56 Home/Library UI contract missing: '.$needle);
foreach(['/assets/css/app.css?v=55.3','/assets/js/research-agent-workspace-ui.js?v=55.3'] as $stale)$must(!str_contains($home,$stale),'Phase 56 Home must not retain stale cache key: '.$stale);

$libraryJs=(string)file_get_contents($root.'/assets/js/research-agent-workspace-ui.js');
foreach(['researchMonitorUrl','renderLibraryMonitoring',"libraryFilter==='monitoring'",'/api/research-monitoring.php'] as $needle)$must(str_contains($libraryJs,$needle),'Phase 56 Research Library Monitoring contract missing: '.$needle);

$page=(string)file_get_contents($root.'/research-monitoring.php');
foreach(['CONTINUOUS RESEARCH','data-monitor-create','data-monitor-action','data-candidate-action','Research Agent'] as $needle)$must(str_contains($page,$needle),'Phase 56 Monitoring control-center contract missing: '.$needle);

$research=(string)file_get_contents($root.'/research.php');
$must(str_contains($research,'href="/research-monitoring.php">Monitoring</a>'),'Research landing must expose Monitoring.');

$release=(string)file_get_contents($root.'/app/release.php');
foreach(["'research_monitor'=>['command'=>'php worker/research-monitor-worker.php'","'research_monitor'=>'research_monitor_jobs'"] as $needle)$must(str_contains($release,$needle),'Phase 56 release-health contract missing: '.$needle);

$css=(string)file_get_contents($root.'/assets/css/app.css');
$ext=(string)file_get_contents($root.'/extension/landing-app.css');
$must(hash_equals(hash('sha256',$css),hash('sha256',$ext)),'Extension landing base CSS must match the website base CSS.');
foreach(['.researchMonitoringCanvas','.researchLibraryMonitorWatch','.researchLibraryMonitorEvent'] as $needle)$must(str_contains($css,$needle),'Phase 56 Monitoring CSS contract missing: '.$needle);

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 56 Continuous Research Monitoring contract passed.\n";
