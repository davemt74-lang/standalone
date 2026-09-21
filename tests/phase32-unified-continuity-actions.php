<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function p32(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);echo "PASS: $message\n";}

$team=(string)file_get_contents($root.'/assets/js/team-chat.js');
$cards=(string)file_get_contents($root.'/assets/js/annotation-cards.js');
$agent=(string)file_get_contents($root.'/assets/js/agent-chat.js');
$agentServer=(string)file_get_contents($root.'/app/agent-chat.php');
$home=(string)file_get_contents($root.'/home.php');
$ui=(string)file_get_contents($root.'/app/annotation-ui.php');
$settings=(string)file_get_contents($root.'/settings.php');
$manifest=json_decode((string)file_get_contents($root.'/extension/manifest.json'),true,512,JSON_THROW_ON_ERROR);

p32(str_contains($team,"annotated:object-add-research")&&str_contains($team,"annotated:object-ask-agent"),'Team object cards expose Research and Agent continuation events');
p32(str_contains($team,"if(item.available===false)")&&str_contains($team,"return wrap;"),'unavailable Team object tombstones return before continuity actions are built');
p32(str_contains($team,"const agentEnabled=rail.dataset.agentEnabled==='1'"),'Team Agent action is gated by server-rendered Agent capability state');
p32(str_contains($cards,"annotated:object-add-research")&&str_contains($cards,'annotatedOpenResearch(id,null)'), 'shared Annotation runtime consumes Team-to-Research handoff');
p32(str_contains($cards,"annotated:object-ask-agent")&&str_contains($cards,'annotatedAskAgent(id)'), 'shared Annotation runtime consumes Team-to-Agent handoff');
p32(str_contains($agent,'function attachmentUrl')&&str_contains($agent,"annotation:'/annotation.php?id='")&&str_contains($agent,"research:'/research-project.php?id='"),'Agent context chips map supported references back to authoritative objects');
p32(str_contains($agent,"const chip=document.createElement(href?'a':'span')"),'Agent only emits an object link when a deterministic destination exists');
p32(str_contains($agentServer,"is_blocked($pdo,(int)$viewer['id'],(int)$a['user_id'])"),'Agent Annotation context preserves the app user-block boundary server-side');
p32(str_contains($home,'data-agent-enabled=')&&str_contains($home,'user_is_pro($pdo,$u)'), 'Home renders current Agent capability into Team Chat without trusting client plan state');
p32(str_contains($ui,'annotation-cards.js?v=32.0')&&str_contains($settings,'annotation-cards.js?v=32.0'),'website Annotation surfaces load the Phase 32 continuity runtime');
p32(($manifest['version']??'')==='0.32.0','Chrome extension version is v0.32.0');
p32(!str_contains($team,'INSERT INTO ')&&!str_contains($cards,'INSERT INTO '),'continuity action JavaScript does not introduce a persistence path');

echo "Phase 32 Unified Continuity Actions & Object Cards contract suite passed.\n";
