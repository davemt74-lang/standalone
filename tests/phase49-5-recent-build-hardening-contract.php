<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$read=function(string $file)use($root,&$fail): string {$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return '';}return (string)file_get_contents($path);};
$need=function(string $file,string $needle,string $message)use($read,&$fail){$s=$read($file);if($s!==''&&!str_contains($s,$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use($read,&$fail){$s=$read($file);if($s!==''&&str_contains($s,$needle))$fail[]=$message;};
$block=function(string $source,string $fn): string {$start=strpos($source,'function '.$fn);if($start===false)return '';$next=strpos($source,"
function ",$start+10);return substr($source,$start,$next===false?null:$next-$start);};

$critical=[
 'app/functions.php','app/data-datasets.php','app/data-post-training.php','app/data-model-deployment.php',
 'app/data-model-observability.php','app/data-model-improvement.php','app/data-model-campaigns.php',
 'app/intelligence-release-audit.php','app/release-operations.php'
];
foreach($critical as $file){
    $s=$read($file);if($s==='')continue;preg_match_all('/function\s+([A-Za-z0-9_]+)\s*\(/',$s,$m);$counts=array_count_values($m[1]??[]);
    foreach($counts as $name=>$count)if($count>1)$fail[]="$file defines duplicate function $name ($count times).";
}

foreach(['app_advisory_lock_name','app_advisory_lock','app_advisory_unlock','app_with_advisory_lock'] as $fn)$need('app/functions.php','function '.$fn,'Shared advisory-lock helper missing: '.$fn);
$need('app/functions.php','SELECT GET_LOCK(?,?)','Governed advisory locking must use MySQL/MariaDB GET_LOCK.');
$need('app/functions.php','SELECT RELEASE_LOCK(?)','Governed advisory locking must release connection-scoped locks.');

$deployment=$read('app/data-model-deployment.php');
$need('app/data-model-deployment.php','function data_model_deployment_locked','Deployment state machine must serialize each deployment.');
foreach(['data_model_deployment_preflight','data_model_deployment_start_shadow','data_model_deployment_checkpoint_submit','data_model_deployment_advance','data_model_deployment_pause','data_model_deployment_resume','data_model_deployment_stop','data_model_deployment_rollback'] as $fn){
    $b=$block($deployment,$fn);if($b===''||!str_contains($b,'data_model_deployment_locked('))$fail[]="$fn must execute under the deployment advisory lock.";
}
foreach(['revision=?','status=?'] as $needle)$need('app/data-model-deployment.php',$needle,'Deployment state writes must use stale-state guards.');
$rollback=$block($deployment,'data_model_deployment_rollback');$registryPos=strpos($rollback,'data_model_rollback($pdo,$viewer');$routingPos=strpos($rollback,'data_model_deployment_apply_routing');
if($registryPos===false||$routingPos===false||$registryPos>$routingPos)$fail[]='Full deployment rollback must restore Model Registry state before routing and compensate if routing fails.';
$need('app/data-model-deployment.php','rollback_compensation_failed','Rollback compensation failure must be explicitly audited.');
$need('app/data-model-deployment.php','activation_compensation_failed','Activation compensation failure must be explicitly audited.');

$need('app/data-model-observability.php',"app_with_advisory_lock($pdo,'model-incident'",'Production incident upserts must be serialized per deployment/route/metric.');

$improvement=$read('app/data-model-improvement.php');
$need('app/data-model-improvement.php','function data_model_improvement_case_locked','Phase 46 case mutations must have a shared lock helper.');
$need('app/data-model-improvement.php','function data_model_improvement_proposal_locked','Phase 46 proposal mutations must have a shared lock helper.');
$need('app/data-model-improvement.php',"app_with_advisory_lock($pdo,'model-improvement-cluster'",'Phase 46 production evidence ingestion must serialize cluster creation.');
foreach(['data_model_improvement_proposal_update','data_model_improvement_proposal_approve','data_model_improvement_proposal_publish'] as $fn){$b=$block($improvement,$fn);if($b===''||!str_contains($b,'data_model_improvement_proposal_locked('))$fail[]="$fn must serialize proposal state.";}
$need('app/data-model-improvement.php',"fresh['status']!=='draft'",'Idempotent proposal saves must verify reloaded draft state instead of relying on affected-row count.');

$campaign=$read('app/data-model-campaigns.php');
$need('app/data-model-campaigns.php','function data_model_campaign_locked','Phase 47 campaign mutations must have a shared lock helper.');
foreach(['data_model_campaign_add_case','data_model_campaign_remove_case','data_model_campaign_add_proposal','data_model_campaign_remove_proposal','data_model_campaign_create_dataset_drafts','data_model_campaign_lock','data_model_campaign_prepare_evaluation_suite','data_model_campaign_prepare_training_draft','data_model_campaign_prepare_post_training_plan','data_model_campaign_close'] as $fn){$b=$block($campaign,$fn);if($b===''||!str_contains($b,'data_model_campaign_locked('))$fail[]="$fn must serialize campaign state.";}
$sync=$block($campaign,'data_model_campaign_sync_status');$need('app/data-model-campaigns.php',"status NOT IN ('completed','abandoned')",'Campaign sync must never reopen a human-closed campaign.');
if(!str_contains($sync,'AND status=?'))$fail[]='Campaign sync must compare-and-swap against the state it evaluated.';
$need('app/data-model-campaigns.php','data_model_improvement_proposal_locked($pdo,$proposalPublicId','Campaign proposal refresh must serialize against Phase 46 proposal mutations.');

$need('app/data-datasets.php',"$ownsTx=!$pdo->inTransaction()", 'Dataset creation must be composable inside an outer governance transaction.');
$need('app/data-datasets.php',"app_with_advisory_lock($pdo,'dataset-slug'",'Dataset version allocation must be serialized by slug.');
$need('app/data-post-training.php',"$ownsTx=!$pdo->inTransaction()", 'Post-training plan creation must be composable inside the Phase 47 transaction.');

$release=$read('app/release-operations.php');
$need('app/release-operations.php','RecursiveDirectoryIterator','Release fingerprint must cover the deploy tree rather than a selected file list.');
$need('app/release-operations.php',"if($file->isLink())throw new RuntimeException",'Release fingerprint must reject package symlinks.');
$need('app/release-operations.php','function release_installed_manifest_status','Runtime preflight must verify the installed release manifest.');
$need('app/release-operations.php','package fingerprint mismatch','Installed package verification must fail on deploy-tree drift.');
$need('app/release-operations.php','Backup database target does not match the configured database.','Restore planning must fail closed on database-target mismatch.');
$need('app/release-operations.php','Backup private-storage target does not match the configured storage directory.','Restore planning must fail closed on storage-target mismatch.');
$need('app/release-operations.php','Restore prerequisites are incomplete','Restore planning must refuse to emit an executable plan without required tools.');
$need('app/release-operations.php',"'package_integrity'=>",'Operational readiness must include installed package integrity.');

$need('.github/workflows/package-two-zips.yml','php package-website/bin/release-manifest.php','Release manifest must be generated from the staged deploy tree.');
$need('tests/ci/package-smoke.sh','Release package must not contain symlinks.','Package smoke must reject symlinks.');
$need('tests/ci/package-smoke.sh','release_package_fingerprint($site)','Package smoke must recompute the full staged-tree fingerprint.');

$need('app/intelligence-release-audit.php',"status<>'abandoned'",'Closed-loop release sample must not treat an abandoned campaign as the representative active lineage.');

if($fail){foreach($fail as $message)fwrite(STDERR,"FAIL: $message\n");exit(1);}
echo "Recent Build Phase 44–49 hardening contract passed.\n";
