<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$dsn=(string)getenv('DB_DSN');$dbUser=(string)getenv('DB_USER');$dbPass=(string)getenv('DB_PASS');if($dsn==='')throw new RuntimeException('DB_DSN is required.');
$pdo=new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
require_once $root.'/app/installer.php';
foreach(['storage','jobs','concurrency','functions','shell','access','notifications','rate-limit','ai','ai-access','source-integrity','annotation-intelligence','research-workspace','research-knowledge','research-intelligence','research-reports','conversations','agent-actions','agent-chat','cognitive-feed','research-entities','proactive-intelligence','research-automation','research-agent-workspace','research-agents','research-retrieval','workspace-context','object-handoff','research-autonomy','research-monitoring','research-tasks','research-programs','cross-research','research-outcomes','research-decisions','research-action-plans','research-action-plan-variance','research-action-plan-cognition','research-action-plan-outcomes','research-reviews','change-impact','research-portfolio','living-research','research-publishing','research-intelligence-portfolios','research-intelligence-operations','research-missions','research-intelligence-decision-rollups','research-intelligence-pattern-memory','research-intelligence-strategic-graph','research-intelligence-strategic-reviews','research-intelligence-strategic-briefings','research-intelligence-organizational-cognition'] as $lib)require_once $root.'/app/'.$lib.'.php';
$fail=[];
if(glob($root.'/database/migrations/*_104_*.sql'))$fail[]='Section 7 unexpectedly added migration 104.';
if(!installer_table_exists($pdo,'research_intelligence_strategic_briefings'))$fail[]='Migration 103 Section 6 baseline is missing.';
foreach(['research_intelligence_organizational_cognition','organizational_agent_cognition','research_intelligence_cognition_proposals'] as $table)if(installer_table_exists($pdo,$table))$fail[]='Section 7 unexpectedly added parallel cognition table '.$table.'.';
if(!research_intelligence_organizational_cognition_ready($pdo))$fail[]='Section 7 cognition is not ready on the unchanged migration 103 schema.';
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}
echo "PASS: Phase 73 Section 7 runs on migration 103 with no migration 104 and no parallel cognition/proposal tables.\n";
