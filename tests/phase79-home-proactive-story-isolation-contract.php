<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$home=(string)file_get_contents($root.'/home.php');
$stories=(string)file_get_contents($root.'/app/research-agent-stories.php');

$proactiveStart=strpos($home,'try{\n    $proactiveReady=proactive_intelligence_ready($pdo);');
$storySync=strpos($home,"if(function_exists('research_agent_story_sync'))");
$proactiveCatch=strpos($home,"$recordHomeIncident('proactive'",(int)$proactiveStart);
if($proactiveStart===false||$proactiveCatch===false)$fail[]='Home proactive runtime block not found.';
if($storySync===false)$fail[]='Home Story runtime not found.';
if($proactiveStart!==false&&$proactiveCatch!==false&&$storySync>$proactiveStart&&$storySync<$proactiveCatch)$fail[]='Story runtime must not execute inside the proactive failure domain.';
if(!str_contains($home,"function_exists('research_agent_stories_ready')&&research_agent_stories_ready($pdo)"))$fail[]='Home must gate Story runtime on full Story readiness.';
if(str_contains($home,"$recordHomeIncident('stories'"))$fail[]='Optional Story failures must not place Home into reduced mode.';
foreach(['generation_status','status','published_at','expires_at','story_id','user_id','viewed_at','dismissed_at'] as $column){
    if(!str_contains($stories,"'".$column."'"))$fail[]='Story readiness must verify required column '.$column;
}
if(!str_contains($stories,'catch(Throwable $e){continue;}'))$fail[]='Story sync must isolate per-item generation failures.';
if(!str_contains($stories,"return ['ready'=>false,'created'=>0];"))$fail[]='Story sync must fail closed when its runtime is unavailable.';

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Home proactive/Story failure-domain isolation contract passed.\n";
