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
$must('database/migrations/20260925_080_research_agent_knowledge_system_reports.sql',[
 'research_system_reports','research_system_report_events','input_state_hash','evidence_refs_json','document_object_id'
],'Phase 67 migration');
$must('database/migrations/20260926_081_phase67_system_report_provenance_hardening.sql',[
 'requested_by_user_id BIGINT UNSIGNED NULL','ON DELETE SET NULL','fk_system_reports_requester'
],'Phase 67 provenance hardening migration');
$must('app/research-system-reports.php',[
 'research_system_report_types','research_system_report_snapshot','research_system_report_generate','research_system_report_knowledge','research_system_report_coverage_counts','research_system_report_component_error','research_system_report_ref_bundle','research_system_report_extended_intelligence','research_system_report_review_context',
 "'research_brief'","'evidence_audit'","'claims_verification'","'contradictions_gaps'","'source_freshness'","'entity_map'","'timeline'","'action_plan'","'full_intelligence'",
 'research_retrieval_queue_project','state_hash','System Reports','coverage','diagnostics','claim_relations','entity_relations','extended_intelligence','beginTransaction','rollBack','research_system_report_nonfatal_event','retrieval_queue_failed','autonomy_queue_failed','provenance_truncated','provenance_total_unique','research_system_report_authoritative_corpus_hash'
],'Phase 67 reports runtime');
$avoid('app/research-system-reports.php',['cross_research_context(','research_outcome_context(','research_network_project_context('],'Persisted System Reports permission boundary');
$must('app/research-retrieval.php',[
 "'claim'","'finding'","'entity'","'claim_relation'","'entity_relation'","'task'","'program'","'report'",
 'research_retrieval_project_object_allowed',"'report'","research_claims","research_findings","research_entities","'input_hash'=>\$state['state_hash']??null"
],'Phase 67 unified retrieval');
$must('app/agent-chat.php',[
 "if(\$type==='claim'","if(\$type==='finding'","if(\$type==='entity'","if(\$type==='task'","if(\$type==='program'"
],'Phase 67 Agent structured context');
$must('app/agent-actions.php',[
 "'research.create_system_report'","research_system_report_generate","Run Research Report"
],'Phase 67 governed Agent report action');
$must('app/bootstrap.php',["research-system-reports.php"],'Phase 67 bootstrap');
$must('research-agent-knowledge.php',[
 'RESEARCH AGENT KNOWLEDGE','WHAT I KNOW','OPEN QUESTIONS','DISPUTED','KNOWLEDGE GRAPH','WHAT CHANGED','WORKING ON','REPORT RUNS'
],'Phase 67 Knowledge surface');
$must('research-reports.php',[
 'RESEARCH AGENT · REPORT STUDIO','Run Report','Recent Reports','Saved Presets','Create Document'
],'Phase 67 Reports surface');
$must('home.php',[
 'data-research-library-filter="claim"','data-research-library-filter="finding"','data-research-library-filter="entity"','data-research-library-filter="relation"','data-research-library-filter="report"',
 '/research-agent-knowledge.php?agent=','/research-reports.php?agent='
],'Phase 67 Research Agent canvas');
$must('research.php',['Knowledge','Reports','/research-agent-knowledge.php','/research-reports.php'],'Phase 67 Research Agents navigation');
$must('api/research-system-reports.php',["\$action==='types'","\$action==='knowledge'","\$action==='generate'","\$action==='archive'","METHOD_NOT_ALLOWED","require_api_mutation_auth","research_system_report_archive(\$pdo,\$viewer,(string)(\$input['report_id']??''),\$agent)"],'Phase 67 report API lineage');
$must('docs/phase-67-unified-research-knowledge-system-reports.md',[
 'One Research knowledge universe','Research Agent Knowledge view','Unified Research Library','System Reports','research.create_system_report','Desktop / Library / Knowledge responsibilities'
],'Phase 67 architecture');

$css=(string)file_get_contents($root.'/assets/css/app.css');$ext=(string)file_get_contents($root.'/extension/landing-app.css');
if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';
foreach(['Phase 67 — Unified Research Knowledge & System Reports','.researchKnowledgeGrid','.researchSystemReportTypes','.researchCanvasTopLink','Phase 68 — Research Agent Report Studio'] as $needle)if(!str_contains($css,$needle))$fail[]='Phase 67 CSS missing '.$needle;
if(is_file($root.'/worker/research-system-report-worker.php')||is_file($root.'/worker/research-knowledge-worker.php'))$fail[]='Phase 67 must not add a second report/knowledge worker.';
$must('tests/ci/run-full-regression.sh',['tests/phase67-unified-research-knowledge-system-reports-db.php'],'Phase 67 regression gate');
$must('.github/workflows/full-regression.yml',['phase67-upgrade-from-079.php','phase67-unified-research-knowledge-system-reports-db.php'],'Phase 67 MySQL gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260925_080_research_agent_knowledge_system_reports.sql','20260926_081_phase67_system_report_provenance_hardening.sql','phase-67-unified-research-knowledge-system-reports.md','research-system-reports.php','research-agent-knowledge.php','research-reports.php'
],'Phase 67 production package');
if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 67 Unified Research Knowledge & System Reports static contracts passed.\n";
