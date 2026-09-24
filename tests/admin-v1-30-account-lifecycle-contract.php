<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$p=$root.'/'.$file;if(!is_file($p)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($p),$needle))$fail[]=$message;};
foreach(['account_entitlement_overrides','account_admin_events','entitlement_key','event_type'] as $n)$need('database/migrations/20260924_064_account_lifecycle_membership_entitlements.sql',$n,'Migration 064 missing '.$n);
foreach(['function account_admin_create','function account_admin_update_lifecycle','function account_admin_add_member','function account_admin_transfer_owner','function account_admin_set_override','function account_admin_effective_entitlements','function account_admin_change_package'] as $n)$need('app/account-admin.php',$n,'Account admin runtime missing '.$n);
foreach(['Account lifecycle','Account members','Package baseline + account overrides','Account administration history'] as $n)$need('admin/account.php',$n,'Account detail UI missing '.$n);
$need('admin/accounts.php','+ Create organization account','Accounts list must support organization creation.');
$need('app/ai-usage.php','entitlement_source','Canonical AI metering must use effective account token entitlements.');
$need('app/functions.php','subscription_user_account($pdo,(int)$user','Legacy Free/Pro compatibility must honor canonical personal-account lifecycle state.');
$need('admin/account.php',"admin_ui_sidebar('accounts')",'Account detail must remain inside shared Admin navigation.');
$need('app/bootstrap.php',"/account-admin.php",'Account admin runtime must load before AI usage.');
$need('app/admin-ui.php','Admin V1.30','Shared admin shell must identify V1.30.');
$need('tests/ci/run-full-regression.sh','tests/admin-v1-30-account-lifecycle-db.php','Full regression must execute Admin V1.30 DB journey.');
$need('.github/workflows/full-regression.yml','admin-v1-30-upgrade-from-063.php','Phase gate must rehearse migration 064.');
$need('.github/workflows/package-two-zips.yml','admin/account.php','Production package must include account detail administration.');
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Admin V1.30 account lifecycle static contract passed.\n";
