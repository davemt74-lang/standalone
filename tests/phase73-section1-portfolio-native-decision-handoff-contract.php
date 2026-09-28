<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail): void{
  $path=$root.'/'.$file;if(!is_file($path)){$fail[]=$label.' missing '.$file;return;}
  $c=(string)file_get_contents($path);foreach($needles as $needle)if(!str_contains($c,$needle))$fail[]=$label.' missing '.$needle.' in '.$file;
};
$avoid=function(string $file,array $needles,string $label)use($root,&$fail): void{
  $path=$root.'/'.$file;if(!is_file($path))return;$c=(string)file_get_contents($path);foreach($needles as $needle)if(str_contains($c,$needle))$fail[]=$label.' must not contain '.$needle.' in '.$file;
};

$must('database/migrations/20260928_099_portfolio_native_decision_handoff.sql',[
 'MODIFY COLUMN outcome_id BIGINT UNSIGNED NULL','decision_id BIGINT UNSIGNED NULL','handoff_key CHAR(64)',
 'fk_intel_decision_native','chk_intel_portfolio_decision_target','chk_intel_portfolio_native_handoff_key'
],'Phase 73 Section 1 migration');
$must('app/research-intelligence-operations.php',[
 'research_intelligence_portfolio_native_decisions_ready','research_intelligence_portfolio_anchor_program',
 'research_intelligence_portfolio_create_native_decision','portfolio-native-decision|',
 'research_decision_create','native_decision_created','decision_portfolio_handoff',
 "['record_kind']='native'"
],'Native Portfolio Decision runtime');
$must('app/research-intelligence-operations.php',[
 'function research_intelligence_portfolio_record_decision','research_outcome_record'
],'Legacy Phase 61 compatibility helper');
$must('app/research-decisions.php',[
  '$type===\'portfolio\'','$type===\'portfolio_insight\'','$type===\'executive_briefing\''
],'Native Decision Portfolio provenance');
$must('api/research-intelligence-portfolios.php',[
 "research_intelligence_portfolio_create_native_decision"
],'Portfolio API native routing');
$avoid('api/research-intelligence-portfolios.php',[
  'research_intelligence_portfolio_record_decision($pdo,$viewer'
],'Portfolio API legacy routing');
$must('research-intelligence-portfolios.php',[
 'PHASE 73 NATIVE DECISION HANDOFF','Create Draft Decision','Decision statement',
 'research_intelligence_portfolio_create_native_decision','Open Decision','LEGACY PHASE 61'
],'Portfolio native Decision UI');
$avoid('research-intelligence-portfolios.php',[
 'Create existing Research follow-up task','follow_up_title'
],'Portfolio direct Task shortcut');
$must('docs/phase-73-portfolio-decision-intelligence-organizational-learning.md',[
 '# Phase 73 — Portfolio Decision Intelligence & Organizational Learning',
 '## Section 1 — Portfolio → Native Decision Handoff',
 'Historical Phase 61 records are preserved','Draft','Phase 72 Action Plan'
],'Phase 73 Section 1 architecture');
$must('tests/ci/run-static-contracts.sh',['phase73-section1-portfolio-native-decision-handoff-contract.php'],'Section 1 static gate');
$must('tests/ci/run-full-regression.sh',['phase73-section1-portfolio-native-decision-handoff-db.php'],'Section 1 DB gate');
$must('.github/workflows/full-regression.yml',['phase73-section1-upgrade-from-098.php'],'Section 1 upgrade gate');
$must('.github/workflows/package-two-zips.yml',[
 '20260928_099_portfolio_native_decision_handoff.sql','phase73-section1-portfolio-native-decision-handoff-contract.php',
 'phase73-section1-portfolio-native-decision-handoff-db.php','phase73-section1-upgrade-from-098.php'
],'Section 1 package gate');

if($fail){fwrite(STDERR,implode("\n",array_values(array_unique($fail)))."\n");exit(1);}
echo "Phase 73 Section 1 Portfolio → Native Decision Handoff contracts passed.\n";
