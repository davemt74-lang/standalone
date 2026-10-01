<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};
$m='database/migrations/20260930_115_phase80_research_sponsor_account_governance.sql';
foreach(['research_account_profiles','sponsor_account_profiles','research_account_governance_events',"ENUM('pending','approved','suspended','revoked')",'marketplace_visible','payout_readiness'] as $n)$need($m,$n,'Migration 115 missing '.$n);
foreach(['function research_account_apply','function sponsor_account_apply','function research_account_admin_decide','function research_account_is_approved','function sponsor_account_is_approved','function research_account_require_approved','function research_account_eligibility_snapshot','function research_account_governance_history'] as $n)$need('app/research-accounts.php',$n,'Phase 80 Section 1 runtime missing '.$n);
$need('app/bootstrap.php',"/research-accounts.php'",'Research Account governance runtime must load globally.');
$need('admin/research-accounts.php','Research & Sponsor Accounts','Admin approval queue is missing.');
$need('research-account.php','Submit Research Account application','User Research Account application surface is missing.');
$need('app/admin-access.php',"/admin/research-accounts.php",'Research Account Admin route must reuse canonical account-management capabilities.');
$need('app/admin-ui.php',"'research_accounts'=>['label'=>'Research Accounts'","Admin navigation must expose Research Accounts.");
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Phase 80 Section 1 Research & Sponsor Account Governance static contract passed.\n";
