<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$need=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(!is_file($path)){$fail[]='Missing '.$file;return;}if(!str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};
$avoid=function(string $file,string $needle,string $message)use(&$fail,$root){$path=$root.'/'.$file;if(is_file($path)&&str_contains((string)file_get_contents($path),$needle))$fail[]=$message;};

foreach([
 'app/intelligence-release-audit.php','admin/intelligence-release-audit.php',
 'tests/phase48-closed-loop-release-audit-db.php','tests/phase48-release-hardening-contract.php',
 'docs/phase-48-end-to-end-closed-loop-release-hardening.md'
] as $file)if(!is_file($root.'/'.$file))$fail[]='Phase 48 file missing: '.$file;

if(is_file($root.'/database/migrations/20260922_047_end_to_end_closed_loop_release_hardening.sql'))$fail[]='Phase 48 must not introduce a database migration.';

$runtime=(string)file_get_contents($root.'/app/intelligence-release-audit.php');
foreach(['intelligence_release_phase_matrix','intelligence_release_active_model_checks','intelligence_release_decision_checks','intelligence_release_campaign_checks','intelligence_release_closed_loop_sample','intelligence_release_audit'] as $fn)
    if(!str_contains($runtime,'function '.$fn))$fail[]='Phase 48 runtime helper missing: '.$fn;
foreach([
 ['INSERT INTO ','Phase 48 audit runtime must be read-only.'],
 ['UPDATE ','Phase 48 audit runtime must be read-only.'],
 ['DELETE FROM ','Phase 48 audit runtime must be read-only.'],
 ['data_dataset_freeze(','Phase 48 must never freeze datasets.'],
 ['data_evaluation_suite_activate(','Phase 48 must never activate evaluation suites.'],
 ['data_evaluation_run_queue(','Phase 48 must never queue evaluation runs.'],
 ['data_training_job_create(','Phase 48 must never create training jobs.'],
 ['data_training_queue(','Phase 48 must never queue training.'],
 ['data_training_manual_complete(','Phase 48 must never complete training.'],
 ['data_post_training_prepare(','Phase 48 must never prepare candidate evaluation.'],
 ['data_model_transition(','Phase 48 must never change Model Registry lifecycle.'],
 ['data_model_rollback(','Phase 48 must never invoke Model Registry rollback.'],
 ['data_model_release_finalize(','Phase 48 must never sign release decisions.'],
 ['data_model_deployment_advance(','Phase 48 must never advance deployment.'],
 ['data_model_deployment_rollback(','Phase 48 must never roll back deployment.'],
 ['ai_generate(','Phase 48 must never invoke inference for release governance.'],
 ['UPDATE ai_settings','Phase 48 must never mutate AI routing.'],
] as [$needle,$message])$avoid('app/intelligence-release-audit.php',$needle,$message);

foreach(['PHASE 48 · END-TO-END CLOSED LOOP AUDIT','Intelligence release readiness','RELEASE VERDICT','PHASE MATRIX','CLOSED-LOOP SAMPLE','RELEASE BOUNDARY'] as $needle)
    $need('admin/intelligence-release-audit.php',$needle,'Phase 48 Admin release-audit contract missing: '.$needle);
$avoid('admin/intelligence-release-audit.php','method="post"','Phase 48 Admin release audit must not expose mutation forms.');
$need('app/shell.php','Intelligence Release Audit','Phase 48 must be first-class Admin navigation.');
$need('admin/index.php','Intelligence Release Audit','Admin Home must surface Phase 48.');
$need('app/bootstrap.php',"require_once __DIR__ . '/intelligence-release-audit.php';",'Phase 48 audit runtime must load with the application.');

$need('install.php','installer_run($pdo,$schemaFile,$migrationDir)','Fresh install must use the canonical schema + all bundled migrations.');
$need('install.php',"count(installer_pending_migrations($pdo,$migrationDir))===0",'Fresh installer must verify no pending migrations before handing off to first-admin.');
$need('upgrade.php','migration_apply_pending($pdo,$dir,10)','Upgrade UI must use the canonical migration manager.');
$need('upgrade.php','Administrator access required.','Database upgrade must remain administrator-only after users exist.');
$need('upgrade.php','Previous migration failure recorded.','Upgrade UI must retain failed-migration recovery evidence.');

$need('.github/workflows/package-two-zips.yml','test -f package-website/upgrade.php','Release package must contain the database upgrade entry point.');
$need('.github/workflows/package-two-zips.yml','test -f package-website/admin/intelligence-release-audit.php','Release package must contain the Phase 48 audit workspace.');
$need('.github/workflows/package-two-zips.yml','test -f package-website/admin/model-campaigns.php','Release package must contain the Phase 47 campaign workspace.');
$need('.github/workflows/package-two-zips.yml','20260922_046_model_improvement_campaigns.sql','Release package must contain the latest Phase 47 migration.');
$need('.github/workflows/package-two-zips.yml','phase-48-end-to-end-closed-loop-release-hardening.md','Release package must contain Phase 48 release documentation.');

$need('tests/ci/run-model-governance.sh','seq 37 99','Targeted governance CI must automatically include Phase 48.');
$need('tests/ci/run-full-regression.sh','tests/phase48-closed-loop-release-audit-db.php','Full regression must include the Phase 48 integrated release audit.');
$need('tests/phase44-5-workflow-contract.php','tests/phase48-closed-loop-release-audit-db.php','Workflow hardening contract must protect Phase 48 historical coverage.');

$doc=(string)file_get_contents($root.'/docs/phase-48-end-to-end-closed-loop-release-hardening.md');
foreach(['release-hardening phase, not a new intelligence subsystem','adds **no database migration**','Release readiness is evidence, not authority','zero file differences'] as $needle)
    if(!str_contains($doc,$needle))$fail[]='Phase 48 documentation boundary missing: '.$needle;

if($fail){foreach($fail as $f)fwrite(STDERR,"FAIL: $f\n");exit(1);}
echo "Phase 48 End-to-End Closed Loop Audit & Release Hardening contract passed.\n";
