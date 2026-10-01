<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(is_file($p)&&str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};
$m='database/migrations/20261001_116_research_agent_stories_toggle.sql';
$need($m,'stories_enabled TINYINT(1) NOT NULL DEFAULT 1','Migration 116 must add a default-on Stories master switch.');
$need('app/research-agent-stories.php',"'stories_enabled'=>1",'Story policy defaults must preserve Stories for existing Agents.');
$need('app/research-agent-stories.php','function research_agent_stories_enabled','Stories must expose one canonical enabled check.');
$need('app/research-agent-stories.php',"'reason'=>'stories_disabled'",'Proactive Stories must be suppressed while the Agent switch is off.');
$need('app/research-agent-stories.php','COALESCE(sp.stories_enabled,1)=1','Canonical Story reads must filter disabled Agents while preserving pre-policy defaults.');
$need('app/research-agent-stories.php','Stories are turned off for this Research Agent.','Manual publishing must honor the master switch.');
$need('research-agent-edit.php','name="stories_enabled"','Research Agent Edit must expose the Stories on/off toggle.');
$need('home.php','elseif($agentStoryGroups)','Home must render the Stories section only when active Story groups exist.');
$avoid('home.php','homeAgentStoryCard emptyStory','Home must not display a placeholder Stories rail when there are no active Stories.');
if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Research Agent Stories master-toggle contract passed.\n";
