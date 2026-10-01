<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};
$m='database/migrations/20261001_120_phase80_sponsored_research_review.sql';
foreach(['sponsored_research_review_cases','sponsored_research_review_assignments','sponsored_research_review_responses','sponsored_research_disputes','sponsored_research_review_events','blind_review','criteria_hash','compensation_eligible'] as $n)$need($m,$n,'Migration 120 missing '.$n);
foreach(['function sponsored_research_review_open','function sponsored_research_review_assign','function sponsored_research_review_assignment_for','function sponsored_research_review_respond','function sponsored_research_review_decide','function sponsored_research_dispute_open','function sponsored_research_dispute_resolve'] as $n)$need('app/sponsored-research-reviews.php',$n,'Sponsored review runtime missing '.$n);
$need('app/sponsored-research-reviews.php',"'Anonymous Researcher'",'Blind review must mask researcher identity.');
$need('app/sponsored-research-reviews.php',"unset($snapshot['researcher_user_id']",'Blind review must redact researcher identity from frozen snapshot.');
$need('app/sponsored-research-reviews.php',"status='disputed',compensation_eligible=0",'Dispute must place accepted compensation eligibility on hold.');
$need('app/sponsored-research-reviews.php','All assigned reviewers must respond or be recused before a final decision.','Final decision must not bypass assigned reviewers.');
$need('app/bootstrap.php',"'/sponsored-research-reviews.php'",'Sponsored review runtime must load through bootstrap.');
$need('sponsored-review.php','Quality criteria','Assigned reviewer workspace is missing.');
$need('sponsored-research.php','Review cases','Sponsor review queue is missing.');
$need('research-opportunities.php','Open dispute','Researcher dispute action is missing.');
$need('admin-sponsored-research-disputes.php','Sponsored Research Disputes','Admin dispute workspace is missing.');
$need('app/research-surface-map.php',"'app/sponsored-research-reviews.php'=>'governance'",'Sponsored review engine must be registered as Research governance.');
if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Phase 80 Section 5 Review, Acceptance, Revision & Disputes contract passed.\n";
