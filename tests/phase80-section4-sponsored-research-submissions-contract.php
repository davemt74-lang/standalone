<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};
$m='database/migrations/20261001_119_phase80_sponsored_research_submissions.sql';
foreach(['sponsored_research_submissions','sponsored_research_submission_versions','sponsored_research_submission_assets','sponsored_research_submission_events','participation_acceptance_id','snapshot_hash','source_rights_snapshot_hash','data_contribution_id'] as $n)$need($m,$n,'Migration 119 missing '.$n);
foreach(['function sponsored_research_submission_draft','function sponsored_research_submission_submit','function sponsored_research_submission_asset_snapshot','function sponsored_research_submission_version_assets','function sponsored_research_campaign_submissions'] as $n)$need('app/sponsored-research-submissions.php',$n,'Submission runtime missing '.$n);
foreach(["'annotation'","'claim'","'finding'","'report_version'","'source'","'mission'","'dataset'"] as $n)$need('app/sponsored-research-submissions.php',$n,'Submission runtime must support canonical asset type '.$n);
$need('app/sponsored-research-submissions.php','data_attribution_capture_object','Contributor-owned assets must bridge into Phase 37 contribution lineage.');
$need('app/sponsored-research-submissions.php','data_provenance_edge_record','Submission assets must create Phase 37 provenance edges.');
$need('app/sponsored-research-submissions.php','data_source_rights','Sources must retain separate rights governance.');
$need('app/sponsored-research-submissions.php','Only completed Research Missions can be submitted.','Mission submissions must require completion.');
$need('app/sponsored-research-submissions.php','Only frozen Dataset versions can be submitted.','Dataset submissions must require immutable frozen versions.');
$need('app/sponsored-research-submissions.php','re-accept the current campaign revision','Submission must fail closed on stale participation consent.');
$need('app/bootstrap.php',"'/sponsored-research-submissions.php'",'Submission runtime must load through bootstrap.');
$need('research-opportunities.php','Submit immutable revision','Researcher UI must expose immutable submission.');
$need('sponsored-research.php','Research submissions','Sponsor UI must expose the submission queue.');
$need('app/research-surface-map.php',"'app/sponsored-research-submissions.php'=>'governance'",'Submission engine must remain registered as Research governance.');
if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Phase 80 Section 4 Sponsored Research Submission & Provenance contract passed.\n";
