<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function p35c(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}

$runtime=(string)file_get_contents($root.'/app/action-center.php');
$cognitive=(string)file_get_contents($root.'/app/cognitive-feed.php');
$page=(string)file_get_contents($root.'/action-center.php');
$web=(string)file_get_contents($root.'/assets/js/action-center.js');
$chrome=(string)file_get_contents($root.'/extension/sidepanel-feed.js');
$html=(string)file_get_contents($root.'/extension/sidepanel.html');

foreach(['confirm','respond','review','continue'] as $kind)p35c(str_contains($runtime,"'$kind'"),"Action Center exposes $kind routing");
foreach(['pending_agent_action','team_activity','review_requested','source_change','research_gap','research_conflict','change_impact','research_task'] as $type)p35c(str_contains($runtime,"'$type'"),"Action Center recognizes actionable type: $type");
p35c(str_contains($cognitive,'function cognitive_feed_items'),'Phase 35 reuses one sorted Cognitive observation source rather than duplicating collectors');
p35c(str_contains($runtime,'cognitive_feed_items')&&str_contains($runtime,'research_workflow_state'),'Action Center composes existing Cognitive and Research lifecycle intelligence');
foreach(['INSERT INTO ','UPDATE ','DELETE FROM ','CREATE TABLE'] as $forbidden)p35c(!str_contains($runtime,$forbidden),"Action Center creates no shadow action persistence: $forbidden");
foreach(['openai','anthropic','gemini','ai_generate','llm'] as $forbidden)p35c(!str_contains(strtolower($runtime),strtolower($forbidden)),"Action Center adds no AI ranking layer: $forbidden");
p35c(str_contains($runtime,'cognitive_feed_dismissed_keys')===false&&str_contains($runtime,'cognitive_feed_items($pdo,$viewer,$teamList,false)'),'Action Center inherits existing Cognitive dismissals instead of creating another hide state');
p35c(str_contains($page,'What needs your attention')&&str_contains($page,'data-action-center-link')&&str_contains($page,'data-action-center-agent'),'website Action Center exposes direct and Agent routes');
p35c(str_contains($web,'AnnotatedWorkspaceState')&&str_contains($web,'annotated.pendingAgentHandoff'),'website action routing preserves Phase 34 workspace continuity and Agent handoff');
p35c(str_contains($html,'actionCenterMiniFeed')&&str_contains($html,'openActionCenter'),'Chrome reuses Activity for a compact Action Center instead of adding another tab');
p35c(str_contains($chrome,'phase35LoadActions')&&str_contains($chrome,'/api/action-center.php?limit=60')&&str_contains($chrome,'.slice(0,8)'),'Chrome action queue uses the shared Action Center API');
p35c(str_contains((string)file_get_contents($root.'/home.php'),'/action-center.php'),'Home exposes the unified Action Center');
p35c(!is_file($root.'/database/migrations/20260921_036_action_center.sql'),'Phase 35 adds no Action Center database migration');

echo "Phase 35 Unified Action Center & Attention Routing contract suite passed.\n";
