<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};
$m='database/migrations/20261001_117_phase80_sponsored_research_campaigns.sql';
foreach(['sponsored_research_campaigns','sponsored_research_campaign_questions','sponsored_research_campaign_versions','sponsored_research_campaign_events',"ENUM('public','private','invite_only')",'budget_cents','eligibility_json','disclosure_json','research_agent_id'] as $n)$need($m,$n,'Migration 117 missing '.$n);
foreach(['function sponsored_research_campaign_create','sponsor_account_require_approved','account_membership_account_for_user','function sponsored_research_campaign_update','function sponsored_research_campaign_snapshot','function sponsored_research_campaign_set_status','function sponsored_research_campaign_versions'] as $n)$need('app/sponsored-research-campaigns.php',$n,'Sponsored campaign runtime missing '.$n);
$need('app/bootstrap.php',"'/sponsored-research-campaigns.php'",'Sponsored campaign runtime must load through bootstrap.');
$need('sponsored-research.php','Sponsored Research Campaigns','Sponsor campaign workspace is missing.');
$need('sponsored-research.php','name="access_mode"','Sponsor workspace must expose public/private/invite-only access.');
$need('sponsored-research.php','name="research_agent_id"','Sponsor workspace must reuse existing Research Agents.');
$need('sponsored-research.php','name="sponsor_disclosure"','Sponsor workspace must capture disclosure requirements.');
if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Phase 80 Section 2 Sponsored Research Campaign Foundation contract passed.\n";
