<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=function(string $file,array $needles,string $label)use($root,&$fail){if(!is_file($root.'/'.$file)){$fail[]="$label file missing: $file";return;}$body=(string)file_get_contents($root.'/'.$file);foreach($needles as $needle)if(!str_contains($body,$needle))$fail[]="$label contract missing in $file: $needle";};
$must('database/migrations/20260923_059_portfolio_intelligence_operations_follow_through.sql',[
 'briefing_policy','materiality_threshold','next_cycle_at','research_intelligence_portfolio_cycles','research_intelligence_portfolio_subscriptions',
 'research_intelligence_briefing_receipts','research_intelligence_portfolio_decision_links','research_intelligence_portfolio_feedback'
],'Phase 61 migration');
$must('app/research-intelligence-operations.php',[
 'research_intelligence_portfolio_schedule_next','research_intelligence_portfolio_material_hash','research_intelligence_portfolio_process_due',
 'research_intelligence_portfolio_subscription_set','research_intelligence_portfolio_notify_briefing','research_intelligence_portfolio_acknowledge',
 'research_intelligence_portfolio_record_decision','research_outcome_record','research_task_create_for_project',
 'research_intelligence_portfolio_feedback_set','research_intelligence_organization_command_center','research_intelligence_portfolio_operations_cognitive_observations'
],'Phase 61 runtime');
$ops=(string)file_get_contents($root.'/app/research-intelligence-operations.php');
foreach(['ai_run(','agent_action_confirm_execute(','agent_action_execute_capability('] as $forbidden)if(str_contains($ops,$forbidden))$fail[]="Phase 61 operations must not add hidden AI/action execution: $forbidden";
$must('app/research-intelligence-portfolios.php',['New since last briefing','snapshot_public_id','research_intelligence_portfolio_publication_recipient_ids'],'Phase 61 briefing continuity');
$must('bin/research-automations.php',['research_intelligence_portfolio_process_due','portfolio_cycles','portfolio_briefings'],'Phase 61 existing scheduler reuse');
$must('app/research-publishing.php',['research_intelligence_portfolio_publication_distributed'],'Phase 61 Phase 59 distribution bridge');
$must('app/research-outcomes.php',["$type==='portfolio'","$type==='portfolio_insight'","$type==='executive_briefing'"],'Phase 61 Decision Memory provenance');
$must('app/notifications.php',["$type==='research_intelligence_portfolio'","research-intelligence-portfolios.php?portfolio="],'Phase 61 notification access');
$must('api/research-intelligence-portfolios.php',['command_center','subscription_set','acknowledge','record_decision','feedback_set'],'Phase 61 API');
$must('research-intelligence-portfolios.php',['PHASE 61 · PORTFOLIO INTELLIGENCE OPERATIONS','61A · SCHEDULED INTELLIGENCE','61C · EXECUTIVE SUBSCRIPTION','61D · DECISIONS & FOLLOW-THROUGH','61E · EXPLICIT LEARNING','Acknowledge'],'Phase 61 Portfolio UI');
$must('research-intelligence-command-center.php',['PHASE 61 · ORGANIZATION COMMAND CENTER','NEEDS ATTENTION','NEW SINCE LAST BRIEFING','DECISIONS AWAITING FOLLOW-THROUGH','EMERGING OPPORTUNITIES','CROSS-PORTFOLIO THEMES','BRIEFINGS AWAITING REVIEW'],'Phase 61 command center');
$must('app/cognitive-feed.php',['research_intelligence_portfolio_operations_cognitive_observations'],'Phase 61 Now integration');
$must('docs/phase-61-portfolio-intelligence-operations-follow-through.md',['61A — Scheduled Portfolio Intelligence','61B — Executive Briefing Automation','61C — Executive Subscriptions & Distribution','61D — Decisions & Follow-Through','61E — Portfolio Learning Loop','61F — Organization Intelligence Command Center'],'Phase 61 architecture');
if(is_file($root.'/worker/research-intelligence-portfolio-worker.php')||is_file($root.'/worker/portfolio-intelligence-worker.php'))$fail[]='Phase 61 must not add a Portfolio worker.';
$css=(string)file_get_contents($root.'/assets/css/app.css');$ext=(string)file_get_contents($root.'/extension/landing-app.css');if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension landing CSS must remain byte-identical.';
foreach(['.intelligenceOperationsPanel','.intelligenceCommandCenter','.commandCenterRow','@media(max-width:720px)'] as $needle)if(!str_contains($css,$needle))$fail[]="Phase 61 responsive CSS contract missing: $needle";
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}echo "Phase 61 Portfolio Intelligence Operations & Executive Follow-Through static contracts passed.\n";
