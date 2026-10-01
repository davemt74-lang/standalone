<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};
$m='database/migrations/20261001_117_phase80_sponsored_research_campaigns.sql';
foreach(['sponsored_research_campaigns','sponsored_research_campaign_questions','sponsored_research_campaign_versions','sponsored_research_campaign_events',"ENUM('public','private','invite_only')",'budget_cents','eligibility_json','disclosure_json','research_agent_id','review_deadline','config_hash'] as $n)$need($m,$n,'Migration 117 missing '.$n);
foreach(['function sponsored_research_campaign_create','sponsor_account_require_approved','account_membership_account_for_user','function sponsored_research_campaign_update','function sponsored_research_campaign_snapshot','function sponsored_research_campaign_set_status','function sponsored_research_campaign_versions','function sponsored_research_campaign_events','function sponsored_research_campaign_locked','function sponsored_research_campaign_datetime','function sponsored_research_campaign_money_cents','function sponsored_research_campaign_eligibility','function sponsored_research_campaign_disclosures'] as $n)$need('app/sponsored-research-campaigns.php',$n,'Sponsored campaign runtime missing '.$n);
$need('app/bootstrap.php',"'/sponsored-research-campaigns.php'",'Sponsored campaign runtime must load through bootstrap.');
$need('sponsored-research.php','Sponsored Research Campaigns','Sponsor campaign workspace is missing.');
$need('sponsored-research.php','name="access_mode"','Sponsor workspace must expose public/private/invite-only access.');
$need('sponsored-research.php','name="research_agent_id"','Sponsor workspace must reuse existing Research Agents.');
$need('sponsored-research.php','name="sponsorship_disclosure_required"','Sponsor workspace must capture sponsorship disclosure requirements.');
$need('sponsored-research.php','name="conflict_disclosure_required"','Sponsor workspace must capture conflict disclosure requirements.');
$need('sponsored-research.php','name="review_deadline"','Sponsor workspace must capture a review deadline.');
$need('sponsored-research.php','optional_separate_consent','Training use must remain an optional separate-consent request.');
$need('sponsored-research.php','name="op" value="update"','Sponsor workspace must support versioned campaign amendments.');
$need('research-account.php','Open Sponsored Research','Approved Sponsor Accounts must have a direct Sponsored Research entry point.');
$need('app/research-surface-map.php',"'app/sponsored-research-campaigns.php'=>'governance'",'Sponsored Research must remain registered in the canonical Research governance map.');
$need('tests/ci/run-full-regression.sh','tests/phase80-section2-sponsored-research-campaigns-db.php','Release regression must retain the Section 2 DB journey.');
if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Phase 80 Section 2 Sponsored Research Campaign Foundation contract passed.\n";
