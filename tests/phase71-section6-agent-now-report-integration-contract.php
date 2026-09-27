<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p)){$fail[]=$label.' missing '.$file;return;}$c=(string)file_get_contents($p);foreach($needles as $n)if(!str_contains($c,$n))$fail[]=$label.' missing '.$n.' in '.$file;};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{$p=$root.'/'.$file;if(!is_file($p))return;$c=(string)file_get_contents($p);foreach($needles as $n)if(str_contains($c,$n))$fail[]=$label.' must not contain '.$n.' in '.$file;};

$must('app/research-decisions.php',[
 'research_decision_agent_context','research_decision_cognitive_observations','research_decision_report_snapshot',
 '[DECISION MEMORY]','Decision state is durable application state','Decision needs review:'
],'Phase 71 Section 6 Decision projections');
$must('app/agent-chat.php',[
 'research_decision_agent_context','Decision Memory context is read-only in Agent Chat','decisionAgentContext'
],'Agent Chat Decision Memory integration');
$must('app/cognitive-feed.php',[
 'research_decision_cognitive_observations'
],'Now / Cognitive Feed Decision integration');
$must('app/research-reports.php',[
 "'decisions'=>[]",'research_decision_report_snapshot',"'type'=>'decision'","'type'=>'decision_outcome'"
],'Report snapshot Decision integration');
$must('research-report.php',[
 'Decisions & Outcomes','Observed outcomes','Decision challenges','Reconsideration history'
],'Published report Decision rendering');
$must('docs/phase-71-research-decisions-conclusions-outcome-memory.md',[
 '## Section 6 — Agent / Now / Report Studio Integration','Agent Decision context is read-only',
 'public reports exclude unresolved/internal Decision review state','no new schema or background worker'
],'Phase 71 Section 6 architecture');
$avoid('app/agent-chat.php',[
 'research_decision_set_status(','research_decision_apply_reconsideration(','research_decision_open_reconsideration('
],'Agent Chat read-only Decision integration');
$avoid('app/cognitive-feed.php',[
 'research_decision_set_status(','research_decision_apply_reconsideration(','research_decision_open_reconsideration('
],'Now read-only Decision integration');
$must('tests/ci/run-full-regression.sh',['tests/phase71-section6-agent-now-report-integration-db.php'],'Phase 71 Section 6 regression gate');
$must('.github/workflows/package-two-zips.yml',[
 'phase71-section6-agent-now-report-integration-contract.php','phase71-section6-agent-now-report-integration-db.php'
],'Phase 71 Section 6 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 71 Section 6 Agent / Now / Report Studio contracts passed.\n";
