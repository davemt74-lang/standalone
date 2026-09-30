<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};

$need('database/migrations/20260930_110_research_agent_story_intelligence.sql','intelligence_hash','78.4 must persist deterministic intelligence identity.');
$need('database/migrations/20260930_110_research_agent_story_intelligence.sql','parent_story_id','78.4 must persist follow-up lineage.');
$need('database/migrations/20260930_110_research_agent_story_intelligence.sql','why_it_matters','78.4 must persist why-this-matters context.');
$need('app/research-agent-stories.php','function research_agent_story_intelligence_ready','78.4 must fail safely on partial upgrades.');
$need('app/research-agent-stories.php','function research_agent_story_intelligence_hash','78.4 must compute deterministic Story identity.');
$need('app/research-agent-stories.php','function research_agent_story_intelligence_parent','78.4 must resolve related prior Stories.');
$need('app/research-agent-stories.php','function research_agent_story_intelligence_decision','78.4 must route proactive Stories through an intelligence decision.');
$need('app/research-agent-stories.php',"'reason'=>'duplicate'",'78.4 must suppress exact duplicates.');
$need('app/research-agent-stories.php',"'reason'=>\$parent?'follow_up':'new'",'78.4 must distinguish follow-up Stories from new Stories.');
$need('app/research-agent-stories.php','function research_agent_story_why_it_matters','78.4 must explain why a Story matters.');
$need('home.php','data-story-why','Home Story viewer must expose why-this-matters context.');
$need('home.php','data-story-follow-up','Home Story viewer must expose follow-up state.');
$need('assets/js/research-agent-stories.js','story.parent_story_id','Story viewer must render follow-up lineage.');
$need('assets/js/research-agent-stories.js','story.why_it_matters','Story viewer must render why-this-matters.');
$need('assets/css/app.css','/* Phase 78.4 — Agent Story Intelligence */','78.4 styling missing.');
if(file_get_contents($root.'/assets/css/app.css')!==file_get_contents($root.'/extension/landing-app.css'))$fail[]='Website and extension landing CSS must remain byte-for-byte synchronized.';
if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 78 Section 4 Agent Story Intelligence contract passed.\n";
