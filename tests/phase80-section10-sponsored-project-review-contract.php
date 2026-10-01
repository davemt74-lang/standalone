<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $msg)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$msg;};
$m='database/migrations/20261001_125_phase80_sponsored_project_review_revision_completion.sql';
foreach(['supersedes_submission_id','revision_number','review_note','reviewed_by_user_id','completed_submission_id'] as $n)$need($m,$n,'Migration 125 missing '.$n);
foreach(['function sponsored_project_revision_chain','function sponsored_project_agent_context','Revision instructions are required.','project_submission_resubmitted','completed_submission_id'] as $n)$need('app/sponsored-research-projects.php',$n,'Section 10 runtime missing '.$n);
$need('research-sponsored-projects.php','Revision required','Researcher revision-required state is missing.');
$need('research-sponsored-projects.php','supersedes_submission_id','Researcher resubmission lineage is missing.');
$need('admin/sponsored-projects.php','Review note / revision instructions','Admin structured review note UI is missing.');
$need('admin/sponsored-projects.php','Decision recorded. This submission is immutable.','Admin final-decision immutability UI is missing.');
$need('app/notifications.php',"'sponsored_project_submission'",'Sponsored submission notification routing is missing.');
$need('app/research-agents.php',"'sponsored_project_context'",'Research Agent sponsored assignment context is missing.');
if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Phase 80 Section 10 Sponsored Project review/revision/completion contract passed.\n";
