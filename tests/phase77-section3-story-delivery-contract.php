<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach(['research_agent_story_social_recipients','research_agent_story_reconcile_social_delivery'] as $fn)$need('app/research-agent-stories.php','function '.$fn,'Phase 77.3 missing Story delivery runtime: '.$fn);
$need('app/research-agent-stories.php',"JOIN follows f ON f.follower_user_id=u.id AND f.followed_user_id=?",'Public Story recipients must come from the live follower graph.');
$need('app/research-agent-stories.php',"if($visibility==='friends')",'Friends-only Story recipients must require mutual follow state.');
$need('app/research-agent-stories.php','NOT EXISTS(SELECT 1 FROM blocks','Story recipients must enforce block boundaries.');
$need('app/research-agent-stories.php',"n.object_type='research_agent_story'",'Delivery reconciliation must target persisted Story notifications only.');
$need('app/research-agent-stories.php','research_agent_story_access($pdo,$viewer','Persisted Story notifications must be revalidated against current access.');
$need('app/research-agent-stories.php','archived_at=COALESCE(archived_at,NOW())','Inaccessible Story notifications must be archived instead of remaining unread.');
$need('app/research-agent-stories.php','research_agent_story_list($pdo,$viewer,60)','Delivery reconciliation must report current live Story availability.');
$need('app/research-agent-stories.php','foreach(research_agent_story_social_recipients($pdo,$agent) as $uid)','Story notification publishing must reuse canonical recipient resolution.');
$need('app/profile-network.php','research_agent_story_reconcile_social_delivery($pdo,$viewer,$targetId)','Follow mutations must reconcile Story delivery for the acting viewer.');
$need('app/profile-network.php','research_agent_story_reconcile_social_delivery($pdo,$targetViewer,$viewerId)','Follow mutations must reconcile mutual friends-only delivery for the other user too.');
$need('app/profile-network.php',"$state['story_delivery']=",'Follow API state must expose current Story delivery summary.');

if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 77 Section 3 Social Story Delivery contract passed.\n";
