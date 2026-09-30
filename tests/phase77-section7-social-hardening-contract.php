<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach([
  'tests/phase77-section1-follow-reliability-contract.php',
  'tests/phase77-section2-agent-visibility-contract.php',
  'tests/phase77-section3-social-story-delivery-contract.php',
  'tests/phase77-section4-profile-agent-social-ux-contract.php',
  'tests/phase77-section5-follow-lists-social-graph-contract.php',
  'tests/phase77-section6-public-agent-discovery-contract.php'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Missing prior Phase 77 contract: '.$file;

$need('app/research-agents.php',"function_exists('research_agent_stories_ready')&&research_agent_stories_ready($pdo)",'Public Agent discovery must tolerate pre-Stories upgrade states.');
$need('app/research-agents.php','0 published_story_count','Public Agent discovery must provide a safe Story-count fallback.');
$need('app/research-agent-stories.php',"ra.status<>'archived'",'Exact Story access must reject archived Research Agents.');
$need('app/research-agent-stories.php','(s.expires_at IS NULL OR s.expires_at>NOW())','Exact Story access must reject expired Stories.');
$need('app/research-agent-stories.php',"JOIN users u ON u.id=ra.owner_user_id AND u.status='active'",'Story reads must reject inactive Agent owners.');
$need('app/notifications.php',"if($type==='research_agent_story')return function_exists('research_agent_story_access')",'Notification access must route through exact Story access.');
$need('api/profile-follow.php','profile_network_set_follow','Follow API must retain canonical idempotent relationship mutation.');
$need('profile-connections.php','data-connection-follow','Follow-list interaction must remain available.');
$need('people.php','PUBLIC RESEARCH AGENTS','Public Research Agent discovery must remain exposed.');
$need('research-agent-public.php','research_agent_social_access($pdo,$viewerContext,$agentId)','Public Agent deep links must retain canonical Agent access.');
if(is_file($root.'/assets/css/app.css')&&is_file($root.'/extension/landing-app.css')&&file_get_contents($root.'/assets/css/app.css')!==file_get_contents($root.'/extension/landing-app.css'))$fail[]='Website and extension landing CSS must remain byte-for-byte synchronized.';

if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 77 Section 7 End-to-End Social Graph Hardening contract passed.\n";
