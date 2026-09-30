<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};
$need('app/research-agent-stories.php','function research_agent_story_update_manual','Story drafts must be editable.');
$need('app/research-agent-stories.php','function research_agent_story_archive_manual','Story drafts must be archivable.');
$need('app/research-agent-stories.php','function research_agent_story_publish_due','Scheduled Stories must have a publish-due runtime.');
$need('app/research-agent-stories.php','scheduled_at<=NOW()','Scheduled publishing must only process due drafts.');
$need('research-agent-edit.php','name="scheduled_at"','Agent Edit must expose Story scheduling.');
$need('research-agent-edit.php','name="op" value="update_story"','Agent Edit must expose Story editing.');
$need('research-agent-edit.php','name="op" value="archive_story"','Agent Edit must expose Story archive.');
$need('database/migrations/20260930_109_research_agent_story_composer.sql','edited_by_user_id','Story edit provenance must be persisted.');
$need('database/migrations/20260930_109_research_agent_story_composer.sql','archived_at','Story archive time must be persisted.');
$need('assets/css/app.css','/* Phase 78.3 — Story Composer, Editing & Scheduling */','Phase 78.3 styling missing.');
if(file_get_contents($root.'/assets/css/app.css')!==file_get_contents($root.'/extension/landing-app.css'))$fail[]='Website and extension landing CSS must remain byte-for-byte synchronized.';
if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Phase 78 Section 3 Story Composer contract passed.\n";