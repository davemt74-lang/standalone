<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]="Missing $file";return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

$need('register.php','/onboarding.php','New accounts must enter first-run onboarding.');
$need('app/release.php','Publish your first annotation','Onboarding milestones must guide the first annotation.');
$need('app/release.php','Follow a researcher or source','Onboarding milestones must guide social/source following.');
$need('app/release.php','Start or join Research','Onboarding milestones must guide Research setup.');
$need('onboarding.php',"\$status['steps']",'Onboarding page must render the server-derived milestone checklist.');
$need('extension/service-worker.js',"details.reason==='install'",'Extension install must trigger first-run setup.');
$need('extension/sidepanel.html','id="authLoginForm"','Chrome sidebar must expose a normal login form.');
$need('extension/sidepanel.html','id="authRegisterForm"','Chrome sidebar must expose normal account creation.');
$need('extension/sidepanel-state.js','/api/extension-account.php','Chrome sidebar must authenticate against the shared Annotated account backend.');
$need('extension/sidepanel-state.js','client_version:chrome.runtime.getManifest().version','Chrome authentication must identify the client version.');
$avoid('extension/sidepanel-state.js','extension-connect.php','Primary Chrome login must stay inside the sidebar.');
$avoid('extension/sidepanel-state.js','/api/extension-pair.php','Primary Chrome login must not use the legacy pairing flow.');
$need('extension/sidepanel-capture.js','action=publish','Capture must publish through the server API.');
$need('extension/sidepanel-feed.js','action=follow','Feed must support following.');
$need('extension/sidepanel-social.js','live_message','Live messaging must remain wired.');
$need('extension/sidepanel-search.js','action=search','Chrome Search must remain wired.');
$need('api/extension-search.php','search_add_research','Search results must remain addable to Research.');
$need('app/source-integrity.php','source_integrity_notify_event','Source-change intelligence must notify affected users.');
$need('app/notifications.php','notification_rows','Unified notifications must remain access-aware.');
$need('app/moderation.php','rights_claim_create','Rights claims must remain available.');
$need('app/moderation.php','moderation_report_create','Community reports must remain available.');
$need('admin/system-health.php','release_environment_checks','Admin must expose production readiness.');
$need('bin/release-preflight.php','release_environment_checks','CLI must expose the same production readiness service.');
$need('docs/RELEASE-V1.1-RC1.md','Restore the **database backup and private evidence backup as a matched pair**','Release runbook must document matched-data rollback.');
$need('connected-accounts.php','revoke_everywhere','Users must be able to revoke browser and extension sessions.');
$need('oauth/callback.php','$fresh=$issued>0&&$issued>=time()-600','OAuth callback state must expire.');
$need('oauth/callback.php',"unset(\$_SESSION['oauth_state']",'OAuth callback state must be consumed before provider exchange.');
$need('extension/sidepanel.html','role="tablist"','Sidebar must expose tab semantics.');
$need('extension/sidepanel-feed.js',"setAttribute('aria-selected'",'Sidebar tab state must stay accessible.');
$init=(string)file_get_contents($root.'/extension/sidepanel-init.js');if(preg_match("/(?<!\\$)\\$\\('nav button'\\)\\.forEach/",$init))$fail[]='Sidebar nav wiring must not call forEach on a single selector.';if(preg_match("/(?<!\\$)\\$\\('\\.modes button'\\)\\.forEach/",$init))$fail[]='Capture mode wiring must not call forEach on a single selector.';

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "V1.1 RC1 end-to-end release contract passed.\n";
