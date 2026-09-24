<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
foreach(['Overview','Accounts & Billing','AI & Models','Research & Data','Trust & Operations'] as $needle)$need('app/admin-ui.php',$needle,'Admin sidebar section missing: '.$needle);
foreach(['function admin_ui_sidebar','function admin_ui_dashboard_snapshot','function admin_ui_account_rows'] as $needle)$need('app/admin-ui.php',$needle,'Admin V1.20 service missing: '.$needle);
foreach(['Admin control center','Operational alerts','ADMIN WORKSPACES','Metered AI runs'] as $needle)$need('admin/index.php',$needle,'Admin dashboard missing: '.$needle);
foreach(['Commercial accounts','adminAccountFilters','AI allowance','Research Teams remain a separate collaboration model'] as $needle)$need('admin/accounts.php',$needle,'Admin Accounts surface missing: '.$needle);
$pages=[
'ai.php'=>'ai','assistant.php'=>'assistant','data-attribution.php'=>'data_attribution','datasets.php'=>'datasets','discovery-entities.php'=>'discovery_entities','evaluations.php'=>'evaluations','index.php'=>'dashboard','intelligence-release-audit.php'=>'release_audit','model-campaigns.php'=>'model_campaigns','model-deployment.php'=>'model_deployment','model-improvements.php'=>'model_improvements','model-observability.php'=>'model_observability','model-registry.php'=>'model_registry','model-release.php'=>'model_release','moderation.php'=>'moderation','packages.php'=>'packages','post-training.php'=>'post_training','source-monitor.php'=>'source_monitor','system-health.php'=>'system_health','training.php'=>'training','usage.php'=>'usage','users.php'=>'users','accounts.php'=>'accounts'];
foreach($pages as $page=>$key)$need('admin/'.$page,"admin_ui_sidebar('".$key."')",'Admin page is not connected to shared sidebar: '.$page);
$need('app/bootstrap.php',"/admin-ui.php",'Admin UI service must load globally.');
foreach(['.adminSidebar','.adminDashboardMetrics','.adminWorkspaceGrid','.adminAccountFilters'] as $needle)$need('assets/css/app.css',$needle,'Admin V1.20 CSS missing: '.$needle);
$css=(string)file_get_contents($root.'/assets/css/app.css');$ext=(string)file_get_contents($root.'/extension/landing-app.css');if(!hash_equals(hash('sha256',$css),hash('sha256',$ext)))$fail[]='Website and extension shared CSS must remain byte-identical.';
$need('tests/ci/run-full-regression.sh','tests/admin-v1-20-dashboard-db.php','Full regression must execute Admin V1.20 database journey.');
$need('.github/workflows/package-two-zips.yml','admin/accounts.php','Production package must include Admin V1.20 Accounts.');
if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}echo "Admin V1.20 information architecture static contract passed.\n";
