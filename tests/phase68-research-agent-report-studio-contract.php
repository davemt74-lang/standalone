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

$must('database/migrations/20260926_082_research_agent_report_studio.sql',[
  'research_report_presets','rendered_html','rendered_summary','parameters_json','scope_json','sections_json','knowledge_manifest_json',
  'freshness_state','refreshed_from_report_id','preset_id','document_created_at','generation_mode','ON DELETE SET NULL'
],'Phase 68 migration');

$must('app/research-report-studio.php',[
  'research_report_studio_ready','research_report_studio_options','research_report_studio_scope_snapshot','research_report_studio_sections',
  'research_report_studio_manifest','research_report_studio_compare_manifests','research_report_studio_freshness',
  'research_report_studio_create_document','research_report_studio_refresh','research_report_studio_preset_save','research_report_studio_run_preset',
  'research_report_studio_scope_choices','research_report_studio_previous_run'
],'Phase 68 runtime');

$must('app/research-system-reports.php',[
  "'default_depth'","'scope'","'sections'","document_object_id","rendered_html","knowledge_manifest_json","research_system_report_authoritative_corpus_hash",
  "A Research Report Run was generated.","document_public_id'=>null"
],'Phase 68 report-run persistence');
$avoid('app/research-system-reports.php',[
  "research_agent_workspace_post_document_to_chat"
],'Phase 68 report generation must not auto-create/post a document');

$must('app/research-retrieval.php',[
  "research_retrieval_record('report'","d.object_type='report'","'report'=>","source_report_id"
],'Phase 68 first-class report retrieval');

$must('app/agent-chat.php',[
  "if(\$type==='report'","[REPORT RUN"
],'Phase 68 Agent report context');

$must('app/object-handoff.php',[
  "'report'=>'Research Report Run'","if(\$type==='report'","Review this Research Report Run"
],'Phase 68 unified object handoff');

$must('app/agent-actions.php',[
  "'research.create_system_report'","'research.create_document_from_report'","Run Research Report","Create document from Report Run",
  "agent_action_project_map","\$type==='report'"
],'Phase 68 governed Agent actions');

$must('api/research-system-reports.php',[
  "'generate','refresh','create_document','archive','save_preset','archive_preset','run_preset'","METHOD_NOT_ALLOWED","require_api_mutation_auth",
  "\$action==='compare'","\$action==='presets'"
],'Phase 68 API');

$must('research-reports.php',[
  'RESEARCH AGENT · REPORT STUDIO','Run Report','Recent Reports','Saved Presets','REPORT RUN','Create Document','Ask Agent','Refresh','WHAT CHANGED',
  'Research Program'
],'Phase 68 per-Agent UI');

$must('research-agent-knowledge.php',[
  'REPORT RUNS','Open Report Studio','Report only','Open Report Run'
],'Phase 68 Knowledge integration');

$must('app/bootstrap.php',[
  'research-system-reports.php','research-report-studio.php'
],'Phase 68 bootstrap');

$must('docs/phase-68-research-agent-report-studio.md',[
  'System Report Definition','Report Run','Research Document','per-Research-Agent','Create Document','Saved presets','Migration 082'
],'Phase 68 architecture');

$css=(string)file_get_contents($root.'/assets/css/app.css');$ext=(string)file_get_contents($root.'/extension/landing-app.css');
if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';
foreach(['Phase 68 — Research Agent Report Studio','.reportStudioTabs','.reportRunViewer','.reportStudioSections','.reportCompareGrid'] as $needle)if(!str_contains($css,$needle))$fail[]='Phase 68 CSS missing '.$needle;

if(is_file($root.'/worker/research-report-studio-worker.php'))$fail[]='Phase 68 must not add a new Report Studio worker.';
if(is_file($root.'/worker/research-system-report-worker.php'))$fail[]='Phase 68 must not add a second System Report worker.';
$programWorker=(string)file_get_contents($root.'/worker/research-program-worker.php');
if(str_contains($programWorker,'research_report_studio_run_preset'))$fail[]='Phase 68 must not silently add Report preset scheduling authority to the Research Program worker.';

$must('tests/ci/run-full-regression.sh',['tests/phase68-research-agent-report-studio-db.php'],'Phase 68 regression gate');
$must('.github/workflows/full-regression.yml',['phase68-upgrade-from-081.php','phase68-research-agent-report-studio-db.php'],'Phase 68 MySQL gate');
$must('.github/workflows/package-two-zips.yml',[
  '20260926_082_research_agent_report_studio.sql','phase-68-research-agent-report-studio.md','research-report-studio.php',
  'phase68-research-agent-report-studio-contract.php','phase68-research-agent-report-studio-db.php','phase68-upgrade-from-081.php'
],'Phase 68 production package');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 68 Research Agent Report Studio static contracts passed.\n";
