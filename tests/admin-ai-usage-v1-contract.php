<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$migration='database/migrations/20260924_063_ai_usage_metering.sql';
foreach(['ai_usage_events','ai_usage_adjustments','estimated_cost_micros','token_delta'] as $needle)$need($migration,$needle,'AI usage migration missing: '.$needle);
foreach(['function ai_usage_assert_can_run','function ai_usage_record_completed_run','function ai_usage_account_summary','function ai_usage_admin_adjust','function ai_usage_refresh_account_period'] as $needle)$need('app/ai-usage.php',$needle,'AI usage service missing: '.$needle);
$need('app/ai.php','ai_usage_assert_can_run','ai_run must enforce account allowance before provider execution.');
$need('app/ai.php','ai_usage_record_completed_run','ai_run must write completed provider usage to the canonical ledger.');
foreach(['AI usage & token management','Account balances','Recent metered runs','Token adjustments'] as $needle)$need('admin/usage.php',$needle,'Admin usage UI missing: '.$needle);
$need('admin/index.php','/admin/usage.php','Admin dashboard must link to AI Usage.');
$need('admin/users.php','used_tokens','Admin Users must expose current account token consumption.');
$need('app/bootstrap.php',"/ai-usage.php","AI usage service must load after subscriptions.");
$need('tests/ci/run-full-regression.sh','tests/admin-ai-usage-v1-db.php','Full regression must execute the AI usage database journey.');
$need('.github/workflows/full-regression.yml','ai-usage-v1-upgrade-from-062.php','Phase gate must rehearse migration 063 from migration 062.');
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Admin AI Usage V1.10 static contract passed.\n";
