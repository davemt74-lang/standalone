<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};

$need('app/research-agent-stories.php','function research_agent_story_activity','78.5 must expose Story activity through the canonical Activity system.');
$need('app/research-agent-stories.php',"research_agent_story_list(\$pdo,\$viewer,\$limit,true)",'Story Activity must reuse canonical visibility and include dismissed-but-still-accessible Stories.');
$need('app/research-agent-stories.php',"'type'=>'research_agent_story_published'",'Story Activity must identify published Story events.');
$need('app/research-agent-stories.php',"'object'=>['type'=>'research_agent_story'",'Story Activity must retain governed Story object identity.');
$need('app/research-agent-stories.php',"'follow_up'=>!empty(\$story['parent_story_id'])?'yes':null",'Story Activity must expose follow-up lineage.');
$need('app/unified-activity.php',"function_exists('research_agent_story_activity')",'Unified Activity must consume Story activity without shadow persistence.');
$need('app/notifications.php',"if(\$type==='research_agent_story')return function_exists('research_agent_story_access')",'Story notifications must enforce canonical Story access.');
$need('app/notifications.php',"if(\$type==='research_agent_story')",'Story notifications must deep-link through the canonical Story route.');
$need('app/research-agent-stories.php','research_agent_story_notify_social','Published Stories must retain social notification delivery.');
$need('app/research-agent-stories.php','research_agent_story_social_recipients','Social delivery must retain canonical eligible-recipient resolution.');

if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 78 Section 5 Notifications, Activity & Social Integration contract passed.\n";
