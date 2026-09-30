<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach(['research_agent_social_visibility_sql','research_agent_social_visibility_params','research_agent_social_access','research_agent_profile_list'] as $fn)$need('app/research-agents.php','function '.$fn,'Phase 77.2 missing canonical visibility runtime: '.$fn);
$need('app/research-agents.php',"ra.visibility='public'",'Canonical visibility must support public Research Agents.');
$need('app/research-agents.php',"ra.visibility='friends'",'Canonical visibility must support friends-only Research Agents.');
$need('app/research-agents.php','raf1.follower_user_id=:social_friend1','Friends visibility must require viewer → owner follow.');
$need('app/research-agents.php','raf2.follower_user_id=ra.owner_user_id','Friends visibility must require owner → viewer follow.');
$need('app/research-agents.php','NOT EXISTS(','Canonical visibility must include block exclusion.');
$need('app/research-agents.php',"ra.visibility='public'\n          ORDER BY",'Signed-out profile viewers must only see public Research Agents.');
$need('app/research-agents.php',"((ra.team_id IS NULL AND ra.owner_user_id=?) OR (ra.team_id IS NOT NULL AND tm.user_id=?))",'Internal Research Agent access must remain owner/team-only.');
$need('app/research-agent-stories.php','return research_agent_social_visibility_sql(true);','Stories must reuse canonical Agent visibility with follower delivery for public Agents.');
$need('app/research-agent-stories.php','return research_agent_social_visibility_params($viewer);','Stories must reuse canonical Agent visibility parameters.');
$need('profile.php','research_agent_profile_list($pdo,(int)$p[\'id\'],null,30)','Main profiles must use the canonical public-only Research Agent listing.');
$need('profile.php','profileAgentShowcaseGrid','Profile Research tab must render visible Research Agents.');
$avoid('profile.php','SELECT ra.','Profile UI must not duplicate Research Agent visibility SQL.');

if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 77 Section 2 Research Agent Visibility contract passed.\n";
