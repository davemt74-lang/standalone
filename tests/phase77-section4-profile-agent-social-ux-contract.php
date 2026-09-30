<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

$need('app/research-agents.php',"latest_story_public_id",'Profile Agent listing must expose live Story metadata.');
$need('app/research-agents.php',"research_agent_story_list(\$pdo,\$viewer,60)",'Profile Agent Story metadata must reuse canonical Story access.');
$need('profile.php','/research-agent-public.php?agent=','Profile Agent cards must open the public Agent experience.');
$need('profile.php','annotatedProfileRelationship','Profile must surface current social relationship state.');
$need('research-agent-public.php','research_agent_social_access($pdo,$viewerContext,$agentId)','Public Agent page must use canonical visibility access.');
$need('research-agent-public.php','research_agent_story_list($pdo,$viewer,60)','Public Agent page must reuse canonical Story visibility.');
$need('research-agent-public.php','research_agent_access($pdo,$viewer,$agentId)','Internal Agent controls must only appear after internal owner/team access succeeds.');
$need('research-agent-public.php','View latest Story','Public Agent page must hand off to the latest permitted Story.');
$need('research-agent-public.php','Log in to follow Stories','Signed-out public Agent viewers must get a safe Story CTA instead of private controls.');
$need('assets/css/profile-social.css','.annotatedProfileAgentIdentity','Rewritten profile must include responsive public Agent styling.');
if(is_file($root.'/assets/css/app.css')&&is_file($root.'/extension/landing-app.css')&&file_get_contents($root.'/assets/css/app.css')!==file_get_contents($root.'/extension/landing-app.css'))$fail[]='Website and extension landing CSS must remain byte-for-byte synchronized.';
$avoid('research-agent-public.php','conversation_messages','Public Agent experience must not expose private conversation content.');
$avoid('research-agent-public.php','project_sources','Public Agent experience must not expose private Research workspace content.');

if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 77 Section 4 Profile Agent Social UX contract passed.\n";
