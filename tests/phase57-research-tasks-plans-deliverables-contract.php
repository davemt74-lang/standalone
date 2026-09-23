<?php
declare(strict_types=1);

$root=dirname(__DIR__);$fail=[];
$must=function(bool $ok,string $message)use(&$fail): void {if(!$ok)$fail[]=$message;};

$required=[
  'database/migrations/20260923_055_research_tasks_plans_deliverables.sql',
  'app/research-tasks.php','api/research-tasks.php','worker/research-task-worker.php',
  'research-tasks.php','tests/phase57-research-tasks-plans-deliverables-db.php',
  'docs/phase-57-research-tasks-plans-deliverables.md'
];
foreach($required as $path)$must(is_file($root.'/'.$path),'Phase 57 required file missing: '.$path);

$m=(string)file_get_contents($root.'/database/migrations/20260923_055_research_tasks_plans_deliverables.sql');
foreach(['research_task_plans','research_task_plan_versions','research_task_dependencies','research_task_completion_gates','research_task_evidence_refs','research_task_jobs','research_task_runs','research_task_deliverables','research_task_events','created_by_agent','completion_evaluation_json','human_reviewed_at','rerun_requested'] as $needle)
  $must(str_contains($m,$needle),'Phase 57 migration contract missing: '.$needle);

$rt=(string)file_get_contents($root.'/app/research-tasks.php');
foreach(['research_task_plan_create','research_task_plan_snapshot','research_task_queue','research_task_dependencies_complete','research_task_execution_context','research_task_apply_ai_output','research_task_gate_evaluate','research_task_refresh_deliverable','research_task_review','research_task_gate_waive','research_task_sync_agent_signals','research_task_cognitive_observations','research_task_deliverable_resume','research_task_deliverable_finalize'] as $needle)
  $must(str_contains($rt,$needle),'Phase 57 runtime contract missing: '.$needle);
foreach(['research_task_independent_sources','research_task_ref_fresh_timestamp','research_task_deliverables rtd JOIN research_workspace_objects'] as $needle)
  $must(str_contains($rt,$needle),'Phase 57 completion/evidence hardening missing: '.$needle);
$must(!str_contains($rt,"UPDATE research_claims SET"),'Phase 57 execution must not mutate authoritative saved Claims.');
$must(str_contains($rt,"status='needs_review'"),'Phase 57 must stop Agent deliverable updates after user document edits.');

$worker=(string)file_get_contents($root.'/worker/research-task-worker.php');
foreach(["release_worker_heartbeat(\$pdo,'research_tasks'","job_claim(\$pdo,'research_task_jobs'","research_task_dependencies_complete","ai_queue_job","research_task_execution"] as $needle)
  $must(str_contains($worker,$needle),'Phase 57 task worker contract missing: '.$needle);

$ai=(string)file_get_contents($root.'/worker/ai-worker.php');
foreach(['research_task_execution','research_task_apply_ai_output','Research Task Execution','stale_discarded'] as $needle)
  $must(str_contains($ai,$needle),'Phase 57 governed AI execution contract missing: '.$needle);

$actions=(string)file_get_contents($root.'/app/agent-actions.php');
foreach(["'research.create_plan'","'research.create_task'","research_task_plan_create","research_task_create_for_project"] as $needle)
  $must(str_contains($actions,$needle),'Phase 57 Agent action contract missing: '.$needle);

$home=(string)file_get_contents($root.'/home.php');
foreach(['data-research-library-filter="tasks"','app.css?v=57.0','research-agent-workspace-ui.js?v=57.0'] as $needle)
  $must(str_contains($home,$needle),'Phase 57 Home/Library contract missing: '.$needle);
foreach(['app.css?v=56.0','research-agent-workspace-ui.js?v=56.0'] as $stale)
  $must(!str_contains($home,$stale),'Phase 57 Home retains stale UI cache key: '.$stale);

$js=(string)file_get_contents($root.'/assets/js/research-agent-workspace-ui.js');
foreach(['researchTaskUrl','renderLibraryTasks','loadLibraryTasks',"libraryFilter==='tasks'"] as $needle)
  $must(str_contains($js,$needle),'Phase 57 Library Tasks contract missing: '.$needle);

$page=(string)file_get_contents($root.'/research-tasks.php');
foreach(['RESEARCH EXECUTION','data-plan-create','data-plan-update','data-task-add','data-task-update','data-gate-waive','finalize_deliverable','resume_deliverable'] as $needle)
  $must(str_contains($page,$needle),'Phase 57 Task Center contract missing: '.$needle);

$research=(string)file_get_contents($root.'/research.php');
foreach(['href="/research-tasks.php">Tasks</a>','researchAgentTaskSummary','task_summary'] as $needle)
  $must(str_contains($research,$needle),'Phase 57 Research Agent integration missing: '.$needle);

$cognitive=(string)file_get_contents($root.'/app/cognitive-feed.php');
$must(str_contains($cognitive,'research_task_cognitive_observations'),'Phase 57 task state must feed Now/cognitive surfaces.');

$project=(string)file_get_contents($root.'/research-project.php');
foreach(['Manage in Task Center','governed by Phase 57','research_task_create_for_project'] as $needle)
  $must(str_contains($project,$needle),'Legacy Research project task path must route governed tasks through Phase 57: '.$needle);

$release=(string)file_get_contents($root.'/app/release.php');
foreach(["'research_tasks'=>['command'=>'php worker/research-task-worker.php'","'research_tasks'=>'research_task_jobs'"] as $needle)
  $must(str_contains($release,$needle),'Phase 57 release-health contract missing: '.$needle);

$css=(string)file_get_contents($root.'/assets/css/app.css');$ext=(string)file_get_contents($root.'/extension/landing-app.css');
$must(hash_equals(hash('sha256',$css),hash('sha256',$ext)),'Extension landing base CSS must remain byte-identical to website app CSS.');
foreach(['.researchTasksCanvas','.researchTaskPlanCard','.researchTaskCard','.researchLibraryTaskPlan'] as $needle)
  $must(str_contains($css,$needle),'Phase 57 CSS contract missing: '.$needle);

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Phase 57 Research Tasks, Plans & Deliverables contract passed.\n";
