<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};
$m='database/migrations/20261001_121_phase80_sponsored_research_financial_ledger.sql';
foreach(['sponsored_research_finance_settings','sponsored_research_financial_transactions','sponsored_research_financial_entries','sponsored_research_compensation_reservations','researcher_pending','researcher_held','platform_fee_held','idempotency_key'] as $n)$need($m,$n,'Migration 121 missing '.$n);
foreach(['function sponsored_research_finance_transaction','function sponsored_research_finance_campaign_balances','function sponsored_research_finance_researcher_balances','function sponsored_research_finance_record_funding','function sponsored_research_compensation_reserve','function sponsored_research_compensation_settle_review','function sponsored_research_compensation_hold_review','function sponsored_research_compensation_release_hold','function sponsored_research_compensation_reverse'] as $n)$need('app/sponsored-research-finance.php',$n,'Sponsored Research finance runtime missing '.$n);
$need('app/sponsored-research-finance.php','Internal Sponsored Research ledger transactions must balance to zero.','Internal ledger journals must be balanced.');
$need('app/sponsored-research-finance.php','Administrator access required to record campaign funding.','Sponsors must not self-declare campaign funds.');
$need('app/sponsored-research-finance.php','A stable payment or invoice reference is required to record funding.','Manual funding must require a stable idempotency reference.');
$need('app/sponsored-research-reviews.php','sponsored_research_compensation_require_reserved','Acceptance must require a funded compensation reservation.');
$need('app/sponsored-research-reviews.php','sponsored_research_compensation_settle_review','Accepted research must settle through the immutable finance ledger.');
$need('app/sponsored-research-reviews.php','sponsored_research_compensation_hold_review','Disputes must hold settled compensation.');
$need('research-earnings.php','Balances are derived from the immutable Sponsored Research financial ledger.','Researcher earnings view must describe ledger-derived balances.');
$need('admin/sponsored-research-finance.php','Stripe customer credits are not researcher earnings.','Admin finance surface must preserve the Stripe/customer-credit boundary.');
$need('sponsored-research.php','Campaign finance','Sponsor campaign surface must expose funded/reserved finance state.');
$need('app/research-surface-map.php',"'app/sponsored-research-finance.php'=>'governance'",'Sponsored finance engine must remain registered as Research governance.');
if($fail){foreach(array_unique($fail) as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Phase 80 Section 6 Compensation, Earnings & Financial Ledger contract passed.\n";
