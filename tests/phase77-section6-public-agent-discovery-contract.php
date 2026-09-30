<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

$need('app/research-agents.php','function research_agent_discover_public','Phase 77.6 must add a canonical public Agent discovery runtime.');
$need('app/research-agents.php',"ra.visibility='public'",'Discovery must only return public Research Agents.');
$need('app/research-agents.php','NOT EXISTS(SELECT 1 FROM blocks','Discovery must exclude blocked owner relationships.');
$need('app/research-agents.php',"COALESCE(up.search_visibility,1)=1",'Discovery must respect owner search visibility.');
$need('app/research-agents.php',"COALESCE(up.profile_visibility,'public')='public'",'Discovery must respect owner profile visibility.');
$need('app/research-agents.php','research_agent_story_list($pdo,$viewer,80)','Signed-in Story metadata must reuse canonical Story access.');
$need('people.php','PUBLIC RESEARCH AGENTS','People discovery must expose public Research Agents.');
$need('people.php','/research-agent-public.php?agent=','People Agent cards must open the governed public Agent page.');
$need('explore.php','Agents to discover','Explore must surface public Research Agents.');
$need('explore.php','research_agent_discover_public($pdo,$viewer','Explore must reuse canonical public Agent discovery.');
$need('assets/css/app.css','/* Phase 77.6 — Social Discovery & Public Research Agent Discovery */','Phase 77.6 must include responsive Agent discovery styling.');
if(is_file($root.'/assets/css/app.css')&&is_file($root.'/extension/landing-app.css')&&file_get_contents($root.'/assets/css/app.css')!==file_get_contents($root.'/extension/landing-app.css'))$fail[]='Website and extension landing CSS must remain byte-for-byte synchronized.';

if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 77 Section 6 Public Agent Discovery contract passed.\n";
