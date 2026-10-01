<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};
$m='database/migrations/20261001_118_phase80_sponsored_research_participation.sql';
foreach(['sponsored_research_campaign_invites','sponsored_research_campaign_terms','sponsored_research_participations','sponsored_research_participation_acceptances','sponsored_research_participation_events','campaign_revision_accepted','eligibility_snapshot_json','terms_hash','conflict_disclosure','nda_accepted'] as $n)$need($m,$n,'Migration 118 missing '.$n);
foreach(['function sponsored_research_campaign_terms_publish','function sponsored_research_campaign_invite','function sponsored_research_campaign_eligibility_check','function sponsored_research_campaign_opportunities','function sponsored_research_campaign_join','function sponsored_research_campaign_withdraw','function sponsored_research_campaign_participants','function sponsored_research_participation_acceptances'] as $n)$need('app/sponsored-research-participation.php',$n,'Participation runtime missing '.$n);
$need('app/sponsored-research-participation.php',"'training_consent_granted'=>false",'Participation event must explicitly record that campaign joining does not grant training consent.');
$need('app/bootstrap.php',"'/sponsored-research-participation.php'",'Participation runtime must load through bootstrap.');
$need('sponsored-research.php','name="op" value="terms"','Sponsor workspace must publish versioned participation terms.');
$need('sponsored-research.php','name="op" value="invite"','Sponsor workspace must invite approved researchers.');
$need('research-opportunities.php','Research Opportunities','Researcher opportunity workspace is missing.');
$need('research-opportunities.php','I accept these campaign participation terms.','Researcher must explicitly accept campaign terms.');
$need('research-opportunities.php','does not grant model training or evaluation rights','Researcher UI must preserve the separate training-consent boundary.');
$need('research-account.php','Open Research Opportunities','Approved Research Accounts must have a direct opportunities entry point.');
$need('app/research-surface-map.php',"'app/sponsored-research-participation.php'=>'governance'",'Participation engine must remain registered as Research governance.');
if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Phase 80 Section 3 Campaign Participation, Eligibility & Terms contract passed.\n";
