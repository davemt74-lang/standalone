<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach(['research_agent_story_social_recipients','research_agent_story_relationship_changed'] as $fn)$need('app/research-agent-stories.php','function '.$fn,'Phase 77.3 missing social Story runtime: '.$fn);
$need('app/research-agent-stories.php',"JOIN follows f ON f.follower_user_id=u.id AND f.followed_user_id=?",'Public Story delivery must target followers of the Agent owner.');
$need('app/research-agent-stories.php',"if(\$visibility==='friends')",'Friends-only Story delivery must narrow recipients to mutual relationships.');
$need('app/research-agent-stories.php','NOT EXISTS(SELECT 1 FROM blocks','Story delivery must exclude blocked relationships.');
$need('app/research-agent-stories.php','research_agent_story_access($pdo,$viewer','Relationship invalidation must re-check canonical Story access.');
$need('app/research-agent-stories.php',"object_type='research_agent_story'",'Relationship invalidation must target only Research Agent Story notifications.');
$need('app/research-agent-stories.php','archived_at=COALESCE(archived_at,NOW())','Inaccessible Story notifications must be archived immediately.');
$need('app/profile-network.php','research_agent_story_relationship_changed($pdo,$viewer,$targetId)','Follow/unfollow mutations must invalidate stale Story notifications immediately.');
$need('app/notifications.php',"if(\$type==='research_agent_story')return function_exists('research_agent_story_access')",'Notification reads must continue enforcing canonical Story access.');

if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 77 Section 3 Social Story Delivery contract passed.\n";
