<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail){if(!is_file($root.'/'.$file)){$fail[]="$label file missing: $file";return;}$body=(string)file_get_contents($root.'/'.$file);foreach($needles as $needle)if(!str_contains($body,$needle))$fail[]="$label contract missing in $file: $needle";};

$must('database/migrations/20260923_056_research_programs_recurring_intelligence.sql',[
 'research_programs','research_program_versions','research_program_runs','program_revision','program_config_json','research_program_deltas','research_program_events','program_run_id',
 'max_concurrent_runs','monthly_run_limit','token_budget_per_run','quiet_mode','materiality_threshold','catch_up_mode'
],'Phase 58 migration');
$must('app/research-programs.php',[
 'research_program_create','research_program_update','research_program_enqueue_due','research_program_effective_for_run','research_program_snapshot','research_program_compare_snapshots',
 'research_program_material_deltas','research_program_create_plan','research_program_task_budget','research_program_memory','research_program_reconcile_runs','research_program_cognitive_observations'
],'Phase 58 runtime');
$must('worker/research-program-worker.php',[
 "release_worker_heartbeat(\$pdo,'research_programs'","research_program_enqueue_due","research_program_claim","research_program_effective_for_run","research_program_reconcile_runs"
],'Phase 58 worker');
$must('worker/research-task-worker.php',['program_run_id','research_program_task_budget','program_token_budget'],'Phase 58 task budget');
$must('api/research-programs.php',['run_now','run_detail','pause','resume','archive'],'Phase 58 API');
$must('research-programs.php',['RECURRING INTELLIGENCE','Run now','PROGRAM CONTINUITY','RUN HISTORY','Token budget / run'],'Phase 58 Control Center');
$must('app/agent-actions.php',["'research.create_program'","research_program_create"],'Phase 58 governed Agent action');
$must('home.php',['data-research-library-filter="programs"','app.css?v=59.0','research-agent-workspace-ui.js?v=59.0'],'Phase 58 Home/Library');
$must('assets/js/research-agent-workspace-ui.js',['researchProgramUrl','renderLibraryPrograms','loadLibraryPrograms'],'Phase 58 Library JS');
$must('app/cognitive-feed.php',['research_program_cognitive_observations'],'Phase 58 Now integration');
$must('app/notifications.php',["\$type==='research_program'","/research-programs.php?agent="],'Phase 58 notification routing');
$must('app/release.php',["'research_programs'=>['command'=>'php worker/research-program-worker.php'","'research_programs'=>'research_program_runs'"],'Phase 58 release health');
$must('app/jobs.php',["'research_program_runs' => ['schedule'=>'available_at'"],'Phase 58 lease registry');
$must('app/research-tasks.php',['program_run_id'],'Phase 58 atomic Program-plan linkage');
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}
echo "Phase 58 Research Programs static contracts passed.\n";
