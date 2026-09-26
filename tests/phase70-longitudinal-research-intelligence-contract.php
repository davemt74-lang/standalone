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

$must('database/migrations/20260926_084_longitudinal_research_intelligence.sql',[
  'research_longitudinal_snapshots','research_longitudinal_changes','research_longitudinal_milestones',
  'uq_p70_snapshot_project_state','before_json','after_json','dedupe_key','fk_p70_snapshot_agent','fk_p70_change_snapshot','fk_p70_milestone_change'
],'Phase 70 migration');

$must('app/research-longitudinal-intelligence.php',[
  'research_longitudinal_ready','research_longitudinal_state','research_longitudinal_capture','research_longitudinal_classify',
  'research_longitudinal_snapshot_access','research_longitudinal_change_list','research_longitudinal_milestone_list','research_longitudinal_compare_snapshots',
  'research_longitudinal_summary','research_longitudinal_prompt_context','research_longitudinal_report_data','research_longitudinal_render_report',
  'research_longitudinal_capture_program','research_longitudinal_cognitive_observations',
  "'introduced'","'strengthened'","'weakened'","'disputed'","'verified'","'source_updated'","'resolved'"
],'Phase 70 runtime');
$avoid('app/research-longitudinal-intelligence.php',[
  'research_agent_workspace_create_document(','research_report_studio_create_document(','mail(','PHPMailer','smtp'
],'Phase 70 ledger must not create Documents or delivery channels');

$must('app/research-system-reports.php',[
  "'research_evolution'","'what_changed'","'confidence_contradictions'","'open_questions_evolution'","'entity_theme_evolution'",
  "research_longitudinal_report_data","research_longitudinal_render_report"
],'Phase 70 System Reports');
$must('app/research-report-studio.php',[
  "unset(\$basis['generated_at'],\$basis['state_hash'],\$basis['longitudinal'])"
],'Phase 70 Report freshness isolation');

$must('app/research-programs.php',[
  "research_longitudinal_capture_program(\$pdo,\$program,(int)\$run['id'],'program_quiet')",
  "research_longitudinal_capture_program(\$pdo,\$program,(int)\$runId,'program_completed')"
],'Phase 70 existing Program capture hooks');
$must('worker/research-program-worker.php',['research_program_complete_quiet_run','research_program_reconcile_runs'],'Phase 70 existing scheduler lineage');
foreach(['research-longitudinal-worker.php','research-evolution-worker.php','research-history-worker.php'] as $worker)if(is_file($root.'/worker/'.$worker))$fail[]='Phase 70 must not add a new worker: '.$worker;

$must('app/agent-chat.php',['research_longitudinal_prompt_context'],'Phase 70 Agent catch-up');
$must('app/cognitive-feed.php',["'research_longitudinal_change'=>86",'research_longitudinal_cognitive_observations'],'Phase 70 Now integration');
$must('app/agent-actions.php',[
  'research_evolution|what_changed|confidence_contradictions|open_questions_evolution|entity_theme_evolution'
],'Phase 70 governed report proposals');

$must('research-evolution.php',[
  'LONGITUDINAL RESEARCH INTELLIGENCE','Capture current state','RESEARCH CHANGE LEDGER','STATE HISTORY','Strengthening & weakening',
  'Open Questions Brief','Entity & Theme Evolution'
],'Phase 70 Evolution UI');
$must('api/research-longitudinal.php',[
  "\$action==='summary'","\$action==='snapshots'","\$action==='changes'","\$action==='compare'","\$action==='capture'",
  'METHOD_NOT_ALLOWED','require_api_mutation_auth'
],'Phase 70 API');
$must('research-agent-knowledge.php',['research-evolution.php','Evolution'],'Phase 70 Knowledge navigation');
$must('research-reports.php',['research-evolution.php','Evolution'],'Phase 70 Reports navigation');
$must('app/bootstrap.php',['research-longitudinal-intelligence.php'],'Phase 70 bootstrap');

$must('docs/phase-70-longitudinal-research-intelligence-synthesis.md',[
  'Authoritative Research state → Longitudinal snapshot → Change ledger','The first snapshot is a baseline',
  'Research Evolution Brief','What Changed Brief','Migration 084'
],'Phase 70 architecture');

$css=(string)file_get_contents($root.'/assets/css/app.css');$ext=(string)file_get_contents($root.'/extension/landing-app.css');
if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';
foreach(['Phase 70 — Longitudinal Research Intelligence & Synthesis','.researchEvolutionCanvas','.researchEvolutionLedgerRows','.researchEvolutionReports'] as $needle)if(!str_contains($css,$needle))$fail[]='Phase 70 CSS missing '.$needle;

$must('tests/ci/run-full-regression.sh',['tests/phase70-longitudinal-research-intelligence-db.php'],'Phase 70 regression gate');
$must('.github/workflows/full-regression.yml',['phase70-upgrade-from-083.php','phase70-longitudinal-research-intelligence-db.php'],'Phase 70 MySQL gate');
$must('.github/workflows/package-two-zips.yml',[
  '20260926_084_longitudinal_research_intelligence.sql','phase-70-longitudinal-research-intelligence-synthesis.md',
  'research-longitudinal-intelligence.php','api/research-longitudinal.php','research-evolution.php',
  'phase70-longitudinal-research-intelligence-contract.php','phase70-longitudinal-research-intelligence-db.php','phase70-upgrade-from-083.php'
],'Phase 70 production package');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 70 Longitudinal Research Intelligence & Synthesis static contracts passed.\n";
