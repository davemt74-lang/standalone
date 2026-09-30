<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};

$need('database/migrations/20260930_111_research_agent_story_approval.sql',"ENUM('manual','proactive')",'78.6 must persist Story origin.');
$need('database/migrations/20260930_111_research_agent_story_approval.sql',"ENUM('not_required','pending','approved','rejected')",'78.6 must persist approval lifecycle.');
$need('database/migrations/20260930_111_research_agent_story_approval.sql','reviewed_by_user_id','78.6 must persist reviewer identity.');
$need('database/migrations/20260930_111_research_agent_story_approval.sql','review_note','78.6 must persist review notes.');
$need('app/research-agent-stories.php','function research_agent_story_review_ready','78.6 must fail safely on partial upgrades.');
$need('app/research-agent-stories.php','function research_agent_story_review_manual','78.6 must expose canonical approval/rejection runtime.');
$need('app/research-agent-stories.php','requires approval before publishing','Pending proactive Stories must not bypass approval.');
$need('app/research-agent-stories.php',"approval_state='pending'",'Rejected proactive Stories must return to pending after revision.');
$need('research-agent-edit.php','name="op" value="review_story"','Agent Edit must expose Story review.');
$need('research-agent-edit.php','Approve & publish','Agent Edit must expose explicit approval.');
$need('research-agent-edit.php','value="reject"','Agent Edit must expose explicit rejection.');
$need('research-agent-edit.php','name="review_note"','Agent Edit must expose review notes.');
$need('assets/css/app.css','/* Phase 78.6 — Story Approval & Operational Lifecycle */','78.6 styling missing.');
if(file_get_contents($root.'/assets/css/app.css')!==file_get_contents($root.'/extension/landing-app.css'))$fail[]='Website and extension landing CSS must remain byte-for-byte synchronized.';
if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 78 Section 6 Story Approval & Operational Lifecycle contract passed.\n";
